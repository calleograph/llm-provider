"""ЕДИНСТВЕННЫЙ компонент, который обращается к сервису обезличивания. Любой код, отправляющий пользовательские
данные наружу, обязан сначала получить AnonymizedText отсюда.

Сервис один — встроенный (контейнер anonymizer, ANONYMIZER_URL + INTERNAL_API_TOKEN из окружения).
Fail closed: при любой неопределённости (не настроен, таймаут, не-2xx, некорректный JSON, нет текста в ответе,
пустой результат для непустого входа, нет подтверждения проверки) — AnonymizationException, и вызывающий код НЕ
отправляет данные наружу. В диагностический журнал — только метаданные (длины, число текстов, длительность,
счётчики по категориям); ни вход, ни ответ сервиса не журналируются."""

from __future__ import annotations

import re
import time
from dataclasses import dataclass, field
from typing import Any

from ..files.base import php_int, php_trim
from ..http import HttpRequest, HttpResponse, HttpTransport, HttpTransportError
from ..logs import DiagnosticLogger
from . import settings as s
from .settings import AnonymizationSettings
from .text import AnonymizedText

SOURCE = "anonymization"


class AnonymizationException(RuntimeError):
    """Код безопасен для журнала: not_configured | transport_<kind> | http_<status> | invalid_json | missing_field |
    empty_result | not_clean | nlp_unavailable | <код сервиса> | …. Текст запроса и ответа в исключение
    не включается."""

    def __init__(self, error_code: str, technical_message: str = ""):
        super().__init__(technical_message or error_code)
        self.error_code = error_code

    def user_message(self) -> str:
        """Текст для пользователя чата: без технических подробностей."""
        c = self.error_code
        if c == "not_clean":
            return ("Сервис обезличивания не подтвердил полную очистку данных — запрос не отправлен во внешний сервис. "
                    "Уберите из текста чувствительные сведения или обратитесь к администратору.")
        return "Не удалось обезличить данные — запрос не отправлен во внешний сервис. Повторите позже или обратитесь к администратору."


@dataclass(frozen=True)
class AnonymizationBatch:
    """Совместное обезличивание нескольких текстов одной сессией замен: один человек — одно обозначение во всех частях.
    stats — число замен по категориям (без значений); replacements/candidates — только для лаборатории админки
    (исходные значения!), не журналировать."""
    texts: list[AnonymizedText]
    replaced: int = 0
    stats: dict[str, int] = field(default_factory=dict)
    warnings: list[str] = field(default_factory=list)
    duration_ms: int = 0
    replacements: list = field(default_factory=list)
    candidates: list = field(default_factory=list)


def _ms(started: float) -> int:
    return int(round((time.monotonic() - started) * 1000))


def _dig(data: Any, *keys: str) -> Any:
    """$a['x']['y'] ?? null PHP: None, если по пути не объект."""
    for k in keys:
        if not isinstance(data, dict) or k not in data:
            return None
        data = data[k]
    return data


