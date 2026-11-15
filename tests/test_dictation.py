"""Билет голосового ввода: формат и подпись совпадают с services/asr/app/ws_gateway.py::verify_ticket (перенос tests/DictationTicketTest.php)."""

from __future__ import annotations

import ast
import base64
import hashlib
import hmac
import json
import re

import pytest

from llmp import paths
from llmp.chat import ChatSettings
from llmp.dictation import TTL, DictationTicket
from llmp.settings import AppSettings
from tests import kit

SECRET = "s" * 48


@pytest.fixture
def app(monkeypatch):
    monkeypatch.setenv("DICTATION_SECRET", SECRET)
    monkeypatch.setenv("ASR_ENABLED", "1")
    c = kit.database()
    yield AppSettings(c)
    c.close()


def b64d(s: str) -> bytes:
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


def save(app: AppSettings, values: dict[str, str]) -> None:
    for k, v in values.items():
        app.set("llm_chat_" + k, v)


def gateway_verify_ticket():
    """verify_ticket из шлюза ASR без его зависимостей (fastapi, redis): функция и её константы берутся из исходника."""
    src = (paths.ROOT / "services/asr/app/ws_gateway.py").read_text()
    tree = ast.parse(src)
    keep = [n for n in tree.body if (isinstance(n, ast.FunctionDef) and n.name in ("_b64d", "verify_ticket"))
            or (isinstance(n, ast.Assign) and any(getattr(t, "id", "") == "TICKET_MAX_AGE" for t in n.targets))]
    ns: dict = {}
    exec("import base64, binascii, hashlib, hmac, json, time\n" + ast.unparse(ast.Module(keep, [])), ns)  # noqa: S102
    return ns["verify_ticket"]


def test_ticket_is_signed_short_lived_and_unique(app):
    """Подпись HMAC-SHA256, срок TTL, настройки по умолчанию, одноразовый номер, base64url без заполнения."""
    tickets = DictationTicket(ChatSettings(app))
    assert tickets.enabled()
    t = tickets.issue("ivanov@corp.local", 1_000_000)
    body, sig = t.split(".")
    assert hmac.new(SECRET.encode(), body.encode(), hashlib.sha256).digest() == b64d(sig)
    data = json.loads(b64d(body))
    assert data["u"] == "ivanov@corp.local"
    assert data["exp"] == 1_000_000 + TTL
    assert data["ms"] == 120
    assert data["mc"] == 4
    assert len(data["n"]) == 32
    assert t != tickets.issue("ivanov@corp.local", 1_000_000), "одноразовый номер в каждом билете свой"
    assert not re.search(r"[+/=]", t), "base64url без заполнения — годится для адреса"


def test_disabled_by_admin_or_without_asr(app, monkeypatch):
    """Выключено администратором или без ASR; длительность ограничена 600 с."""
    save(app, {"dictation_enabled": "0"})
    assert not DictationTicket(ChatSettings(app)).enabled()
    save(app, {"dictation_enabled": "1", "dictation_max_seconds": "9999"})
    assert DictationTicket(ChatSettings(app)).max_seconds() == 600
    monkeypatch.setenv("ASR_ENABLED", "0")
    assert not DictationTicket(ChatSettings(app)).enabled()


# ---- сверх PHP-набора ----

def test_gateway_accepts_python_ticket(app):
    """Билет Python-версии принимает verify_ticket шлюза; чужой секрет, подделка и истёкший срок — нет."""
    verify = gateway_verify_ticket()
    t = DictationTicket(ChatSettings(app)).issue("Иванов", 1_000_000)
    data = verify(t, SECRET, now=1_000_000)
    assert data is not None and data["u"] == "Иванов" and data["ms"] == 120
    assert verify(t, "x" * 48, now=1_000_000) is None
    assert verify(t[:-2] + ("AA" if t[-2:] != "AA" else "BB"), SECRET, now=1_000_000) is None
    assert verify(t, SECRET, now=1_000_000 + TTL + 1) is None


def test_asr_ready_requires_plain_http_url(app, monkeypatch):
    """Без ASR_URL или с не-http адресом готовность — нет, без сетевого запроса."""
    monkeypatch.delenv("ASR_URL", raising=False)
    assert not DictationTicket(ChatSettings(app)).asr_ready()
    monkeypatch.setenv("ASR_URL", "file:///etc/passwd")
    assert not DictationTicket(ChatSettings(app)).asr_ready()
