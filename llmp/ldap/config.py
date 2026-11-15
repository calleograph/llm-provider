"""Именованные конфигурации подключения к AD (таблица ldap_configs) и проверка полей формы.
Активна ровно одна конфигурация; активную удалять нельзя."""

from __future__ import annotations

import sqlite3

from ..db import now_iso, transaction
from .client import LdapConnectionConfig

_PHP_TRIM = " \t\n\r\0\x0b"
_FIELDS = ("name", "ldaps_url", "host", "port", "base_dn", "domain", "netbios_domain", "admin_group_dn",
           "protocol_version", "starttls", "network_timeout", "ca_bundle_file")


def _values(data: dict) -> list:
    return [(1 if data[f] else 0) if f == "starttls" else data[f] for f in _FIELDS]


class LdapConfigRepository:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def find_active(self) -> LdapConnectionConfig | None:
        row = self.conn.execute("SELECT * FROM ldap_configs WHERE is_active = 1 LIMIT 1").fetchone()
        return LdapConnectionConfig.from_row(row) if row is not None else None

    def find_by_id(self, config_id: int) -> LdapConnectionConfig | None:
        row = self.conn.execute("SELECT * FROM ldap_configs WHERE id = ?", (config_id,)).fetchone()
        return LdapConnectionConfig.from_row(row) if row is not None else None

    def all(self) -> list[LdapConnectionConfig]:
        return [LdapConnectionConfig.from_row(r) for r in self.conn.execute("SELECT * FROM ldap_configs ORDER BY name")]

    def create(self, data: dict, make_active: bool = False) -> int:
        now = now_iso()
        with transaction(self.conn):
            if make_active:
                self.conn.execute("UPDATE ldap_configs SET is_active = 0")
            cur = self.conn.execute(
                f"INSERT INTO ldap_configs (is_active, {', '.join(_FIELDS)}, created_at, updated_at) VALUES ({', '.join('?' * (len(_FIELDS) + 3))})",
                [1 if make_active else 0, *_values(data), now, now],
            )
            return int(cur.lastrowid)

    def update(self, config_id: int, data: dict) -> None:
        self.conn.execute(
            f"UPDATE ldap_configs SET {', '.join(f + ' = ?' for f in _FIELDS)}, updated_at = ? WHERE id = ?",
            [*_values(data), now_iso(), config_id],
        )

    def set_active(self, config_id: int) -> None:
        if self.find_by_id(config_id) is None:
            raise RuntimeError("Конфигурация не найдена.")
        with transaction(self.conn):
            self.conn.execute("UPDATE ldap_configs SET is_active = 0")
            self.conn.execute("UPDATE ldap_configs SET is_active = 1, updated_at = ? WHERE id = ?", (now_iso(), config_id))

    def delete(self, config_id: int) -> None:
        config = self.find_by_id(config_id)
        if config is None:
            return
        if config.is_active:
            raise RuntimeError("Нельзя удалить активную конфигурацию.")
        self.conn.execute("DELETE FROM ldap_configs WHERE id = ?", (config_id,))

    def name_exists(self, name: str, exclude_id: int | None = None) -> bool:
        if exclude_id is not None:
            row = self.conn.execute("SELECT COUNT(*) FROM ldap_configs WHERE name = ? AND id != ?", (name, exclude_id)).fetchone()
        else:
            row = self.conn.execute("SELECT COUNT(*) FROM ldap_configs WHERE name = ?", (name,)).fetchone()
        return row[0] > 0


def validate_fields(data: dict) -> dict[str, str]:
    """Ошибка с привязкой к полю формы: имя поля → текст (подсвечивается именно это поле)."""
    errors: dict[str, str] = {}
    if data["name"].strip(_PHP_TRIM) == "":
        errors["name"] = "Укажите имя конфигурации."
    if data["host"].strip(_PHP_TRIM) == "":
        errors["host"] = "Укажите host."
    elif any(c in data["host"] for c in "/ "):
        errors["host"] = "Только имя или IP контроллера, без ldaps:// и пути (например dc1.corp.local)."
    if data["base_dn"].strip(_PHP_TRIM) == "":
        errors["base_dn"] = "Укажите Base DN."
    if data["domain"].strip(_PHP_TRIM) == "":
        errors["domain"] = "Укажите домен."
    if not data["ldaps_url"].lower().startswith("ldaps://"):
        errors["ldaps_url"] = "LDAPS URL должен начинаться с ldaps://"
    if data["port"] < 1 or data["port"] > 65535:
        errors["port"] = "Порт должен быть в диапазоне 1–65535."
    if type(data["protocol_version"]) is not int or data["protocol_version"] not in (2, 3):
        errors["protocol_version"] = "Допустимые значения: 2 или 3."
    if data["network_timeout"] < 1 or data["network_timeout"] > 120:
        errors["network_timeout"] = "Таймаут должен быть в диапазоне 1–120 сек."
    return errors


def validate(data: dict) -> str | None:
    """Первая ошибка или None."""
    errors = validate_fields(data)
    return next(iter(errors.values())) if errors else None
