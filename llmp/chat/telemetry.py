"""Телеметрия: журнал того, что ушло во внешние нейросети (llm_outbound), независимый от удаления диалогов пользователем.

Пишется сразу после обращения к LLM (успех или ошибка): кто, какая модель, сколько токенов, сколько длилось и
точный текст сообщения в том виде, в каком он ушёл (обезличенный; «без обезличивания» — как ушёл, с флагом).
Текст хранится сжатым (llmp.compact.pack). Сбой записи телеметрии никогда не ломает отправку — только диагностика.
Срок хранения — llm_chat_telemetry_retention_days (0 — бессрочно), зачистку раз в час делает планировщик."""

from __future__ import annotations

import sqlite3
import time

from .. import compact
from ..db import now_iso
from ..logs import DiagnosticLogger
from .conversations import iso_days_ago
from .core import ChatUser

KINDS = ("chat", "summary", "project_state")
_FILTERS = ("owner_key", "model_name", "kind")


def _search_text(blob) -> str:
    try:
        return compact.unpack(blob).casefold()
    except RuntimeError:
        return ""


class Telemetry:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    # ---- запись ----
    def record(self, user: ChatUser, kind: str, *, provider_id: int | None, provider_name: str | None, model_id: int | None,
               model_name: str | None, outcome: str = "ok", text: str | None = None, conversation_id: int | None = None,
               project_id: int | None = None, fallback_from: str | None = None, unanonymized: bool = False,
               attachment_types: str | None = None, attachment_count: int = 0, input_tokens: int | None = None,
               output_tokens: int | None = None, tokens: int = 0, duration_ms: int = 0) -> int | None:
        """id записи или None, если записать не удалось (причина — в diagnostic_log)."""
        try:
            cur = self.conn.execute(
                "INSERT INTO llm_outbound (created_at, owner_key, username, domain, conversation_id, project_id, provider_id, provider_name, "
                "model_id, model_name, fallback_from, kind, text_z, text_chars, unanonymized, attachment_types, attachment_count, "
                "input_tokens, output_tokens, tokens, duration_ms, outcome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                (now_iso(), user.owner_key(), user.username, user.domain_fqdn, conversation_id, project_id, provider_id, provider_name,
                 model_id, model_name, fallback_from, kind, compact.pack(text) if text else None, len(text or ""), int(unanonymized),
                 attachment_types, attachment_count, input_tokens, output_tokens, tokens, duration_ms, outcome))
            return int(cur.lastrowid)
        except Exception as e:  # noqa: BLE001 — телеметрия не должна мешать пользователю
            try:
                DiagnosticLogger(self.conn).error("telemetry", "TELEMETRY_WRITE_FAILED", f"{type(e).__name__}: {e}", {"kind": kind})
            except Exception:  # noqa: BLE001
                pass
            return None

    def attach_conversation(self, record_id: int | None, conversation_id: int) -> None:
        """Новый диалог создаётся после ответа — его id дописывается к уже сделанной записи."""
        if record_id is None:
            return
        try:
            self.conn.execute("UPDATE llm_outbound SET conversation_id = ? WHERE id = ?", (conversation_id, record_id))
        except Exception:  # noqa: BLE001
            pass

    def purge(self, days: int) -> int:
        if days <= 0:
            return 0
        return self.conn.execute("DELETE FROM llm_outbound WHERE created_at < ?", (iso_days_ago(days),)).rowcount

    # ---- чтение (админка) ----
    def _where(self, f: dict) -> tuple[str, list]:
        where, params = ["created_at >= ?", "created_at < ?"], [f["since"], f["until"]]
        for col in _FILTERS:
            if f.get(col):
                where.append(f"{col} = ?")
                params.append(f[col])
        if f.get("unanonymized"):
            where.append("unanonymized = 1")
        if f.get("q"):
            # сначала индексные условия (период, пользователь…), распаковка текста — только для оставшихся строк
            self.conn.create_function("llmp_outbound_text", 1, _search_text, deterministic=True)
            where.append("instr(llmp_outbound_text(text_z), ?) > 0")
            params.append(f["q"].casefold())
        return " AND ".join(where), params

    def query(self, f: dict, limit: int = 50, offset: int = 0) -> dict:
        w, p = self._where(f)
        total = self.conn.execute(f"SELECT COUNT(*) FROM llm_outbound WHERE {w}", p).fetchone()[0]
        rows = self.conn.execute(
            f"SELECT id, created_at, owner_key, conversation_id, project_id, provider_name, model_name, fallback_from, kind, text_chars, "
            f"unanonymized, attachment_types, attachment_count, input_tokens, output_tokens, tokens, duration_ms, outcome "
            f"FROM llm_outbound WHERE {w} ORDER BY id DESC LIMIT ? OFFSET ?", [*p, limit, offset]).fetchall()
        return {"rows": [dict(r) for r in rows], "total": int(total)}

    def iter_export(self, f: dict, limit: int = 50000):
        w, p = self._where(f)
        for r in self.conn.execute(f"SELECT *, CAST(text_z AS BLOB) AS text_blob FROM llm_outbound WHERE {w} ORDER BY id DESC LIMIT ?", [*p, limit]):
            row = dict(r)
            row.pop("text_z")
            row["text"] = compact.unpack(row.pop("text_blob"))
            yield row

    def get(self, record_id: int) -> dict | None:
        r = self.conn.execute("SELECT *, CAST(text_z AS BLOB) AS text_blob FROM llm_outbound WHERE id = ?", (record_id,)).fetchone()
        if r is None:
            return None
        row = dict(r)
        row.pop("text_z")
        row["text"] = compact.unpack(row.pop("text_blob"))
        return row

    def overview(self, since: str, until: str) -> dict:
        p = (since, until)
        w = "created_at >= ? AND created_at < ?"
        t = self.conn.execute(
            f"SELECT COUNT(*) AS requests, COALESCE(SUM(tokens), 0) AS tokens, COUNT(DISTINCT owner_key) AS users, "
            f"COALESCE(SUM(unanonymized), 0) AS unanonymized, COALESCE(SUM(outcome <> 'ok'), 0) AS errors FROM llm_outbound WHERE {w}", p).fetchone()
        top = lambda col, order: [dict(r) for r in self.conn.execute(  # noqa: E731
            f"SELECT {col} AS name, COUNT(*) AS requests, COALESCE(SUM(tokens), 0) AS tokens FROM llm_outbound WHERE {w} "
            f"GROUP BY {col} ORDER BY {order} DESC LIMIT 10", p)]
        return {"totals": dict(t), "top_tokens": top("owner_key", "tokens"), "top_requests": top("owner_key", "requests"),
                "top_models": top("model_name", "requests")}

    def model_names(self) -> list[str]:
        return [r[0] for r in self.conn.execute("SELECT DISTINCT model_name FROM llm_outbound WHERE model_name IS NOT NULL ORDER BY 1")]

    def user_conversations(self, owner_key: str, limit: int = 300) -> list[dict]:
        rows = self.conn.execute(
            "SELECT c.id, c.title, c.created_at, c.updated_at, p.name AS project, "
            "(SELECT COUNT(*) FROM llm_messages m WHERE m.conversation_id = c.id) AS messages, "
            "(SELECT COALESCE(SUM(m.tokens), 0) FROM llm_messages m WHERE m.conversation_id = c.id) AS tokens "
            "FROM llm_conversations c LEFT JOIN llm_projects p ON p.id = c.project_id WHERE c.owner_key = ? "
            "ORDER BY c.updated_at DESC, c.id DESC LIMIT ?", (owner_key, limit)).fetchall()
        return [dict(r) for r in rows]

    def conversation(self, conversation_id: int) -> dict | None:
        r = self.conn.execute(
            "SELECT c.id, c.owner_key, c.title, c.created_at, c.updated_at, p.name AS project FROM llm_conversations c "
            "LEFT JOIN llm_projects p ON p.id = c.project_id WHERE c.id = ?", (conversation_id,)).fetchone()
        return dict(r) if r is not None else None


def elapsed_ms(started: float) -> int:
    return int((time.monotonic() - started) * 1000)
