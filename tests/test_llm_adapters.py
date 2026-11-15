"""Адаптеры LLM API, нормализация истории, реестр типов и проверка формы провайдера (tests/LlmAdaptersTest.php)."""

from __future__ import annotations

import json

import pytest

from llmp.http import HttpResponse
from llmp.llm.adapters import AdapterRegistry, AnthropicAdapter, OpenAiAdapter, OpenAiCompatibleAdapter, alternate
from llmp.llm.errors import LlmException
from llmp.llm.providers import ProviderConfig, ProviderValidator


def provider(ptype: str, **o) -> ProviderConfig:
    return ProviderConfig(1, "Test", ptype, o.get("base_url", "https://polza.ai/api/v1"), o.get("endpoint", "/chat/completions"), "sk-secret",
                          "openai/gpt-4o", o.get("api_version"), 5, 60, 256, o.get("extra_headers", {}), False, True)


def test_openai_compatible_request_matches_polza_example():
    """Запрос OpenAI-совместимого API совпадает с примером polza.ai."""
    request = OpenAiCompatibleAdapter().build_request(provider("openai_compatible"), [{"role": "user", "content": "Что думаешь об этой жизни?"}],
                                                      "Системная инструкция", 256, None)
    payload = json.loads(request.body)
    assert request.url == "https://polza.ai/api/v1/chat/completions"
    assert request.headers["Authorization"] == "Bearer sk-secret"
    assert payload["model"] == "openai/gpt-4o"
    assert payload["max_tokens"] == 256
    assert payload["messages"] == [
        {"role": "system", "content": "Системная инструкция"},
        {"role": "user", "content": "Что думаешь об этой жизни?"},
    ]


def test_openai_uses_max_completion_tokens_and_api_version_query():
    """OpenAI: поле max_completion_tokens и версия API в query string."""
    request = OpenAiAdapter().build_request(provider("openai", base_url="https://api.openai.com/v1", api_version="2024-06-01"),
                                            [{"role": "user", "content": "x"}], None, 100, None)
    assert "max_completion_tokens" in json.loads(request.body)
    assert request.url.endswith("?api-version=2024-06-01")


def test_extra_headers_cannot_override_authorization():
    """Дополнительные заголовки не подменяют Authorization."""
    request = OpenAiAdapter().build_request(provider("openai", extra_headers={"authorization": "Bearer evil", "X-Org": "corp"}),
                                            [{"role": "user", "content": "x"}], None, 10, None)
    assert request.headers["Authorization"] == "Bearer sk-secret"
    assert "authorization" not in request.headers
    assert request.headers["X-Org"] == "corp"


def test_chat_completions_response_parsing():
    """Разбор ответа Chat Completions: текст, токены, finish_reason."""
    result = OpenAiCompatibleAdapter().parse_response(HttpResponse(200, json.dumps({
        "model": "openai/gpt-4o",
        "choices": [{"message": {"role": "assistant", "content": "Ответ"}, "finish_reason": "stop"}],
        "usage": {"prompt_tokens": 10, "completion_tokens": 3},
    })))
    assert result.text == "Ответ"
    assert result.input_tokens == 10
    assert result.finish_reason == "stop"


def test_anthropic_request_and_response():
    """Anthropic: заголовки, system отдельным полем, чередование ролей; разбор блоков content."""
    adapter = AnthropicAdapter()
    request = adapter.build_request(
        provider("anthropic", base_url="https://api.anthropic.com", endpoint="/v1/messages", api_version="2023-06-01"),
        [{"role": "assistant", "content": "лишнее начало"}, {"role": "user", "content": "Первый"}, {"role": "user", "content": "Второй"}],
        "Система", 300, None,
    )
    payload = json.loads(request.body)
    assert request.url == "https://api.anthropic.com/v1/messages"
    assert request.headers["x-api-key"] == "sk-secret"
    assert request.headers["anthropic-version"] == "2023-06-01"
    assert payload["system"] == "Система"
    assert payload["max_tokens"] == 300
    assert payload["messages"] == [{"role": "user", "content": "Первый\n\nВторой"}]

    result = adapter.parse_response(HttpResponse(200, json.dumps({
        "model": "claude-sonnet-5",
        "content": [{"type": "text", "text": "Привет"}, {"type": "text", "text": "!"}],
        "stop_reason": "end_turn",
        "usage": {"input_tokens": 7, "output_tokens": 2},
    })))
    assert result.text == "Привет!"
    assert result.output_tokens == 2


@pytest.mark.parametrize("status,code", [(401, "auth_failed"), (429, "rate_limited"), (400, "bad_request"), (503, "server_error")])
def test_http_errors_are_classified(status, code):
    """HTTP-ошибки классифицируются по коду; тип ошибки провайдера сохраняется."""
    with pytest.raises(LlmException) as exc:
        OpenAiAdapter().parse_response(HttpResponse(status, '{"error":{"message":"m","type":"t"}}'))
    assert exc.value.error_code == code
    assert exc.value.provider_error_type == "t"


def test_unexpected_success_body_is_invalid_response():
    """Тело 200 без ожидаемой структуры — ошибка."""
    with pytest.raises(LlmException):
        AnthropicAdapter().parse_response(HttpResponse(200, '{"choices":[]}'))


def test_normalizer_alternates_roles():
    """Нормализатор: склейка подряд идущих ролей, пустые реплики отброшены."""
    assert alternate([
        {"role": "user", "content": "a"},
        {"role": "assistant", "content": "b"},
        {"role": "assistant", "content": "c"},
        {"role": "user", "content": " "},
        {"role": "user", "content": "d"},
    ]) == [{"role": "user", "content": "a"}, {"role": "assistant", "content": "b\n\nc"}, {"role": "user", "content": "d"}]


def test_registry_knows_required_types():
    """Реестр знает обязательные типы; неизвестный — исключение."""
    registry = AdapterRegistry.with_defaults()
    for ptype in ("openai", "anthropic", "openai_compatible"):
        assert registry.has(ptype)
    with pytest.raises(LlmException):
        registry.get("unknown")


def test_provider_validation():
    """Нормализация и проверка формы провайдера."""
    registry = AdapterRegistry.with_defaults()
    valid = ProviderValidator.normalize({
        "name": "Polza", "provider_type": "openai_compatible", "base_url": "https://polza.ai/api/v1/",
        "endpoint": "/chat/completions", "model": "openai/gpt-4o", "connect_timeout": 10, "response_timeout": 120, "max_tokens": 2048,
    })
    assert valid["base_url"] == "https://polza.ai/api/v1"
    assert ProviderValidator.validate(valid, registry) == {}

    invalid = ProviderValidator.validate({**valid, "base_url": "http://insecure.example", "endpoint": "chat", "model": "bad model"}, registry)
    assert "base_url" in invalid
    assert "endpoint" in invalid
    assert "model" in invalid


def test_header_parsing():
    """Разбор дополнительных заголовков: формат, запрещённые имена, пустое значение."""
    headers, error = ProviderValidator.parse_headers("X-Org: corp\nX-Project: llm")
    assert error is None
    assert headers == {"X-Org": "corp", "X-Project": "llm"}

    assert ProviderValidator.parse_headers("Host: evil")[1] is not None
    assert ProviderValidator.parse_headers("broken line")[1] is not None
    assert ProviderValidator.parse_headers("X-A: b\r\nX-B:")[1] is not None
