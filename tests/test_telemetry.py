"""Телеметрия: журнал запросов к внешним LLM (llm_outbound), раздел админки «Телеметрия», аудит просмотров, срок хранения."""

from __future__ import annotations

import csv
import io
import re
from pathlib import Path
from urllib.parse import quote

import pytest
from fastapi.testclient import TestClient

import kit_admin
from kit_anon import use_service
from llmp import compact, db, paths, scheduler
from llmp.chat import ChatException
from llmp.chat.core import ChatUser
from llmp.chat.telemetry import Telemetry
from llmp.web.admin.sections import telemetry as section
from llmp.web.app import create_app
from test_admin_shell import login, setup_admin
from test_chat_service import ANON, RAW, Env


@pytest.fixture
def env(monkeypatch):
    use_service(monkeypatch)
    return Env()


def outbound(conn) -> list[dict]:
    rows = [dict(r) for r in conn.execute("SELECT *, CAST(text_z AS BLOB) AS blob FROM llm_outbound ORDER BY id")]
    for r in rows:
        r["text"] = compact.unpack(r.pop("blob"))
    return rows


def test_successful_send_is_recorded_with_anonymized_text_and_tokens(env):
    env.answer = lambda body: {"model": "openai/gpt-4o", "choices": [{"message": {"content": "Ок"}, "finish_reason": "stop"}],
                               "usage": {"prompt_tokens": 70, "completion_tokens": 30}}
    result = env.send()
    [row] = outbound(env.conn)
    assert row["text"] == ANON and "Иванов" not in row["text"]
    assert (row["kind"], row["outcome"], row["unanonymized"]) == ("chat", "ok", 0)
    assert (row["input_tokens"], row["output_tokens"], row["tokens"]) == (70, 30, 100)
    assert row["owner_key"] == "ivanov@corp.local" and row["username"] == "ivanov" and row["domain"] == "corp.local"
    assert row["provider_id"] == env.provider_id and row["provider_name"] == "Polza GPT-4o" and row["model_name"]
    assert row["conversation_id"] == result["conversation"]["id"], "id нового диалога дописан после сохранения"


def test_failed_send_is_recorded_as_error_without_tokens(env):
    env.answer = lambda body: {"choices": [{"message": {"content": ""}, "finish_reason": "stop"}]}
    with pytest.raises(ChatException):
        env.send()
    [row] = outbound(env.conn)
    assert row["outcome"].startswith("error:") and row["tokens"] == 0 and row["input_tokens"] is None
    assert row["text"] == ANON and row["conversation_id"] is None


def test_unanonymized_send_is_flagged_with_text_as_sent(env):
    env.groups.set_allow_unanonymized(env.group_id, True)
    env.send(skip=True)
    [row] = outbound(env.conn)
    assert row["unanonymized"] == 1 and row["text"] == RAW


def test_record_survives_conversation_deletion(env):
    result = env.send()
    env.module.conversations.delete(result["conversation"]["id"], env.user.owner_key())
    assert env.conn.execute("SELECT COUNT(*) FROM llm_conversations").fetchone()[0] == 0
    assert len(outbound(env.conn)) == 1


def test_telemetry_write_failure_does_not_break_send(env):
    env.conn.execute("DROP TABLE llm_outbound")
    result = env.send()
    assert result["assistant_message"]["content"]
    assert env.conn.execute("SELECT COUNT(*) FROM diagnostic_log WHERE event_code = 'TELEMETRY_WRITE_FAILED'").fetchone()[0] == 1


def test_summary_step_records_kind_and_tokens_without_text(env):
    model = env.module.models.allowed_for({env.provider_id: {}})
    model = next(iter(model.values()))
    chat = env.chat

    def summarise():
        chat.summaries.spent_tokens += 42
        return True

    assert chat._outbound_step(env.user, {"conversation_id": 7, "project_id": None}, "summary", model, summarise) is True
    assert chat._outbound_step(env.user, {"conversation_id": 7, "project_id": None}, "summary", model, lambda: False) is False
    [row] = outbound(env.conn)
    assert (row["kind"], row["tokens"], row["text"], row["conversation_id"]) == ("summary", 42, "", 7), "без обращения к LLM записи нет"


def test_retention_purge_runs_hourly(env, var_dir):
    t = Telemetry(env.conn)
    env.send()
    env.conn.execute("UPDATE llm_outbound SET created_at = '2020-01-01T00:00:00+00:00'")
    env.send()
    assert t.purge(0) == 0, "0 — хранить бессрочно"
    assert t.purge(365) == 1 and len(outbound(env.conn)) == 1


