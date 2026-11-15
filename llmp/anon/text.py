"""Обезличенный текст. Шлюз LLM принимает только AnonymizedText — необезличенная строка туда не попадёт по ошибке
типа. Создаётся шлюзом обезличивания (from_gateway) или из уже сохранённой обезличенной истории (from_storage)."""

from __future__ import annotations

import re

_LABEL = re.compile(r"\A\[[\w :.,()-]{1,60}\]\Z")


class AnonymizedText:
    __slots__ = ("value",)

    def __init__(self, value: str, _token: object = None):
        if _token is not _ISSUER:
            raise TypeError("AnonymizedText создаётся через from_gateway или from_storage")
        self.value = value

    @classmethod
    def from_gateway(cls, value: str) -> "AnonymizedText":
        return cls(value, _ISSUER)

    @classmethod
    def from_storage(cls, value: str) -> "AnonymizedText":
        return cls(value, _ISSUER)

    def with_label(self, label: str) -> "AnonymizedText":
        if not _LABEL.match(label) or "_" in label:
            raise ValueError("Недопустимая служебная метка.")
        return AnonymizedText(label + "\n" + self.value, _ISSUER)

    @classmethod
    def join(cls, parts: list["AnonymizedText"], glue: str = "\n\n") -> "AnonymizedText":
        g = glue if glue in ("\n\n", "\n") else "\n\n"
        return cls(g.join(p.value for p in parts if not p.is_empty()), _ISSUER)

    def is_empty(self) -> bool:
        return self.value.strip() == ""

    def __repr__(self) -> str:   # содержимое не попадает в журналы и трассировки
        return f"AnonymizedText(<{len(self.value)} chars>)"


_ISSUER = object()
