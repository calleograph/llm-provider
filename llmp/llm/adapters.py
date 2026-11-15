"""Адаптеры форматов LLM API: чистое преобразование «сообщения → HttpRequest» и «HttpResponse → LlmResult».

Сетевой вызов, проверка прав и журналирование — в LlmGateway. Новый тип API — свой адаптер и регистрация в
AdapterRegistry.with_defaults(); чат и админка при этом не меняются."""

from __future__ import annotations

from typing import Protocol
from urllib.parse import quote

from ..chat.settings import php_int
from ..http import HttpRequest, HttpResponse
from .errors import LlmException, LlmResult
from .providers import PHP_WS, ProviderConfig


class LlmAdapter(Protocol):
    type: str    # хранится в llm_providers.provider_type
    label: str

    def defaults(self) -> dict[str, str]: ...

    def build_request(self, provider: ProviderConfig, messages: list[dict], system_prompt: str | None, max_tokens: int,
                      ca_bundle_path: str | None) -> HttpRequest: ...

    def parse_response(self, response: HttpResponse) -> LlmResult: ...


def alternate(messages: list[dict]) -> list[dict]:
    """Строгое чередование user/assistant с user в начале: пустые реплики отбрасываются, подряд идущие одной роли
    склеиваются. Нужно Anthropic Messages API и безопасно для остальных."""
    result: list[dict] = []
    for m in messages:
        role = "assistant" if m["role"] == "assistant" else "user"
        content = str(m["content"])
        if content.strip(PHP_WS) == "" or (not result and role == "assistant"):
            continue
        if result and result[-1]["role"] == role:
            result[-1]["content"] += "\n\n" + content
            continue
        result.append({"role": role, "content": content})
    return result


def merge_headers(extra: dict[str, str], auth: dict[str, str]) -> dict[str, str]:
    """Заголовки авторизации приоритетнее дополнительных (без учёта регистра имени)."""
    reserved = {k.lower() for k in auth}
    return {**{k: v for k, v in extra.items() if k.lower() not in reserved}, **auth}


def _php_int(v) -> int | None:
    """isset → (int), как в PHP; None, если значения нет."""
    if v is None:
        return None
    if isinstance(v, (bool, int, float)):
        return int(v)
    return php_int(v)


def _dig(d, *keys):
    for k in keys:
        if isinstance(d, dict):
            d = d.get(k)
        elif isinstance(d, list) and isinstance(k, int) and 0 <= k < len(d):
            d = d[k]
        else:
            return None
    return d


def _error(response: HttpResponse, json) -> LlmException:
    return LlmException.from_http_status(response.status, json, response.body[:300], response.retry_after())


class ChatCompletionsAdapter:
    """Общий формат OpenAI Chat Completions (POST {base}/chat/completions): OpenAI и совместимые API
    (polza.ai, vLLM, LiteLLM, Azure OpenAI и т.п.)."""

    type = ""
    label = ""
    max_tokens_field = "max_tokens"   # у OpenAI — max_completion_tokens

    def build_request(self, provider: ProviderConfig, messages: list[dict], system_prompt: str | None, max_tokens: int,
                      ca_bundle_path: str | None) -> HttpRequest:
        payload_messages = []
        if system_prompt is not None and system_prompt.strip(PHP_WS) != "":
            payload_messages.append({"role": "system", "content": system_prompt})
        payload_messages += alternate(messages)
        payload: dict = {"model": provider.model, "messages": payload_messages, self.max_tokens_field: max_tokens}
        if provider.temperature is not None:
            payload["temperature"] = provider.temperature

        # Маршрутизация Polza/OpenRouter — только для OpenAI-совместимых шлюзов: OpenAI отвечает 400 на поле provider.
        routable = self.type == "openai_compatible"
        if routable and provider.routing_provider is not None:
            payload["provider"] = {"only": [provider.routing_provider]}
        elif routable and provider.sort_by_price:
            # самый дешёвый доступный маршрут; max_price (₽ за млн токенов) отсекает дорогие по обоим направлениям
            options: dict = {"sort": "price", "allow_fallbacks": provider.allow_fallbacks}
            if provider.max_price_per_mtoken is not None:
                options["max_price"] = {"prompt": provider.max_price_per_mtoken, "completion": provider.max_price_per_mtoken}
            payload["provider"] = options

        url = provider.url()
        if provider.api_version is not None:
            # Azure OpenAI и ряд шлюзов требуют версию API в query string
            url += ("&" if "?" in url else "?") + "api-version=" + quote(provider.api_version, safe="")
        auth = {"Authorization": "Bearer " + provider.api_key} if provider.api_key != "" else {}
        return HttpRequest.json(url, payload, merge_headers(provider.extra_headers, auth), provider.connect_timeout, provider.response_timeout, ca_bundle_path)

    def parse_response(self, response: HttpResponse) -> LlmResult:
        json = response.json()
        if not response.is_success():
            raise _error(response, json)
        choice = _dig(json, "choices", 0)
        if not isinstance(json, dict) or not isinstance(choice, (dict, list)):
            raise LlmException("invalid_response", "Ответ не содержит choices[0].")
        content = _dig(choice, "message", "content")
        if content is None:
            content = ""
        if isinstance(content, (list, dict)):
            # формат «content parts»: [{type: text, text: …}, …]
            parts = content.values() if isinstance(content, dict) else content
            content = "".join(p["text"] for p in parts if isinstance(p, dict) and isinstance(p.get("text"), str))
        if not isinstance(content, str):
            raise LlmException("invalid_response", "Поле message.content имеет неожиданный тип.")
        finish = _dig(choice, "finish_reason")
        return LlmResult(
            text=content,
            model=json["model"] if isinstance(json.get("model"), str) else None,
            input_tokens=_php_int(_dig(json, "usage", "prompt_tokens")),
            output_tokens=_php_int(_dig(json, "usage", "completion_tokens")),
            finish_reason=finish if isinstance(finish, str) else None,
        )