def test_scheduler_purges_by_setting(var_dir):
    paths.ensure_directories()
    c = db.connect(migrate_schema=True)
    c.execute("INSERT INTO llm_outbound (created_at, owner_key, username, domain, kind, outcome) VALUES "
              "('2020-01-01T00:00:00+00:00', 'a@b', 'a', 'b', 'chat', 'ok')")
    scheduler.run_once(0, now=10_000)
    assert c.execute("SELECT COUNT(*) FROM llm_outbound").fetchone()[0] == 0
    c.close()


# ---- раздел админки ----
def seed(conn) -> None:
    rows = [("ivanov@corp.local", "GPT", "chat", "Договор с [PERSON_1]", 0, "ok", 100),
            ("petrov@corp.local", "Claude", "chat", "Секретный план без меток", 1, "ok", 50),
            ("petrov@corp.local", "Claude", "summary", None, 0, "error:timeout", 0)]
    for owner, model, kind, text, raw, outcome, tokens in rows:
        conn.execute(
            "INSERT INTO llm_outbound (created_at, owner_key, username, domain, model_name, kind, text_z, text_chars, unanonymized, outcome, tokens) "
            "VALUES (strftime('%Y-%m-%dT%H:%M:%S+00:00', 'now'), ?, ?, 'corp.local', ?, ?, ?, ?, ?, ?, ?)",
            (owner, owner.split("@")[0], model, kind, compact.pack(text) if text else None, len(text or ""), raw, outcome, tokens))
    Telemetry(conn).reindex_all()   # как при обновлении: записи, сделанные до поиска и DLP


def record(conn, text: str, owner: str = "sidorov") -> int:
    return Telemetry(conn).record(ChatUser(owner, 1, "corp.local", [1], ["g"]), "chat", provider_id=1, provider_name="P",
                                  model_id=1, model_name="M", text=text)


def found(conn, query: str = "", **kw) -> str:
    html = section.handle(kit_admin.page(conn, "telemetry", query=quote(query, safe="=&+%"), **kw))
    return re.search(r"Найдено: ([\d ]+)", html).group(1).replace(" ", "")


def test_section_feed_filters_and_text_inline(conn):
    seed(conn)
    html = section.handle(kit_admin.page(conn, "telemetry"))
    assert "Найдено: 3" in html and "Секретный план без меток" in html, "текст сообщения виден прямо в ленте, без «Открыть»"
    assert "Ведётся" not in html
    assert found(conn, "unanon=1") == "1"
    assert found(conn, "user=PETROV@corp.local") == "2"
    assert found(conn, "user=petr") == "2", "пользователь — по началу логина"
    assert found(conn, "kind=summary") == "1"
    assert found(conn, "model=GPT") == "1"
    assert found(conn, "q=%D0%B4%D0%BE%D0%B3%D0%BE%D0%B2%D0%BE%D1%80%D1%8B") == "1"   # «договоры» → «Договор»
    assert found(conn, "period=custom&from=2020-01-01&to=2020-01-02") == "0"
    a = kit_admin.audit(conn)[0]
    assert a["action"] == "telemetry_feed_view" and "shown=3" in a["details"], "страница ленты показывает чужие тексты — это аудируется"


def test_overview_tab_is_not_audited(conn):
    seed(conn)
    html = section.handle(kit_admin.page(conn, "telemetry", query="tab=overview"))
    assert "Срабатывания по правилам" in html and "petrov@corp.local" in html
    assert kit_admin.audit(conn) == []


def test_dlp_rules_flag_records_on_write_and_highlight(conn):
    card = record(conn, "Оплата картой 4111 1111 1111 1111, пароль: Qwerty123")
    record(conn, "Карта 4111 1111 1111 1112 — неверная контрольная сумма")
    grif = record(conn, "Это коммерческая тайна, не пересылать")
    plain = record(conn, "Как испечь пирог")
    sev = dict(conn.execute("SELECT id, dlp_severity FROM llm_outbound").fetchall())
    assert sev[card] == 3 and sev[grif] == 3 and sev[plain] == 0
    assert sev[card + 1] == 0, "номер без верной суммы Луна — не карта"
    html = section.handle(kit_admin.page(conn, "telemetry", query="sev=3"))
    assert "Найдено: 2" in html and "Номера банковских карт" in html
    assert '<mark class="hl-dlp hl-sev-3" title="Грифы конфиденциальности">коммерческая тайна</mark>' in html
    rule = conn.execute("SELECT id FROM dlp_rules WHERE builtin_key = 'cards'").fetchone()[0]
    assert found(conn, f"rule={rule}") == "1"
    assert found(conn, "review=todo") == "2"


