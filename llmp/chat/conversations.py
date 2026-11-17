"""История диалогов. Каждый запрос фильтрует по owner_key — чужой диалог не прочитать и не изменить даже по чужому id.

Содержимое сообщений в норме только обезличенное; исключение — отправленные с явным флагом «без обезличивания»
(право allow_unanonymized), они хранятся открыто. Длинные сообщения хранятся сжатыми (llmp.compact) в обоих случаях.
Колонки *_z читаются через CAST(… AS BLOB): PHP писал часть из них как TEXT с двоичным содержимым."""

from __future__ import annotations

import json
import re
import sqlite3
import time
from typing import Callable

from .. import compact
from ..db import now_iso, transaction


def iso_days_ago(days: int) -> str:
    """gmdate('c', time() - days*86400)."""
    return time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime(time.time() - days * 86400))


def _conversation(r) -> dict:
    return {
        "id": int(r["id"]), "title": str(r["title"]),
        "provider_id": int(r["provider_id"]) if r["provider_id"] is not None else None,
        "project_id": int(r["project_id"]) if r["project_id"] is not None else None,
        "pinned": bool(r["pinned"]), "tokens_spent": int(r["tokens_spent"] or 0), "created_at": str(r["created_at"]), "updated_at": str(r["updated_at"]),
    }