class OpenAiAdapter(ChatCompletionsAdapter):
    type = "openai"
    label = "OpenAI API"
    max_tokens_field = "max_completion_tokens"

    def defaults(self) -> dict[str, str]:
        return {"base_url": "https://api.openai.com/v1", "endpoint": "/chat/completions", "api_version": "", "model_placeholder": "gpt-4o"}


class OpenAiCompatibleAdapter(ChatCompletionsAdapter):
    """Любой сервис с OpenAI-совместимым API, например polza.ai: модель «openai/gpt-4o» или «anthropic/claude-3-5-sonnet»."""

    type = "openai_compatible"
    label = "OpenAI-compatible API"
    max_tokens_field = "max_tokens"

    def defaults(self) -> dict[str, str]:
        return {"base_url": "https://polza.ai/api/v1", "endpoint": "/chat/completions", "api_version": "", "model_placeholder": "openai/gpt-4o"}


class AnthropicAdapter:
    """Anthropic Messages API: POST {base}/v1/messages, заголовки x-api-key и anthropic-version, system — отдельным полем."""

    type = "anthropic"
    label = "Anthropic API"
    DEFAULT_VERSION = "2023-06-01"

    def defaults(self) -> dict[str, str]:
        return {"base_url": "https://api.anthropic.com", "endpoint": "/v1/messages", "api_version": self.DEFAULT_VERSION, "model_placeholder": "claude-sonnet-5"}

    def build_request(self, provider: ProviderConfig, messages: list[dict], system_prompt: str | None, max_tokens: int,
                      ca_bundle_path: str | None) -> HttpRequest:
        payload: dict = {"model": provider.model, "max_tokens": max_tokens, "messages": alternate(messages)}
        if system_prompt is not None and system_prompt.strip(PHP_WS) != "":
            payload["system"] = system_prompt
        if provider.temperature is not None:
            payload["temperature"] = min(1.0, provider.temperature)
        auth = {"anthropic-version": provider.api_version if provider.api_version is not None else self.DEFAULT_VERSION}
        if provider.api_key != "":
            auth["x-api-key"] = provider.api_key
        return HttpRequest.json(provider.url(), payload, merge_headers(provider.extra_headers, auth), provider.connect_timeout, provider.response_timeout, ca_bundle_path)

    def parse_response(self, response: HttpResponse) -> LlmResult:
        json = response.json()
        if not response.is_success():
            raise _error(response, json)
        content = json.get("content") if isinstance(json, dict) else None
        if not isinstance(content, (list, dict)):
            raise LlmException("invalid_response", "Ответ не содержит массива content.")
        blocks = content.values() if isinstance(content, dict) else content
        text = "".join(b["text"] for b in blocks if isinstance(b, dict) and b.get("type", "") == "text" and isinstance(b.get("text"), str))
        stop = json.get("stop_reason")
        return LlmResult(
            text=text,
            model=json["model"] if isinstance(json.get("model"), str) else None,
            input_tokens=_php_int(_dig(json, "usage", "input_tokens")),
            output_tokens=_php_int(_dig(json, "usage", "output_tokens")),
            finish_reason=stop if isinstance(stop, str) else None,
        )


class AdapterRegistry:
    def __init__(self) -> None:
        self.adapters: dict[str, LlmAdapter] = {}

    @classmethod
    def with_defaults(cls) -> "AdapterRegistry":
        r = cls()
        for a in (OpenAiAdapter(), AnthropicAdapter(), OpenAiCompatibleAdapter()):
            r.register(a)
        return r

    def register(self, adapter: LlmAdapter) -> None:
        self.adapters[adapter.type] = adapter

    def has(self, ptype: str) -> bool:
        return ptype in self.adapters

    def get(self, ptype: str) -> LlmAdapter:
        if ptype not in self.adapters:
            raise LlmException("unsupported_type", "Тип провайдера не поддерживается: " + ptype)
        return self.adapters[ptype]

    def all(self) -> dict[str, LlmAdapter]:
        return dict(self.adapters)
