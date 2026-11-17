"""Раздел «Аудит и DLP»: что ушло во внешние нейросети (llm_outbound) — лента с текстом сообщений, поиском и подсветкой,
правила DLP с разбором срабатываний, сводка за период, диалоги пользователя (только чтение), выгрузка CSV.

Каждый просмотр чужих данных (страница ленты, запись, диалоги пользователя, переписка), каждая выгрузка, разбор записей и
правка правил пишутся в audit_log: видно, кто из администраторов и чьи данные смотрел."""

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

from ....chat import dlp
from ....chat.conversations import iso_days_ago
from ....chat.settings import ChatSettings, php_int
from ....chat.telemetry import KINDS, REVIEWS, Telemetry
from ....db import now_iso
from ..base import FORM_STALE, AdminPage
from .log import tsv_field

PER_PAGE = (25, 50, 100, 200)
SNIPPET_CHARS = 420
FULL_TEXT_CHARS = 50000
EXPORT_LIMIT = 5000   # shortcut: выгрузка собирается в памяти, при больших объёмах — потоковая отдача
PERIODS = {"today": "Сегодня", "1": "Сутки", "7": "7 дней", "30": "30 дней", "custom": "Свой период"}
TABS = {"feed": "Лента", "overview": "Обзор", "rules": "Правила и хранение"}
KIND_LABELS = {"chat": "Сообщение", "summary": "Сводка диалога", "project_state": "Состояние проекта"}
REVIEW_FILTERS = {"todo": "Не разобраны", "user": "Отметки пользователей", **REVIEWS}
RESCAN_DAYS = {"7": "7 дней", "30": "30 дней", "0": "весь журнал"}
_DATE = re.compile(r"\d{4}-\d{2}-\d{2}")
# поля фильтра ленты → параметр адреса
_ARGS = (("period", "period"), ("from", "from"), ("to", "to"), ("user", "owner_key"), ("model", "model_name"), ("kind", "kind"),
         ("unanon", "unanonymized"), ("q", "q"), ("sev", "severity"), ("rule", "rule_id"), ("review", "review"), ("per", "per"))


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
    sev = php_int(q.get("sev", "0"))
    per = php_int(q.get("per", "50"))
    f.update({"owner_key": q.get("user", "").strip().lower(), "model_name": q.get("model", ""),
              "kind": q.get("kind", "") if q.get("kind") in KINDS else "", "unanonymized": q.get("unanon") == "1",
              "q": q.get("q", "").strip()[:200], "severity": sev if sev in dlp.SEVERITIES else 0,
              "rule_id": max(0, php_int(q.get("rule", "0"))), "review": q.get("review", "") if q.get("review") in REVIEW_FILTERS else "",
              "per": per if per in PER_PAGE else 50})
    return f


def _query_args(f: dict, **override) -> dict:
    """Параметры адреса для текущих фильтров (без пустых и значений по умолчанию)."""
    args = {name: f[key] for name, key in _ARGS}
    args["unanon"] = "1" if f["unanonymized"] else ""
    args.update(override)
    return {k: v for k, v in args.items() if v not in ("", None, 0, False) and not (k == "per" and v == 50)}


def _url(**args) -> Markup:
    return Markup("index.php?" + urlencode({"section": "telemetry", **{k: v for k, v in args.items() if v not in ("", None)}}))


def _csv_field(v) -> str:
    s = tsv_field(v)
    # формулы в ячейках (=, +, -, @) табличный редактор выполнил бы при открытии выгрузки
    return "'" + s if s[:1] in ("=", "+", "-", "@") else s


