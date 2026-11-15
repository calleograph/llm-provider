"""Автоочистка содержимого у пользователей, не заходивших дольше порога (по умолчанию 90 дней).

Стирается только содержимое: текст сообщений, текст источников (chars = 0), сводки диалогов и состояние проектов.
Диалоги и проекты как сущности (заголовки, даты) остаются. Запускается лениво при входе любого пользователя."""

from __future__ import annotations

import sqlite3

from .conversations import iso_days_ago

DEFAULT_THRESHOLD_DAYS = 90


class InactivityCleanupService:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def cleanup_inactive(self, threshold_days: int = DEFAULT_THRESHOLD_DAYS) -> dict:
        """{owners, messages, sources} — сколько затронуто."""
        cutoff = iso_days_ago(max(1, threshold_days))
        owners = [r[0] for r in self.conn.execute("SELECT owner_key FROM llm_users WHERE last_seen_at < ?", (cutoff,))]
        if not owners:
            return {"owners": 0, "messages": 0, "sources": 0}
        marks = ",".join("?" * len(owners))
        # Только строки, ещё содержащие текст, — уже очищенные не трогаются на каждом запуске.
        messages = self.conn.execute(
            "UPDATE llm_messages SET content = '', content_z = NULL "
            f"WHERE conversation_id IN (SELECT id FROM llm_conversations WHERE owner_key IN ({marks})) "
            "AND (LENGTH(content) > 0 OR content_z IS NOT NULL)", owners,
        ).rowcount
        sources = self.conn.execute(
            "UPDATE llm_project_sources SET content_z = X'', chars = 0 "
            f"WHERE project_id IN (SELECT id FROM llm_projects WHERE owner_key IN ({marks})) AND chars > 0", owners,
        ).rowcount
        # Производные от содержимого — иначе «очищенное» уходило бы в модель при продолжении старого чата.
        self.conn.execute(f"DELETE FROM llm_conversation_summaries WHERE conversation_id IN (SELECT id FROM llm_conversations WHERE owner_key IN ({marks}))", owners)
        self.conn.execute(f"UPDATE llm_projects SET state_z = NULL WHERE owner_key IN ({marks}) AND state_z IS NOT NULL", owners)
        return {"owners": len(owners), "messages": messages, "sources": sources}
