"""SQLite: подключение и миграции. Схема и порядок шагов — llmp/schema.py (как у PHP-версии).

Миграции выполняются при старте процесса (не на каждом запросе) и на готовой БД ничего не пишут: колонки
проверяются чтением PRAGMA table_info, начальные данные — запросом EXISTS. Поэтому запуск рядом с работающим
экземпляром не ждёт чужую запись."""

from __future__ import annotations

import os
import sqlite3
import time
from pathlib import Path

from . import paths
from .schema import STEPS

BUSY_TIMEOUT_S = 30


def now_iso() -> str:
    """Формат gmdate('c') PHP: 2026-11-15T10:00:00+00:00 — тот же, что в уже сохранённых строках."""
    return time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime())


def connect(path: Path | str | None = None, *, migrate_schema: bool = False) -> sqlite3.Connection:
    p = str(path or paths.database())
    is_new = p != ":memory:" and not os.path.exists(p)
    try:
        conn = sqlite3.connect(p, timeout=BUSY_TIMEOUT_S, isolation_level=None, check_same_thread=False)
    except sqlite3.Error as exc:
        raise RuntimeError("Не удалось открыть хранилище настроек.") from exc
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    if p != ":memory:":
        conn.execute("PRAGMA journal_mode = WAL")
    if is_new:
        try:
            os.chmod(p, 0o660)
        except OSError:
            pass
    if migrate_schema:
        migrate(conn)
    return conn


def _columns(conn: sqlite3.Connection, table: str) -> set[str]:
    return {r[1] for r in conn.execute(f"PRAGMA table_info({table})")}


def migrate(conn: sqlite3.Connection) -> None:
    for step in STEPS:
        kind = step[0]
        if kind == "sql":
            conn.execute(step[1])
        elif kind == "column":
            table, definition = step[1], step[2]
            if definition.split()[0] not in _columns(conn, table):
                conn.execute(f"ALTER TABLE {table} ADD COLUMN {definition}")
        elif kind == "seed":
            SEEDS[step[1]](conn)


def _seed_default_limit_category(conn: sqlite3.Connection) -> None:
    """Категория «Базовый» из прежних общих настроек лимита — только если категорий ещё нет."""
    if conn.execute("SELECT EXISTS (SELECT 1 FROM llm_limit_categories)").fetchone()[0]:
        return
    get = lambda k: (r[0] if (r := conn.execute("SELECT value FROM app_settings WHERE key = ?", (k,)).fetchone()) else None)  # noqa: E731
    period = get("llm_chat_limit_period")
    period = period if period in ("day", "week", "month", "none") else "day"
    raw = get("llm_chat_limit_tokens")
    tokens = None if period == "none" else max(0, int(raw) if raw not in (None, "") else 300000)
    now = now_iso()
    conn.execute(
        "INSERT INTO llm_limit_categories (name, period, tokens, storage_mb, is_default, created_at, updated_at) VALUES (?, ?, ?, 50, 1, ?, ?)",
        ("Базовый", period, tokens, now, now),
    )


def seed_default_models(conn: sqlite3.Connection) -> None:
    """Каждому провайдеру без моделей — модель по умолчанию из его полей model/max_tokens (переход со схемы «провайдер = модель»)."""
    if not conn.execute(
        "SELECT EXISTS (SELECT 1 FROM llm_providers p WHERE NOT EXISTS (SELECT 1 FROM llm_models m WHERE m.provider_id = p.id))"
    ).fetchone()[0]:
        return
    now = now_iso()
    conn.execute(
        "INSERT INTO llm_models (provider_id, model, display_name, max_output_tokens, is_default, created_at, updated_at) "
        "SELECT p.id, p.model, p.model, p.max_tokens, 1, ?, ? FROM llm_providers p "
        "WHERE NOT EXISTS (SELECT 1 FROM llm_models m WHERE m.provider_id = p.id)",
        (now, now),
    )


def _seed_dlp_rules(conn: sqlite3.Connection) -> None:
    from .chat.dlp import BUILTIN, seed_builtin
    if conn.execute("SELECT COUNT(*) FROM dlp_rules WHERE builtin_key IS NOT NULL").fetchone()[0] < len(BUILTIN):
        seed_builtin(conn)


def _seed_outbound_index(conn: sqlite3.Connection) -> None:
    """Записи телеметрии, сделанные до появления поиска и DLP, — в индекс один раз."""
    if conn.execute("SELECT EXISTS (SELECT 1 FROM app_settings WHERE key = 'telemetry_index_version')").fetchone()[0]:
        return
    from .chat.telemetry import Telemetry
    Telemetry(conn).reindex_all()
    conn.execute("INSERT INTO app_settings (key, value) VALUES ('telemetry_index_version', '1') ON CONFLICT(key) DO NOTHING")


SEEDS = {"default_limit_category": _seed_default_limit_category, "default_models": seed_default_models,
         "dlp_rules": _seed_dlp_rules, "outbound_index": _seed_outbound_index}


class transaction:
    """with transaction(conn): … — BEGIN IMMEDIATE / COMMIT / ROLLBACK; вложенный вызов — точка сохранения."""

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn
        self.savepoint: str | None = None

    def __enter__(self) -> sqlite3.Connection:
        if self.conn.in_transaction:
            self.savepoint = f"sp{id(self)}"
            self.conn.execute(f"SAVEPOINT {self.savepoint}")
        else:
            self.conn.execute("BEGIN IMMEDIATE")
        return self.conn

    def __exit__(self, exc_type, exc, tb) -> None:
        if self.savepoint:
            self.conn.execute(f"ROLLBACK TO {self.savepoint}" if exc_type else f"RELEASE {self.savepoint}")
            if exc_type:
                self.conn.execute(f"RELEASE {self.savepoint}")
        else:
            self.conn.execute("ROLLBACK" if exc_type else "COMMIT")