class AnonymizationGateway:
    def __init__(self, settings: AnonymizationSettings, transport: HttpTransport, diagnostic_logger: DiagnosticLogger | None = None):
        self.settings = settings
        self.transport = transport
        self.log = diagnostic_logger

    def anonymize(self, text: str, purpose: str) -> AnonymizedText:
        """purpose — безопасная метка для журнала: message | file | check …"""
        return self.anonymize_many([text], purpose).texts[0]

    def anonymize_many(self, texts: list[str], purpose: str, detail: bool = False, profile: str | None = None,
                       draft: dict[str, str] | None = None) -> AnonymizationBatch:
        """Все тексты — одним запросом (согласованные обозначения)."""
        return self._call_internal(list(texts), purpose, detail, profile or self.settings.get("internal_profile"),
                                   self.settings.internal_engine_settings(draft or {}))

    def mask_secrets(self, texts: list[str], purpose: str) -> AnonymizationBatch:
        """Только секреты (пароли, токены, ключи) — для отправки без обезличивания пользователем с правом
        allow_unanonymized: остальные категории остаются. Без встроенного сервиса текст не меняется, как раньше."""
        texts = list(texts)
        if AnonymizationSettings.internal_url() == "" or AnonymizationSettings.internal_token() == "":
            return AnonymizationBatch([AnonymizedText.from_gateway(t) for t in texts])
        keep_all = {k: "keep" for k in s.INTERNAL_CATEGORIES}
        return self._call_internal(texts, purpose + "_secrets", False, "custom", {"custom_actions": keep_all})

    def _call_internal(self, texts: list[str], purpose: str, detail: bool, profile: str, engine_settings: dict) -> AnonymizationBatch:
        url, token = AnonymizationSettings.internal_url(), AnonymizationSettings.internal_token()
        if url == "" or token == "":
            raise AnonymizationException("not_configured", "Встроенный сервис обезличивания не настроен (ANONYMIZER_URL/INTERNAL_API_TOKEN).")
        if all(php_trim(t) == "" for t in texts):
            return AnonymizationBatch([AnonymizedText.from_gateway(t) for t in texts])
        started = time.monotonic()
        context = {"purpose": purpose, "texts": len(texts), "input_chars": sum(len(t) for t in texts)}
        try:
            timeout = max(5, php_int(self.settings.get("internal_timeout")))
            response = self._send(HttpRequest.json(url + "/v1/anonymize",
                                                   {"texts": texts, "profile": profile, "settings": engine_settings, "detail": detail},
                                                   {"X-Internal-Token": token}, 5, timeout + 10, None, True))
            data = response.json()
            if not response.is_success():
                detail_v = _dig(data, "detail")
                if isinstance(detail_v, dict) and isinstance(detail_v.get("code"), str):
                    code = detail_v["code"]
                elif isinstance(detail_v, str) and re.fullmatch(r"[a-z_]{2,40}", detail_v):
                    code = detail_v
                else:
                    code = f"http_{response.status}"
                message = detail_v["message"] if isinstance(detail_v, dict) and isinstance(detail_v.get("message"), str) else f"HTTP {response.status}"
                raise AnonymizationException("not_clean" if code == "verification_failed" else code, message)
            out_texts = _dig(data, "texts")
            if not isinstance(out_texts, list) or len(out_texts) != len(texts) or _dig(data, "verified") is not True:
                raise AnonymizationException("invalid_json", "Ответ сервиса обезличивания не соответствует контракту.")
            out = []
            for i, source in enumerate(texts):
                value = out_texts[i]
                if not isinstance(value, str):
                    raise AnonymizationException("missing_field", f"Нет текста №{i} в ответе.")
                if php_trim(source) != "" and php_trim(value) == "":
                    raise AnonymizationException("empty_result", "Пустой результат для непустого текста.")
                out.append(AnonymizedText.from_gateway(value))
            raw_stats = _dig(data, "stats")
            stats = {k: v for k, v in raw_stats.items()
                     if re.fullmatch(r"[a-z_]{2,20}", k) and isinstance(v, int) and not isinstance(v, bool)} if isinstance(raw_stats, dict) else {}
            raw_warn = _dig(data, "warnings")
            items = [raw_warn] if isinstance(raw_warn, str) else list(raw_warn.values()) if isinstance(raw_warn, dict) else raw_warn if isinstance(raw_warn, list) else []
            warnings = [w for w in items if isinstance(w, str)]
            replaced = data.get("replaced")
            batch = AnonymizationBatch(
                out, php_int(replaced) if replaced is not None else sum(stats.values()), stats, warnings, _ms(started),
                data["replacements"] if detail and isinstance(data.get("replacements"), list) else [],
                data["candidates"] if detail and isinstance(data.get("candidates"), list) else [],
            )
        except AnonymizationException as e:
            if self.log:
                self.log.error(SOURCE, "anonymize_failed_" + e.error_code, f"Обезличивание не выполнено: {e.error_code}.",
                               {**context, "duration_ms": _ms(started)})
            raise
        # Только объёмы и счётчики по категориям — без текста и найденных значений.
        if self.log:
            self.log.info(SOURCE, "anonymize_success", "Текст обезличен встроенным сервисом.", {
                **context, "replaced": batch.replaced, "categories": ",".join(f"{k}:{v}" for k, v in batch.stats.items()),
                "duration_ms": batch.duration_ms})
        return batch

    def _assert_ready(self) -> None:
        if not self.settings.is_configured():
            raise AnonymizationException("not_configured", "ANONYMIZER_URL/INTERNAL_API_TOKEN не заданы.")
        ready = self._send(HttpRequest("GET", AnonymizationSettings.internal_url() + "/readyz", {}, None, 2, 4, None, True))
        if ready.status != 200:
            raise AnonymizationException("nlp_unavailable", f"Сервис запущен, но модели распознавания имён не готовы (HTTP {ready.status}).")

    def ready_error(self) -> str | None:
        """Живое состояние для админки (только /readyz, без данных): None — готов, иначе понятное сообщение."""
        try:
            self._assert_ready()
        except AnonymizationException as e:
            return self.describe(e)
        return None

    def test_connection(self) -> dict:
        """Проверка из админки: синтетическая строка без реальных данных проходит полный путь. {ok, message, duration_ms}."""
        started = time.monotonic()
        try:
            self._assert_ready()
            self.anonymize("Проверка связи: Иванов Иван Иванович, тел. +7 900 000-00-00.", "check")
            ok, message = True, "Сервис отвечает, результат обезличивания получен."
        except AnonymizationException as e:
            ok, message = False, self.describe(e)
        self.settings.record_check(ok, message)
        return {"ok": ok, "message": message, "duration_ms": _ms(started)}

    @staticmethod
    def describe(e: AnonymizationException) -> str:
        c = e.error_code
        check = " Проверьте контейнер anonymizer: ./scripts/ctl.sh status"
        fixed = {
            "not_configured": "Сервис обезличивания не подключён: в окружении нет ANONYMIZER_URL или INTERNAL_API_TOKEN." + check,
            "nlp_unavailable": "Сервис обезличивания запущен, но модели распознавания имён ещё не загружены." + check,
            "timeout": "Обезличивание не уложилось в отведённое время.",
            "invalid_json": "Сервис обезличивания вернул ответ не по контракту.",
            "missing_field": "В ответе сервиса нет обезличенного текста.",
            "empty_result": "Сервис вернул пустой результат для непустого текста.",
            "not_clean": "Повторная проверка нашла неочищенные данные — такой текст не отправляется наружу.",
        }
        if c in fixed:
            return fixed[c]
        if c.startswith("http_"):
            return f"Сервис обезличивания ответил HTTP {c[5:]}." + check
        if c.startswith("transport_"):
            return f"Сервис обезличивания недоступен (сетевая ошибка: {c[10:]})." + check
        return f"Ошибка обезличивания: {c}."

    def _send(self, request: HttpRequest) -> HttpResponse:
        try:
            return self.transport.send(request)
        except HttpTransportError as e:
            raise AnonymizationException("transport_" + e.kind, str(e)) from None
