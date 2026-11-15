"""Общие параметры чата (app_settings, префикс llm_chat_) — те же ключи и значения по умолчанию, что у версии 1.x."""

from __future__ import annotations

import re

from ..settings import AppSettings

PERIOD_NONE = "none"
PERIODS = ("day", "week", "month", PERIOD_NONE)
PREFIX = "llm_chat_"

DEFAULTS = {
    # Установки администратора — слой 3 иерархии PromptAssembler.
    "system_prompt": "",
    "history_max_chars": "60000",
    "max_message_chars": "20000",
    # Общий лимит токенов (если категорий нет); индивидуальный — в «Пользователи чата».
    "limit_period": "none",
    "limit_tokens": "300000",
    # Срок хранения неиспользуемых диалогов, дней (0 — бессрочно).
    "retention_days": "0",
    # Суммарный объём источников проекта (символов обезличенного текста) — уходит в каждый запрос проекта.
    "project_sources_max_chars": "30000",
    "project_sources_max_files": "3",
    # Блоки инструкций проекта: число блоков и длина одного блока.
    "project_instructions_max_blocks": "5",
    "project_instruction_max_chars": "2000",
    # Порог неактивности (дней) для зачистки содержимого; 0 — никогда.
    "inactivity_content_purge_days": "90",
    # После резервных моделей пробовать остальные доступные пользователю по приоритету.
    "fallback_auto": "1",
    "style_default": "balanced",
    "styles_allowed": "balanced,concise,detailed,technical,business,research,documents,free",
    # Модель для сводок (llm_models.id); пусто — та же, что отвечает.
    "summary_model_id": "",
    "dictation_enabled": "1",
    "dictation_max_seconds": "120",
    "dictation_max_concurrent": "4",
    # Срок хранения телеметрии (llm_outbound), дней; 0 — бессрочно.
    "telemetry_retention_days": "365",
}

_RANGES = {
    "history_max_chars": (1000, 2000000), "max_message_chars": (100, 500000), "limit_tokens": (0, 1000000000),
    "retention_days": (0, 3650), "project_sources_max_chars": (1000, 2000000), "project_sources_max_files": (1, 50),
    "project_instructions_max_blocks": (1, 30), "project_instruction_max_chars": (100, 20000),
    "inactivity_content_purge_days": (0, 3650), "telemetry_retention_days": (0, 3650),
}


def php_int(value) -> int:
    """(int) PHP для строки: ведущее целое, иначе 0."""
    if isinstance(value, (int, float)):
        return int(value)
    m = re.match(r"[ \t\n\r\v\f]*([+-]?\d+)", str(value or ""))
    return int(m.group(1)) if m else 0


class ChatSettings:
    def __init__(self, app_settings: AppSettings):
        self.app_settings = app_settings

    def get(self, name: str) -> str:
        return str(self.app_settings.get(PREFIX + name, DEFAULTS.get(name, "")))

    def all(self) -> dict[str, str]:
        return {name: self.get(name) for name in DEFAULTS}

    def save(self, values: dict) -> None:
        for name, value in values.items():
            if name in DEFAULTS:
                self.app_settings.set(PREFIX + name, str(value))

    @staticmethod
    def validate(values: dict) -> dict[str, str]:
        errors = {}
        if len(values.get("system_prompt") or "") > 8000:
            errors["system_prompt"] = "Не длиннее 8000 символов."
        if "limit_period" in values and values["limit_period"] not in PERIODS:
            errors["limit_period"] = "Недопустимый период."
        for name, (lo, hi) in _RANGES.items():
            if name in values and not lo <= php_int(values[name]) <= hi:
                errors[name] = f"Допустимый диапазон: {lo}–{hi}."
        return errors
