"""Раздел «Состояние сервисов» (перенос public/admin/sections/services.php): LDAPS доменных подключений (живая
проверка при открытии), сертификаты, обезличивание, нейросети, локальная обработка файлов. Проверка внешних
API — только по кнопке: каждая проверка нейросети — реальный синтетический запрос."""

from __future__ import annotations

import os
import time

from .... import paths
from ....certs import BUNDLE_FILENAME
from ....files.base import ProcessRunner
from ....ldap.status import AdLinkStatusChecker
from ..base import FORM_STALE, AdminPage
from .status import cert_expiry, ymd


def _last_check(at, ok) -> dict:
    if at in (None, ""):
        return {"class": "status-warn", "label": "Не проверялся"}
    return {"class": "status-ok", "label": "Доступен"} if str(ok) == "1" else {"class": "status-bad", "label": "Ошибка"}


def _fmt(at) -> str:
    return str(at)[:16].replace("T", " ") + " UTC" if at else "—"


def handle(page: AdminPage) -> str:
    m = page.module
    if page.is_post("check_external"):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        else:
            ok_count = total = 0
            if m.anonymization_settings.is_configured():
                total += 1
                ok_count += 1 if m.anonymizer.test_connection()["ok"] else 0
            for p in m.providers.all():
                if not p["is_enabled"]:
                    continue
                total += 1
                ok_count += 1 if m.gateway.test_connection(int(p["id"]))["ok"] else 0
            m.events.admin(page.admin, page.ip, "services_check", ok_count == total, {"checked": total, "ok": ok_count})
            page.flash_success = f"Проверено сервисов: {total}, доступно: {ok_count}."

    checker = AdLinkStatusChecker(m.ca_bundle)
    domain_rows = []
    for d in m.domains.all():
        if not d.llm_enabled and not d.config.is_active:
            continue
        try:
            reachable = checker.is_reachable(d.config)
        except Exception:  # noqa: BLE001
            reachable = False
        domain_rows.append({"domain": d, "reachable": reachable, "groups": len(m.groups.for_domain(d.id(), True))})

    certs = m.certificates.all()
    now = time.time()
    expiries = [e for e in (cert_expiry(c["valid_to"]) for c in certs) if e is not None]
    nearest = min(expiries) if expiries else None
    anon = m.anonymization_settings
    fs = m.file_settings
    tmp = paths.llm_tmp()
    tools = {label: ProcessRunner.is_executable(fs.get(key)) for label, key in
             (("pdftotext", "pdftotext_path"), ("pdftoppm", "pdftoppm_path"), ("tesseract (OCR)", "tesseract_path"))}
    page.page_title = "Состояние сервисов"
    return page.render(
        "admin/sections/services.j2", domain_rows=domain_rows, certs=certs, expired=sum(1 for e in expiries if e < now),
        nearest=ymd(nearest) if nearest is not None else None, expiry_soon=nearest is not None and nearest < now + 30 * 86400,
        bundle_ok=(paths.certs() / BUNDLE_FILENAME).is_file(), anon=anon, anon_error=m.anonymizer.ready_error(),
        providers=m.providers.all(), last_check=_last_check, fmt=_fmt, tools=tools,
        tmp_ok=tmp.is_dir() and os.access(tmp, os.W_OK),
    )
