"""Настройки встроенного обезличивания в app_settings (префикс anon_): параметры движка и итог последней проверки.
Адрес и токен сервиса — из окружения, не из БД. Строки app_settings удалённых в 2.0 настроек (DocClean, внешний
JSON API, anon_profile, anon_secret_encrypted) не читаются."""

from __future__ import annotations

import json
import os
import re
from typing import Any

from ..db import now_iso
from ..files.base import php_int, php_trim
from ..settings import AppSettings

INTERNAL_PROFILES = {"basic": "Базовый", "strict": "Строгий", "custom": "Настраиваемый"}
INTERNAL_ACTIONS = {"keep": "оставить", "mask": "метка", "pseudonym": "обозначение с номером"}
INTERNAL_CATEGORIES = {
    "person": "Люди (ФИО)", "organization": "Организации", "location": "Места", "address": "Адреса", "phone": "Телефоны",
    "email": "Почта", "ip": "IP-адреса", "host": "Серверы", "url": "Ссылки", "login": "Учётные записи",
    "id_number": "ИНН, СНИЛС, ОГРН, паспорт", "bank": "Карты и счета", "health": "Здоровье", "marker": "Грифы",
    "doc_number": "Номера договоров и документов", "money": "Суммы", "project": "Проекты", "custom": "Свой словарь",
}
PREFIX = "anon_"
DEFAULTS = {
    "internal_profile": "basic",
    "internal_terms": "",            # строки «термин;категория;замена» — словарь организации
    "internal_exceptions": "",       # по одному в строке — никогда не заменять (кроме секретов)
    "internal_domains": "",          # внутренние домены — адреса и серверы в них заменяются
    "internal_custom_actions": "",   # JSON {категория: keep|mask|pseudonym} для профиля «Настраиваемый»
    "internal_person_format": "short",
    "internal_org_scope": "legal",
    "internal_timeout": "120",
    "last_check_at": "",
    "last_check_ok": "",
    "last_check_message": "",
}

_NEWLINE = re.compile(r"\r\n|[\n\v\f\r\x85  ]")   # \R PCRE


def _json(text: str) -> Any:
    try:
        return json.loads(text)
    except (ValueError, TypeError):
        return None


def _lines(text: str) -> list[str]:
    return [x for x in (php_trim(s) for s in _NEWLINE.split(text)) if x != ""]


class AnonymizationSettings:
    def __init__(self, app_settings: AppSettings):
        self.app_settings = app_settings

    def get(self, name: str) -> str:
        return str(self.app_settings.get(PREFIX + name, DEFAULTS.get(name, "")))

    def all(self) -> dict[str, str]:
        """Все параметры движка и итог последней проверки."""
        return {k: self.get(k) for k in DEFAULTS}

    def save(self, values: dict[str, str]) -> None:
        for name, value in values.items():
            if name in DEFAULTS:
                self.app_settings.set(PREFIX + name, str(value))

    def is_configured(self) -> bool:
        return self.internal_url() != "" and self.internal_token() != ""

    @staticmethod
    def internal_url() -> str:
        """Адрес сервиса во внутренней сети — из окружения (в браузер не попадает)."""
        return os.environ.get("ANONYMIZER_URL", "").rstrip("/")

    @staticmethod
    def internal_token() -> str:
        return os.environ.get("INTERNAL_API_TOKEN", "")

    def internal_engine_settings(self, draft: dict[str, str] | None = None) -> dict[str, Any]:
        """Настройки движка для запроса к сервису. draft — непроверенный черновик лаборатории админки поверх сохранённых."""
        draft = draft or {}
        get = lambda k: str(draft[k]) if k in draft else self.get(k)  # noqa: E731
        terms = []
        for line in _lines(get("internal_terms")):
            parts = [php_trim(p) for p in line.split(";")]
            if parts[0] != "":
                terms.append({"term": parts[0][:200], "category": parts[1] if len(parts) > 1 else "custom",
                              "replacement": (parts[2] if len(parts) > 2 else "")[:100]})
        actions = _json(get("internal_custom_actions"))
        return {
            "terms": terms,
            "exceptions": _lines(get("internal_exceptions")),
            "internal_domains": _lines(get("internal_domains")),
            "custom_actions": actions if isinstance(actions, (dict, list)) else [],
            "custom_person_format": get("internal_person_format"),
            "custom_org_scope": get("internal_org_scope"),
        }

    def record_check(self, ok: bool, message: str) -> None:
        self.save({"last_check_at": now_iso(), "last_check_ok": "1" if ok else "0", "last_check_message": message[:300]})

    @staticmethod
    def validate(values: dict[str, str]) -> dict[str, str]:
        """Поле → ошибка."""
        errors: dict[str, str] = {}
        if values.get("internal_profile", "") not in INTERNAL_PROFILES:
            errors["internal_profile"] = "Профиль: базовый, строгий или настраиваемый."
        if values.get("internal_person_format", "short") not in ("short", "role"):
            errors["internal_person_format"] = "Недопустимый формат имён."
        if values.get("internal_org_scope", "legal") not in ("legal", "all"):
            errors["internal_org_scope"] = "Недопустимая область организаций."
        actions = php_trim(str(values.get("internal_custom_actions", "")))
        if actions != "":
            d = _json(actions)
            ok = d == [] or (isinstance(d, dict) and all(k in INTERNAL_CATEGORIES for k in d)
                             and all(isinstance(v, str) and v in INTERNAL_ACTIONS for v in d.values()))
            if not ok:
                errors["internal_custom_actions"] = "Неизвестная категория или действие."
        if not 5 <= php_int(values.get("internal_timeout", 120)) <= 600:
            errors["internal_timeout"] = "Допустимый диапазон: 5–600."
        for field, max_lines in {"internal_terms": 5000, "internal_exceptions": 5000, "internal_domains": 500}.items():
            text = str(values.get(field, ""))
            if len(_NEWLINE.split(text)) > max_lines or len(text) > 500000:
                errors[field] = f"Не больше {max_lines} строк."
        return errors
