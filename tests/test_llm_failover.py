"""Резервирование моделей на заглушках LLM, уровень шлюза (tests/LlmFailoverTest.php).

Проверяется: что считается ошибкой генерации, порядок переключения, учёт прав, паузы, Retry-After, отсутствие
бесконечных повторов. Проверки сохранённых сообщений, тарификации и кодов ChatException — этап чата (см. parity)."""

from __future__ import annotations

import json
import time
from email.utils import formatdate

import pytest

from kit_llm import FailoverBench, ok
from llmp.http import HttpResponse, HttpTransportError
from llmp.llm import failover
from llmp.llm.errors import LlmException


@pytest.fixture
def b():
    bench = FailoverBench()
    yield bench
    bench.conn.close()


def test_empty_content_with_http200_is_a_generation_error_and_backup_answers(b):
    """Пустой content при HTTP 200 — ошибка генерации: один повтор, затем отвечает резервная модель."""
    b.script["primary.example.com"] = [ok(""), ok("   \n")]
    result = b.send()
    assert result.text == "ответ backup.example.com"
    assert result.provider_name == "Backup"
    assert result.fallback_from == "openai/gpt-4o", "пользователь видит, что ответила резервная модель"
    assert b.calls_to("primary") == 2, "пустой ответ: один повтор, затем резерв"
    assert b.calls_to("forbidden") == 0, "модель без прав пользователя в цепочке пропускается"
    stats = b.health.stats()
    primary_model = b.models.default_for(b.primary)
    assert int(stats[primary_model]["empty_count"]) == 2
    assert int(stats[primary_model]["fallback_count"]) == 1


def test_content_filter_refusal_is_not_a_model_failure(b):
    """Отказ фильтра содержимого — не сбой модели: без повтора, резерва и паузы."""
    b.script["primary.example.com"] = [ok("", "content_filter")]
    with pytest.raises(LlmException) as exc:
        b.send()
    assert exc.value.error_code == "content_refused"
    assert b.calls_to("primary") == 1, "без повтора"
    assert b.calls_to("backup") == 0, "отказ по содержимому не уходит в резерв"
    primary_model = b.models.default_for(b.primary)
    assert b.health.cooling_until(primary_model) == 0, "модель не ставится на паузу для всех"
    assert b.health.consecutive_failures(primary_model) == 0


def test_rate_limit_honours_retry_after_then_falls_back_and_cools_down(b):
    """429: Retry-After учитывается, затем резерв и пауза модели; следующий запрос сразу идёт в резерв."""
    slow = '{"error":{"message":"slow down"}}'
    b.script["primary.example.com"] = [
        HttpResponse(429, slow, 0, {"retry-after": "2"}),
        HttpResponse(429, slow, 0, {"retry-after": "2"}),
        HttpResponse(429, slow, 0, {"retry-after": "120"}),
    ]
    result = b.send()
    assert result.provider_name == "Backup"
    assert b.sleeps == [2.0, 2.0], "Retry-After учтён; длинное ожидание не держит запрос пользователя"
    primary_model = b.models.default_for(b.primary)
    assert b.health.cooling_until(primary_model) > time.time() + 60, "модель на паузе по Retry-After"

    # Следующий запрос: модель на паузе уходит в конец цепочки — сразу отвечает резервная.
    b.transport.requests.clear()
    again = b.send()
    assert again.provider_name == "Backup"
    assert b.calls_to("primary") == 0


@pytest.mark.parametrize("script,code", [
    ([HttpResponse(502, "bad gateway"), HttpResponse(500, "{}"), HttpResponse(503, "{}")], "server_error"),
    ([HttpTransportError("timeout", "curl errno=28")], "transport_timeout"),
    ([HttpTransportError("closed", "curl errno=56"), HttpTransportError("closed", "curl errno=18")], "transport_closed"),
    ([HttpResponse(200, "<html>oops"), HttpResponse(200, '{"choices":null}')], "invalid_response"),
    ([HttpResponse(400, '{"error":{"message":"weird"}}')], "bad_request"),
], ids=["5xx", "timeout", "connection dropped", "malformed body", "bad request"])
def test_transient_failures_switch_to_backup(b, script, code):
    """Временные сбои переключают на резервную модель с ограниченным числом повторов."""
    b.script["primary.example.com"] = script
    result = b.send()
    assert result.provider_name == "Backup"
    row = b.conn.execute("SELECT code, action FROM llm_model_incidents WHERE action = 'fallback'").fetchone()
    assert row["code"] == code
    assert b.calls_to("primary") <= failover.MAX_RETRIES_PER_MODEL + 1


