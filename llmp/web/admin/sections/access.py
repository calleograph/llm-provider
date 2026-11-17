"""Раздел «Доступ к админ-панели» (перенос public/admin/sections/access.php): учётная запись для проверки, DN группы администраторов AD
(группа проверяется в каталоге под этой учёткой), отдельные доменные администраторы и пароль локального аварийного администратора."""

from __future__ import annotations

import hmac
import re

from ....auth import AdminSession, LocalAdminRepository, admin_users, set_admin_users
from ....ldap.client import LdapClient, LdapException
from ..base import FORM_STALE, AdminPage

_TRIM = " \t\n\r\0\x0b"
_SAM = re.compile(r"[A-Za-z0-9._-]{1,64}")


def _fail(page: AdminPage, event: str, exc: Exception, message: str) -> None:
    m = page.module
    page.flash_error = message
    m.audit.log(page.admin, page.ip, event, False)
    m.diagnostics.error("ldap_bind", getattr(exc, "error_code", type(exc).__name__), str(exc), {"admin": page.admin}, page.ip)


def _save_check_account(page: AdminPage, active) -> None:
    """Учётка для проверки: bind под ней обязателен, затем она сохраняется (шифруется) как служебная учётка подключения —
    та же, что в «LDAP-подключениях». Пустой пароль при заданном логине — оставить сохранённый и перепроверить его."""
    m = page.module
    user = page.post("check_username").strip(_TRIM)
    password = page.post("check_password")
    domain = m.domains.find(active.id)
    if not _SAM.fullmatch(user):
        page.flash_error = "Укажите логин без домена, например svc-llm-read."
        return
    if password == "" and domain is not None and domain.service_username == user and domain.has_service_password:
        password = m.domains.service_password(active.id) or ""
    if password == "":
        page.flash_error = "Укажите пароль учётной записи."
        return
    client = None
    try:
        client = LdapClient(active, m.ca_bundle_path())
        client.connect()
        client.bind(f"{user}@{active.domain}", password)
    except Exception as exc:  # noqa: BLE001
        _fail(page, "admin_check_account_change", exc, "Не удалось войти под этой учётной записью: проверьте логин, пароль, сертификаты и доступность каталога. Данные не сохранены.")
        return
    finally:
        if client is not None:
            client.close()
    m.domains.save_llm_settings(active.id, domain.llm_enabled if domain else False, user, password, False)
    m.audit.log(page.admin, page.ip, "admin_check_account_change", True, "user=" + user)
    page.flash_success = "Учётная запись для проверки сохранена."


def _update_admin_group(page: AdminPage, active) -> None:
    m = page.module
    new_dn = page.post("admin_group_dn").strip(_TRIM)
    if new_dn == "":
        page.flash_error = "Укажите DN группы."
        return
    domain = m.domains.find(active.id)
    if domain is None or not domain.has_service_account():
        page.flash_error = "Сначала сохраните учётную запись для проверки (блок выше): без неё нельзя проверить, что группа существует."
        return
    client = None
    try:
        client = LdapClient(active, m.ca_bundle_path())
        client.connect()
        client.bind(m.authenticator.service_principal(str(domain.service_username), active.domain), m.domains.service_password(active.id) or "")
        if not client.group_exists(new_dn):
            page.flash_error = "Указанная группа не найдена в каталоге. DN не изменён."
            return
        warning = ""
        if page.admin_method == "domain":
            user_dn = client.find_user_dn_by_sam_account_name(page.admin)
            if user_dn is not None and not client.is_member_of_group_recursive(user_dn, new_dn):
                warning = " Внимание: текущий администратор НЕ входит в новую группу — после сохранения возможна потеря доступа через доменный вход."
    except Exception as exc:  # noqa: BLE001
        _fail(page, "admin_group_change", exc, "Не удалось проверить группу: учётная запись для проверки не вошла в каталог. Проверьте её в блоке выше.")
        return
    finally:
        if client is not None:
            client.close()
    m.ldap_configs.update(active.id, {
        "name": active.name, "ldaps_url": active.ldaps_url, "host": active.host, "port": active.port, "base_dn": active.base_dn,
        "domain": active.domain, "netbios_domain": active.netbios_domain, "admin_group_dn": new_dn,
        "protocol_version": active.protocol_version, "starttls": active.start_tls, "network_timeout": active.network_timeout,
        "ca_bundle_file": active.ca_bundle_file,
    })
    m.audit.log(page.admin, page.ip, "admin_group_change", True, "new_dn=" + new_dn)
    page.flash_success = "DN группы администраторов обновлён." + warning


