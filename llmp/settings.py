"""Настройки в таблице app_settings (ключ → строка), как у версии 1.x."""

from __future__ import annotations

import sqlite3

# Темы оформления: id, название, пояснение. Тот же список — в public/assets/js/theme.js.
THEMES: tuple[tuple[str, str, str], ...] = (
    ("glass", "Стекло", "Светлая, воздушная, с мягкими панелями — по умолчанию"),
    ("dark", "Тёмная", "Для работы вечером и при ярком мониторе"),
)
THEME_IDS = frozenset(t[0] for t in THEMES)
DEFAULT_THEME = "glass"


class AppSettings:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def get(self, key: str, default: str | None = None) -> str | None:
        row = self.conn.execute("SELECT value FROM app_settings WHERE key = ?", (key,)).fetchone()
        return str(row[0]) if row is not None else default

    def set(self, key: str, value: str) -> None:
        self.conn.execute(
            "INSERT INTO app_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            (key, value),
        )

    def trusted_proxies(self) -> list[str]:
        return [p.strip() for p in (self.get("trusted_proxies", "") or "").split(",") if p.strip()]

    def set_trusted_proxies(self, proxies: list[str]) -> None:
        self.set("trusted_proxies", ",".join(proxies))

    def site_theme(self) -> str:
        """Тема для всех, закреплённая администратором; выбор пользователя в браузере важнее неё."""
        value = self.get("site_theme", DEFAULT_THEME)
        return value if value in THEME_IDS else DEFAULT_THEME
