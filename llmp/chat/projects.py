"""Проекты пользователя (как в ChatGPT/Claude Projects и по схеме Hermes, см. docs/PROJECTS.md):
инструкции (основной текст в llm_projects.instructions_z + необязательные именованные блоки), материалы
(llm_project_sources), память проекта (state_z — ведётся автоматически, пользователь может править и очищать)
и настройки проекта (settings, JSON). Всё, что уходит в модель, хранится только обезличенным (AnonymizedText) и
сжатым; название проекта в модель не передаётся. Все методы фильтруют по owner_key."""

from __future__ import annotations

import json
import sqlite3

from .. import compact
from ..anon.text import AnonymizedText
from ..db import now_iso

TYPE_INSTRUCTION = "instruction"   # source_type именованных блоков инструкций (без нумерации в контексте)
PHP_WS = " \t\n\r\0\x0b"           # набор trim() PHP
# Настройки проекта: модель новых чатов (id, 0 — как выбрано в чате), стиль ("" — как у пользователя),
# передавать ли память и материалы, гарантированная доля окна под материалы (%).
DEFAULT_SETTINGS = {"model_id": 0, "style": "", "use_memory": True, "use_sources": True, "sources_share": 40}
SOURCES_SHARES = (20, 40, 60, 80)


def parse_settings(raw: str | None) -> dict:
    """Сохранённые настройки поверх значений по умолчанию; значение чужого типа — по умолчанию."""
    try:
        stored = json.loads(raw) if raw else {}
    except ValueError:
        stored = {}
    if not isinstance(stored, dict):
        stored = {}
    return {k: stored[k] if type(stored.get(k)) is type(v) else v for k, v in DEFAULT_SETTINGS.items()}


