"""Перенос tests/LlmInternalAnonymizationTest.php на уровне шлюза: встроенный сервис обезличивания — совместная
обработка сообщения и вложений одним вызовом, строгая проверка ответа, fail closed, маскирование секретов.
Сценарии через ChatService::send (отказ 503 anonymization_failed, проекты, ответы на необезличенные сообщения)
переносятся на этапе чата — см. tests/parity/anon_files.md."""

from __future__ import annotations

import json

import pytest

from kit_anon import anon_settings, dumps, file_settings
from llmp.anon.gateway import AnonymizationException, AnonymizationGateway
from llmp.anon.settings import AnonymizationSettings
from llmp.files import FileProcessor
from llmp.http import FakeHttpTransport, HttpResponse
from llmp.logs import DiagnosticLogger


def _replace(t: str) -> str:
    for a, b in (("Петров Сергей", "Сергей П."), ("Петрову Сергею", "Сергею П."), ("S3cr3t-Value-77", "[секрет удалён]")):
        t = t.replace(a, b)
    return t


class Env:
    def __init__(self, conn):
        self.conn = conn
        self.settings = anon_settings(conn)
        self.anonymizer = lambda body: HttpResponse(200, dumps({"texts": [_replace(t) for t in body["texts"]], "stats": {"person": 2},
                                                                "replaced": 2, "warnings": [], "verified": True}))

        def handler(r):
            if r.url.endswith("/readyz"):
                return HttpResponse(200, '{"ready":true}')
            return self.anonymizer(json.loads(r.body))

        self.transport = FakeHttpTransport(handler)
        self.gateway = AnonymizationGateway(self.settings, self.transport, DiagnosticLogger(conn))


@pytest.fixture
def env(conn, monkeypatch):
    monkeypatch.setenv("ANONYMIZER_URL", "http://anonymizer:8091")
    monkeypatch.setenv("INTERNAL_API_TOKEN", "test-internal-token")
    return Env(conn)


def test_service_is_configured_from_environment(env, monkeypatch):
    """Встроенный сервис — единственный; настроен из окружения."""
    assert env.settings.is_configured()
    monkeypatch.delenv("ANONYMIZER_URL")
    assert not env.settings.is_configured()


def test_message_and_attachment_go_in_one_call_with_one_session_of_replacements(env, conn, tmp_path):
    """Сообщение и текст вложения — одним вызовом /v1/anonymize; в журнале только категории и счётчики."""
    src = tmp_path / "note.txt"
    src.write_text("Отчёт подготовил Петров Сергей.")
    attachment = FileProcessor.with_defaults(file_settings(conn), str(tmp_path / "work")).process(str(src), "note.txt", src.stat().st_size, False)

    batch = env.gateway.anonymize_many(["Напиши Петрову Сергею", attachment.text], "message")

    calls = env.transport.requests_to("/v1/anonymize")
    assert len(calls) == 1, "сообщение и вложение — одним вызовом"
    body = json.loads(calls[0].body)
    assert body["texts"] == ["Напиши Петрову Сергею", "Отчёт подготовил Петров Сергей."]
    assert body["profile"] == "basic"
    assert calls[0].headers["X-Internal-Token"] == "test-internal-token"
    assert calls[0].url == "http://anonymizer:8091/v1/anonymize"
    assert [t.value for t in batch.texts] == ["Напиши Сергею П.", "Отчёт подготовил Сергей П.."]
    assert batch.stats == {"person": 2} and batch.replaced == 2

    log = json.dumps([dict(r) for r in conn.execute("SELECT * FROM diagnostic_log")], ensure_ascii=False)
    assert "Петров" not in log
    assert "person:2" in log, "в журнале — категории и число замен"


