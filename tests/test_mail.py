"""Почта: поэтапная проверка, классификация, очередь с ограниченными повторами, секреты не утекают (перенос tests/MailTest.php)."""

from __future__ import annotations

import base64
import json
import time

import pytest

from llmp.mail import Alerts, MailQueue, MailSettings, SmtpClient
from llmp.settings import AppSettings
from tests import kit
from tests.fake_smtp import FakeSmtp


@pytest.fixture
def pdo():
    c = kit.database()
    yield c
    c.close()


@pytest.fixture
def settings(pdo) -> MailSettings:
    return MailSettings(AppSettings(pdo))


@pytest.fixture
def server(settings):
    started: list[FakeSmtp] = []

    def start(scenario: dict[str, int]) -> FakeSmtp:
        srv = FakeSmtp(scenario)
        started.append(srv)
        settings.save({"enabled": "1", "host": "127.0.0.1", "port": str(srv.port), "security": "none", "auth": "auto",
                       "username": "robot", "from_address": "robot@example.com", "timeout": "5"}, "S3cret-Pass-123")
        return srv

    yield start
    for s in started:
        s.close()


def test_staged_check_and_send_without_leaking_the_password(settings, server):
    """Все этапы до DATA пройдены; пароль и обмен AUTH в результат не попадают; тема в base64."""
    srv = server({})
    r = SmtpClient(settings, True).check("admin@example.com", "Тема", "Строка 1\nСтрока 2")
    assert r["ok"], json.dumps(r["stages"], ensure_ascii=False)
    stages = [s["stage"] for s in r["stages"]]
    for s in ("dns", "tcp", "greeting", "ehlo", "tls", "methods", "auth", "sender", "recipient", "data"):
        assert s in stages
    assert "S3cret" not in json.dumps(r, ensure_ascii=False)
    assert base64.b64encode(b"\0robot\0S3cret-Pass-123").decode() not in json.dumps(r)
    srv.close()
    assert "Subject: =?UTF-8?B?" + base64.b64encode("Тема".encode()).decode() + "?=" in srv.wire


def test_auth_failure_is_classified_and_never_retried(pdo, settings, server):
    """Неверный пароль не повторяется (блокировка в AD), тело стёрто."""
    server({"auth": 535})
    queue = MailQueue(pdo)
    queue.enqueue("test", "admin@example.com", {"host": "x", "time": "now"})
    queue.process(settings, 5, lambda: SmtpClient(settings, True))
    row = pdo.execute("SELECT status, last_error, payload_enc FROM mail_queue").fetchone()
    assert [row["status"], row["last_error"], row["payload_enc"]] == ["failed", "auth_failed", None]


def test_temporary_reject_is_retried_with_backoff(pdo, settings, server):
    """Временный отказ — повтор с паузой; письмо ждёт повтора зашифрованным."""
    server({"rcpt": 451})
    queue = MailQueue(pdo)
    queue.enqueue("model_alert", "admin@example.com", {"model": "М", "provider": "П", "reason": "HTTP 429", "until": "—"})
    queue.process(settings, 5, lambda: SmtpClient(settings, True))
    row = pdo.execute("SELECT status, attempts, last_error, next_attempt_at, payload_enc FROM mail_queue").fetchone()
    assert row["status"] == "queued"
    assert row["last_error"] == "temporary"
    assert int(row["next_attempt_at"]) >= int(time.time()) + 55
    assert row["payload_enc"] is not None, "письмо ждёт повтора зашифрованным"
    assert "HTTP 429" not in row["payload_enc"]


