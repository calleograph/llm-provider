"""ЕДИНСТВЕННАЯ точка обращения приложения к внешним LLM API (как LlmGateway PHP-версии).

- принимает только AnonymizedText — необезличенную строку передать нельзя (empty_input);
- повторно проверяет право пользователя на провайдера (AccessPolicy), даже если вызывающий код уже проверил;
- выбирает адаптер по типу провайдера из AdapterRegistry;
- пишет в диагностический журнал только метаданные (провайдер, модель, статус, токены, длительность), без текстов."""

from __future__ import annotations

import time
from typing import Callable

from ..anon.text import AnonymizedText
from ..chat.core import ChatUser
from ..http import HttpTransport, HttpTransportError
from ..logs import DiagnosticLogger
from . import failover
from .access import AccessPolicy
from .adapters import AdapterRegistry
from .errors import LlmException, LlmResult
from .health import ModelHealthRepository
from .models import ModelRepository
from .providers import PHP_WS, ProviderConfig, ProviderRepository

SOURCE = "llm_gateway"
_REFUSAL_REASONS = ("content_filter", "refusal", "safety", "recitation")

_ADMIN_PREFIX = {
    "auth_failed": "Ключ отклонён",
    "rate_limited": "Лимит запросов (HTTP 429)",
    "overloaded": "Провайдер перегружен",
    "billing": "Исчерпан оплаченный лимит",
    "empty_response": "Пустой ответ при HTTP 200",
    "context_overflow": "Превышено контекстное окно",
    "unsupported_param": "Неподдерживаемый параметр",
    "model_not_found": "Модель не найдена у провайдера",
    "transport_closed": "Соединение оборвалось",
    "all_failed": "Все модели недоступны",
    "bad_request": "Запрос отклонён (проверьте модель/endpoint)",
    "server_error": "Ошибка на стороне провайдера",
    "invalid_response": "Неожиданный формат ответа (проверьте тип API/endpoint)",
    "unsupported_type": "Неизвестный тип API",
    "secret_unavailable": "Не удалось расшифровать ключ",
    "transport_timeout": "Таймаут",
    "transport_dns": "DNS-имя не разрешается",
    "transport_connect": "Нет TCP-соединения",
    "transport_tls": "Ошибка TLS-сертификата",
    "transport_scheme": "Разрешён только https://",
}


def _plus(a: dict, b: dict) -> dict:
    """$a + $b PHP: ключи a приоритетнее, порядок a, затем новые из b."""
    return {**a, **{k: v for k, v in b.items() if k not in a}}


def _ms(started: float) -> int:
    return int(round((time.monotonic() - started) * 1000))


