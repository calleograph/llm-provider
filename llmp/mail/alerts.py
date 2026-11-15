"""Уведомления администраторам по почте: не чаще раза в час на один повод (модель, «все недоступны»)."""

from __future__ import annotations

import hashlib
import time

from ..settings import AppSettings
from .queue import MailQueue
from .settings import MailSettings

THROTTLE_S = 3600


class Alerts:
    def __init__(self, settings: MailSettings, queue: MailQueue, app: AppSettings):
        self.settings = settings
        self.queue = queue
        self.app = app

    def notify(self, template: str, variables: dict[str, str], throttle_key: str) -> None:
        """Обратный вызов шлюза LLM: (шаблон, переменные, ключ повода)."""
        if not self.settings.is_configured() or self.settings.get("alerts_enabled") != "1":
            return
        if not self._claim("alert_last_" + hashlib.sha256(f"{template}|{throttle_key}".encode()).hexdigest()[:16]):
            return
        for to in self.settings.alert_recipients():
            self.queue.enqueue(template, to, variables)

    def _claim(self, key: str) -> bool:
        """Атомарный захват повода: два процесса одновременно не отправят одно уведомление дважды."""
        now = int(time.time())
        conn = self.app.conn
        # как у PHP: повтор, когда с прошлого прошло не меньше часа (time() - last >= 3600)
        if conn.execute("UPDATE app_settings SET value = ? WHERE key = ? AND CAST(value AS INTEGER) <= ?", (str(now), key, now - THROTTLE_S)).rowcount:
            return True
        return conn.execute("INSERT OR IGNORE INTO app_settings (key, value) VALUES (?, ?)", (key, str(now))).rowcount == 1
