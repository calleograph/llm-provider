"""Ядро: CSRF, адрес клиента, сессии, журналы (перенос tests/CsrfTest, ClientIpTest, SessionManagerTest,
DiagnosticLoggerTest, LogRotationServiceTest, UnifiedEventLogTest, LlmEventLogTest)."""

from __future__ import annotations

import json
import time

import pytest
from fastapi import Request   # на уровне модуля: аннотации — строки (__future__), FastAPI ищет имя здесь

from llmp import db, logs, paths, security
from llmp.domain import ChatAuthenticator
from llmp.logs import AppLogger, DiagnosticLogger, LlmEventLog, LogRotation, UnifiedEventLog
from llmp.security import Session, SessionStore, client_ip, csrf_rotate, csrf_token, csrf_verify


def _bare() -> "db.sqlite3.Connection":
    """Пустая БД в памяти (row_factory как у приложения) — таблицы создаёт тест, как в PHP-наборе."""
    return db.connect(":memory:")


# ---- CsrfTest ----
def test_token_is_generated_and_persisted():
    """Токен создаётся один раз и хранится в сессии."""
    s = Session(None)
    t1, t2 = csrf_token(s), csrf_token(s)
    assert t1 == t2 and len(t1) >= 32


def test_verify_accepts_matching_token():
    """Совпадающий токен принимается."""
    s = Session(None)
    assert csrf_verify(s, csrf_token(s))


def test_verify_rejects_wrong_token():
    """Чужой токен отклоняется."""
    s = Session(None)
    csrf_token(s)
    assert not csrf_verify(s, "wrong-token")


def test_verify_rejects_null_token():
    """Отсутствующий токен отклоняется."""
    s = Session(None)
    csrf_token(s)
    assert not csrf_verify(s, None)


def test_verify_rejects_when_no_token_in_session():
    """В сессии нет токена — отказ."""
    assert not csrf_verify(Session(None), "anything")


def test_rotate_changes_token():
    """После ротации прежний токен недействителен."""
    s = Session(None)
    t1 = csrf_token(s)
    csrf_rotate(s)
    assert csrf_token(s) != t1 and not csrf_verify(s, t1)


# ---- ClientIpTest ----
def test_ignores_forwarded_for_from_untrusted_source():
    """X-Forwarded-For от недоверенного адреса игнорируется."""
    assert client_ip("203.0.113.5", "10.0.0.99", ["10.0.0.10"]) == "203.0.113.5"


def test_trusts_forwarded_for_from_trusted_exact_ip():
    """От доверенного прокси (точный адрес) — берётся X-Forwarded-For."""
    assert client_ip("10.0.0.10", "198.51.100.7", ["10.0.0.10"]) == "198.51.100.7"


def test_trusts_forwarded_for_from_trusted_cidr():
    """Доверенная подсеть CIDR."""
    assert client_ip("10.0.0.55", "198.51.100.9", ["10.0.0.0/24"]) == "198.51.100.9"


def test_falls_back_to_remote_addr_when_no_forwarded_header():
    """Без заголовка — адрес соединения."""
    assert client_ip("10.0.0.10", None, ["10.0.0.10"]) == "10.0.0.10"


def test_takes_first_address_in_forwarded_for_chain():
    """Из цепочки берётся первый адрес."""
    assert client_ip("10.0.0.10", "198.51.100.7, 10.0.0.10, 10.0.0.1", ["10.0.0.10"]) == "198.51.100.7"


# ---- SessionManagerTest (сессии теперь на сервере, таблица web_sessions) ----
def test_min_session_lifetime_is_at_least_ten_minutes():
    """Сессия (и CSRF-токен открытой формы) живёт не меньше 10 минут."""
    assert security.SESSION_LIFETIME_SECONDS >= 600


def test_admin_idle_timeout_is_at_least_four_hours():
    """Тайм-аут бездействия администратора по умолчанию — не меньше 4 часов."""
    assert security.DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS >= 4 * 3600


def test_min_session_lifetime_is_not_shorter_than_admin_idle_timeout():
    """Срок хранения сессии не короче наибольшего настраиваемого тайм-аута бездействия."""
    assert security.SESSION_LIFETIME_SECONDS >= security.MAX_ADMIN_IDLE_TIMEOUT_SECONDS


