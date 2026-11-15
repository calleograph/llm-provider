"""Раздел «Журналы» (перенос public/admin/sections/log.php): сводная лента, операции пользователей, аудит
администраторов, диагностика; поиск, сортировка, страницы, выгрузка .txt (поля через табуляцию)."""

from __future__ import annotations

import math
import re
import time
from html import escape
from urllib.parse import quote_plus, urlencode

from fastapi.responses import Response
from markupsafe import Markup

from ....chat.settings import php_int
from ....logs import LEVELS, SOURCES, AppLogger, UnifiedEventLog
from ..base import FORM_STALE, AdminPage

PER_PAGE = 50
TABS = ("all", "operations", "audit", "diagnostics")
CATEGORY_LABELS = {
    "password_change": "Прочие операции", "llm_auth": "Вход в чат", "llm_chat": "Переписка и лимиты",
    "llm_anonymization": "Обезличивание", "llm_file": "Файлы", "admin_action": "Админ. действие", "diagnostic": "Диагностика",
}
CATEGORY_FILTER_OPTIONS = {
    "password_change": "Прочие операции", "llm_auth": "Нейросети — вход в чат", "llm_chat": "Нейросети — переписка и лимиты",
    "llm_anonymization": "Нейросети — обезличивание", "llm_file": "Нейросети — файлы",
    "admin_action": "Административные действия", "diagnostic": "Диагностика",
}
_LINE_BREAKERS = re.compile(r"[\t\r\n]")


def tsv_field(v) -> str:
    """Табуляция и переводы строк в любом поле подделали бы колонки или целые строки выгрузки."""
    return _LINE_BREAKERS.sub(" ", "" if v is None else str(v))


def tsv_line(*fields) -> str:
    return "\t".join(tsv_field(f) for f in fields) + "\n"


def category_label(c: str) -> str:
    return CATEGORY_LABELS.get(c, c)


def diagnostic_filters(q) -> dict[str, str]:
    return {k: q[k] for k in ("level", "source", "search", "since", "until") if q.get(k) not in (None, "", "0")}


def outcome_icon(outcome: str) -> Markup:
    return Markup({"success": '<span class="status-dot status-ok" role="img" aria-label="Успех" title="Успех"></span>',
                   "warning": '<span class="status-dot status-warn" role="img" aria-label="Требует внимания" title="Требует внимания"></span>'}.get(
        outcome, '<span class="status-dot status-bad" role="img" aria-label="Ошибка" title="Ошибка"></span>'))


def _sort_link(column: str, label: str, sort: str, direction: str, tab: str, extra: dict | None = None) -> Markup:
    nxt = "desc" if sort == column and direction == "asc" else "asc"
    arrow = (" ↑" if direction == "asc" else " ↓") if sort == column else ""
    query = {**(extra or {}), "section": "log", "tab": tab, "sort": column, "dir": nxt}
    return Markup(f'<a href="index.php?{urlencode(query)}" class="sort-link">{escape(label)}{arrow}</a>')


def _export(page: AdminPage, tab: str, search: str) -> Response:
    m = page.module
    export_tab = page.query("export_tab", tab)
    if export_tab not in TABS:
        export_tab = "all"
    if export_tab == "audit":
        lines = [tsv_line(r["created_at"], r["actor"], r["ip"], r["action"], r["details"], "success" if int(r["success"]) == 1 else "failure")
                 for r in m.audit.recent(5000)]
    elif export_tab == "diagnostics":
        rows = m.diagnostics.query(diagnostic_filters(page.request.query_params) | {"limit": 5000})["rows"]
        lines = [tsv_line(r["created_at"], r["level"], r["source"], r["event_code"], r["message"], r["context"], r["ip"]) for r in rows]
    elif export_tab == "operations":
        lines = [tsv_line(r["created_at"], r["ip"], r["username"], r["operation"], r["result"]) for r in AppLogger(m.conn).recent(5000)]
    else:
        rows = UnifiedEventLog(m.conn).query({"search": search or None, "limit": 5000})["rows"]
        lines = [tsv_line(r["created_at"], category_label(r["category"]), r["outcome"], r["actor"], r["ip"], r["event_type"],
                          f"{r['summary'] or ''} {r['details'] or ''}") for r in rows]
    filename = f"log_{export_tab}_{time.strftime('%Y%m%d_%H%M%S', time.gmtime())}.txt"
    return Response("".join(lines), media_type="text/plain; charset=utf-8",
                    headers={"Content-Disposition": f'attachment; filename="{filename}"'})


def handle(page: AdminPage) -> str | Response:
    m = page.module
    q = page.request.query_params
    tab = q.get("tab", "all")
    if tab not in TABS:
        tab = "all"
    num = max(1, php_int(q.get("page", "1")))
    sort = q.get("sort", "created_at")
    direction = "asc" if q.get("dir", "desc").lower() == "asc" else "desc"
    search = q.get("search", "").strip()

    if page.action == "export":
        # выгрузка журнала — с токеном формы, как выгрузка телеметрии и конфигурации: чужая страница не скачает журнал от имени админа
        if not page.csrf_ok():
            return Response(FORM_STALE, status_code=403, media_type="text/plain; charset=utf-8")
        return _export(page, tab, search)

    diag = diagnostic_filters(q)
    if tab == "all":
        result = UnifiedEventLog(m.conn).query({"search": search or None, "category": q.get("category"), "limit": PER_PAGE,
                                                "offset": (num - 1) * PER_PAGE})
    elif tab == "operations":
        result = AppLogger(m.conn).paginated(num, PER_PAGE, sort, direction)
    elif tab == "audit":
        result = m.audit.paginated(num, PER_PAGE, sort, direction)
    else:
        result = m.diagnostics.query(diag | {"limit": PER_PAGE, "offset": (num - 1) * PER_PAGE, "sort": sort, "dir": direction})
    total_pages = max(1, math.ceil(result["total"] / PER_PAGE))

    export_url = f"index.php?section=log&action=export&csrf={page.csrf}&export_tab={escape(tab)}&search={quote_plus(search)}" + "".join(
        f"&{quote_plus(k)}={quote_plus(v)}" for k, v in diag.items())
    base = {**diag, "section": "log", "tab": tab, "sort": sort, "dir": direction, "search": search}
    page.page_title = "Журналы"
    return page.render(
        "admin/sections/log.j2", tab=tab, num=num, total_pages=total_pages, result=result, search=search, diag=diag,
        category=q.get("category", ""), category_options=CATEGORY_FILTER_OPTIONS, levels=LEVELS, sources=SOURCES,
        export_url=Markup(export_url), outcome_icon=outcome_icon, category_label=category_label,
        sort_link=lambda col, label, extra=None: _sort_link(col, label, sort, direction, tab, extra),
        prev_url=Markup("index.php?" + urlencode(base | {"page": max(1, num - 1)})),
        next_url=Markup("index.php?" + urlencode(base | {"page": min(total_pages, num + 1)})),
    )
