"""Раздел «Состояние» (перенос public/admin/sections/status.php): активная конфигурация, связь с AD, сертификаты,
модули, права на каталоги, последние ошибки диагностики."""

from __future__ import annotations

import calendar
import os
import time

from .... import paths
from ....certs import BUNDLE_FILENAME
from ....ldap.status import AdLinkStatusChecker
from ..base import AdminPage


def cert_expiry(valid_to: str) -> int | None:
    """strtotime(valid_to) для формата certs.format_timestamp: «2027-01-31 12:00:00 UTC»."""
    try:
        return calendar.timegm(time.strptime(str(valid_to).replace(" UTC", "").strip(), "%Y-%m-%d %H:%M:%S"))
    except ValueError:
        return None


def ymd(ts: int) -> str:
    return time.strftime("%Y-%m-%d", time.gmtime(ts))


def handle(page: AdminPage) -> str:
    m = page.module
    active = m.ldap_configs.find_active()
    certs = m.certificates.all()
    expiries = [e for e in (cert_expiry(c["valid_to"]) for c in certs) if e is not None]
    nearest = min(expiries) if expiries else None
    expiry_soon = nearest is not None and nearest < time.time() + 30 * 86400

    try:
        m.ca_bundle_path()
        bundle_ok = (paths.certs() / BUNDLE_FILENAME).is_file()
    except Exception:  # noqa: BLE001
        bundle_ok = False

    writable = {label: os.access(d, os.W_OK) for label, d in (("База данных", paths.data()), ("Сертификаты", paths.certs()), ("Журналы", paths.logs()))}

    # живая проверка связи с AD — по явному открытию страницы администратором, без кэша
    link = None
    if active is not None:
        try:
            link = AdLinkStatusChecker(m.ca_bundle).is_reachable(active)
        except Exception:  # noqa: BLE001
            link = False

    errors = m.diagnostics.query({"level": "error", "limit": 5})["rows"]
    overall = active is not None and bundle_ok and link is True and all(writable.values())
    page.page_title = "Состояние"
    return page.render("admin/sections/status.j2", first_setup="first_setup" in page.request.query_params, active=active,
                       certs=certs, nearest=ymd(nearest) if nearest is not None else None, expiry_soon=expiry_soon,
                       bundle_ok=bundle_ok, writable=writable, link=link, errors=errors, overall=overall)
