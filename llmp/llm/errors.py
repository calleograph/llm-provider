"""Ошибка обращения к LLM и результат запроса (как LlmException/LlmResult версии 1.x).

error_code безопасен для журнала: not_found | disabled | access_denied | unsupported_type | secret_unavailable |
auth_failed | billing | rate_limited | overloaded | server_error | bad_request | context_overflow | unsupported_param |
model_not_found | invalid_response | empty_response | content_refused | empty_input | all_failed | transport_<kind>.
От кода зависит, повторять ли запрос, переходить ли на резервную модель и ставить ли паузу (FailoverPolicy).
Текст исключения может содержать ответ провайдера — показывается только администратору и не журналируется."""

from __future__ import annotations

import re
from dataclasses import dataclass, replace

_CONTEXT_RE = re.compile(r"context.{0,20}(length|window)|maximum context|too many tokens|prompt is too long|token limit|exceeds the (model|maximum)")
_UNSUPPORTED_RE = re.compile(r"unsupported|not supported|does not support|unrecognized|unknown (parameter|field|argument)|invalid.{0,10}(temperature|parameter)")


def _scalar_str(v) -> str | None:
    """isset && is_scalar → (string), как в PHP; иначе None."""
    if isinstance(v, bool):
        return "1" if v else ""
    if isinstance(v, (int, float, str)):
        return str(v)
    return None


class LlmException(RuntimeError):
    def __init__(self, error_code: str, technical_message: str = "", http_status: int | None = None,
                 provider_error_type: str | None = None, retry_after: int | None = None):
        super().__init__(technical_message if technical_message != "" else error_code)
        self.error_code = error_code
        self.http_status = http_status
        self.provider_error_type = provider_error_type
        self.retry_after = retry_after   # Retry-After провайдера, секунд

    @property
    def message(self) -> str:
        return str(self)

    def user_message(self) -> str:
        c = self.error_code
        if c in ("access_denied", "not_found", "disabled"):
            return "Выбранная нейросеть вам недоступна."
        if c == "auth_failed":
            return "Внешний сервис отклонил ключ доступа. Обратитесь к администратору."
        if c in ("rate_limited", "overloaded"):
            return "Внешний сервис перегружен или исчерпан лимит. Повторите позже."
        if c == "billing":
            return "У внешнего сервиса исчерпан оплаченный лимит. Обратитесь к администратору."
        if c == "content_refused":
            return "Нейросеть отказалась отвечать на этот запрос (фильтр содержимого провайдера). Переформулируйте запрос."
        if c == "empty_response":
            return "Нейросеть вернула пустой ответ. Повторите запрос или выберите другую модель."
        if c == "context_overflow":
            return "Диалог и материалы не помещаются в контекст модели. Начните новый чат или уберите часть источников."
        if c == "all_failed":
            return "Все доступные вам нейросети сейчас не отвечают. Сообщение не потеряно — повторите попытку позже."
        if c == "transport_timeout":
            return "Нейросеть не ответила вовремя. Повторите запрос позже."
        if c.startswith("transport_"):
            return "Нет связи с внешним сервисом нейросети."
        if c == "bad_request":
            return "Внешний сервис отклонил запрос (возможно, слишком длинный диалог или настройки маршрутизации нейросети слишком строгие — сообщите администратору)."
        return "Не удалось получить ответ нейросети."

    @classmethod
    def from_http_status(cls, status: int, json: dict | list | None, body_excerpt: str, retry_after: int | None = None) -> "LlmException":
        j = json if isinstance(json, dict) else {}
        error = j.get("error") if isinstance(j.get("error"), dict) else {}
        etype = _scalar_str(error.get("type"))
        if etype is None:
            etype = _scalar_str(error.get("code"))
        if isinstance(error.get("message"), str):
            message = error["message"]
        else:
            message = j["message"] if isinstance(j.get("message"), str) else body_excerpt
        hay = ((etype or "") + " " + message).lower()
        if status in (401, 403):
            code = "auth_failed"
        elif status == 402 or "insufficient_quota" in hay or "billing" in hay or "credit balance" in hay:
            code = "billing"
        elif status == 413 or _CONTEXT_RE.search(hay):
            code = "context_overflow"
        elif status == 429:
            code = "rate_limited"
        elif status == 529 or "overloaded" in hay:
            code = "overloaded"
        elif status == 408:
            code = "transport_timeout"
        elif status >= 500:
            code = "server_error"
        elif status == 404:
            code = "model_not_found"
        elif _UNSUPPORTED_RE.search(hay):
            code = "unsupported_param"
        else:
            code = "bad_request"
        return cls(code, f"HTTP {status}: {message[:300]}", status, etype[:60] if etype is not None else None, retry_after)


@dataclass(frozen=True)
class LlmResult:
    text: str
    model: str | None = None
    input_tokens: int | None = None
    output_tokens: int | None = None
    finish_reason: str | None = None
    duration_ms: int = 0
    model_id: int | None = None        # llm_models.id ответившей модели (после резервирования может отличаться от выбранной)
    provider_id: int | None = None
    provider_name: str | None = None
    fallback_from: str | None = None   # название выбранной модели, если ответила резервная
    attempts: int = 1

    def with_duration(self, duration_ms: int) -> "LlmResult":
        return replace(self, duration_ms=duration_ms)

    def with_route(self, model_id: int | None, provider_id: int, provider_name: str, fallback_from: str | None, attempts: int) -> "LlmResult":
        return replace(self, model_id=model_id, provider_id=provider_id, provider_name=provider_name, fallback_from=fallback_from, attempts=attempts)

    def truncated(self) -> bool:
        """Генерация остановлена лимитом длины ответа — ответ есть, но может быть неполным."""
        return self.finish_reason in ("length", "max_tokens")
