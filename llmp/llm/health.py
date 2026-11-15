"""Состояние моделей между запросами (llm_model_health) и журнал проблем (llm_model_incidents).

Пишется только безопасное: коды, статусы, длительности, токены, стоимость, техническая причина без текста запросов и ответов."""

from __future__ import annotations

import random
import sqlite3
import time

from ..db import now_iso


class ModelHealthRepository:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def _ensure(self, model_id: int) -> None:
        self.conn.execute("INSERT OR IGNORE INTO llm_model_health (model_id) VALUES (?)", (model_id,))

    def record_success(self, model_id: int, ms: int, tokens_in: int | None, tokens_out: int | None, cost: float) -> None:
        self._ensure(model_id)
        self.conn.execute(
            "UPDATE llm_model_health SET success_count = success_count + 1, consecutive_failures = 0, cooldown_until = 0, total_ms = total_ms + ?, "
            "last_ms = ?, tokens_in = tokens_in + ?, tokens_out = tokens_out + ?, cost = cost + ?, last_success_at = ? WHERE model_id = ?",
            (ms, ms, int(tokens_in or 0), int(tokens_out or 0), cost, now_iso(), model_id),
        )

    def record_failure(self, model_id: int, code: str, admin_message: str, cooldown_seconds: int) -> int:
        """Ошибка попытки; cooldown_seconds > 0 — модель временно исключается из выбора (кроме случая, когда исключены все)."""
        self._ensure(model_id)
        self.conn.execute(
            "UPDATE llm_model_health SET error_count = error_count + 1, consecutive_failures = consecutive_failures + 1, "
            "empty_count = empty_count + ?, last_error_at = ?, last_error_code = ?, last_error_message = ?, "
            "cooldown_until = CASE WHEN ? > 0 THEN MAX(cooldown_until, ?) ELSE cooldown_until END WHERE model_id = ?",
            (1 if code == "empty_response" else 0, now_iso(), code, admin_message[:300], cooldown_seconds, int(time.time()) + cooldown_seconds, model_id),
        )
        return self.consecutive_failures(model_id)

    def record_fallback(self, from_model_id: int) -> None:
        self._ensure(from_model_id)
        self.conn.execute("UPDATE llm_model_health SET fallback_count = fallback_count + 1 WHERE model_id = ?", (from_model_id,))

    def consecutive_failures(self, model_id: int) -> int:
        row = self.conn.execute("SELECT consecutive_failures FROM llm_model_health WHERE model_id = ?", (model_id,)).fetchone()
        return int(row[0]) if row else 0

    def cooling_until(self, model_id: int) -> int:
        row = self.conn.execute("SELECT cooldown_until FROM llm_model_health WHERE model_id = ?", (model_id,)).fetchone()
        until = int(row[0]) if row else 0
        return until if until > time.time() else 0

    def reset_cooldown(self, model_id: int) -> None:
        self.conn.execute("UPDATE llm_model_health SET cooldown_until = 0, consecutive_failures = 0 WHERE model_id = ?", (model_id,))

    def incident(self, model_id: int | None, provider_id: int | None, code: str, http_status: int | None, message: str, action: str,
                 fallback_model_id: int | None = None) -> None:
        self.conn.execute(
            "INSERT INTO llm_model_incidents (created_at, model_id, provider_id, code, http_status, message, action, fallback_model_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            (now_iso(), model_id, provider_id, code, http_status, message[:300], action, fallback_model_id),
        )
        # Журнал не растёт бесконечно: держим последние 5000 записей.
        if random.randint(1, 50) == 1:
            self.conn.execute("DELETE FROM llm_model_incidents WHERE id <= (SELECT MAX(id) - 5000 FROM llm_model_incidents)")

    def stats(self) -> dict[int, dict]:
        """По id модели."""
        return {int(r["model_id"]): dict(r) for r in self.conn.execute("SELECT * FROM llm_model_health")}

    def incidents(self, limit: int = 100) -> list[dict]:
        return [dict(r) for r in self.conn.execute(
            "SELECT i.*, m.display_name AS model_name, f.display_name AS fallback_name, p.name AS provider_name "
            "FROM llm_model_incidents i LEFT JOIN llm_models m ON m.id = i.model_id LEFT JOIN llm_models f ON f.id = i.fallback_model_id "
            "LEFT JOIN llm_providers p ON p.id = i.provider_id ORDER BY i.id DESC LIMIT ?",
            (max(1, min(1000, limit)),),
        )]