class ProjectRepository:
    TYPE_INSTRUCTION = TYPE_INSTRUCTION

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def list_for_owner(self, owner_key: str) -> list[dict]:
        rows = self.conn.execute(
            "SELECT p.id, p.name, p.updated_at, p.pinned, p.settings, (SELECT COUNT(*) FROM llm_project_sources s WHERE s.project_id = p.id) AS sources "
            "FROM llm_projects p WHERE p.owner_key = ? ORDER BY p.pinned DESC, p.updated_at DESC, p.id DESC", (owner_key,),
        ).fetchall()
        return [{"id": int(r["id"]), "name": str(r["name"]), "updated_at": str(r["updated_at"]),
                 "sources": int(r["sources"]), "pinned": bool(r["pinned"]), "model_id": parse_settings(r["settings"])["model_id"]} for r in rows]

    def find_owned(self, project_id: int, owner_key: str) -> dict | None:
        r = self.conn.execute(
            "SELECT id, name, CAST(instructions_z AS BLOB) AS z, instructions_chars, settings FROM llm_projects WHERE id = ? AND owner_key = ?",
            (project_id, owner_key),
        ).fetchone()
        if r is None:
            return None
        return {"id": int(r["id"]), "name": str(r["name"]), "instructions": compact.unpack(r["z"]), "instructions_chars": int(r["instructions_chars"]),
                "settings": parse_settings(r["settings"])}

    def create(self, owner_key: str, name: str) -> int:
        now = now_iso()
        return int(self.conn.execute(
            "INSERT INTO llm_projects (owner_key, name, created_at, updated_at) VALUES (?, ?, ?, ?)", (owner_key, name, now, now)
        ).lastrowid)

    def rename(self, project_id: int, owner_key: str, name: str) -> bool:
        return self.conn.execute(
            "UPDATE llm_projects SET name = ?, updated_at = ? WHERE id = ? AND owner_key = ?", (name, now_iso(), project_id, owner_key)
        ).rowcount > 0

    def set_pinned(self, project_id: int, owner_key: str, pinned: bool) -> bool:
        return self.conn.execute(
            "UPDATE llm_projects SET pinned = ?, updated_at = ? WHERE id = ? AND owner_key = ?", (1 if pinned else 0, now_iso(), project_id, owner_key)
        ).rowcount > 0

    def set_instructions(self, project_id: int, owner_key: str, instructions: AnonymizedText) -> bool:
        z = None if instructions.is_empty() else compact.pack(instructions.value)
        return self.conn.execute(
            "UPDATE llm_projects SET instructions_z = ?, instructions_chars = ?, updated_at = ? WHERE id = ? AND owner_key = ?",
            (z, len(instructions.value), now_iso(), project_id, owner_key),
        ).rowcount > 0

    def delete(self, project_id: int, owner_key: str) -> bool:
        """Чаты проекта остаются, но выходят из проекта."""
        self.conn.execute("UPDATE llm_conversations SET project_id = NULL WHERE project_id = ? AND owner_key = ?", (project_id, owner_key))
        return self.conn.execute("DELETE FROM llm_projects WHERE id = ? AND owner_key = ?", (project_id, owner_key)).rowcount > 0

    def touch(self, project_id: int) -> None:
        self.conn.execute("UPDATE llm_projects SET updated_at = ? WHERE id = ?", (now_iso(), project_id))

    def add_source(self, project_id: int, title: str, source_type: str, file_type: str | None, content: AnonymizedText) -> int:
        """project_id уже проверен на принадлежность владельцу."""
        cur = self.conn.execute(
            "INSERT INTO llm_project_sources (project_id, title, source_type, file_type, content_z, chars, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            (project_id, title, source_type, file_type, compact.pack(content.value), len(content.value), now_iso()),
        )
        self.touch(project_id)
        return int(cur.lastrowid)

    def sources(self, project_id: int, owner_key: str) -> list[dict]:
        rows = self.conn.execute(
            "SELECT s.id, s.title, s.source_type, s.file_type, s.chars, s.created_at FROM llm_project_sources s "
            "JOIN llm_projects p ON p.id = s.project_id WHERE s.project_id = ? AND p.owner_key = ? AND s.source_type != ? ORDER BY s.id",
            (project_id, owner_key, TYPE_INSTRUCTION),
        ).fetchall()
        return [{"id": int(r["id"]), "title": str(r["title"]), "source_type": str(r["source_type"]),
                 "file_type": str(r["file_type"]) if r["file_type"] is not None else None,
                 "chars": int(r["chars"]), "created_at": str(r["created_at"])} for r in rows]

    def instructions(self, project_id: int, owner_key: str) -> list[dict]:
        """Именованные блоки инструкций: [{id, title, chars, created_at}]."""
        rows = self.conn.execute(
            "SELECT s.id, s.title, s.chars, s.created_at FROM llm_project_sources s "
            "JOIN llm_projects p ON p.id = s.project_id WHERE s.project_id = ? AND p.owner_key = ? AND s.source_type = ? ORDER BY s.id",
            (project_id, owner_key, TYPE_INSTRUCTION),
        ).fetchall()
        return [{"id": int(r["id"]), "title": str(r["title"]), "chars": int(r["chars"]), "created_at": str(r["created_at"])} for r in rows]

    def total_source_chars(self, project_id: int) -> int:
        return int(self.conn.execute(
            "SELECT COALESCE(SUM(chars), 0) FROM llm_project_sources WHERE project_id = ? AND source_type != ?", (project_id, TYPE_INSTRUCTION)
        ).fetchone()[0])

    def source_count(self, project_id: int) -> int:
        return int(self.conn.execute(
            "SELECT COUNT(*) FROM llm_project_sources WHERE project_id = ? AND source_type != ?", (project_id, TYPE_INSTRUCTION)
        ).fetchone()[0])

    def instruction_count(self, project_id: int) -> int:
        return int(self.conn.execute(
            "SELECT COUNT(*) FROM llm_project_sources WHERE project_id = ? AND source_type = ?", (project_id, TYPE_INSTRUCTION)
        ).fetchone()[0])

    def delete_source(self, source_id: int, owner_key: str) -> bool:
        return self.conn.execute(
            "DELETE FROM llm_project_sources WHERE id = ? AND project_id IN (SELECT id FROM llm_projects WHERE owner_key = ?)", (source_id, owner_key)
        ).rowcount > 0

    def source_text(self, source_id: int, owner_key: str) -> str | None:
        """Полный обезличенный текст одного источника или блока инструкции — только владельцу."""
        row = self.conn.execute(
            "SELECT CAST(s.content_z AS BLOB) FROM llm_project_sources s JOIN llm_projects p ON p.id = s.project_id WHERE s.id = ? AND p.owner_key = ?",
            (source_id, owner_key),
        ).fetchone()
        return compact.unpack(row[0]) if row is not None else None

    def _rows(self, project_id: int) -> list[tuple[str, str, str]]:
        rows = self.conn.execute(
            "SELECT title, source_type, CAST(content_z AS BLOB) FROM llm_project_sources WHERE project_id = ? ORDER BY id", (project_id,)
        ).fetchall()
        return [(str(r[0]), str(r[1]), compact.unpack(r[2])) for r in rows]

    def context_parts(self, project_id: int, owner_key: str) -> dict | None:
        """Части контекста для ContextBuilder: {instructions, sources: [{title, text}], state, description, settings}.
        instructions — основной текст и блоки; всё уже обезличено. Флаги настроек применяет вызывающий."""
        project = self.find_owned(project_id, owner_key)
        if project is None:
            return None
        rows = self._rows(project_id)
        m = self.conn.execute("SELECT description, CAST(state_z AS BLOB) AS state_z, state_updated_at FROM llm_projects WHERE id = ?", (project_id,)).fetchone()
        main = project["instructions"].strip(PHP_WS)
        return {
            "instructions": "\n\n".join(([main] if main else []) + [f"[Инструкция: {t}]\n{text}" for t, typ, text in rows if typ == TYPE_INSTRUCTION]),
            "sources": [{"title": t, "text": text} for t, typ, text in rows if typ != TYPE_INSTRUCTION],
            "state": compact.unpack(m["state_z"]),
            "state_updated_at": m["state_updated_at"],
            "description": str(m["description"] or ""),
            "settings": project["settings"],
        }

    def set_description(self, project_id: int, anonymized_description: str) -> None:
        self.conn.execute("UPDATE llm_projects SET description = ?, updated_at = ? WHERE id = ?", (anonymized_description, now_iso(), project_id))

    def set_memory(self, project_id: int, owner_key: str, memory: AnonymizedText) -> bool:
        """Правка или очистка памяти проекта пользователем (пустой текст — очистить)."""
        z = None if memory.is_empty() else compact.pack(memory.value)
        return self.conn.execute(
            "UPDATE llm_projects SET state_z = ?, state_updated_at = ? WHERE id = ? AND owner_key = ?", (z, now_iso() if z else None, project_id, owner_key)
        ).rowcount > 0

    def set_settings(self, project_id: int, owner_key: str, settings: dict) -> bool:
        """settings уже проверены сервисом; хранятся только известные ключи."""
        clean = {k: settings.get(k, v) for k, v in DEFAULT_SETTINGS.items()}
        return self.conn.execute(
            "UPDATE llm_projects SET settings = ?, updated_at = ? WHERE id = ? AND owner_key = ?", (json.dumps(clean), now_iso(), project_id, owner_key)
        ).rowcount > 0