def _export(page: AdminPage, t: Telemetry, f: dict) -> Response:
    cols = ("id", "created_at", "owner_key", "conversation_id", "project_id", "provider_name", "model_name", "fallback_from", "kind",
            "unanonymized", "attachment_types", "attachment_count", "input_tokens", "output_tokens", "tokens", "duration_ms", "outcome",
            "dlp_severity", "dlp_rules", "review", "reviewed_by", "text")
    buf = io.StringIO()
    w = csv.writer(buf, lineterminator="\n")
    w.writerow(cols)
    n = 0
    for row in t.iter_export(f, EXPORT_LIMIT):
        w.writerow([_csv_field(row[c]) for c in cols])
        n += 1
    page.module.events.admin(page.admin, page.ip, "telemetry_export", True, {"rows": n, **_audit_filters(f)})
    filename = f"audit_{time.strftime('%Y%m%d_%H%M%S', time.gmtime())}.csv"
    return Response("﻿" + buf.getvalue(), media_type="text/csv; charset=utf-8",
                    headers={"Content-Disposition": f'attachment; filename="{filename}"'})


def _audit_filters(f: dict) -> dict:
    return {"since": f["since"], "until": f["until"], "user": f["owner_key"] or "все", "model": f["model_name"] or "все",
            "kind": f["kind"] or "все", "unanonymized_only": f["unanonymized"], "search": f["q"] or "—",
            "severity": f["severity"] or "все", "rule": f["rule_id"] or "все", "review": f["review"] or "все"}


# ---- правила DLP ----
def _rule_form(page: AdminPage) -> tuple[dict, dict]:
    data = {"name": page.post("name").strip(), "kind": page.post("kind"), "severity": php_int(page.post("severity", "2")),
            "pattern": page.post("pattern").replace("\r\n", "\n").strip(), "is_enabled": page.post("is_enabled") == "1"}
    errors = {}
    if not 1 <= len(data["name"]) <= 100:
        errors["name"] = "Укажите название (до 100 символов)."
    if data["kind"] not in dlp.KINDS:
        errors["kind"] = "Выберите вид правила."
    if data["severity"] not in dlp.SEVERITIES:
        errors["severity"] = "Выберите важность."
    if not data["pattern"]:
        errors["pattern"] = "Добавьте хотя бы одну строку."
    elif len(data["pattern"]) > dlp.MAX_PATTERN_CHARS:
        errors["pattern"] = f"Слишком длинно: не больше {dlp.MAX_PATTERN_CHARS} символов."
    elif data["kind"] == "regex":
        for n, line in enumerate(data["pattern"].splitlines(), 1):
            try:
                re.compile(line)
            except re.error as e:
                errors["pattern"] = f"Строка {n}: ошибка в регулярном выражении ({e.msg})."
                break
    return data, errors


