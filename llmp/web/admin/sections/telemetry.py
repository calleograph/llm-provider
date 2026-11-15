"""Раздел «Телеметрия»: что ушло во внешние нейросети (llm_outbound) и как используется чат — сводка за период, журнал
запросов с фильтрами и выгрузкой CSV, диалоги пользователя и их переписка (только чтение).

Каждый просмотр чужих данных (запись журнала, диалоги пользователя, переписка) и каждая выгрузка пишутся в audit_log:
видно, кто из администраторов и чьи данные смотрел."""

from __future__ import annotations

import csv
import io
import math
import re
import time
from datetime import date, timedelta
from urllib.parse import urlencode

from fastapi.responses import Response
from markupsafe import Markup

from ....chat.conversations import iso_days_ago
from ....chat.settings import ChatSettings, php_int
from ....chat.telemetry import KINDS, Telemetry
from ..base import FORM_STALE, AdminPage
from .log import tsv_field

PER_PAGE = 50
EXPORT_LIMIT = 5000   # shortcut: выгрузка собирается в памяти, при больших объёмах — потоковая отдача
PERIODS = {"today": "Сегодня", "7": "7 дней", "30": "30 дней", "custom": "Свой период"}
KIND_LABELS = {"chat": "Сообщение", "summary": "Сводка диалога", "project_state": "Состояние проекта"}
_DATE = re.compile(r"\d{4}-\d{2}-\d{2}")


def _period(q) -> dict:
    """Период фильтра: since/until — границы в формате created_at (ISO UTC)."""
    period = q.get("period", "7")
    if period not in PERIODS:
        period = "7"
    date_from, date_to = q.get("from", ""), q.get("to", "")
    until = "9999"
    if period == "today":
        since = time.strftime("%Y-%m-%dT00:00:00+00:00", time.gmtime())
    elif period == "custom" and _DATE.fullmatch(date_from or "") and (not date_to or _DATE.fullmatch(date_to)):
        try:
            since = f"{date.fromisoformat(date_from)}T00:00:00+00:00"
            if date_to:
                until = f"{date.fromisoformat(date_to) + timedelta(days=1)}T00:00:00+00:00"
        except ValueError:
            period, since = "7", iso_days_ago(7)
    else:
        period = "7" if period == "custom" else period
        since = iso_days_ago(int(period))
    return {"period": period, "from": date_from, "to": date_to, "since": since, "until": until}


def _filters(q) -> dict:
    f = _period(q)
    f.update({"owner_key": q.get("user", "").strip().lower(), "model_name": q.get("model", ""),
              "kind": q.get("kind", "") if q.get("kind") in KINDS else "", "unanonymized": q.get("unanon") == "1",
              "q": q.get("q", "").strip()[:200]})
    return f


def _query_args(f: dict) -> dict:
    """Параметры адреса для текущих фильтров (без пустых)."""
    args = {"period": f["period"], "from": f["from"], "to": f["to"], "user": f["owner_key"], "model": f["model_name"],
            "kind": f["kind"], "unanon": "1" if f["unanonymized"] else "", "q": f["q"]}
    return {k: v for k, v in args.items() if v}


def _url(**args) -> Markup:
    return Markup("index.php?" + urlencode({"section": "telemetry", **{k: v for k, v in args.items() if v not in ("", None)}}))


def _csv_field(v) -> str:
    s = tsv_field(v)
    # формулы в ячейках (=, +, -, @) табличный редактор выполнил бы при открытии выгрузки
    return "'" + s if s[:1] in ("=", "+", "-", "@") else s


def _export(page: AdminPage, t: Telemetry, f: dict) -> Response:
    cols = ("id", "created_at", "owner_key", "conversation_id", "project_id", "provider_name", "model_name", "fallback_from", "kind",
            "unanonymized", "attachment_types", "attachment_count", "input_tokens", "output_tokens", "tokens", "duration_ms", "outcome", "text")
    buf = io.StringIO()
    w = csv.writer(buf, lineterminator="\n")
    w.writerow(cols)
    n = 0
    for row in t.iter_export(f, EXPORT_LIMIT):
        w.writerow([_csv_field(row[c]) for c in cols])
        n += 1
    page.module.events.admin(page.admin, page.ip, "telemetry_export", True, {
        "rows": n, "since": f["since"], "until": f["until"], "user": f["owner_key"] or "все", "model": f["model_name"] or "все",
        "kind": f["kind"] or "все", "unanonymized_only": f["unanonymized"], "search": f["q"] or "—"})
    filename = f"telemetry_{time.strftime('%Y%m%d_%H%M%S', time.gmtime())}.csv"
    return Response("﻿" + buf.getvalue(), media_type="text/csv; charset=utf-8",
                    headers={"Content-Disposition": f'attachment; filename="{filename}"'})


def handle(page: AdminPage) -> str | Response:
    page.page_title = "Телеметрия"
    m = page.module
    t = Telemetry(m.conn)
    q = page.request.query_params
    f = _filters(q)
    view = q.get("view", "")

    if page.action == "export":
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        else:
            return _export(page, t, f)

    if page.is_post("save_retention"):
        value = str(php_int(page.post("telemetry_retention_days", "365")))
        errors = ChatSettings.validate({"telemetry_retention_days": value})
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif errors:
            page.flash_error = "Срок не сохранён: " + " ".join(errors.values())
        else:
            m.chat_settings.save({"telemetry_retention_days": value})
            m.events.admin(page.admin, page.ip, "telemetry_retention_change", True, {"days": value})
            page.flash_success = "Срок хранения телеметрии сохранён."

    ctx = {"view": view, "f": f, "url": _url, "kind_labels": KIND_LABELS}
    if view == "record":
        record = t.get(php_int(q.get("id", "0")))
        if record is not None:
            m.events.admin(page.admin, page.ip, "telemetry_record_view", True, {"record_id": record["id"], "user": record["owner_key"]})
        return page.render("admin/sections/telemetry.j2", record=record, **ctx)
    if view == "user":
        owner = q.get("user", "").strip().lower()
        m.events.admin(page.admin, page.ip, "telemetry_user_view", True, {"user": owner})
        return page.render("admin/sections/telemetry.j2", owner=owner, conversations=t.user_conversations(owner), **ctx)
    if view == "conversation":
        conv = t.conversation(php_int(q.get("id", "0")))
        messages = []
        if conv is not None:
            m.events.admin(page.admin, page.ip, "telemetry_conversation_view", True, {"conversation_id": conv["id"], "user": conv["owner_key"]})
            messages = m.conversations.messages(conv["id"], conv["owner_key"])
        return page.render("admin/sections/telemetry.j2", conv=conv, messages=messages, **ctx)

    num = max(1, php_int(q.get("page", "1")))
    result = t.query(f, PER_PAGE, (num - 1) * PER_PAGE)
    total_pages = max(1, math.ceil(result["total"] / PER_PAGE))
    args = _query_args(f)
    return page.render(
        "admin/sections/telemetry.j2", overview=t.overview(f["since"], f["until"]), result=result, num=num, total_pages=total_pages,
        periods=PERIODS, models=t.model_names(), retention=m.chat_settings.get("telemetry_retention_days"),
        export_url=_url(action="export", csrf=page.csrf, **args),
        prev_url=_url(page=max(1, num - 1), **args), next_url=_url(page=min(total_pages, num + 1), **args), **ctx)
