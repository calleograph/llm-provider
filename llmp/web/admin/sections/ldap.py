"""Раздел «LDAP-подключения» (перенос public/admin/sections/ldap.php): LDAPS-подключения к AD вместе с параметрами
LLM-модуля и общий CA bundle. Сертификат перед сохранением ждёт подтверждения в серверной сессии (как $_SESSION в PHP)."""

from __future__ import annotations

import datetime as dt
import re
import time
from urllib.parse import urlsplit

from ....certs import CertificateParser
from ....chat.settings import php_int
from ....domain import LlmDomain, _valid_utf8
from ....ldap.config import validate_fields
from ....ldap.diagnostics import ConnectionDiagnostics
from ....llm.providers import php_empty
from ..base import FORM_STALE, AdminPage

TRIM = " \t\n\r\0\x0b"
MAX_CERT_BYTES = 512 * 1024
LDAP_ACTIONS = ("create_ldap", "update_ldap", "delete_ldap", "set_active_ldap", "test_ldap")
CERT_ACTIONS = ("preview_certificate", "preview_certificate_pem", "confirm_certificate", "cancel_certificate", "delete_certificate")
_SAM = re.compile(r"[A-Za-z0-9._-]{1,64}")


def _host_of(value: str) -> str:
    """«ldaps://dc1.corp.local:636/», «dc1.corp.local:636» → «dc1.corp.local»: в поле «Хост» часто вставляют адрес целиком."""
    if "://" in value:
        return urlsplit(value).hostname or ""
    return value.split("/")[0].rsplit(":", 1)[0] if value.count(":") == 1 else value.split("/")[0]


def ldap_form(page: AdminPage, existing) -> dict:
    p = lambda name, default="": page.post(name, default).strip(TRIM)  # noqa: E731
    url, host, port = p("ldaps_url"), _host_of(p("host")), php_int(page.post("port", "636"))
    # одно из двух полей восстанавливается из другого — администратор не обязан повторять адрес дважды
    if host == "" and url != "":
        host = _host_of(url)
    if url == "" and host != "":
        url = f"ldaps://{host}:{port}/"
    return {
        "name": p("name"), "ldaps_url": url, "host": host,
        "port": port,
        "base_dn": p("base_dn"), "domain": p("domain"), "netbios_domain": p("netbios_domain"),
        # группа администраторов сервиса задаётся в разделе «Доступ к админ-панели»
        "admin_group_dn": existing.admin_group_dn if existing is not None else "",
        "protocol_version": php_int(page.post("protocol_version", "3")),
        "starttls": False,
        "network_timeout": max(1, php_int(page.post("network_timeout", "8"))),
        "ca_bundle_file": existing.ca_bundle_file if existing is not None else None,
        "llm_enabled": not php_empty(page.post("llm_enabled")),
        "service_username": p("service_username"),
    }


def ldap_validate(data: dict) -> dict[str, str]:
    errors = validate_fields(data)
    for f in ("name", "ldaps_url", "host", "base_dn", "domain", "netbios_domain"):
        if not _valid_utf8(data[f]):
            errors[f] = "Недопустимые символы (ожидается UTF-8)."
    if data["service_username"] != "" and not _SAM.fullmatch(data["service_username"]):
        errors["service_username"] = "Укажите sAMAccountName без домена, например svc-llm-read."
    return errors


def _clear_pending(session) -> None:
    session.pop("pending_cert_pem", None)
    session.pop("pending_cert_type", None)


def _cert_type(page: AdminPage) -> str:
    return "root" if page.post("cert_type", "intermediate").strip(TRIM) == "root" else "intermediate"