class ConversationRepository:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def list_for_owner(self, owner_key: str, limit: int = 300) -> list[dict]:
        """Как в ChatGPT: последние использованные сверху."""
        limit = max(1, min(1000, limit))
        rows = self.conn.execute(
            "SELECT id, title, provider_id, project_id, pinned, tokens_spent, created_at, updated_at FROM llm_conversations "
            f"WHERE owner_key = ? ORDER BY updated_at DESC, id DESC LIMIT {limit}", (owner_key,),
        ).fetchall()
        return [_conversation(r) for r in rows]

    def find_owned(self, conversation_id: int, owner_key: str) -> dict | None:
        row = self.conn.execute(
            "SELECT id, owner_key, title, provider_id, created_at, updated_at, pinned, project_id, model_id, tokens_spent, "
            "CAST(instructions_z AS BLOB) AS instructions_z, style FROM llm_conversations WHERE id = ? AND owner_key = ?",
            (conversation_id, owner_key),
        ).fetchone()
        return dict(row) if row is not None else None

    def create(self, owner_key: str, title: str, provider_id: int, project_id: int | None = None) -> int:
        now = now_iso()
        cur = self.conn.execute(
            "INSERT INTO llm_conversations (owner_key, title, provider_id, project_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
            (owner_key, title, provider_id, project_id, now, now),
        )
        return int(cur.lastrowid)

    def messages(self, conversation_id: int, owner_key: str) -> list[dict]:
        rows = self.conn.execute(
            "SELECT m.id, m.role, m.content, CAST(m.content_z AS BLOB) AS content_z, m.provider_id, m.model, m.attachment_type, "
            "m.attachment_size, m.unanonymized, m.context_report, m.created_at, m.outbound_id IS NOT NULL AS can_flag "
            "FROM llm_messages m JOIN llm_conversations c ON c.id = m.conversation_id "
            "WHERE m.conversation_id = ? AND c.owner_key = ? ORDER BY m.id",
            (conversation_id, owner_key),
        ).fetchall()
        result = []
        for r in rows:
            row = dict(r)
            row["content"] = compact.decode(row["content"], row.pop("content_z"))
            row["unanonymized"] = bool(row["unanonymized"])
            row["can_flag"] = bool(row["can_flag"])
            result.append(row)
        return result

    def add_message(self, conversation_id: int, role: str, content: str, provider_id: int | None, model: str | None,
                    attachment_type: str | None, attachment_size: int | None, tokens: int | None = None,
                    unanonymized: bool = False, outbound_id: int | None = None) -> int:
        text, z = compact.encode(content)
        cur = self.conn.execute(
            "INSERT INTO llm_messages (conversation_id, role, content, content_z, provider_id, model, attachment_type, attachment_size, tokens, unanonymized, "
            "outbound_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            (conversation_id, role, text, z, provider_id, model, attachment_type, attachment_size, tokens, 1 if unanonymized else 0, outbound_id, now_iso()),
        )
        return int(cur.lastrowid)

    def flag_unmasked(self, conversation_id: int, message_id: int, owner_key: str) -> bool:
        """Пользователь отметил своё сообщение: «здесь не обезличено» — запись ленты аудита попадает в «Не разобрано»."""
        return self.conn.execute(
            "UPDATE llm_outbound SET user_flag_at = COALESCE(user_flag_at, ?) WHERE id = (SELECT m.outbound_id FROM llm_messages m "
            "JOIN llm_conversations c ON c.id = m.conversation_id WHERE m.id = ? AND m.conversation_id = ? AND c.owner_key = ? AND m.role = 'user') "
            "AND owner_key = ?", (now_iso(), message_id, conversation_id, owner_key, owner_key)).rowcount > 0

    def touch(self, conversation_id: int, provider_id: int) -> None:
        self.conn.execute("UPDATE llm_conversations SET provider_id = ?, updated_at = ? WHERE id = ?", (provider_id, now_iso(), conversation_id))

    def add_spent(self, conversation_id: int, tokens: int) -> None:
        """Расход токенов чата (ответы и сводки) — для лимита на один чат."""
        self.conn.execute("UPDATE llm_conversations SET tokens_spent = MAX(0, tokens_spent + ?) WHERE id = ?", (int(tokens), conversation_id))

    def set_model(self, conversation_id: int, model_id: int) -> None:
        """Модель диалога (llm_models.id) — чат восстанавливает выбор при открытии."""
        self.conn.execute("UPDATE llm_conversations SET model_id = ? WHERE id = ?", (model_id, conversation_id))

    def set_context_report(self, message_id: int, report: dict) -> None:
        self.conn.execute("UPDATE llm_messages SET context_report = ? WHERE id = ?", (json.dumps(report, separators=(",", ":")), message_id))

    def preferences(self, conversation_id: int | None, owner_key: str) -> dict:
        """Стиль и инструкции диалога и личные (тексты только обезличенные): {style, dialog, personal}."""
        u = self.conn.execute("SELECT style, CAST(instructions_z AS BLOB) AS z FROM llm_users WHERE owner_key = ?", (owner_key,)).fetchone()
        c = None
        if conversation_id is not None:
            c = self.conn.execute(
                "SELECT style, CAST(instructions_z AS BLOB) AS z FROM llm_conversations WHERE id = ? AND owner_key = ?", (conversation_id, owner_key)
            ).fetchone()
        return {
            "style": (c["style"] if c else None) or (u["style"] if u else None) or None,
            "dialog": compact.unpack(c["z"] if c else None),
            "personal": compact.unpack(u["z"] if u else None),
        }

    def set_user_preferences(self, owner_key: str, style: str | None, anonymized_instructions: str) -> None:
        self.conn.execute(
            "UPDATE llm_users SET style = ?, instructions_z = ? WHERE owner_key = ?",
            (style, compact.pack(anonymized_instructions) if anonymized_instructions != "" else None, owner_key),
        )

    def set_conversation_preferences(self, conversation_id: int, owner_key: str, style: str | None, anonymized_instructions: str | None) -> bool:
        z = compact.pack(anonymized_instructions) if anonymized_instructions else None
        return self._update_owned(conversation_id, owner_key, "style = ?, instructions_z = ?", (style, z))

    def set_pinned(self, conversation_id: int, owner_key: str, pinned: bool) -> bool:
        return self._update_owned(conversation_id, owner_key, "pinned = ?", (1 if pinned else 0,))

    def rename(self, conversation_id: int, owner_key: str, title: str) -> bool:
        return self._update_owned(conversation_id, owner_key, "title = ?", (title,))

    def move_to_project(self, conversation_id: int, owner_key: str, project_id: int | None) -> bool:
        """project_id уже проверен вызывающим кодом на принадлежность владельцу."""
        return self._update_owned(conversation_id, owner_key, "project_id = ?", (project_id,))

    def delete(self, conversation_id: int, owner_key: str) -> bool:
        return self.conn.execute("DELETE FROM llm_conversations WHERE id = ? AND owner_key = ?", (conversation_id, owner_key)).rowcount > 0

    def delete_many(self, ids: list[int], owner_key: str) -> int:
        """Выбранные диалоги владельца одним запросом (в PHP — циклом delete() в ChatService)."""
        ids = [int(i) for i in ids]
        if not ids:
            return 0
        marks = ",".join("?" * len(ids))
        return self.conn.execute(f"DELETE FROM llm_conversations WHERE owner_key = ? AND id IN ({marks})", (owner_key, *ids)).rowcount

    def delete_all_for_owner(self, owner_key: str) -> int:
        """Вся история пользователя, включая закреплённые; число удалённых диалогов."""
        return self.conn.execute("DELETE FROM llm_conversations WHERE owner_key = ?", (owner_key,)).rowcount

    def search(self, owner_key: str, query: str, limit: int = 30) -> list[dict]:
        """Поиск по заголовкам и тексту своих сообщений (по распакованному тексту); запрос нигде не сохраняется.
        Диалоги с полем snippet."""
        needle = query.strip().lower()
        if len(needle) < 2:
            return []
        by_id = {c["id"]: c for c in self.list_for_owner(owner_key, 1000)}
        found = {cid: {**c, "snippet": None} for cid, c in by_id.items() if needle in c["title"].lower()}
        rows = self.conn.execute(
            "SELECT m.conversation_id, m.content, CAST(m.content_z AS BLOB) AS content_z FROM llm_messages m "
            "JOIN llm_conversations c ON c.id = m.conversation_id "
            "WHERE c.owner_key = ? ORDER BY c.updated_at DESC, m.id DESC LIMIT 5000", (owner_key,),
        ).fetchall()
        for r in rows:
            cid = int(r["conversation_id"])
            if cid in found and found[cid]["snippet"] is not None:
                continue
            content = compact.decode(r["content"], r["content_z"])
            pos = content.lower().find(needle)
            if pos < 0 or cid not in by_id:
                continue
            start = max(0, pos - 40)
            snippet = ("…" if start > 0 else "") + re.sub(r"\s+", " ", content[start:start + 120]).strip() + "…"
            found[cid] = {**by_id[cid], "snippet": snippet}
        return sorted(found.values(), key=lambda c: c["updated_at"], reverse=True)[:limit]

    def delete_older_than(self, owner_key: str, days: int) -> int:
        """Диалоги, не использовавшиеся дольше days дней (срок хранения); закреплённые остаются."""
        if days <= 0:
            return 0
        return self.conn.execute(
            "DELETE FROM llm_conversations WHERE owner_key = ? AND pinned = 0 AND updated_at < ?", (owner_key, iso_days_ago(days))
        ).rowcount

    def transactional(self, callback: Callable[[], object]):
        with transaction(self.conn):
            return callback()

    def _update_owned(self, conversation_id: int, owner_key: str, set_sql: str, params: tuple) -> bool:
        return self.conn.execute(
            f"UPDATE llm_conversations SET {set_sql} WHERE id = ? AND owner_key = ?", (*params, conversation_id, owner_key)
        ).rowcount > 0