def _rules_post(page: AdminPage, t: Telemetry) -> dict:
    """Действия вкладки правил; возвращает контекст для шаблона (форма с ошибками, результат проверки)."""
    conn, ev = page.module.conn, page.module.events
    rule_id = php_int(page.post("rule_id", "0"))
    row = conn.execute("SELECT * FROM dlp_rules WHERE id = ?", (rule_id,)).fetchone() if rule_id else None
    if page.action == "rule_save":
        data, errors = _rule_form(page)
        if errors:
            page.flash_error = "Правило не сохранено: проверьте выделенные поля."
            return {"edit": {**data, "id": rule_id or None, "builtin_key": row["builtin_key"] if row else None}, "errors": errors}
        now = now_iso()
        if row is not None:
            conn.execute("UPDATE dlp_rules SET name = ?, kind = ?, pattern = ?, severity = ?, is_enabled = ?, updated_at = ? WHERE id = ?",
                         (data["name"], data["kind"], data["pattern"], data["severity"], int(data["is_enabled"]), now, rule_id))
            action = "dlp_rule_update"
        else:
            rule_id = int(conn.execute(
                "INSERT INTO dlp_rules (name, kind, pattern, severity, is_enabled, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1000, ?, ?)",
                (data["name"], data["kind"], data["pattern"], data["severity"], int(data["is_enabled"]), now, now)).lastrowid)
            action = "dlp_rule_create"
        queued = t.rule_changed(rule_id, data["is_enabled"])
        ev.admin(page.admin, page.ip, action, True, {"rule_id": rule_id, "name": data["name"], "severity": data["severity"],
                                                      "enabled": data["is_enabled"]})
        page.flash_success = f"Правило «{data['name']}» сохранено." + (f" Журнал за 7 дней перепроверяется: {queued} записей." if queued else "")
    elif page.action == "rule_toggle" and row is not None:
        enabled = not row["is_enabled"]
        conn.execute("UPDATE dlp_rules SET is_enabled = ?, updated_at = ? WHERE id = ?", (int(enabled), now_iso(), rule_id))
        queued = t.rule_changed(rule_id, enabled)
        ev.admin(page.admin, page.ip, "dlp_rule_enable" if enabled else "dlp_rule_disable", True, {"rule_id": rule_id, "name": row["name"]})
        page.flash_success = (f"Правило «{row['name']}» включено." + (f" Журнал за 7 дней перепроверяется: {queued} записей." if queued else "")
                              if enabled else f"Правило «{row['name']}» выключено, его срабатывания сняты.")
    elif page.action == "rule_delete" and row is not None:
        if row["builtin_key"]:
            page.flash_error = "Встроенный шаблон удалить нельзя — его можно выключить."
        else:
            t.rule_changed(rule_id, False)
            conn.execute("DELETE FROM dlp_rules WHERE id = ?", (rule_id,))
            ev.admin(page.admin, page.ip, "dlp_rule_delete", True, {"rule_id": rule_id, "name": row["name"]})
            page.flash_success = f"Правило «{row['name']}» удалено."
    elif page.action == "rescan":
        days = page.post("days")
        if days in RESCAN_DAYS:
            n = t.rescan_start(int(days))
            ev.admin(page.admin, page.ip, "dlp_rescan", True, {"days": days, "records": n})
            page.flash_success = f"Перепроверка поставлена: {n} записей. Идёт в фоне, прогресс — ниже." if n else "За этот период записей нет."
    elif page.action == "rule_test":
        sample = page.post("sample")[:FULL_TEXT_CHARS]
        rules = dlp.load_rules(conn)
        found = dlp.scan(rules, sample)
        marked, _ = dlp.highlight(sample, [r for r, _ in found], None)
        return {"test": {"sample": sample, "found": found, "marked": marked}}
    elif page.action == "save_retention":
        value = str(php_int(page.post("telemetry_retention_days", "365")))
        errors = ChatSettings.validate({"telemetry_retention_days": value})
        if errors:
            page.flash_error = "Срок не сохранён: " + " ".join(errors.values())
        else:
            page.module.chat_settings.save({"telemetry_retention_days": value})
            ev.admin(page.admin, page.ip, "telemetry_retention_change", True, {"days": value})
            page.flash_success = "Срок хранения сохранён."
    return {}


def _review_post(page: AdminPage, t: Telemetry) -> None:
    one = page.post("one")
    if ":" in one:   # кнопка у одной записи: «id:статус»
        rid, status = one.split(":", 1)
        ids = [php_int(rid)]
    else:
        ids, status = [php_int(i) for i in page.post_list("ids")], page.post("status")
    ids = [i for i in ids if i > 0][:500]
    if status not in (*REVIEWS, "") or not ids:
        page.flash_error = "Отметьте записи галочками." if not ids else "Неизвестный статус."
        return
    n = t.review(ids, status, page.admin)
    page.module.events.admin(page.admin, page.ip, "telemetry_review", True, {"status": status or "снят", "records": ",".join(map(str, ids))})
    label = REVIEWS.get(status, "без отметки")
    page.flash_success = f"Отмечено «{label}»: {n}." if n != 1 else f"Запись №{ids[0]} — «{label}»."