def test_sessions_are_stored_server_side_and_expire_by_lifetime(conn):
    """Вместо файлов в /tmp — таблица web_sessions в БД приложения; запись старше срока не загружается и удаляется."""
    store = SessionStore(conn)
    s = Session(None, {"k": "v"})
    sid = store.save(s)
    assert len(sid) == 64 and store.load(sid) == {"k": "v"}
    conn.execute("UPDATE web_sessions SET updated_at = ? WHERE id = ?", (int(time.time()) - security.SESSION_LIFETIME_SECONDS - 1, sid))
    assert store.load(sid) == {} and store.load(sid).sid is None
    store.purge_expired()
    assert conn.execute("SELECT COUNT(*) FROM web_sessions").fetchone()[0] == 0


def test_session_storage_is_under_var_not_document_root():
    """Хранилище сессий (БД) — в каталоге данных, не в public/ и не в системном /tmp."""
    assert paths.database().is_relative_to(paths.var())
    assert not paths.database().is_relative_to(paths.ROOT / "public")


def test_regenerate_issues_new_id_and_drops_old(conn):
    """При входе — новый идентификатор, прежняя запись удаляется (защита от фиксации сессии)."""
    store = SessionStore(conn)
    old = store.save(Session(None, {"a": 1}))
    s = store.load(old)
    s.regenerate()
    new = store.save(s)
    assert new != old and store.load(old) == {} and store.load(new) == {"a": 1}


def test_cookie_is_not_secure_over_plain_http_and_secure_behind_https_proxy(tmp_path):
    """По http://IP:порт кука без Secure (иначе первый вход не работает), за HTTPS-прокси — с Secure; HttpOnly и SameSite=Strict всегда."""
    from fastapi.testclient import TestClient

    from llmp.web.app import create_app
    from llmp.web.core import ctx

    app = create_app(tmp_path / "a.sqlite")

    @app.get("/_test_session")
    def touch(request: Request):
        ctx(request).session["x"] = 1
        return {"ok": True}

    client = TestClient(app, base_url="http://192.0.2.10:8080")
    cookie = client.get("/_test_session").headers["set-cookie"]
    assert cookie.startswith(security.COOKIE + "=") and "secure" not in cookie.lower()
    assert "httponly" in cookie.lower() and "samesite=strict" in cookie.lower()
    cookie = TestClient(app).get("/_test_session", headers={"X-Forwarded-Proto": "https"}).headers["set-cookie"]
    assert "secure" in cookie.lower()


# ---- DiagnosticLoggerTest ----
@pytest.fixture
def diag():
    c = _bare()
    c.execute("CREATE TABLE diagnostic_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, level TEXT NOT NULL, "
              "source TEXT NOT NULL, event_code TEXT NOT NULL, message TEXT NOT NULL, context TEXT, ip TEXT)")
    yield DiagnosticLogger(c)
    c.close()


def test_context_key_looking_like_password_is_redacted(diag):
    """Ключ контекста, похожий на пароль, скрывается; прочие — как есть."""
    diag.error("ldap_bind", "test", "test message", {"old_password": "SuperSecret1!", "username": "ivanov"})
    context = json.loads(diag.query()["rows"][0]["context"])
    assert context["old_password"] == "[redacted]" and context["username"] == "ivanov"


def test_message_containing_password_like_substring_is_redacted(diag):
    """«password=…» в тексте сообщения скрывается."""
    diag.error("php_error", "test", "Connection failed, password=hunter2 was rejected")
    msg = diag.query()["rows"][0]["message"]
    assert "hunter2" not in msg and "[redacted]" in msg


def test_filter_by_level(diag):
    """Фильтр по уровню."""
    diag.info("connection_test", "a", "info message")
    diag.error("ldap_bind", "b", "error message")
    rows = diag.query({"level": logs.ERROR})["rows"]
    assert len(rows) == 1 and rows[0]["level"] == "error"


