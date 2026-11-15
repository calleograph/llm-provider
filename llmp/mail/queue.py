"""Очередь писем (на SQLite): тело зашифровано, пока ждёт отправки, и стирается после приёма сервером;
захват записи с арендой 5 минут (зависшая отправка возвращается в очередь); повтор только для временных ошибок,
не больше 5 попыток, пауза min(15 мин, 1 мин·2ⁿ⁻¹); срок жизни письма — 24 ч; журнал — 30 дней.
Шаблоны уведомлений — здесь же (без пользовательских данных и секретов). Таблица mail_queue — в llmp/schema.py."""

from __future__ import annotations

import json
import re
import sqlite3
import time
from collections.abc import Callable

from .. import secretbox
from .settings import MailSettings
from .smtp import RETRYABLE, SmtpClient

MAX_ATTEMPTS = 5
LEASE_S = 300
TEMPLATES = {
    "test": ("Проверка почты LLM-провайдера", "Это тестовое письмо. Если вы его получили — отправка почты настроена правильно.\n\nСервер: {host}\nВремя: {time} UTC"),
    "model_alert": ("LLM-провайдер: проблема с моделью «{model}»", "Модель «{model}» ({provider}) временно исключена из работы: {reason}.\nПауза до {until} UTC. Запросы сотрудников обслуживают резервные модели.\n\nПодробности — Администрирование → Модели и резервирование."),
    "all_failed": ("LLM-провайдер: все модели недоступны", "Ни одна модель цепочки не ответила на запрос сотрудника.\nПоследняя причина: {reason}.\n\nПроверьте раздел «Модели и резервирование» и состояние провайдеров."),
}


def _iso(ts: float) -> str:
    """gmdate('c', ts) PHP."""
    return time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime(ts))


def _strtr(s: str, replace: dict[str, str]) -> str:
    """strtr($s, array) PHP: одновременная замена, длинные ключи первыми, подставленное не заменяется повторно."""
    if not replace:
        return s
    pattern = "|".join(re.escape(k) for k in sorted(replace, key=len, reverse=True))
    return re.sub(pattern, lambda m: replace[m.group(0)], s)


class MailQueue:
    MAX_ATTEMPTS = MAX_ATTEMPTS
    TEMPLATES = TEMPLATES

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def enqueue(self, template: str, to: str, variables: dict[str, str]) -> int:
        if template not in TEMPLATES:
            raise ValueError("Неизвестный шаблон письма.")
        subject, body = TEMPLATES[template]
        replace = {"{" + k + "}": str(v)[:300].replace("\r", " ").replace("\n", " ") for k, v in variables.items()}
        payload = secretbox.encrypt(json.dumps({"subject": _strtr(subject, replace), "body": _strtr(body, replace)}, ensure_ascii=False))
        now = int(time.time())
        cur = self.conn.execute(
            "INSERT INTO mail_queue (template, to_addr, payload_enc, next_attempt_at, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)",
            (template, to, payload, now, _iso(now), now + 86400),
        )
        return int(cur.lastrowid)

    def process(self, settings: MailSettings, limit: int = 20, client_factory: Callable[[], SmtpClient] | None = None) -> int:
        """Обработать готовые к отправке письма; возвращает число отправленных."""
        if not settings.is_configured():
            return 0
        sent = 0
        self.conn.execute("UPDATE mail_queue SET status = 'failed', payload_enc = NULL, last_error = 'expired' "
                          "WHERE status IN ('queued','sending') AND expires_at < ?", (int(time.time()),))
        for _ in range(limit):
            now = int(time.time())
            row = self.conn.execute(
                "SELECT * FROM mail_queue WHERE (status = 'queued' AND next_attempt_at <= ?) OR (status = 'sending' AND locked_at < ?) "
                "ORDER BY next_attempt_at, id LIMIT 1", (now, now - LEASE_S)).fetchone()
            if row is None:
                break
            claim = self.conn.execute(
                "UPDATE mail_queue SET status = 'sending', locked_at = ?, attempts = attempts + 1 "
                "WHERE id = ? AND (status = 'queued' OR (status = 'sending' AND locked_at < ?))", (now, row["id"], now - LEASE_S))
            if claim.rowcount == 0:
                continue   # перехватил другой процесс
            try:
                payload = json.loads(secretbox.decrypt(str(row["payload_enc"] or "")))
                subject, body = str(payload["subject"]), str(payload["body"])
            except Exception:   # письмо не расшифровать — снимаем его, иначе оно каждую минуту блокирует очередь
                self.conn.execute("UPDATE mail_queue SET status = 'failed', payload_enc = NULL, last_error = 'decrypt' WHERE id = ?", (row["id"],))
                continue
            client = client_factory() if client_factory is not None else SmtpClient(settings)
            result = client.check(str(row["to_addr"]), subject, body)
            attempts = int(row["attempts"]) + 1
            if result["ok"]:
                self.conn.execute("UPDATE mail_queue SET status = 'sent', payload_enc = NULL, sent_at = ?, last_error = NULL WHERE id = ?",
                                  (_iso(time.time()), row["id"]))
                sent += 1
            elif result["code"] in RETRYABLE and attempts < MAX_ATTEMPTS:
                delay = min(900, 60 * 2 ** (attempts - 1))
                self.conn.execute("UPDATE mail_queue SET status = 'queued', next_attempt_at = ?, last_error = ? WHERE id = ?",
                                  (int(time.time()) + delay, result["code"], row["id"]))
            else:
                self.conn.execute("UPDATE mail_queue SET status = 'failed', payload_enc = NULL, last_error = ? WHERE id = ?", (result["code"], row["id"]))
        self.conn.execute("DELETE FROM mail_queue WHERE created_at < ? AND status IN ('sent','failed')", (_iso(time.time() - 30 * 86400),))
        return sent

    def recent(self, limit: int = 50) -> list[dict]:
        """Журнал без содержимого писем."""
        rows = self.conn.execute("SELECT id, status, template, to_addr, attempts, last_error, created_at, sent_at FROM mail_queue ORDER BY id DESC LIMIT ?", (limit,))
        return [dict(r) for r in rows]
