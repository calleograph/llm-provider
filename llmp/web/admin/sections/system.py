"""Раздел «Настройки» (перенос public/admin/sections/system.php): тема оформления для всех, таймаут бездействия администратора, доверенные
reverse proxy, выгрузка и восстановление конфигурации LLM-чата (ZIP без секретов)."""

from __future__ import annotations

import ipaddress
import json

from fastapi.responses import Response

from ....backup_config import ConfigBackupException, ConfigBackupService, export_filename
from ....chat.settings import php_int
from ....settings import THEME_IDS
from ....security import DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS, MAX_ADMIN_IDLE_TIMEOUT_SECONDS
from ..base import FORM_STALE, AdminPage

MAX_ARCHIVE_BYTES = 32 * 1024 * 1024


def _valid_ip(value: str) -> bool:
    try:
        ipaddress.ip_address(value)
        return True
    except ValueError:
        return False


def handle(page: AdminPage) -> str | Response:
    m = page.module
    warnings: list[str] = []
    max_minutes = MAX_ADMIN_IDLE_TIMEOUT_SECONDS // 60

    # выгрузка — GET с токеном CSRF в адресе (ссылка «Скачать архив конфигурации»)
    if page.method == "GET" and page.action == "export_llm_config":
        if not page.csrf_ok():
            return Response(FORM_STALE, status_code=403, media_type="text/plain; charset=utf-8")
        content = ConfigBackupService.from_module(m).export_zip()
        m.audit.log(page.admin, page.ip, "llm_config_backup_export", True)
        return Response(content, media_type="application/zip",
                        headers={"Content-Disposition": f'attachment; filename="{export_filename()}"'})

    if page.is_post("import_llm_config"):
        upload = page.file("archive")
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif upload is None:
            page.flash_error = "Выберите файл архива резервной копии (.zip)."
        else:
            content = upload.file.read(MAX_ARCHIVE_BYTES + 1)
            if not content or len(content) > MAX_ARCHIVE_BYTES:
                page.flash_error = "Не удалось прочитать загруженный файл."
            else:
                try:
                    st = ConfigBackupService.from_module(m).import_zip(content, page.admin)
                    m.audit.log(page.admin, page.ip, "llm_config_backup_import", True, json.dumps(st, ensure_ascii=False, separators=(",", ":")))
                    page.flash_success = (
                        f"Восстановлено: доменов создано {st['domains_created']}, обновлено {st['domains_updated']}; "
                        f"групп создано {st['groups_created']}, обновлено {st['groups_updated']}; "
                        f"нейросетей создано {st['providers_created']}, обновлено {st['providers_updated']}; "
                        f"связей прав доступа — {st['access_links']}. "
                        "API-ключи и пароли служебных учёток нужно ввести заново в соответствующих разделах."
                    )
                    warnings = st["warnings"]
                except ConfigBackupException as exc:
                    m.audit.log(page.admin, page.ip, "llm_config_backup_import", False, str(exc))
                    page.flash_error = str(exc)

    if page.is_post("update_admin_idle_timeout"):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        else:
            minutes = php_int(page.post("admin_idle_timeout_minutes", "0"))
            if minutes < 5 or minutes > max_minutes:
                page.flash_error = f"Таймаут — от 5 до {max_minutes} минут."
            else:
                m.app_settings.set("admin_idle_timeout_minutes", str(minutes))
                m.audit.log(page.admin, page.ip, "admin_idle_timeout_update", True, f"minutes={minutes}")
                page.flash_success = "Таймаут бездействия администратора обновлён."

    if page.is_post("update_site_theme"):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        else:
            theme = page.post("site_theme")
            if theme not in THEME_IDS:
                page.flash_error = "Неизвестная тема оформления."
            else:
                m.app_settings.set("site_theme", theme)
                m.audit.log(page.admin, page.ip, "site_theme_update", True, f"theme={theme}")
                page.flash_success = "Тема по умолчанию обновлена."

    if page.is_post("update_trusted_proxies"):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        else:
            entries = [e.strip() for e in page.post("trusted_proxies").strip().split(",")]
            entries = [e for e in entries if e not in ("", "0")]   # array_filter PHP отбрасывает и «0»
            bad = next((e for e in entries if not _valid_ip(e.split("/", 1)[0])), None)
            if bad is not None:
                page.flash_error = "Некорректный адрес или подсеть: " + bad
            else:
                m.app_settings.set_trusted_proxies(entries)
                m.audit.log(page.admin, page.ip, "trusted_proxies_update", True, f"count={len(entries)}")
                page.flash_success = "Список доверенных reverse proxy обновлён."

    page.page_title = "Настройки"
    return page.render(
        "admin/sections/system.j2", trusted_proxies=m.app_settings.trusted_proxies(), max_minutes=max_minutes, warnings=warnings,
        site_theme_value=m.app_settings.site_theme(),
        idle_minutes=php_int(m.app_settings.get("admin_idle_timeout_minutes", str(DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS // 60))),
    )