def handle(page: AdminPage) -> str:
    page.page_title = "LDAP-подключения"
    m = page.module
    configs, domains, events, diag = m.ldap_configs, m.domains, m.events, m.diagnostics
    session = page.ctx.session
    admin, ip = page.admin, page.ip
    test_results: dict[int, dict] = {}
    editing_id: int | None = None         # карточка с ошибками (0 — новая)
    field_errors: dict[str, str] = {}
    form_values: dict | None = None
    posted_id = php_int(page.post("id", "0"))

    if page.is_post(*LDAP_ACTIONS):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif page.action == "create_ldap":
            data = ldap_form(page, None)
            field_errors = ldap_validate(data)
            if not field_errors and configs.name_exists(data["name"]):
                field_errors["name"] = "Подключение с таким названием уже существует."
            if field_errors:
                page.flash_error = "Проверьте выделенные поля формы нового подключения."
                form_values, editing_id = data, 0
            else:
                new_id = configs.create(data, configs.all() == [])
                password = page.post("service_password")
                domains.save_llm_settings(new_id, data["llm_enabled"], data["service_username"], password or None, False)
                events.admin(admin, ip, "ldap_create", True, {
                    "id": new_id, "name": data["name"], "host": data["host"], "llm_enabled": data["llm_enabled"],
                    "service_account": data["service_username"] or "—",
                })
                page.flash_success = f"Подключение «{data['name']}» добавлено."
        elif page.action == "update_ldap":
            existing = domains.find(posted_id)
            if existing is None:
                page.flash_error = "Подключение не найдено."
            else:
                data = ldap_form(page, existing.config)
                field_errors = ldap_validate(data)
                if not field_errors and configs.name_exists(data["name"], posted_id):
                    field_errors["name"] = "Подключение с таким названием уже существует."
                if field_errors:
                    page.flash_error = "Проверьте выделенные поля формы."
                    form_values, editing_id = data, posted_id
                else:
                    cfg = existing.config
                    configs.update(posted_id, data)
                    password = page.post("service_password")
                    # сменился сервер — сохранённый пароль служебной учётки не отправляется на новый адрес без повторного ввода
                    target_changed = (data["ldaps_url"], data["host"], data["port"]) != (cfg.ldaps_url, cfg.host, cfg.port)
                    clear_password = not php_empty(page.post("clear_service_password")) or (target_changed and password == "")
                    domains.save_llm_settings(posted_id, data["llm_enabled"], data["service_username"], password or None, clear_password)
                    props = {"name": "name", "ldaps_url": "ldaps_url", "host": "host", "port": "port", "base_dn": "base_dn",
                             "domain": "domain", "netbios_domain": "netbios_domain", "network_timeout": "network_timeout",
                             "protocol_version": "protocol_version"}
                    changed = [f for f, prop in props.items() if str(data[f]) != str(getattr(cfg, prop))]
                    if data["llm_enabled"] != existing.llm_enabled:
                        changed.append("llm_enabled")
                    if data["service_username"] != (existing.service_username or ""):
                        changed.append("service_username")
                    events.admin(admin, ip, "ldap_update", True, {
                        "id": posted_id, "name": data["name"], "changed": changed or ["—"], "llm_enabled": data["llm_enabled"],
                        "service_auth_change": "cleared" if clear_password else ("replaced" if password != "" else "kept"),
                    })
                    page.flash_success = f"Подключение «{data['name']}» сохранено."
        elif page.action == "set_active_ldap":
            try:
                configs.set_active(posted_id)
                events.admin(admin, ip, "ldap_activate", True, {"id": posted_id})
                page.flash_success = "Подключение назначено активным (используется для входа администраторов)."
            except Exception:  # noqa: BLE001
                page.flash_error = "Не удалось назначить активное подключение."
                events.admin(admin, ip, "ldap_activate", False, {"id": posted_id})
        elif page.action == "delete_ldap":
            existing = domains.find(posted_id)
            try:
                if existing is None:
                    raise RuntimeError("Подключение не найдено.")
                configs.delete(posted_id)
                events.admin(admin, ip, "ldap_delete", True, {"id": posted_id, "name": existing.config.name})
                page.flash_success = "Подключение удалено вместе с его разрешёнными группами и пользователями."
            except Exception as e:  # noqa: BLE001
                page.flash_error = str(e)
                events.admin(admin, ip, "ldap_delete", False, {"id": posted_id})
        elif page.action == "test_ldap":
            existing = domains.find(posted_id)
            if existing is None:
                page.flash_error = "Подключение не найдено."
            else:
                # приоритет — данные, введённые вручную для теста; иначе сервисная учётка домена
                test_username = page.post("test_username").strip(TRIM)
                test_password = page.post("test_password")
                if test_username == "" and existing.has_service_account():
                    test_username = str(existing.service_username)
                    test_password = domains.service_password(posted_id) or ""
                bind_requested = test_username != "" and test_password != ""
                cfg = existing.config
                try:
                    r = ConnectionDiagnostics(m.ca_bundle, m.certificates).check(cfg, test_username or None, test_password or None)
                    test_results[posted_id] = r
                    bind = "not_requested" if not r["bind_tested"] else ("success" if r["bind_successful"] else "failed")
                    events.admin(admin, ip, "ldap_test", True, {
                        "id": posted_id, "name": cfg.name, "tls": r["tls_valid"], "bind_requested": bind_requested, "bind": bind,
                    })
                    b = lambda v: "1" if v else "0"  # noqa: E731
                    diag.info("connection_test", "ldap_test",
                              f"dns={b(r['dns_resolved'])} tcp={b(r['tcp_reachable'])} tls={b(r['tls_valid'])} "
                              f"bind_tested={b(r['bind_tested'])} bind_ok={'n/a' if r['bind_successful'] is None else b(r['bind_successful'])}",
                              {"config": cfg.name, "host": cfg.host}, ip)
                except Exception as e:  # noqa: BLE001
                    page.flash_error = "Не удалось выполнить проверку подключения."
                    events.admin(admin, ip, "ldap_test", False, {"id": posted_id, "name": cfg.name})
                    diag.error("connection_test", type(e).__name__, str(e), {"config": cfg.name}, ip)

    # ---- сертификаты (общий CA bundle, не привязан к подключению) ----
    if page.is_post(*CERT_ACTIONS):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif page.action == "preview_certificate":
            upload = page.file("certificate")
            content = upload.file.read(MAX_CERT_BYTES + 1) if upload is not None else b""
            if upload is None:
                page.flash_error = "Файл не выбран или произошла ошибка загрузки."
            elif len(content) > MAX_CERT_BYTES:
                page.flash_error = "Файл слишком большой (максимум 512 КБ)."
            else:
                try:
                    session["pending_cert_pem"] = CertificateParser.parse(content)["pem"]
                    session["pending_cert_type"] = _cert_type(page)
                except Exception as e:  # noqa: BLE001
                    page.flash_error = str(e)
        elif page.action == "preview_certificate_pem":
            pasted = page.post("certificate_pem")
            if pasted.strip(TRIM) == "":
                page.flash_error = "Вставьте содержимое сертификата в формате PEM."
            elif len(pasted.encode("utf-8", "surrogateescape")) > MAX_CERT_BYTES:
                page.flash_error = "Слишком много текста (максимум 512 КБ)."
            else:
                try:
                    session["pending_cert_pem"] = CertificateParser.parse(pasted)["pem"]
                    session["pending_cert_type"] = _cert_type(page)
                except Exception as e:  # noqa: BLE001
                    page.flash_error = str(e)
        elif page.action == "confirm_certificate":
            if not session.get("pending_cert_pem"):
                page.flash_error = "Нет сертификата, ожидающего подтверждения."
            else:
                try:
                    parsed = CertificateParser.parse(session["pending_cert_pem"])
                    m.certificates.store(parsed, session.get("pending_cert_type", "intermediate"), admin)
                    m.ca_bundle.rebuild()
                    _clear_pending(session)
                    m.audit.log(admin, ip, "certificate_upload", True, "fp=" + parsed["fingerprint_sha256"])
                    page.flash_success = "Сертификат сохранён и включён в CA bundle."
                except Exception as e:  # noqa: BLE001
                    page.flash_error = "Не удалось сохранить сертификат: " + str(e)
                    m.audit.log(admin, ip, "certificate_upload", False)
        elif page.action == "cancel_certificate":
            _clear_pending(session)
        elif page.action == "delete_certificate":
            m.certificates.delete(posted_id)
            try:
                m.ca_bundle.rebuild()
            except Exception:  # noqa: BLE001
                pass  # удалён последний сертификат — bundle нет, LDAPS осознанно отказывает (fail-closed)
            m.audit.log(admin, ip, "certificate_delete", True, f"id={posted_id}")
            page.flash_success = "Сертификат удалён."

    pending = None
    if session.get("pending_cert_pem"):
        try:
            pending = CertificateParser.parse(session["pending_cert_pem"])
        except Exception:  # noqa: BLE001
            _clear_pending(session)

    def value(domain: LlmDomain | None, field: str, default: str = "") -> str:
        """Значение поля карточки: из непрошедшей валидацию формы либо из БД."""
        card_id = domain.id() if domain is not None else 0
        if form_values is not None and editing_id == card_id and field in form_values:
            v = form_values[field]
            return ("1" if v else "") if isinstance(v, bool) else str(v)
        if domain is None:
            return default
        c = domain.config
        return {
            "name": c.name, "ldaps_url": c.ldaps_url, "host": c.host, "port": str(c.port), "base_dn": c.base_dn,
            "domain": c.domain, "netbios_domain": c.netbios_domain, "network_timeout": str(c.network_timeout),
            "protocol_version": str(c.protocol_version), "llm_enabled": "1" if domain.llm_enabled else "",
            "service_username": domain.service_username or "",
        }.get(field, default)

    def errors_for(domain: LlmDomain | None) -> dict:
        return field_errors if editing_id == (domain.id() if domain is not None else 0) else {}

    certificates = m.certificates.all()
    return page.render("admin/sections/ldap.j2", domains=domains.all(), certificates=certificates, test_results=test_results,
                       editing_id=editing_id, value=value, errors_for=errors_for, pending=pending,
                       pending_type=session.get("pending_cert_type", "intermediate"), cert_expiry=_expiry)


def _expiry(valid_to: str) -> str:
    """'' | 'soon' | 'expired' — как strtotime(valid_to) в PHP."""
    try:
        ts = dt.datetime.strptime(valid_to.replace(" UTC", "").strip(), "%Y-%m-%d %H:%M:%S").replace(tzinfo=dt.timezone.utc).timestamp()
    except ValueError:
        return ""
    now = time.time()
    return "expired" if ts < now else ("soon" if ts < now + 30 * 86400 else "")