@pytest.mark.parametrize("response,code", [
    (HttpResponse(422, '{"detail":{"code":"verification_failed","message":"leak"}}'), "not_clean"),
    (HttpResponse(503, '{"detail":"nlp_unavailable"}'), "nlp_unavailable"),
    (HttpResponse(500, '{"detail":"anonymizer_failed"}'), "anonymizer_failed"),
    (HttpResponse(200, '{"texts":["x"],"verified":false}'), "invalid_json"),
    (HttpResponse(200, '{"texts":[],"verified":true}'), "invalid_json"),
    (HttpResponse(200, '{"texts":["   "],"verified":true}'), "empty_result"),
    (HttpResponse(200, "<html>"), "invalid_json"),
], ids=["verification failed", "models not ready", "engine error", "not verified", "wrong count", "empty result", "not json"])
def test_any_failure_blocks_the_request_to_the_llm(env, response, code):
    """Любой сбой встроенного сервиса — AnonymizationException (уровень шлюза; отказ ChatService — этап чата)."""
    env.anonymizer = lambda b: response
    with pytest.raises(AnonymizationException) as e:
        env.gateway.anonymize_many(["Напиши Петрову Сергею"], "message")
    assert e.value.error_code == code
    assert "запрос не отправлен" in e.value.user_message()


def test_verification_failure_maps_to_not_clean(env):
    """verification_failed сервиса — код not_clean."""
    env.anonymizer = lambda b: HttpResponse(422, '{"detail":{"code":"verification_failed","message":"leak"}}')
    with pytest.raises(AnonymizationException) as e:
        env.gateway.anonymize_many(["a"], "check")
    assert e.value.error_code == "not_clean"


def test_unanonymized_send_still_masks_secrets(env):
    """Без обезличивания — профиль custom со всеми категориями keep: скрываются только секреты."""
    batch = env.gateway.mask_secrets(["Петров Сергей, пароль S3cr3t-Value-77"], "message")
    call = json.loads(env.transport.requests_to("/v1/anonymize")[0].body)
    assert call["profile"] == "custom"
    assert call["settings"]["custom_actions"]["person"] == "keep", "без обезличивания — категории остаются, только секреты"
    assert "S3cr3t-Value-77" not in batch.texts[0].value


def test_mask_secrets_without_internal_service_keeps_text(env, monkeypatch):
    """Без встроенного сервиса (ANONYMIZER_URL не задан) текст не меняется и запросов нет."""
    monkeypatch.delenv("ANONYMIZER_URL")
    assert env.gateway.mask_secrets(["пароль S3cr3t-Value-77"], "message").texts[0].value == "пароль S3cr3t-Value-77"
    assert env.transport.requests == []


def test_engine_settings_are_built_from_admin_fields(env):
    """Словарь, исключения, домены и действия по категориям собираются из полей админки; секреты оставить нельзя."""
    env.settings.save({"internal_terms": "Альбатрос;project;\nCRM;project;Система учёта", "internal_exceptions": "Ромашка\n",
                       "internal_domains": "corp.example.local", "internal_custom_actions": '{"location":"pseudonym"}'})
    engine = env.settings.internal_engine_settings()
    assert engine["terms"] == [{"term": "Альбатрос", "category": "project", "replacement": ""},
                               {"term": "CRM", "category": "project", "replacement": "Система учёта"}]
    assert engine["exceptions"] == ["Ромашка"]
    assert engine["custom_actions"] == {"location": "pseudonym"}
    assert AnonymizationSettings.validate({"internal_profile": "custom", "internal_custom_actions": '{"person":"mask"}'}) == {}
    assert "internal_custom_actions" in AnonymizationSettings.validate(
        {"internal_profile": "basic", "internal_custom_actions": '{"secret":"keep"}'}), "секреты нельзя оставить"


def test_internal_connection_check_uses_readyz(env):
    """Проверка связи встроенного сервиса: /readyz, затем пробное обезличивание; результат записан в настройки."""
    result = env.gateway.test_connection()
    assert result["ok"], result["message"]
    assert env.transport.requests[0].url == "http://anonymizer:8091/readyz"
    assert env.settings.get("last_check_ok") == "1"
