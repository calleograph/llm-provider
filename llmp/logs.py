"""Журналы: audit_log (действия администраторов), app_log (операции пользователей), diagnostic_log (технические
события, без паролей), сводная лента и журнал событий LLM. Таблицы и форматы — как у версии 1.x."""

from __future__ import annotations

import json
import math
import re
import sqlite3

from . import paths
from .db import now_iso

INFO, WARNING, ERROR = "info", "warning", "error"
LEVELS = (INFO, WARNING, ERROR)
SOURCES = (
    "ldap_bind", "ldap_search", "ldap_modify", "ldap_connect", "tls", "kpasswd", "connection_test", "certificate",
    "php_error", "ad_link_status", "kerberos_group_sync", "chat_auth", "anonymization", "llm_gateway", "file_processing", "telemetry",
)
LOG_TABLES = ("app_log", "audit_log", "diagnostic_log")


def _page(conn: sqlite3.Connection, table: str, sortable: tuple[str, ...], page: int, per_page: int, sort: str, direction: str) -> dict:
    per_page = max(1, min(200, per_page))
    offset = (max(1, page) - 1) * per_page
    sort = sort if sort in sortable else "created_at"
    direction = "ASC" if direction.lower() == "asc" else "DESC"
    total = conn.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
    rows = conn.execute(f"SELECT * FROM {table} ORDER BY {sort} {direction}, id {direction} LIMIT {per_page} OFFSET {offset}").fetchall()
    return {"rows": [dict(r) for r in rows], "total": total}


class AuditLogger:
    SORTABLE = ("created_at", "actor", "ip", "action", "success")

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def log(self, actor: str, ip: str, action: str, success: bool, details: str | None = None) -> None:
        self.conn.execute(
            "INSERT INTO audit_log (created_at, actor, ip, action, details, success) VALUES (?, ?, ?, ?, ?, ?)",
            (now_iso(), actor, ip, action, details, 1 if success else 0),
        )

    def recent(self, limit: int = 200) -> list[dict]:
        limit = max(1, min(1000, limit))
        return [dict(r) for r in self.conn.execute(f"SELECT * FROM audit_log ORDER BY id DESC LIMIT {limit}")]

    def paginated(self, page: int, per_page: int, sort: str = "created_at", direction: str = "desc") -> dict:
        return _page(self.conn, "audit_log", self.SORTABLE, page, per_page, sort, direction)


class AppLogger:
    SORTABLE = ("created_at", "ip", "username", "operation", "result")

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def log(self, ip: str, username: str | None, operation: str, result: str) -> None:
        self.conn.execute(
            "INSERT INTO app_log (created_at, ip, username, operation, result) VALUES (?, ?, ?, ?, ?)",
            (now_iso(), ip, username, operation, result),
        )

    def recent(self, limit: int = 200) -> list[dict]:
        limit = max(1, min(1000, limit))
        return [dict(r) for r in self.conn.execute(f"SELECT * FROM app_log ORDER BY id DESC LIMIT {limit}")]

    def paginated(self, page: int, per_page: int, sort: str = "created_at", direction: str = "desc") -> dict:
        return _page(self.conn, "app_log", self.SORTABLE, page, per_page, sort, direction)


_SECRET_KEY_PARTS = ("password", "pwd", "secret", "token", "credential")
_SECRET_IN_TEXT = re.compile(r"(password|pwd|secret|token)\s*[=:]\s*\S+", re.I)


def redact_string(value: str) -> str:
    return _SECRET_IN_TEXT.sub(r"\1=[redacted]", value)


def redact_context(context: dict) -> dict:
    out = {}
    for k, v in context.items():
        if any(p in str(k).lower() for p in _SECRET_KEY_PARTS):
            out[k] = "[redacted]"
        else:
            out[k] = redact_string(v) if isinstance(v, str) else v
    return out