def test_search_handles_yo_phrases_and_exclusions(conn):
    record(conn, "Посчитай ёлки в парке")
    record(conn, "Счёт за ёлку")
    record(conn, "Посчитай пироги")
    assert found(conn, "q=елки") == "2", "ё и е — одно и то же"
    assert found(conn, "q=" + "%22посчитай ёлки%22") == "1"
    assert found(conn, "q=посчитай+-пироги") == "1"
    assert found(conn, "q=-пироги") == "0", "одно исключение без слов для поиска ничего не находит"
    html = section.handle(kit_admin.page(conn, "telemetry", query="q=" + quote("пирог")))
    assert '<mark class="hl-q">пироги</mark>' in html


def test_review_single_and_bulk_is_audited(conn):
    ids = [record(conn, f"коммерческая тайна {n}") for n in range(3)]
    p = kit_admin.page(conn, "telemetry", "review", {"one": f"{ids[0]}:incident"}, query="review=todo")
    html = section.handle(p)
    assert "Найдено: 2" in html, "разобранная запись уходит из очереди «Не разобраны»"
    section.handle(kit_admin.page(conn, "telemetry", "review", [("ids[]", str(ids[1])), ("ids[]", str(ids[2])), ("status", "ok")]))
    got = dict(conn.execute("SELECT id, review FROM llm_outbound").fetchall())
    assert got == {ids[0]: "incident", ids[1]: "ok", ids[2]: "ok"}
    assert conn.execute("SELECT reviewed_by FROM llm_outbound WHERE id = ?", (ids[0],)).fetchone()[0] == "admin"
    assert found(conn, "review=incident") == "1"
    reviews = [a["details"] for a in kit_admin.audit(conn) if a["action"] == "telemetry_review"]
    assert reviews == [f"status=incident; records={ids[0]}", f"status=ok; records={ids[1]},{ids[2]}"]
    stale = kit_admin.page(conn, "telemetry", "review", {"one": f"{ids[1]}:incident", "csrf": "bad"}, csrf=False)
    section.handle(stale)
    assert stale.flash_error and conn.execute("SELECT review FROM llm_outbound WHERE id = ?", (ids[1],)).fetchone()[0] == "ok"


def test_rules_crud_validation_and_rescan(conn, var_dir):
    rid = record(conn, "Проект Альбатрос стартует в мае")
    bad = kit_admin.page(conn, "telemetry", "rule_save", {"name": "Плохое", "kind": "regex", "severity": "2", "pattern": "(незакрыта", "is_enabled": "1"})
    assert "ошибка в регулярном выражении" in section.handle(bad) and bad.flash_error
    p = kit_admin.page(conn, "telemetry", "rule_save", {"name": "Кодовые имена", "kind": "words", "severity": "3", "pattern": "альбатрос", "is_enabled": "1"})
    section.handle(p)
    assert "перепроверяется" in p.flash_success
    Telemetry(conn).rescan_step()
    assert conn.execute("SELECT dlp_severity FROM llm_outbound WHERE id = ?", (rid,)).fetchone()[0] == 3, "старая запись найдена перепроверкой"
    new_id = conn.execute("SELECT id FROM dlp_rules WHERE name = 'Кодовые имена'").fetchone()[0]
    section.handle(kit_admin.page(conn, "telemetry", "rule_toggle", {"rule_id": str(new_id)}))
    assert conn.execute("SELECT dlp_severity FROM llm_outbound WHERE id = ?", (rid,)).fetchone()[0] == 0, "выключение снимает срабатывания сразу"
    builtin = conn.execute("SELECT id FROM dlp_rules WHERE builtin_key = 'grif'").fetchone()[0]
    refused = kit_admin.page(conn, "telemetry", "rule_delete", {"rule_id": str(builtin)})
    section.handle(refused)
    assert refused.flash_error and conn.execute("SELECT COUNT(*) FROM dlp_rules WHERE id = ?", (builtin,)).fetchone()[0] == 1
    section.handle(kit_admin.page(conn, "telemetry", "rule_delete", {"rule_id": str(new_id)}))
    assert conn.execute("SELECT COUNT(*) FROM dlp_rules WHERE id = ?", (new_id,)).fetchone()[0] == 0
    actions = [a["action"] for a in kit_admin.audit(conn)]
    assert actions == ["dlp_rule_create", "dlp_rule_disable", "dlp_rule_delete"]
    test = section.handle(kit_admin.page(conn, "telemetry", "rule_test", {"sample": "пароль: Secret-42"}))
    assert '<mark class="hl-dlp hl-sev-3"' in test and "Пароли, ключи и токены доступа" in test


