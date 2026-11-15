"""Что делать после неудачной попытки (по образцу Hermes Agent: error_classifier, compute_error_backoff, fallback_cooldown,
empty_response_guard), как FailoverPolicy PHP-версии:

  retry — повторить ту же модель (ограниченно, с экспоненциальной задержкой и учётом Retry-After);
  fallback — перейти к следующей модели цепочки;
  cooldown — временно исключить модель из выбора (нарастающая пауза min(60·2ⁿ⁻¹, 4 ч), Retry-After сильнее);
  strip_params — повторить без необязательных параметров (температура);
  needs_larger_context — подходит только резервная модель с бо́льшим окном контекста.

Ошибки прав и настройки (нет доступа, провайдер выключен) не переключают: это не сбой модели."""

from __future__ import annotations

import random

from .errors import LlmException

MAX_RETRIES_PER_MODEL = 2
MAX_ATTEMPTS_TOTAL = 8
MAX_INLINE_WAIT_SECONDS = 8   # дольше внутри запроса пользователя не ждём — сразу резерв, а модель на паузу
COOLDOWN_CAP_SECONDS = 14400


def decide(e: LlmException, attempt: int, consecutive_failures: int) -> dict:
    code = e.error_code
    escalating = int(min(COOLDOWN_CAP_SECONDS, 60 * 2 ** max(0, consecutive_failures - 1)))
    after = min(COOLDOWN_CAP_SECONDS, e.retry_after) if e.retry_after is not None and e.retry_after > 0 else None
    d = {"retry": False, "fallback": True, "cooldown": 0, "strip_params": False, "needs_larger_context": False}
    inline = attempt < MAX_RETRIES_PER_MODEL and (after if after is not None else 1) <= MAX_INLINE_WAIT_SECONDS
    repeated = escalating if consecutive_failures >= 2 else 0
    if code in ("access_denied", "not_found", "disabled", "empty_input", "unsupported_type"):
        d["fallback"] = False
    elif code == "rate_limited":
        d.update(retry=inline, cooldown=after if after is not None else escalating)
    elif code in ("overloaded", "server_error"):
        d.update(retry=inline, cooldown=(after if after is not None else escalating) if consecutive_failures >= 2 else 0)
    elif code in ("empty_response", "invalid_response", "transport_connect", "transport_closed", "transport_other"):
        # пустой ответ HTTP 200 и пр.: один повтор, затем резерв; два подряд — модель на паузу
        d.update(retry=attempt < 1, cooldown=repeated)
    elif code == "transport_timeout":
        d["cooldown"] = repeated   # таймаут ответа дорог: не повторяем ту же модель, сразу резерв
    elif code in ("transport_dns", "transport_tls", "transport_scheme", "auth_failed", "secret_unavailable"):
        d["cooldown"] = 300
    elif code == "billing":
        d["cooldown"] = after if after is not None else 3600
    elif code == "model_not_found":
        d["cooldown"] = 600
    elif code == "unsupported_param":
        d.update(retry=attempt < 1, strip_params=True)
    elif code == "context_overflow":
        d["needs_larger_context"] = True
    # bad_request и прочее: без повтора, резервная модель может принять запрос
    return d


def backoff_seconds(e: LlmException, attempt: int) -> float:
    """Пауза перед повтором: Retry-After, иначе 1, 2, 4 … с джиттером, не дольше MAX_INLINE_WAIT_SECONDS."""
    if e.retry_after is not None and e.retry_after > 0:
        return float(min(MAX_INLINE_WAIT_SECONDS, e.retry_after))
    delay = min(float(MAX_INLINE_WAIT_SECONDS), 2.0 ** attempt)
    return min(float(MAX_INLINE_WAIT_SECONDS), delay + delay * 0.5 * random.random())