class DiagnosticLogger:
    SORTABLE = ("created_at", "level", "source", "event_code")

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def log(self, level: str, source: str, event_code: str, message: str, context: dict | None = None, ip: str | None = None) -> None:
        safe = redact_context(context or {})
        self.conn.execute(
            "INSERT INTO diagnostic_log (created_at, level, source, event_code, message, context, ip) VALUES (?, ?, ?, ?, ?, ?, ?)",
            (now_iso(), level, source, event_code, redact_string(message), json.dumps(safe, ensure_ascii=False) if safe else None, ip),
        )

    def info(self, source: str, code: str, message: str, context: dict | None = None, ip: str | None = None) -> None:
        self.log(INFO, source, code, message, context, ip)

    def warning(self, source: str, code: str, message: str, context: dict | None = None, ip: str | None = None) -> None:
        self.log(WARNING, source, code, message, context, ip)

    def error(self, source: str, code: str, message: str, context: dict | None = None, ip: str | None = None) -> None:
        self.log(ERROR, source, code, message, context, ip)

    def query(self, filters: dict | None = None) -> dict:
        f = filters or {}
        where, params = [], []
        if f.get("level") in LEVELS:
            where.append("level = ?"); params.append(f["level"])
        if f.get("source") in SOURCES:
            where.append("source = ?"); params.append(f["source"])
        if f.get("search"):
            where.append("(message LIKE ? OR event_code LIKE ? OR context LIKE ?)"); params += [f"%{f['search']}%"] * 3
        if f.get("since"):
            where.append("created_at >= ?"); params.append(f["since"])
        if f.get("until"):
            where.append("created_at <= ?"); params.append(f["until"])
        w = ("WHERE " + " AND ".join(where)) if where else ""
        total = self.conn.execute(f"SELECT COUNT(*) FROM diagnostic_log {w}", params).fetchone()[0]
        limit = max(1, min(1000, int(f.get("limit") or 200)))
        offset = max(0, int(f.get("offset") or 0))
        sort = f.get("sort") if f.get("sort") in self.SORTABLE else "created_at"
        direction = "ASC" if str(f.get("dir", "desc")).lower() == "asc" else "DESC"
        rows = self.conn.execute(f"SELECT * FROM diagnostic_log {w} ORDER BY {sort} {direction}, id {direction} LIMIT {limit} OFFSET {offset}", params).fetchall()
        return {"rows": [dict(r) for r in rows], "total": total}


class UnifiedEventLog:
    CATEGORIES = ("password_change", "llm_auth", "llm_chat", "llm_anonymization", "llm_file", "admin_action", "diagnostic")
    LLM_OPS = {"llm_auth": "LLM_AUTH", "llm_chat": "LLM_CHAT", "llm_anonymization": "LLM_ANONYMIZATION", "llm_file": "LLM_FILE"}

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def query(self, filters: dict | None = None) -> dict:
        f = filters or {}
        cat = f.get("category") if f.get("category") in self.CATEGORIES else None
        subs, sub_params = [], []
        if cat in (None, "password_change"):
            subs.append("SELECT id, created_at, 'password_change' AS category, username AS actor, ip, operation AS event_type, result AS summary, NULL AS details, "
                        "CASE WHEN result LIKE '%SUCCESS%' OR result LIKE 'success%' THEN 'success' WHEN result LIKE '%REQUIRED%' THEN 'warning' ELSE 'failure' END AS outcome "
                        "FROM app_log WHERE operation NOT LIKE 'LLM\\_%' ESCAPE '\\'")
        for key, op in self.LLM_OPS.items():
            if cat in (None, key):
                subs.append(f"SELECT id, created_at, '{key}' AS category, username AS actor, ip, operation AS event_type, result AS summary, NULL AS details, "
                            "CASE WHEN result LIKE '%SUCCESS%' THEN 'success' ELSE 'failure' END AS outcome FROM app_log WHERE operation = ?")
                sub_params.append(op)
        if cat in (None, "admin_action"):
            subs.append("SELECT id, created_at, 'admin_action' AS category, actor, ip, action AS event_type, action AS summary, details, "
                        "CASE WHEN success = 1 THEN 'success' ELSE 'failure' END AS outcome FROM audit_log")
        if cat in (None, "diagnostic"):
            subs.append("SELECT id, created_at, 'diagnostic' AS category, NULL AS actor, ip, source AS event_type, message AS summary, context AS details, "
                        "CASE WHEN level = 'error' THEN 'failure' WHEN level = 'warning' THEN 'warning' ELSE 'success' END AS outcome FROM diagnostic_log")
        union = " UNION ALL ".join(subs)
        where, params = [], []
        if f.get("search"):
            where.append("(actor LIKE ? OR summary LIKE ? OR details LIKE ?)"); params += [f"%{f['search']}%"] * 3
        if f.get("since"):
            where.append("created_at >= ?"); params.append(f["since"])
        if f.get("until"):
            where.append("created_at <= ?"); params.append(f["until"])
        w = ("WHERE " + " AND ".join(where)) if where else ""
        total = self.conn.execute(f"SELECT COUNT(*) FROM ({union}) AS combined {w}", sub_params + params).fetchone()[0]
        limit = max(1, min(500, int(f.get("limit") or 100)))
        offset = max(0, int(f.get("offset") or 0))
        rows = self.conn.execute(f"SELECT * FROM ({union}) AS combined {w} ORDER BY created_at DESC, id DESC LIMIT {limit} OFFSET {offset}", sub_params + params).fetchall()
        return {"rows": [dict(r) for r in rows], "total": total}


