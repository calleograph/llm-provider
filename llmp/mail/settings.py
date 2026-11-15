"""Настройки SMTP (app_settings, префикс mail_). Пароль хранится только зашифрованным (secretbox) и в форму
не возвращается: пустое поле при сохранении оставляет прежний пароль, смена сервера или порта его сбрасывает
(как в ArkLock — чтобы пароль не ушёл на другой сервер)."""

from __future__ import annotations

import re

from .. import secretbox
from ..settings import AppSettings

PREFIX = "mail_"
SECURITY = {"starttls": "STARTTLS (порт 587)", "tls": "TLS сразу (порт 465)", "none": "Без шифрования (только внутренняя сеть)"}
AUTH = {"auto": "Автоматически (по возможностям сервера)", "plain": "PLAIN", "login": "LOGIN", "none": "Без авторизации (relay по адресу)"}
DEFAULTS = {
    "enabled": "0", "host": "", "port": "587", "security": "starttls", "auth": "auto", "username": "",
    "from_address": "", "from_name": "LLM-провайдер", "timeout": "15", "verify_cert": "1", "helo": "",
    "alert_recipients": "", "alerts_enabled": "1",
}

# shortcut: приближение FILTER_VALIDATE_EMAIL PHP (ASCII, домен с точкой), без IP-литералов и кавычек в имени
_LABEL = r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?"
_EMAIL = re.compile(r"[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*@" + _LABEL + r"(?:\." + _LABEL + r")+")


def valid_email(value: str) -> bool:
    return len(value) <= 320 and _EMAIL.fullmatch(value) is not None


def php_int(value: str | None) -> int:
    """(int) PHP: ведущее целое из строки, иначе 0."""
    m = re.match(r"\s*([+-]?\d+)", value or "")
    return int(m.group(1)) if m else 0


class MailSettings:
    def __init__(self, settings: AppSettings):
        self.settings = settings

    def get(self, name: str) -> str:
        return str(self.settings.get(PREFIX + name, DEFAULTS.get(name, "")))

    def all(self) -> dict[str, str]:
        return {k: self.get(k) for k in DEFAULTS}

    def save(self, values: dict[str, str], new_password: str | None) -> None:
        server_changed = values.get("host", self.get("host")) != self.get("host") or values.get("port", self.get("port")) != self.get("port")
        for k, v in values.items():
            if k in DEFAULTS:
                self.settings.set(PREFIX + k, str(v))
        if new_password:
            self.settings.set(PREFIX + "password_enc", secretbox.encrypt(new_password))
        elif server_changed:
            self.settings.set(PREFIX + "password_enc", "")

    def has_password(self) -> bool:
        return (self.settings.get(PREFIX + "password_enc", "") or "") != ""

    def password(self) -> str:
        """Расшифрованный пароль; SecretError (RuntimeError), если мастер-ключ сменился."""
        enc = self.settings.get(PREFIX + "password_enc", "") or ""
        return "" if enc == "" else secretbox.decrypt(enc)

    def is_configured(self) -> bool:
        return self.get("enabled") == "1" and self.get("host") != "" and valid_email(self.get("from_address"))

    def alert_recipients(self) -> list[str]:
        return [a for a in (p.strip() for p in re.split(r"[\s,;]+", self.get("alert_recipients"))) if valid_email(a)]

    @staticmethod
    def validate(v: dict[str, str]) -> dict[str, str]:
        e: dict[str, str] = {}
        if v.get("enabled", "0") == "1" and not re.fullmatch(r"[A-Za-z0-9.-]{1,253}", v.get("host", "")):
            e["host"] = "Имя или адрес сервера: латиница, цифры, точки и дефисы."
        if not 1 <= php_int(v.get("port")) <= 65535:
            e["port"] = "Порт: 1–65535."
        if v.get("security", "") not in SECURITY:
            e["security"] = "Недопустимый режим шифрования."
        if v.get("auth", "") not in AUTH:
            e["auth"] = "Недопустимый способ авторизации."
        if v.get("enabled", "0") == "1" and not valid_email(v.get("from_address", "")):
            e["from_address"] = "Укажите адрес отправителя."
        if re.search(r"[\r\n]", v.get("from_name", "") + v.get("username", "") + v.get("helo", "")):
            e["from_name"] = "Переводы строк недопустимы."
        if not 3 <= php_int(v.get("timeout")) <= 120:
            e["timeout"] = "Таймаут: 3–120 секунд."
        return e