def handle(page: AdminPage) -> str | Response:
    page.page_title = "Аудит и DLP"
    m = page.module
    t = Telemetry(m.conn)
    q = page.request.query_params
    f = _filters(q)
    view = q.get("view", "")
    tab = q.get("tab", "feed") if q.get("tab") in TABS else "feed"

    if page.action == "export":
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        else:
            return _export(page, t, f)

    extra: dict = {}
    if page.is_post():
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif page.action == "review":
            _review_post(page, t)
        elif page.action in ("rule_save", "rule_toggle", "rule_delete", "rescan", "rule_test", "save_retention"):
            tab = "rules"
            extra = _rules_post(page, t)

    ctx = {"view": view, "tab": tab, "tabs": TABS, "f": f, "url": _url, "kind_labels": KIND_LABELS, "severities": dlp.SEVERITIES,
           "reviews": REVIEWS}
    if view == "record":
        record = t.get(php_int(q.get("id", "0")))
        marked = None
        if record is not None:
            m.events.admin(page.admin, page.ip, "telemetry_record_view", True, {"record_id": record["id"], "user": record["owner_key"]})
            marked, _ = dlp.highlight(record["text"], dlp.load_rules(m.conn), dlp.terms_regex(f["q"]))
        return page.render("admin/sections/telemetry.j2", record=record, marked=marked, **ctx)
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

    if tab == "rules":
        rules = [dict(r) for r in m.conn.execute("SELECT * FROM dlp_rules ORDER BY position, id")]
        edit = extra.get("edit")
        if edit is None and q.get("edit"):
            row = next((r for r in rules if str(r["id"]) == q.get("edit")), None)
            edit = row or ({"id": None, "name": "", "kind": "words", "severity": 2, "pattern": "", "is_enabled": 1, "builtin_key": None}
                           if q.get("edit") == "new" else None)
        return page.render("admin/sections/telemetry.j2", rules=rules, hits=t.rule_hits(30), edit=edit, errors=extra.get("errors", {}),
                           test=extra.get("test"), rule_kinds=dlp.KINDS, rescan_days=RESCAN_DAYS, rescan_left=t.rescan_remaining(),
                           retention=m.chat_settings.get("telemetry_retention_days"), **ctx)

    if tab == "overview":
        return page.render("admin/sections/telemetry.j2", overview=t.overview(f["since"], f["until"]), periods=PERIODS, args=_query_args(f), **ctx)

    per = f["per"]
    num = max(1, php_int(q.get("page", "1")))
    result = t.query(f, per, (num - 1) * per)
    total_pages = max(1, math.ceil(result["total"] / per))
    rules = dlp.load_rules(m.conn)
    terms = dlp.terms_regex(f["q"])
    for r in result["rows"]:
        text = r["text"]
        r["snippet"], r["cut"] = dlp.highlight(text, rules, terms, limit=SNIPPET_CHARS, around_first=True)
        if r["cut"]:
            r["full"], r["full_cut"] = dlp.highlight(text, rules, terms, limit=FULL_TEXT_CHARS)
    if result["rows"]:
        m.events.admin(page.admin, page.ip, "telemetry_feed_view", True, {"page": num, "shown": len(result["rows"]), **_audit_filters(f)})
    args = _query_args(f)
    base = {k: v for k, v in args.items() if k not in ("sev", "review", "unanon", "rule")}
    tot = t.counts(f)
    presets = [("Все", base, tot["requests"], ""), ("Срабатывания DLP", {**base, "sev": 1}, tot["flagged"], ""),
               ("Критичные", {**base, "sev": 3}, tot["critical"], "bad"), ("Не разобраны", {**base, "review": "todo"}, tot["todo"], "warn"),
               ("Инциденты", {**base, "review": "incident"}, tot["incidents"], "bad"),
               ("Без обезличивания", {**base, "unanon": "1"}, tot["unanonymized"], "warn"),
               ("Отметки пользователей", {**base, "review": "user"}, tot["user_flags"], "warn")]
    all_rules = [dict(r) for r in m.conn.execute("SELECT id, name, severity FROM dlp_rules ORDER BY position, id")]
    more_open = any(args.get(k) for k in ("user", "model", "kind", "rule", "per")) or f["period"] == "custom"
    return page.render(
        "admin/sections/telemetry.j2", result=result, num=num, total_pages=total_pages, periods=PERIODS, models=t.model_names(),
        all_rules=all_rules, review_filters=REVIEW_FILTERS, per_page=PER_PAGE, presets=presets, args=args, more_open=more_open,
        export_url=_url(action="export", csrf=page.csrf, **args), review_url=_url(action="review", **args, page=num if num > 1 else ""),
        prev_url=_url(page=max(1, num - 1), **args), next_url=_url(page=min(total_pages, num + 1), **args), **ctx)