def test_all_models_down_gives_clear_error_and_saves_nothing(b):
    """Все модели недоступны: понятная ошибка all_failed, число попыток ограничено, инциденты записаны."""
    b.script["primary.example.com"] = [HttpResponse(503, "{}") for _ in range(10)]
    b.script["backup.example.com"] = [ok("") for _ in range(10)]
    with pytest.raises(LlmException) as exc:
        b.send()
    assert exc.value.error_code == "all_failed"
    assert "не отвечают" in exc.value.user_message()
    assert len(b.transport.requests_to("example.com")) <= failover.MAX_ATTEMPTS_TOTAL, "число попыток ограничено"
    assert b.conn.execute("SELECT COUNT(*) FROM llm_model_incidents").fetchone()[0] >= 2, "журнал проблемных моделей"
    # шлюз ничего не сохраняет и не тарифицирует; проверка на уровне ChatService — этап чата
    assert b.conn.execute("SELECT COUNT(*) FROM llm_messages").fetchone()[0] == 0
    assert b.alerts and b.alerts[-1][0] == "all_failed"


def test_no_backup_configured_returns_the_real_reason(b):
    """Без резервных моделей пользователь получает настоящую причину."""
    b.set_chain(b.primary, [])
    b.script["primary.example.com"] = [ok(""), ok("")]
    with pytest.raises(LlmException) as exc:
        b.send()
    assert exc.value.error_code == "empty_response"


def test_context_overflow_goes_only_to_a_larger_model(b):
    """Переполнение контекста — только модель с бо́льшим окном."""
    m = b.models
    b.conn.execute("UPDATE llm_models SET context_window = 8000 WHERE id = ?", (m.default_for(b.primary),))
    b.conn.execute("UPDATE llm_models SET context_window = 8000 WHERE id = ?", (m.default_for(b.backup),))
    big_id = m.create({"provider_id": b.backup, "model": "big-128k", "display_name": "Большая", "context_window": 128000,
                       "max_output_tokens": 2048, "is_enabled": 1, "fallback_ids": []})
    b.conn.execute("UPDATE llm_models SET fallback_ids = ? WHERE id = ?", (json.dumps([m.default_for(b.backup), big_id]), m.default_for(b.primary)))
    b.script["primary.example.com"] = [HttpResponse(400, '{"error":{"message":"This model\'s maximum context length is 8192 tokens"}}')]

    result = b.send()
    body = json.loads(b.transport.requests_to("backup.example.com")[0].body)
    assert body["model"] == "big-128k", "модель с тем же окном пропущена, выбрана с бо́льшим"
    assert result.provider_name == "Backup"


def test_unsupported_parameter_is_retried_without_it(b):
    """Неподдерживаемый параметр — повтор без него на той же модели."""
    b.conn.execute("UPDATE llm_models SET temperature = 0.3 WHERE id = ?", (b.models.default_for(b.primary),))
    b.script["primary.example.com"] = [HttpResponse(400, '{"error":{"message":"Unsupported parameter: temperature"}}')]
    result = b.send()
    assert result.provider_name == "Primary"
    calls = b.transport.requests_to("primary.example.com")
    assert json.loads(calls[0].body)["temperature"] == 0.3
    assert "temperature" not in json.loads(calls[1].body)


def test_auth_and_access_errors_are_classified_separately():
    """Классификация: права, ключ, контекст, нарастающая пауза, billing/overloaded, Retry-After."""
    assert not failover.decide(LlmException("access_denied"), 0, 1)["fallback"], "нет прав — не сбой модели"
    assert failover.decide(LlmException("auth_failed"), 0, 1)["cooldown"] == 300
    assert failover.decide(LlmException("context_overflow"), 0, 1)["needs_larger_context"]
    assert failover.decide(LlmException("rate_limited", "", 429), 5, 3)["cooldown"] == 240, "нарастающая пауза 60·2ⁿ⁻¹"
    assert failover.decide(LlmException("rate_limited", "", 429), 5, 40)["cooldown"] == failover.COOLDOWN_CAP_SECONDS
    assert LlmException.from_http_status(429, {"error": {"code": "insufficient_quota", "message": "x"}}, "").error_code == "billing"
    assert LlmException.from_http_status(529, None, "overloaded").error_code == "overloaded"
    assert HttpResponse(429, "", 0, {"retry-after": "30"}).retry_after() == 30
    assert HttpResponse(429, "", 0, {"retry-after": formatdate(1_000_010, usegmt=True)}).retry_after(1_000_000) == 10


def test_truncated_answer_is_accepted_and_flagged(b):
    """Ответ, обрезанный лимитом длины, принимается и помечается."""
    b.script["primary.example.com"] = [ok("Начало ответа…", "length")]
    result = b.send()
    assert result.provider_name == "Primary"
    assert result.truncated()
    assert result.fallback_from is None


def test_explicit_model_must_belong_to_allowed_provider(b):
    """Модель недоступного провайдера выбрать нельзя."""
    foreign = b.models.default_for(b.forbidden)
    with pytest.raises(LlmException) as exc:
        b.send(model_id=foreign)
    assert exc.value.error_code == "access_denied"


def test_gateway_rejects_plain_text_history(b):
    """Шлюз принимает только AnonymizedText (дополнительно к PHP-набору: защита типов из LlmGateway::plainMessages)."""
    allowed = b.allowed_models()
    with pytest.raises(LlmException) as exc:
        b.gateway.complete_with_failover(b.user, b.models.default_for(b.primary), [{"role": "user", "content": "сырой текст"}], None, None, allowed)
    assert exc.value.error_code == "empty_input"
    assert b.transport.requests == []