def _admin_user(page: AdminPage, active) -> None:
    """Отдельный администратор: добавляется только существующая в каталоге учётка (проверка под учёткой для проверки), удаляется сразу."""
    m = page.module
    user = page.post("admin_user").strip(_TRIM).lower()
    users = admin_users(m.conn, active.id)
    if page.action == "remove_admin_user":
        if user in users:
            set_admin_users(m.conn, active.id, [u for u in users if u != user])
            m.audit.log(page.admin, page.ip, "admin_user_remove", True, "user=" + user)
            page.flash_success = f"{user} больше не администратор (действующая сессия завершится не позже предельного срока)."
        return
    if not _SAM.fullmatch(user):
        page.flash_error = "Укажите логин без домена, например ivanov."
        return
    if user in users:
        page.flash_error = f"{user} уже в списке."
        return
    domain = m.domains.find(active.id)
    if domain is None or not domain.has_service_account():
        page.flash_error = "Сначала сохраните учётную запись для проверки (первый блок): без неё нельзя проверить, что учётка существует."
        return
    client = None
    try:
        client = LdapClient(active, m.ca_bundle_path())
        client.connect()
        client.bind(m.authenticator.service_principal(str(domain.service_username), active.domain), m.domains.service_password(active.id) or "")
        found = client.find_user_dn_by_sam_account_name(user) is not None
    except Exception as exc:  # noqa: BLE001
        _fail(page, "admin_user_add", exc, "Не удалось проверить учётную запись: учётка для проверки не вошла в каталог. Проверьте её в первом блоке.")
        return
    finally:
        if client is not None:
            client.close()
    if not found:
        page.flash_error = f"Учётная запись {user} не найдена в каталоге. Список не изменён."
        return
    set_admin_users(m.conn, active.id, users + [user])
    m.audit.log(page.admin, page.ip, "admin_user_add", True, "user=" + user)
    page.flash_success = f"{user} добавлен в администраторы: входит в админку доменным паролем, способ входа «Доменная учётная запись»."


def _change_local_password(page: AdminPage, admins: LocalAdminRepository) -> None:
    # повторный ввод прежнего пароля намеренно не нужен: из доменной сессии можно восстановить локальный доступ
    new, confirm = page.post("new_local_password"), page.post("confirm_local_password")
    audit = page.module.audit
    if len(new) < 16:
        page.flash_error = "Новый пароль локального администратора должен быть не короче 16 символов."
        audit.log(page.admin, page.ip, "local_admin_password_change", False, "reason=password_too_short; session_method=" + page.admin_method)
    elif not hmac.compare_digest(new.encode(), confirm.encode()):
        page.flash_error = "Новый пароль и подтверждение не совпадают."
        audit.log(page.admin, page.ip, "local_admin_password_change", False, "reason=confirmation_mismatch; session_method=" + page.admin_method)
    else:
        admins.change_password(new)
        AdminSession.refresh_stamp(page.ctx.session, admins.credential_stamp())   # своя сессия остаётся, прочие завершаются
        audit.log(page.admin, page.ip, "local_admin_password_change", True,
                  f"target={admins.username() or ''}; session_method={page.admin_method}")
        page.flash_success = "Пароль локального администратора изменён."


def handle(page: AdminPage) -> str:
    m = page.module
    admins = LocalAdminRepository(m.conn)
    active = m.ldap_configs.find_active()
    if page.is_post("update_admin_group", "save_check_account", "add_admin_user", "remove_admin_user"):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif active is None:
            page.flash_error = "Нет активной конфигурации подключения."
        else:
            {"update_admin_group": _update_admin_group, "save_check_account": _save_check_account}.get(page.action, _admin_user)(page, active)
            active = m.ldap_configs.find_by_id(active.id)
    if page.is_post("change_local_admin_password"):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        else:
            _change_local_password(page, admins)
    if page.is_post("update_admin_group", "save_check_account", "add_admin_user", "remove_admin_user", "change_local_admin_password"):
        return page.redirect_after_post()   # обновление страницы не должно повторять отправку формы
    page.page_title = "Доступ к админ-панели"
    domain = m.domains.find(active.id) if active is not None else None
    return page.render("admin/sections/access.j2", active=active, local_username=admins.username() or "",
                       check_user=domain.service_username if domain and domain.has_service_account() else "",
                       admin_users=admin_users(m.conn, active.id) if active is not None else [])
