"""Независимая повторная проверка результата обезличивания.

Движок (`Anonymizer._verify`) уже перепроверяет секреты и фамилии участников. Здесь — второй, независимый от таблицы замен проход:
результат заново прогоняется через детекторы структурированных данных и секретов как «чужой» текст. Всё, что найдено в категории,
которую политика требует убрать, считается утечкой, и запрос блокируется (fail closed).

Проверяются только категории с однозначными форматами (контрольные суммы, адреса, номера) — контекстные (суммы, адреса, номера договоров)
дают ложные срабатывания на самих метках замен и проверяются движком.
"""
from __future__ import annotations

from .privacy.engine import Policy
from .privacy.patterns import find_structured
from .privacy.secrets import find_secrets

STRICT_CATEGORIES = ("email", "phone", "ip", "id_number", "bank")


def independent_check(text: str, policy: Policy) -> list[str]:
    """Список категорий, оставшихся в тексте (пусто — чисто). Секреты проверяются всегда."""
    leaks = ["secret" for _ in find_secrets(text)]
    want = {c for c in STRICT_CATEGORIES if policy.action(c) != "keep"}
    if want:
        leaks += [f.category for f in find_structured(text, internal_domains=policy.internal_domains, public_domains=policy.public_domains)
                  if f.category in want]
    return leaks
