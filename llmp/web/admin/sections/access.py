"""Раздел «Доступ к админ-панели» (перенос public/admin/sections/access.php): DN группы администраторов AD
(с проверкой существования группы в каталоге) и пароль локального аварийного администратора."""

from __future__ import annotations

import hmac

from ....auth import AdminSession, LocalAdminRepository
from ....ldap.client import LdapClient, LdapException
from ..base import FORM_STALE, AdminPage

_TRIM = " \t\n\r\0\x0b"


def _update_admin_group(page: AdminPage, active) -> None:
    m = page.module
    new_dn = page.post("admin_group_dn").strip(_TRIM)
    if new_dn == "":
        page.flash_error = "Укажите DN группы."
        return
    try:
        client = LdapClient(active, m.ca_bundle_path())
        client.connect()
        try:
            # для проверки нужен собственный bind — доменные логин/пароль подтверждаются повторно и не сохраняются
            user = page.post("confirm_username").strip(_TRIM)
            password = page.post("confirm_password")
            if user == "" or password == "":
                page.flash_error = "Для проверки существования группы укажите логин и пароль доменной учётной записи с правом чтения каталога."
                return
            client.bind(f"{user}@{active.domain}", password)
            if not client.group_exists(new_dn):
                page.flash_error = "Указанная группа не найдена в каталоге. DN не изменён."
                return
            warning = ""
            user_dn = client.find_user_dn_by_sam_account_name(user)
            if page.admin_method == "domain" and user_dn is not None and not client.is_member_of_group_recursive(user_dn, new_dn):
                warning = " Внимание: текущий администратор НЕ входит в новую группу — после сохранения возможна потеря доступа через доменный вход."
        finally:
            client.close()
        m.ldap_configs.update(active.id, {
            "name": active.name, "ldaps_url": active.ldaps_url, "host": active.host, "port": active.port, "base_dn": active.base_dn,
            "domain": active.domain, "netbios_domain": active.netbios_domain, "admin_group_dn": new_dn,
            "protocol_version": active.protocol_version, "starttls": active.start_tls, "network_timeout": active.network_timeout,
            "ca_bundle_file": active.ca_bundle_file,
        })
        m.audit.log(page.admin, page.ip, "admin_group_change", True, "new_dn=" + new_dn)
        page.flash_success = "DN группы администраторов обновлён." + warning
    except LdapException as exc:
        page.flash_error = "Не удалось подключиться к каталогу для проверки группы."
        m.audit.log(page.admin, page.ip, "admin_group_change", False)
        m.diagnostics.error("ldap_bind", exc.error_code, str(exc), {"admin": page.admin}, page.ip)
    except Exception as exc:  # noqa: BLE001
        page.flash_error = "Не удалось подключиться к каталогу для проверки группы."
        m.audit.log(page.admin, page.ip, "admin_group_change", False)
        m.diagnostics.error("ldap_connect", type(exc).__name__, str(exc), {"admin": page.admin}, page.ip)


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
    if page.is_post("update_admin_group"):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif active is None:
            page.flash_error = "Нет активной конфигурации подключения."
        else:
            _update_admin_group(page, active)
            active = m.ldap_configs.find_by_id(active.id)
    if page.is_post("change_local_admin_password"):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        else:
            _change_local_password(page, admins)
    page.page_title = "Доступ к админ-панели"
    return page.render("admin/sections/access.j2", active=active, local_username=admins.username() or "")