def test_filter_by_source(diag):
    """Фильтр по источнику."""
    diag.info("connection_test", "a", "msg1")
    diag.info("kpasswd", "b", "msg2")
    rows = diag.query({"source": "kpasswd"})["rows"]
    assert len(rows) == 1 and rows[0]["source"] == "kpasswd"


def test_filter_by_search_text(diag):
    """Поиск по тексту."""
    diag.info("connection_test", "a", "unique-marker-xyz")
    diag.info("connection_test", "b", "something else")
    assert len(diag.query({"search": "unique-marker-xyz"})["rows"]) == 1


def test_total_count_is_independent_of_limit(diag):
    """total не зависит от limit."""
    for i in range(5):
        diag.info("connection_test", "a", f"msg{i}")
    r = diag.query({"limit": 2})
    assert len(r["rows"]) == 2 and r["total"] == 5


def test_ignores_invalid_level_filter(diag):
    """Неизвестный уровень в фильтре игнорируется."""
    diag.info("connection_test", "a", "msg")
    assert len(diag.query({"level": "not-a-real-level"})["rows"]) == 1


# ---- LogRotationServiceTest ----
@pytest.fixture
def logdb():
    c = _bare()
    for t in ("app_log", "audit_log", "diagnostic_log"):
        c.execute(f"CREATE TABLE {t} (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT)")
    yield c
    c.close()


def test_deletes_oldest_fraction_when_over_limit(logdb):
    """Удаляются именно 20 % старейших записей (наименьшие id)."""
    for i in range(1, 101):
        logdb.execute("INSERT INTO app_log (created_at) VALUES (?)", (f"2026-01-01T00:00:{i}Z",))
    LogRotation(logdb).delete_oldest_fraction("app_log", 0.2)
    assert logdb.execute("SELECT COUNT(*) FROM app_log").fetchone()[0] == 80
    assert logdb.execute("SELECT MIN(id) FROM app_log").fetchone()[0] == 21


def test_delete_oldest_fraction_is_no_op_on_empty_table(logdb):
    """Пустая таблица — ничего не происходит."""
    LogRotation(logdb).delete_oldest_fraction("app_log", 0.2)
    assert logdb.execute("SELECT COUNT(*) FROM app_log").fetchone()[0] == 0


def test_max_bytes_defaults_to_fifty_megabytes():
    """Порог по умолчанию — 50 МБ."""
    assert LogRotation.DEFAULT_MAX_BYTES == 50 * 1024 * 1024


def test_only_log_tables_count_towards_the_limit_not_chat_data(logdb):
    """Большая переписка не повод удалять аудит; переросшие порог журналы — повод."""
    logdb.execute("CREATE TABLE llm_messages (id INTEGER PRIMARY KEY, content BLOB)")
    logdb.execute("INSERT INTO llm_messages (content) VALUES (randomblob(500000))")
    rotation = LogRotation(logdb, 100000)
    for _ in range(50):
        logdb.execute("INSERT INTO audit_log (created_at) VALUES ('2026-01-01')")
    assert rotation.rotate_if_needed() is False, "большая переписка не повод удалять аудит"
    assert logdb.execute("SELECT COUNT(*) FROM audit_log").fetchone()[0] == 50
    logdb.execute("INSERT INTO app_log (created_at) SELECT hex(randomblob(1000)) FROM llm_messages, "
                  "(SELECT 1 UNION ALL SELECT 2) a, (SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) b")
    for _ in range(6):
        logdb.execute("INSERT INTO app_log (created_at) SELECT created_at FROM app_log")
    assert rotation.rotate_if_needed() is True, "журналы сами переросли порог"
    assert logdb.execute("SELECT COUNT(*) FROM audit_log").fetchone()[0] == 40


