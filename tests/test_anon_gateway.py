"""Перенос tests/LlmAnonymizationGatewayTest.php: шлюз обезличивания работает по принципу fail closed — любой
неоднозначный ответ сервиса — исключение, а не «пропустить как есть». С 2.0 сервис один — встроенный (контейнер
anonymizer); внешний JSON API с настраиваемыми полями, фрагментами и корпоративным CA удалён."""

from __future__ import annotations

import json

import pytest

from kit_anon import anon_settings, dumps
from llmp.anon.gateway import AnonymizationException, AnonymizationGateway
from llmp.http import FakeHttpTransport, HttpResponse, HttpTransportError


@pytest.fixture
def settings(conn, monkeypatch):
    monkeypatch.setenv("ANONYMIZER_URL", "http://anonymizer:8091/")
    monkeypatch.setenv("INTERNAL_API_TOKEN", "anon-secret-token")
    return anon_settings(conn)


def gateway(settings, transport):
    return AnonymizationGateway(settings, transport)


def echo_service():
    return FakeHttpTransport(lambda r: HttpResponse(200, dumps({
        "texts": [t.replace("Иванов", "[PERSON_1]") for t in json.loads(r.body)["texts"]], "stats": {}, "replaced": 0,
        "warnings": [], "verified": True})))


def test_not_configured_fails_closed(settings, monkeypatch):
    """Нет адреса или токена в окружении — not_configured и ни одного запроса."""
    monkeypatch.delenv("INTERNAL_API_TOKEN")
    transport = echo_service()
    with pytest.raises(AnonymizationException) as e:
        gateway(settings, transport).anonymize("Иванов", "message")
    assert e.value.error_code == "not_configured"
    assert transport.requests == []


def test_success_returns_anonymized_text(settings):
    """Успех — обезличенный текст, запрос на ANONYMIZER_URL + /v1/anonymize с полем texts."""
    transport = echo_service()
    result = gateway(settings, transport).anonymize("Позвонить Иванов завтра", "message")
    assert result.value == "Позвонить [PERSON_1] завтра"
    assert transport.requests[0].url == "http://anonymizer:8091/v1/anonymize"
    assert json.loads(transport.requests[0].body)["texts"] == ["Позвонить Иванов завтра"]


def _timeout(r):
    raise HttpTransportError("timeout", "timeout")


@pytest.mark.parametrize("handler,expected", [
    (lambda r: HttpResponse(500, '{"texts":["x"],"verified":true}'), "http_500"),
    (lambda r: HttpResponse(200, "<html>"), "invalid_json"),
    (lambda r: HttpResponse(200, '{"texts":[["x"]],"verified":true}'), "missing_field"),
    (lambda r: HttpResponse(200, '{"texts":["  "],"verified":true}'), "empty_result"),
    (lambda r: HttpResponse(200, '{"texts":["x"]}'), "invalid_json"),
    (_timeout, "transport_timeout"),
], ids=["HTTP 500", "не JSON", "текст не строка", "пустой результат", "нет подтверждения проверки", "таймаут"])
def test_failures_throw(settings, handler, expected):
    """Каждый сбой сервиса — исключение со своим кодом."""
    with pytest.raises(AnonymizationException) as e:
        gateway(settings, FakeHttpTransport(handler)).anonymize("Иванов Иван", "message")
    assert e.value.error_code == expected


def test_token_is_sent_but_never_stored(settings, conn):
    """Токен сервиса уходит в заголовке и берётся из окружения — в БД его нет."""
    transport = echo_service()
    gateway(settings, transport).anonymize("Иванов", "message")
    assert transport.requests[0].headers["X-Internal-Token"] == "anon-secret-token"
    assert "anon-secret-token" not in json.dumps([tuple(r) for r in conn.execute("SELECT * FROM app_settings")])


def test_old_external_settings_are_ignored(settings, conn):
    """Строки удалённых настроек (DocClean, внешний API) не мешают: профиль docclean работает как встроенный."""
    conn.executemany("INSERT INTO app_settings (key, value) VALUES (?, ?)", [
        ("anon_profile", "docclean"), ("anon_base_url", "https://docclean.example.local"), ("anon_secret_encrypted", "garbage")])
    transport = echo_service()
    assert gateway(settings, transport).anonymize("Иванов", "message").value == "[PERSON_1]"
    assert transport.requests[0].url == "http://anonymizer:8091/v1/anonymize"
    assert "profile" not in settings.all() and "base_url" not in settings.all()


def test_ready_error_explains_what_to_check(settings):
    """Живое состояние для админки: готов — None; недоступен — понятное сообщение с командой проверки."""
    ready = FakeHttpTransport(lambda r: HttpResponse(200, '{"ready":true}'))
    assert gateway(settings, ready).ready_error() is None
    assert ready.requests[0].url == "http://anonymizer:8091/readyz"
    message = gateway(settings, FakeHttpTransport(lambda r: (_ for _ in ()).throw(HttpTransportError("connect", "refused")))).ready_error()
    assert "./scripts/ctl.sh status" in message


def test_internal_http_reuses_one_keep_alive_connection():
    """Сервис обезличивания вызывается на каждое сообщение: соединение и клиент — общие на процесс, а не новые на запрос."""
    import threading
    from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

    from llmp.http import HttpRequest, HttpxTransport

    peers = []

    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def do_POST(self):
            self.rfile.read(int(self.headers["Content-Length"]))
            peers.append(self.client_address)
            self.send_response(200)
            self.send_header("Content-Length", "2")
            self.end_headers()
            self.wfile.write(b"{}")

    srv = ThreadingHTTPServer(("127.0.0.1", 0), H)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    try:
        url = f"http://127.0.0.1:{srv.server_port}/v1/anonymize"
        for _ in range(3):   # новый транспорт на каждый запрос — как LlmModule на каждый HTTP-запрос приложения
            assert HttpxTransport().send(HttpRequest.json(url, {"texts": ["x"]}, {}, 5, 5, None, True)).status == 200
        assert len(peers) == 3 and len(set(peers)) == 1, peers
        with pytest.raises(HttpTransportError) as e:
            HttpxTransport().send(HttpRequest.json(url, {}, {}, 5, 5))        # http без явного разрешения — по-прежнему запрещён
        assert e.value.kind == "scheme"
    finally:
        srv.shutdown()
        srv.server_close()