def test_classification_and_safety(settings):
    """Классификация ответов, очистка текста сервера, защита от SSRF, сброс пароля при смене сервера."""
    assert SmtpClient.classify("recipient", 421, "Too many connections") == "limit"
    assert SmtpClient.classify("auth", 504, "Unrecognized authentication type") == "auth_unsupported"
    assert SmtpClient.classify("recipient", 550, "Unable to relay") == "relay_denied"
    assert SmtpClient.classify("recipient", 550, "No such user") == "recipient_rejected"
    assert SmtpClient.server_text("QUJD" * 20) == "[…]"
    settings.save({"enabled": "1", "host": "127.0.0.1", "port": "25", "security": "none", "from_address": "a@example.com"}, None)
    r = SmtpClient(settings).check()
    assert r["code"] == "forbidden_target", "loopback без явного разрешения — защита от SSRF"
    settings.save({"host": "smtp.example.com", "port": "587"}, "pass1")
    settings.save({"host": "other.example.com"}, None)
    assert not settings.has_password(), "смена сервера сбрасывает сохранённый пароль"


def test_undecryptable_mail_is_dropped_and_does_not_block_the_queue(pdo, settings, server):
    """Битое письмо снято, следующее отправлено."""
    server({})
    queue = MailQueue(pdo)
    queue.enqueue("test", "admin@example.com", {"host": "x", "time": "now"})
    queue.enqueue("test", "admin@example.com", {"host": "y", "time": "now"})
    pdo.execute("UPDATE mail_queue SET payload_enc = 'v1:испорчено' WHERE id = (SELECT MIN(id) FROM mail_queue)")
    queue.process(settings, 5, lambda: SmtpClient(settings, True))
    rows = [tuple(r) for r in pdo.execute("SELECT status, last_error FROM mail_queue ORDER BY id")]
    assert rows == [("failed", "decrypt"), ("sent", None)]


# ---- сверх PHP-набора ----

def test_undecryptable_stored_password_is_auth_failure(pdo, settings):
    """Пароль не расшифровать (сменился мастер-ключ) — этап auth, код auth_failed, без обращения к сети."""
    settings.save({"enabled": "1", "host": "127.0.0.1", "port": "2525", "from_address": "a@example.com"}, None)
    AppSettings(pdo).set("mail_password_enc", "испорчено")
    r = SmtpClient(settings, True).check()
    assert (r["ok"], r["code"], r["stages"][-1]["stage"]) == (False, "auth_failed", "auth")


def test_crlf_in_recipient_is_rejected(settings, server):
    """Перевод строки в адресе не превращается во внедрённую команду SMTP."""
    srv = server({})
    r = SmtpClient(settings, True).check("a@example.com>\r\nRCPT TO:<evil@example.com", "x", "y")
    assert r["ok"] is False and r["code"] == "protocol"
    srv.close()
    assert "evil" not in srv.wire


def test_alert_throttle_claim_is_atomic(pdo, settings):
    """Один повод — одно письмо в час, даже если notify вызывают два процесса подряд; через час — снова."""
    app = AppSettings(pdo)
    settings.save({"enabled": "1", "host": "smtp.example.com", "from_address": "a@example.com",
                   "alert_recipients": "x@example.com, y@example.com; bad"}, None)
    queue = MailQueue(pdo)
    alerts_a, alerts_b = Alerts(settings, queue, app), Alerts(MailSettings(app), MailQueue(pdo), app)
    alerts_a.notify("all_failed", {"reason": "HTTP 500"}, "all")
    alerts_b.notify("all_failed", {"reason": "HTTP 500"}, "all")
    assert pdo.execute("SELECT COUNT(*) FROM mail_queue").fetchone()[0] == 2, "по письму каждому из двух адресатов, без повтора"
    alerts_a.notify("all_failed", {"reason": "HTTP 500"}, "other")
    assert pdo.execute("SELECT COUNT(*) FROM mail_queue").fetchone()[0] == 4, "другой повод не подавлен"
    pdo.execute("UPDATE app_settings SET value = ? WHERE key LIKE 'alert_last_%'", (str(int(time.time()) - 3600),))
    alerts_b.notify("all_failed", {"reason": "HTTP 500"}, "all")
    assert pdo.execute("SELECT COUNT(*) FROM mail_queue").fetchone()[0] == 6, "через час повод снова отправляется"