class LlmGateway:
    def __init__(self, providers: ProviderRepository, access_policy: AccessPolicy, adapters: AdapterRegistry, transport: HttpTransport,
                 ca_bundle_path: Callable[[], str | None], diagnostic_logger: DiagnosticLogger | None = None,
                 models: ModelRepository | None = None, health: ModelHealthRepository | None = None,
                 sleeper: Callable[[float], None] | None = None,
                 alert: Callable[[str, dict[str, str], str], None] | None = None):
        """sleeper — пауза перед повтором (в тестах без ожидания); alert(шаблон, переменные, ключ частоты) — уведомление администраторам."""
        self.providers = providers
        self.access_policy = access_policy
        self.adapters = adapters
        self.transport = transport
        self.ca_bundle_path = ca_bundle_path
        self.diagnostic_logger = diagnostic_logger
        self.models = models
        self.health = health
        self.sleeper = sleeper or time.sleep
        self.alert = alert

    def complete_with_failover(self, user: ChatUser, model_id: int, history: list[dict], system_prompt: str | None,
                               context: AnonymizedText | None, allowed_models: dict[int, dict], auto_fallback: bool = True,
                               time_budget_seconds: int = 540, required_context: int = 0) -> LlmResult:
        """Выбранная модель → её резервные → (по настройке) остальные доступные по приоритету. Каждая модель — не больше
        одного раза, повторы и общее число попыток/время ограничены. Ответ принимается только полноценный (2xx, корректная
        структура, непустой текст). history — [{'role', 'content': AnonymizedText}]; allowed_models — ModelRepository.allowed_for().
        Исключение: LlmException — последняя ошибка либо all_failed."""
        if self.models is None or self.health is None:
            raise LlmException("unsupported_type", "Резервирование не настроено.")
        if context is not None and not context.is_empty():
            system_prompt = ((system_prompt or "") + "\n\n" + context.value).strip(PHP_WS)
        messages = self._plain_messages(history)
        if model_id not in allowed_models:
            raise LlmException("access_denied", "Модель недоступна пользователю.")

        # Модели на паузе после сбоев — в конец цепочки (если на паузе все, всё равно пробуем по порядку).
        chain = self.models.chain(model_id, allowed_models, auto_fallback)
        ready = [i for i in chain if self.health.cooling_until(i) == 0]
        chain = list(dict.fromkeys(ready + chain))

        started = time.monotonic()
        primary_name = str(allowed_models[model_id]["display_name"])
        attempts_total = 0
        last: LlmException | None = None
        min_context = 0
        exhausted = False
        for position, candidate_id in enumerate(chain):
            model = allowed_models.get(candidate_id)
            # Резервная модель с меньшим окном, чем нужно собранному контексту, пропускается (иначе гарантированный отказ).
            if model is None or int(model["context_window"]) <= min_context or (
                    candidate_id != model_id and int(model["context_window"]) - int(model["max_output_tokens"]) < required_context):
                continue
            try:
                self.access_policy.assert_allowed(user, int(model["provider_id"]))   # права — на каждую резервную модель отдельно
                provider = self._load_provider(int(model["provider_id"]))
            except LlmException as e:
                last = e
                continue
            if not provider.is_enabled:
                continue
            config = provider.with_model(candidate_id, str(model["model"]), int(model["max_output_tokens"]), model["temperature"])
            attempt = 0
            while True:
                if attempts_total >= failover.MAX_ATTEMPTS_TOTAL or (time.monotonic() - started) + min(config.response_timeout, 60) > time_budget_seconds:
                    exhausted = True
                    break
                attempts_total += 1
                try:
                    result = self._execute(config, messages, system_prompt, config.max_tokens, {"user": user.log_name(), "model_id": candidate_id})
                except LlmException as e:
                    if e.error_code == "content_refused":
                        raise   # отказ из-за содержимого запроса — не сбой модели: без повтора, паузы и резерва
                    last = e
                    failures = self.health.consecutive_failures(candidate_id) + 1
                    decision = failover.decide(e, attempt, failures)
                    reason = self.describe_for_admin(e)
                    self.health.record_failure(candidate_id, e.error_code, reason, decision["cooldown"])
                    if decision["cooldown"] >= 300 and self.alert is not None:
                        self.alert("model_alert", {"model": str(model["display_name"]), "provider": provider.name, "reason": reason,
                                                   "until": time.strftime("%Y-%m-%d %H:%M", time.gmtime(time.time() + decision["cooldown"]))}, f"model{candidate_id}")
                    if decision["strip_params"] and config.temperature is not None:
                        config = config.without_optional_params()
                        self.health.incident(candidate_id, provider.id, e.error_code, e.http_status, reason, "retry_without_params")
                        attempt += 1
                        continue
                    if decision["retry"]:
                        self.health.incident(candidate_id, provider.id, e.error_code, e.http_status, reason, "retry")
                        self.sleeper(failover.backoff_seconds(e, attempt))
                        attempt += 1
                        continue
                    if not decision["fallback"]:
                        raise
                    if decision["needs_larger_context"]:
                        min_context = max(min_context, int(model["context_window"]))
                    nxt = chain[position + 1] if position + 1 < len(chain) else None
                    self.health.record_fallback(candidate_id)
                    self.health.incident(candidate_id, provider.id, e.error_code, e.http_status, reason, "fallback" if nxt is not None else "give_up", nxt)
                    break
                cost = float(model.get("price_in_per_mtoken") or 0) * int(result.input_tokens or 0) / 1e6 \
                    + float(model.get("price_out_per_mtoken") or 0) * int(result.output_tokens or 0) / 1e6
                self.health.record_success(candidate_id, result.duration_ms, result.input_tokens, result.output_tokens, cost)
                return result.with_route(candidate_id, provider.id, provider.name, primary_name if candidate_id != model_id else None, attempts_total)
            if exhausted:
                break

        if last is not None and len(chain) == 1:
            raise last   # резервных моделей нет — пользователю причина как есть (лимит, ключ, пустой ответ…)
        reason = self.describe_for_admin(last) if last is not None else "нет попыток"
        if self.alert is not None:
            self.alert("all_failed", {"reason": reason}, "all")
        raise LlmException("all_failed", "Все модели цепочки не ответили; последняя ошибка: " + reason,
                           last.http_status if last else None, last.error_code if last else None)

    @staticmethod
    def _plain_messages(history: list[dict]) -> list[dict]:
        messages = []
        for item in history:
            if not isinstance(item.get("content"), AnonymizedText):
                # защита от ошибок программирования: сюда не должен попасть сырой текст
                raise LlmException("empty_input", "В LLM Gateway передан необезличенный текст.")
            messages.append({"role": item["role"], "content": item["content"].value})
        if not messages:
            raise LlmException("empty_input", "Пустой запрос.")
        return messages

    def complete(self, user: ChatUser, provider_id: int, history: list[dict], system_prompt: str | None = None,
                 context: AnonymizedText | None = None) -> LlmResult:
        """history — обезличенная история, последняя реплика — новая реплика пользователя."""
        # Контекст проекта (инструкции, источники) — только обезличенный.
        if context is not None and not context.is_empty():
            system_prompt = ((system_prompt or "") + "\n\n" + context.value).strip(PHP_WS)
        self.access_policy.assert_allowed(user, provider_id)
        provider = self._load_provider(provider_id)
        if not provider.is_enabled:
            raise LlmException("disabled", "Провайдер отключён.")
        messages = self._plain_messages(history)
        return self._execute(provider, messages, system_prompt, provider.max_tokens, {"user": user.log_name()})

    def test_connection(self, provider_id: int) -> dict:
        """Проверка подключения из админки: синтетический запрос без пользовательских данных. Права не проверяются,
        провайдер может быть выключен. → {ok, message, duration_ms}."""
        started = time.monotonic()
        try:
            provider = self._load_provider(provider_id)
            result = self._execute(provider, [{"role": "user", "content": "Ответь одним словом: OK"}], None,
                                   min(64, max(16, provider.max_tokens)), {"check": "admin"})
            ok = True
            message = ("Подключение успешно" + (f", модель {result.model}" if result.model is not None else "")
                       + (f", finish_reason={result.finish_reason}" if result.finish_reason is not None else "") + ".")
        except LlmException as e:
            ok = False
            message = self.describe_for_admin(e)
        duration = _ms(started)
        self.providers.record_check(provider_id, ok, message)
        return {"ok": ok, "message": message, "duration_ms": duration}

    def test_model(self, model_id: int, mode: str = "ping") -> dict:
        """Проверка модели из админки: ping — минимальный ответ «OK», generate — тестовая генерация. Результат — в диагностику
        модели, пауза после ошибки проверки не ставится. → {ok, message, duration_ms, preview}."""
        started = time.monotonic()
        preview = None
        try:
            model = self.models.find(model_id) if self.models is not None else None
            if model is None:
                raise LlmException("not_found", "Модель не найдена.")
            provider = self._load_provider(int(model["provider_id"]))
            mt = int(model["max_output_tokens"])
            config = provider.with_model(model_id, str(model["model"]), min(64, mt) if mode == "ping" else min(400, mt), model["temperature"])
            prompt = "Ответь одним словом: OK" if mode == "ping" else "Объясни в трёх предложениях, зачем компании резервные языковые модели."
            result = self._execute(config, [{"role": "user", "content": prompt}], None, config.max_tokens, {"check": "admin_" + mode})
            if self.health is not None:
                self.health.record_success(model_id, result.duration_ms, result.input_tokens, result.output_tokens, 0.0)
            preview = result.text[:600]
            ok = True
            message = (("Подключение успешно" if mode == "ping" else "Генерация успешна") + (f", модель {result.model}" if result.model is not None else "")
                       + f", токены {int(result.input_tokens or 0)}/{int(result.output_tokens or 0)}"
                       + (f", finish_reason={result.finish_reason}" if result.finish_reason is not None else "") + ".")
        except LlmException as e:
            ok = False
            message = self.describe_for_admin(e)
            if self.health is not None:
                self.health.record_failure(model_id, e.error_code, message, 0)
                self.health.incident(model_id, None, e.error_code, e.http_status, message, "admin_check")
        return {"ok": ok, "message": message, "duration_ms": _ms(started), "preview": preview}

    @staticmethod
    def describe_for_admin(e: LlmException) -> str:
        return _ADMIN_PREFIX.get(e.error_code, "Ошибка " + e.error_code) + ": " + str(e)[:250]

    def _load_provider(self, provider_id: int) -> ProviderConfig:
        try:
            provider = self.providers.load_for_gateway(provider_id)
        except RuntimeError as e:
            raise LlmException("secret_unavailable", str(e)) from None
        if provider is None:
            raise LlmException("not_found", "Провайдер не найден.")
        return provider

    def _execute(self, provider: ProviderConfig, messages: list[dict], system_prompt: str | None, max_tokens: int, log_context: dict) -> LlmResult:
        adapter = self.adapters.get(provider.type)
        ca_bundle = None
        if provider.use_corporate_ca:
            try:
                ca_bundle = self.ca_bundle_path()
            except Exception as e:
                raise LlmException("transport_tls", f"CA bundle приложения недоступен: {e}") from None
        request = adapter.build_request(provider, messages, system_prompt, max_tokens, ca_bundle)
        context = _plus(log_context, {"provider": provider.name, "provider_id": provider.id, "type": provider.type, "model": provider.model})
        started = time.monotonic()
        try:
            response = self.transport.send(request)
            result = adapter.parse_response(response).with_duration(_ms(started))
            # HTTP 200 с пустым содержимым — ошибка генерации, а не ответ (его нельзя сохранять и тарифицировать).
            if result.text.strip(PHP_WS) == "" and (result.finish_reason or "").lower() in _REFUSAL_REASONS:
                raise LlmException("content_refused", f"HTTP {response.status}, ответ заблокирован фильтром содержимого (finish_reason={result.finish_reason}).", response.status)
            if result.text.strip(PHP_WS) == "":
                fr = f" (finish_reason={result.finish_reason})" if result.finish_reason is not None else ""
                raise LlmException("empty_response", f"HTTP {response.status}, пустой content{fr}.", response.status)
        except HttpTransportError as e:
            exc = LlmException("transport_" + e.kind, str(e))
            self._log_failure(exc, context, started)
            raise exc from None
        except LlmException as e:
            self._log_failure(e, context, started)
            raise
        if self.diagnostic_logger is not None:
            self.diagnostic_logger.info(SOURCE, "llm_request_success", "Ответ LLM получен.", _plus(context, {
                "http_status": response.status,
                "messages": len(messages),
                # не "*_tokens": DiagnosticLogger редактирует ключи со словом token
                "usage_in": result.input_tokens,
                "usage_out": result.output_tokens,
                "finish_reason": result.finish_reason,
                "duration_ms": result.duration_ms,
            }))
        return result

    def _log_failure(self, e: LlmException, context: dict, started: float) -> None:
        # Текст ошибки провайдера намеренно не пишем: только код, статус и тип.
        if self.diagnostic_logger is not None:
            self.diagnostic_logger.error(SOURCE, "llm_request_failed_" + e.error_code, f"Запрос к LLM завершился ошибкой {e.error_code}.", _plus(context, {
                "http_status": e.http_status,
                "provider_error_type": e.provider_error_type,
                "duration_ms": _ms(started),
            }))