class LogRotation:
    """Объём именно таблиц журналов (в том же файле — чаты); при превышении — 20 % старейших записей каждой и VACUUM.
    Запускается планировщиком раз в час."""

    DEFAULT_MAX_BYTES = 50 * 1024 * 1024
    DELETE_FRACTION = 0.2

    def __init__(self, conn: sqlite3.Connection, max_bytes: int = DEFAULT_MAX_BYTES):
        self.conn = conn
        self.max_bytes = max_bytes

    def log_bytes(self) -> int | None:
        names = ",".join(f"'{t}'" for t in LOG_TABLES)
        try:
            return int(self.conn.execute(
                f"SELECT COALESCE(SUM(pgsize), 0) FROM dbstat WHERE name IN ({names}) "
                f"OR name IN (SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name IN ({names}))"
            ).fetchone()[0])
        except sqlite3.OperationalError:   # SQLite без dbstat — размер файла
            try:
                return paths.database().stat().st_size
            except OSError:
                return None

    def delete_oldest_fraction(self, table: str, fraction: float) -> None:
        total = self.conn.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
        n = math.ceil(total * fraction)
        if n > 0:
            self.conn.execute(f"DELETE FROM {table} WHERE id IN (SELECT id FROM {table} ORDER BY id ASC LIMIT {n})")

    def rotate_if_needed(self) -> bool:
        size = self.log_bytes()
        if size is None or size <= self.max_bytes:
            return False
        for t in LOG_TABLES:
            self.delete_oldest_fraction(t, self.DELETE_FRACTION)
        self.conn.execute("VACUUM")
        return True


class LlmEventLog:
    OP_AUTH, OP_CHAT, OP_ANONYMIZATION, OP_FILE = "LLM_AUTH", "LLM_CHAT", "LLM_ANONYMIZATION", "LLM_FILE"
    _FORBIDDEN = ("text", "content", "prompt", "message", "body", "password", "pwd", "secret", "token", "key",
                  "credential", "header", "answer", "response", "filename", "file_name")
    _ALLOWED = ("max_tokens", "input_tokens", "output_tokens", "text_chars", "content_chars", "extra_header_count", "key_changed", "headers_changed")
    MAX_VALUE_LENGTH = 300

    def __init__(self, app_logger: AppLogger, audit_logger: AuditLogger | None = None):
        self.app_logger = app_logger
        self.audit_logger = audit_logger

    def user(self, ip: str, username: str | None, operation: str, event: str, fields: dict | None = None) -> None:
        d = self.format_details(fields or {})
        self.app_logger.log(ip, username, operation, f"{event}:{d}" if d else event)

    def admin(self, actor: str, ip: str, action: str, success: bool, fields: dict | None = None) -> None:
        if self.audit_logger is not None:
            d = self.format_details(fields or {})
            self.audit_logger.log(actor, ip, action, success, d or None)

    @classmethod
    def is_allowed_key(cls, key: str) -> bool:
        k = key.lower()
        return k in cls._ALLOWED or not any(p in k for p in cls._FORBIDDEN)

    @classmethod
    def format_details(cls, fields: dict) -> str:
        parts = []
        for key, value in fields.items():
            if not cls.is_allowed_key(str(key)):
                continue
            if isinstance(value, (list, tuple)):
                value = ",".join(str(v) for v in value)
            elif isinstance(value, bool):
                value = "1" if value else "0"
            elif value is None:
                value = "—"
            parts.append(f"{key}={cls._sanitize(str(value))}")
        return "; ".join(parts)

    @classmethod
    def _sanitize(cls, value: str) -> str:
        value = re.sub(r"[\x00-\x1f\x7f]+", " ", value).replace(";", ",")
        if len(value) > cls.MAX_VALUE_LENGTH:
            value = value[: cls.MAX_VALUE_LENGTH] + "…"
        return value.strip()