def test_purge_removes_index_and_hits(conn):
    rid = record(conn, "коммерческая тайна")
    conn.execute("UPDATE llm_outbound SET created_at = '2000-01-01T00:00:00+00:00'")
    assert Telemetry(conn).purge(30) == 1
    assert conn.execute("SELECT COUNT(*) FROM llm_outbound_dlp").fetchone()[0] == 0
    assert conn.execute("SELECT COUNT(*) FROM llm_outbound_fts WHERE llm_outbound_fts MATCH 'тайна'").fetchone()[0] == 0
    assert rid


def test_detail_user_and_conversation_views_are_audited(conn):
    seed(conn)
    html = section.handle(kit_admin.page(conn, "telemetry", query="view=record&id=2"))
    assert "Секретный план без меток" in html and "без обезличивания" in html
    cid = conn.execute("INSERT INTO llm_conversations (owner_key, title, created_at, updated_at) VALUES ('petrov@corp.local', 'План', 'x', 'x')").lastrowid
    conn.execute("INSERT INTO llm_messages (conversation_id, role, content, created_at) VALUES (?, 'user', 'Привет, [PERSON_1]', 'x')", (cid,))
    assert "План" in section.handle(kit_admin.page(conn, "telemetry", query="view=user&user=petrov@corp.local"))
    assert "Привет, [PERSON_1]" in section.handle(kit_admin.page(conn, "telemetry", query=f"view=conversation&id={cid}"))
    actions = [(a["actor"], a["action"], a["details"]) for a in kit_admin.audit(conn)]
    assert actions == [("admin", "telemetry_record_view", "record_id=2; user=petrov@corp.local"),
                       ("admin", "telemetry_user_view", "user=petrov@corp.local"),
                       ("admin", "telemetry_conversation_view", f"conversation_id={cid}; user=petrov@corp.local")]


def test_csv_export_requires_csrf_is_sanitized_and_audited(conn):
    seed(conn)
    conn.execute("INSERT INTO llm_outbound (created_at, owner_key, username, domain, kind, text_z, outcome) VALUES "
                 "(strftime('%Y-%m-%dT%H:%M:%S+00:00', 'now'), 'x@y', 'x', 'y', 'chat', ?, 'ok')", (compact.pack("=HYPERLINK()\r\nподдельная\tстрока"),))
    stale = section.handle(kit_admin.page(conn, "telemetry", "export"))
    assert isinstance(stale, str) and all(a["action"] != "telemetry_export" for a in kit_admin.audit(conn)), "без csrf выгрузки нет"
    sess = kit_admin.session()
    r = section.handle(kit_admin.page(conn, "telemetry", "export", sess=sess, query="csrf=" + sess["csrf_token"]))
    text = r.body.decode()
    assert r.media_type.startswith("text/csv") and "attachment" in r.headers["content-disposition"]
    assert "\r" not in text and "\t" not in text
    rows = list(csv.reader(io.StringIO(text.lstrip("\ufeff"))))
    assert len(rows) == 5 and all(len(row) == len(rows[0]) for row in rows)
    assert rows[1][-1] == "'=HYPERLINK()  поддельная строка"
    [a] = [a for a in kit_admin.audit(conn) if a["action"] == "telemetry_export"]
    assert "rows=4" in a["details"]


def test_retention_setting_saved(conn):
    section.handle(kit_admin.page(conn, "telemetry", "save_retention", {"telemetry_retention_days": "30"}))
    assert kit_admin.page(conn, "telemetry").module.chat_settings.get("telemetry_retention_days") == "30"
    p = kit_admin.page(conn, "telemetry", "save_retention", {"telemetry_retention_days": "99999"})
    section.handle(p)
    assert p.flash_error and p.module.chat_settings.get("telemetry_retention_days") == "30"


def test_section_needs_admin_login():
    client = TestClient(create_app(paths.database()))
    setup_admin(client)
    fresh = TestClient(client.app)
    r = fresh.get("/admin/index.php?section=telemetry&view=record&id=1")
    assert "audit-feed" not in r.text and 'name="password"' in r.text
    login(fresh)
    r = fresh.get("/admin/index.php?section=telemetry")
    assert r.status_code == 200 and 'href="index.php?section=telemetry" class="active"' in r.text


def test_chat_page_shows_telemetry_label():
    html = (Path(__file__).resolve().parents[1] / "llmp/web/templates/chat/app.j2").read_text()
    assert "Учёт запросов включен" in html and "Обезличивание включено" in html and "соглашением об использовании" in html