# ---- UnifiedEventLogTest ----
@pytest.fixture
def unified():
    c = _bare()
    c.execute("CREATE TABLE app_log (id INTEGER PRIMARY KEY, created_at TEXT, ip TEXT, username TEXT, operation TEXT, result TEXT)")
    c.execute("CREATE TABLE audit_log (id INTEGER PRIMARY KEY, created_at TEXT, actor TEXT, ip TEXT, action TEXT, details TEXT, success INTEGER)")
    c.execute("CREATE TABLE diagnostic_log (id INTEGER PRIMARY KEY, created_at TEXT, level TEXT, source TEXT, event_code TEXT, message TEXT, context TEXT, ip TEXT)")
    c.execute("INSERT INTO app_log (created_at, ip, username, operation, result) VALUES ('2026-01-01T10:00:00Z', '10.0.0.1', 'ivanov', 'CHANGE_PASSWORD', 'CHANGE_PASSWORD_SUCCESS')")
    c.execute("INSERT INTO audit_log (created_at, actor, ip, action, details, success) VALUES ('2026-01-01T09:00:00Z', 'admin', '10.0.0.2', 'admin_login', 'method=local', 1)")
    c.execute("INSERT INTO diagnostic_log (created_at, level, source, event_code, message, context, ip) VALUES ('2026-01-01T09:30:00Z', 'error', 'ldap_bind', 'invalid_credentials', 'bind failed for ivanov', '{}', '10.0.0.1')")
    yield UnifiedEventLog(c)
    c.close()


def test_combines_all_three_sources_ordered_by_time(unified):
    """Три источника в одной ленте, по убыванию времени."""
    r = unified.query()
    assert r["total"] == 3 and [row["category"] for row in r["rows"]] == ["password_change", "diagnostic", "admin_action"]


def test_filters_by_category(unified):
    """Фильтр по категории."""
    r = unified.query({"category": "admin_action"})
    assert r["total"] == 1 and r["rows"][0]["category"] == "admin_action"


def test_search_finds_across_all_sources(unified):
    """Поиск находит и actor (app_log), и summary (diagnostic_log)."""
    assert unified.query({"search": "ivanov"})["total"] == 2


def test_outcome_is_derived_correctly_per_category(unified):
    """Итог события вычисляется по правилам категории."""
    by = {row["category"]: row["outcome"] for row in unified.query()["rows"]}
    assert by == {"password_change": "success", "admin_action": "success", "diagnostic": "failure"}


def test_pagination_limits_results(unified):
    """limit ограничивает строки, total — полный."""
    r = unified.query({"limit": 1})
    assert len(r["rows"]) == 1 and r["total"] == 3


# ---- LlmEventLogTest ----
def test_content_and_secret_fields_are_dropped():
    """Содержимое и секреты в журнал не попадают."""
    details = LlmEventLog.format_details({
        "provider": "OpenAI", "text": "Иванов Иван, паспорт 1234", "content": "секрет", "prompt": "x", "api_key": "sk-123",
        "password": "p", "token": "t", "file_name": "Увольнение Иванова.docx", "input_tokens": 10, "text_chars": 25,
    })
    assert details == "provider=OpenAI; input_tokens=10; text_chars=25"
    assert "Иванов" not in details and "sk-123" not in details


def test_values_are_sanitized():
    """Значения очищаются: «;» → «,», управляющие символы → пробел, bool → 1, None → «—»."""
    assert LlmEventLog.format_details({"groups": ["A;B", "C\nD"], "ok": True, "none": None}) == "groups=A,B,C D; ok=1; none=—"


def test_login_parsing():
    """Разбор логина: user, DOMAIN\\user, user@domain; недопустимые символы — None."""
    assert ChatAuthenticator.parse_login(" ivanov ") == {"sam": "ivanov", "domain_hint": None}
    assert ChatAuthenticator.parse_login("CORP\\ivanov") == {"sam": "ivanov", "domain_hint": "corp"}
    assert ChatAuthenticator.parse_login("ivanov@Corp.Local") == {"sam": "ivanov", "domain_hint": "corp.local"}
    assert ChatAuthenticator.parse_login("iva*nov") is None
    assert ChatAuthenticator.parse_login("a=b,c") is None
    assert ChatAuthenticator.parse_login("") is None


def test_user_event_goes_to_app_log(conn):
    """Событие пользователя пишется в app_log с деталями после двоеточия."""
    LlmEventLog(AppLogger(conn)).user("10.0.0.1", "ivanov", LlmEventLog.OP_AUTH, "SUCCESS", {"groups": ["A"]})
    assert conn.execute("SELECT result FROM app_log").fetchone()[0] == "SUCCESS:groups=A"
