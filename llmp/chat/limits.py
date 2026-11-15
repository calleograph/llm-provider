"""Категории лимитов, лимит токенов на период (UsageLimiter) и квота хранения (StorageQuotaService) — как в версии 1.x.

Лимит токенов по приоритету: персональный вручную → персональная категория → самая щедрая категория групп
пользователя → категория по умолчанию → общие настройки чата (если категорий нет). Расход — агрегат
«пользователь × день» в llm_usage. Даты периодов — в часовом поясе PHP-образа (UTC), если не задан другой."""

from __future__ import annotations

import datetime as dt
import math
import sqlite3

from ..db import now_iso
from .core import ChatException, ChatUser
from .settings import PERIOD_NONE, PERIODS, ChatSettings, php_int

# Для сравнения категорий групп с разными периодами: токены, приведённые к месяцу.
_PER_MONTH = {"day": 30, "week": 4.35, "month": 1}


def _category(r) -> dict:
    return {
        "id": int(r["id"]), "name": str(r["name"]), "period": str(r["period"]),
        "tokens": int(r["tokens"]) if r["tokens"] is not None else None,
        "storage_mb": int(r["storage_mb"]) if r["storage_mb"] is not None else None,
        "is_default": bool(r["is_default"]),
    }


class LimitCategoryRepository:
    """Именованные категории («Базовый», «Расширенный»…); ровно одна — по умолчанию."""

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def all(self) -> list[dict]:
        return [_category(r) for r in self.conn.execute("SELECT * FROM llm_limit_categories ORDER BY is_default DESC, name")]

    def find(self, category_id: int) -> dict | None:
        r = self.conn.execute("SELECT * FROM llm_limit_categories WHERE id = ?", (category_id,)).fetchone()
        return _category(r) if r is not None else None

    def default_category(self) -> dict | None:
        r = self.conn.execute("SELECT * FROM llm_limit_categories WHERE is_default = 1 LIMIT 1").fetchone()
        return _category(r) if r is not None else None

    def name_exists(self, name: str, exclude_id: int | None = None) -> bool:
        return self.conn.execute(
            "SELECT COUNT(*) FROM llm_limit_categories WHERE name = ? AND id != ?", (name, exclude_id or 0)
        ).fetchone()[0] > 0

    def create(self, name: str, period: str, tokens: int | None, storage_mb: int | None, is_default: bool) -> int:
        """storage_mb None/0 — без ограничения места."""
        now = now_iso()
        if is_default:
            self._clear_default()
        return int(self.conn.execute(
            "INSERT INTO llm_limit_categories (name, period, tokens, storage_mb, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            (name, period, None if period == PERIOD_NONE else tokens, storage_mb if storage_mb else None, 1 if is_default else 0, now, now),
        ).lastrowid)

    def update(self, category_id: int, name: str, period: str, tokens: int | None, storage_mb: int | None, is_default: bool) -> None:
        if is_default:
            self._clear_default()
        self.conn.execute(
            "UPDATE llm_limit_categories SET name = ?, period = ?, tokens = ?, storage_mb = ?, is_default = ?, updated_at = ? WHERE id = ?",
            (name, period, None if period == PERIOD_NONE else tokens, storage_mb if storage_mb and storage_mb > 0 else None,
             1 if is_default else 0, now_iso(), category_id),
        )

    def delete(self, category_id: int) -> bool:
        """Категорию по умолчанию удалить нельзя — сначала назначить другую (иначе новые пользователи без лимита)."""
        category = self.find(category_id)
        if category is None or category["is_default"]:
            return False
        self.conn.execute("DELETE FROM llm_limit_categories WHERE id = ?", (category_id,))
        return True

    def _clear_default(self) -> None:
        self.conn.execute("UPDATE llm_limit_categories SET is_default = 0")


def _group_categories(conn: sqlite3.Connection, categories: LimitCategoryRepository, group_ids: list[int]):
    """Категории лимита групп пользователя (группы без категории и удалённые пропускаются)."""
    for gid in group_ids:
        row = conn.execute("SELECT limit_category_id FROM llm_domain_groups WHERE id = ?", (int(gid),)).fetchone()
        if row is None or row[0] is None:
            continue
        category = categories.find(int(row[0]))
        if category is not None:
            yield category


def _split_owner_key(owner_key: str) -> tuple[str, str]:
    user, _, domain = owner_key.partition("@")
    return user, domain


def _fmt(value: int) -> str:
    return f"{value:,}".replace(",", " ")


class UsageLimiter:
    PERIOD_NONE = PERIOD_NONE
    PERIODS = PERIODS

    def __init__(self, conn: sqlite3.Connection, settings: ChatSettings, limit_categories: LimitCategoryRepository,
                 timezone: dt.tzinfo | None = None):
        self.conn = conn
        self.settings = settings
        self.limit_categories = limit_categories
        self.timezone = timezone or dt.timezone.utc

    def touch_profile(self, user: ChatUser) -> None:
        now = now_iso()
        self.conn.execute(
            "INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?) "
            "ON CONFLICT(owner_key) DO UPDATE SET last_seen_at = excluded.last_seen_at",
            (user.owner_key(), user.username, user.domain_fqdn, now, now),
        )

    def is_blocked(self, owner_key: str) -> bool:
        row = self.conn.execute("SELECT is_blocked FROM llm_users WHERE owner_key = ?", (owner_key,)).fetchone()
        return row is not None and int(row[0] or 0) == 1

    def effective_limit(self, owner_key: str, group_ids: list[int] | None = None) -> dict:
        """{period, tokens (None — без лимита), individual, source}."""
        row = self.conn.execute("SELECT limit_period, limit_tokens, limit_category_id FROM llm_users WHERE owner_key = ?", (owner_key,)).fetchone()
        # 1. Персональный лимит вручную — высший приоритет (совместимость с уже настроенными записями).
        if row is not None and row["limit_period"] in PERIODS:
            return {"period": row["limit_period"], "tokens": None if row["limit_period"] == PERIOD_NONE else max(0, int(row["limit_tokens"] or 0)),
                    "individual": True, "source": "manual"}
        # 2. Персональная категория.
        if row is not None and row["limit_category_id"] is not None:
            c = self.limit_categories.find(int(row["limit_category_id"]))
            if c is not None:
                return {"period": c["period"], "tokens": c["tokens"], "individual": True, "source": "category:" + c["name"]}
        # 3. Самая щедрая категория групп.
        best = None
        for c in _group_categories(self.conn, self.limit_categories, group_ids or []):
            if best is None or self._more_generous(c, best):
                best = c
        if best is not None:
            return {"period": best["period"], "tokens": best["tokens"], "individual": False, "source": "group:" + best["name"]}
        # 4. Категория по умолчанию.
        d = self.limit_categories.default_category()
        if d is not None:
            return {"period": d["period"], "tokens": d["tokens"], "individual": False, "source": "default:" + d["name"]}
        # 5. Категорий нет — общие настройки чата.
        period = self.settings.get("limit_period")
        period = period if period in PERIODS else PERIOD_NONE
        return {"period": period, "tokens": None if period == PERIOD_NONE else max(0, php_int(self.settings.get("limit_tokens"))),
                "individual": False, "source": "settings"}

    @staticmethod
    def _more_generous(candidate: dict, current: dict) -> bool:
        """«Без лимита» щедрее всего; иначе сравниваются токены, приведённые к месяцу (день×30, неделя×4,35)."""
        if candidate["period"] == PERIOD_NONE or candidate["tokens"] is None:
            return not (current["period"] == PERIOD_NONE or current["tokens"] is None)
        if current["period"] == PERIOD_NONE or current["tokens"] is None:
            return False
        per_month = lambda c: int(c["tokens"]) * _PER_MONTH.get(c["period"], 1)  # noqa: E731
        return per_month(candidate) > per_month(current)

    def status(self, owner_key: str, group_ids: list[int] | None = None) -> dict:
        """{period, used, limit, remaining, resets_at (Y-m-d), individual, source}."""
        limit = self.effective_limit(owner_key, group_ids)
        start, reset = self.period_bounds("month" if limit["period"] == PERIOD_NONE else limit["period"])
        used = self.used_since(owner_key, start)
        tokens = limit["tokens"]
        return {
            "period": limit["period"], "used": used, "limit": tokens,
            "remaining": max(0, tokens - used) if tokens is not None else None,
            "resets_at": reset if tokens is not None else None,
            "individual": limit["individual"], "source": limit["source"],
        }

    def assert_can_send(self, owner_key: str, group_ids: list[int] | None = None) -> None:
        """ChatException 403 для заблокированного, 429 при исчерпанном лимите."""
        if self.is_blocked(owner_key):
            raise ChatException("user_blocked", "Доступ к чату для вашей учётной записи приостановлен администратором.", 403)
        s = self.status(owner_key, group_ids)
        if s["limit"] is not None and s["used"] >= s["limit"]:
            label = {"day": "день", "week": "неделю", "month": "месяц"}.get(s["period"], s["period"])
            y, m, d = str(s["resets_at"]).split("-")
            raise ChatException(
                "limit_exceeded",
                f"Исчерпан лимит на {label}: {_fmt(s['used'])} из {_fmt(s['limit'])} токенов. Лимит обновится {d}.{m}.{y}.",
                429,
            )

    def record(self, owner_key: str, tokens: int, requests: int = 1) -> None:
        """tokens может быть отрицательным — возврат брони; итог за день не опускается ниже нуля.
        В PHP прибавлялся excluded.tokens, уже обрезанный до 0, и возврат не срабатывал — здесь прибавляется исходное значение."""
        self.conn.execute(
            "INSERT INTO llm_usage (owner_key, day, tokens, requests) VALUES (?, ?, MAX(0, ?), ?) "
            "ON CONFLICT(owner_key, day) DO UPDATE SET tokens = MAX(0, tokens + ?), requests = requests + excluded.requests",
            (owner_key, self._today().isoformat(), tokens, requests, tokens),
        )

    @staticmethod
    def estimate_tokens(chars: int) -> int:
        return math.ceil(chars / 3)

    def period_bounds(self, period: str) -> tuple[str, str]:
        """(начало периода, дата сброса) в формате Y-m-d; неделя — с понедельника."""
        today = self._today()
        if period == "day":
            start, reset = today, today + dt.timedelta(days=1)
        elif period == "week":
            start = today - dt.timedelta(days=today.weekday())
            reset = start + dt.timedelta(days=7)
        else:
            start = today.replace(day=1)
            reset = (start + dt.timedelta(days=32)).replace(day=1)
        return start.isoformat(), reset.isoformat()

    def used_since(self, owner_key: str, start_day: str) -> int:
        return int(self.conn.execute("SELECT COALESCE(SUM(tokens), 0) FROM llm_usage WHERE owner_key = ? AND day >= ?", (owner_key, start_day)).fetchone()[0])

    def all_profiles(self) -> list[dict]:
        """Для админки: профили с расходом за текущий период (групповой лимит здесь не учитывается — членство в
        группах известно только на момент входа)."""
        since = (self._today() - dt.timedelta(days=30)).isoformat()
        rows = []
        for r in self.conn.execute(
            "SELECT owner_key, username, domain_fqdn, first_seen_at, last_seen_at, limit_period, limit_tokens, is_blocked, "
            "allow_unanonymized, limit_category_id, style FROM llm_users ORDER BY last_seen_at DESC"
        ).fetchall():
            row = dict(r)
            row["status"] = self.status(str(row["owner_key"]))
            row["requests_30d"] = self._requests_since(str(row["owner_key"]), since)
            rows.append(row)
        return rows

    def set_individual_limit(self, owner_key: str, period: str | None, tokens: int | None) -> None:
        t = None if period is None or period == PERIOD_NONE else tokens
        cur = self.conn.execute("UPDATE llm_users SET limit_period = ?, limit_tokens = ?, limit_category_id = NULL WHERE owner_key = ?", (period, t, owner_key))
        if cur.rowcount == 0:
            # профиля ещё нет — иначе назначение лимита стало бы тихим no-op
            u, d = _split_owner_key(owner_key)
            now = now_iso()
            self.conn.execute(
                "INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at, limit_period, limit_tokens) VALUES (?, ?, ?, ?, ?, ?, ?) "
                "ON CONFLICT(owner_key) DO UPDATE SET limit_period = excluded.limit_period, limit_tokens = excluded.limit_tokens, limit_category_id = NULL",
                (owner_key, u, d, now, now, period, t),
            )

    def set_limit_category(self, owner_key: str, category_id: int | None) -> None:
        """None — снять персональную категорию (пользователь вернётся к групповой/умолчанию)."""
        cur = self.conn.execute("UPDATE llm_users SET limit_category_id = ?, limit_period = NULL, limit_tokens = NULL WHERE owner_key = ?", (category_id, owner_key))
        if cur.rowcount == 0:
            u, d = _split_owner_key(owner_key)
            now = now_iso()
            self.conn.execute(
                "INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at, limit_category_id) VALUES (?, ?, ?, ?, ?, ?) "
                "ON CONFLICT(owner_key) DO UPDATE SET limit_category_id = excluded.limit_category_id, limit_period = NULL, limit_tokens = NULL",
                (owner_key, u, d, now, now, category_id),
            )

    def set_blocked(self, owner_key: str, blocked: bool) -> None:
        self.conn.execute("UPDATE llm_users SET is_blocked = ? WHERE owner_key = ?", (1 if blocked else 0, owner_key))

    def set_allow_unanonymized(self, owner_key: str, allowed: bool) -> None:
        """Персональное разрешение «без обезличивания» — в дополнение к групповому."""
        a = 1 if allowed else 0
        if self.conn.execute("UPDATE llm_users SET allow_unanonymized = ? WHERE owner_key = ?", (a, owner_key)).rowcount == 0:
            u, d = _split_owner_key(owner_key)
            now = now_iso()
            self.conn.execute(
                "INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at, allow_unanonymized) VALUES (?, ?, ?, ?, ?, ?) "
                "ON CONFLICT(owner_key) DO UPDATE SET allow_unanonymized = excluded.allow_unanonymized",
                (owner_key, u, d, now, now, a),
            )

    def purge_old_usage(self) -> None:
        """Агрегаты старше года не нужны ни для одного периода лимита."""
        self.conn.execute("DELETE FROM llm_usage WHERE day < ?", ((self._today() - dt.timedelta(days=400)).isoformat(),))

    def _requests_since(self, owner_key: str, day: str) -> int:
        return int(self.conn.execute("SELECT COALESCE(SUM(requests), 0) FROM llm_usage WHERE owner_key = ? AND day >= ?", (owner_key, day)).fetchone()[0])

    def _today(self) -> dt.date:
        return dt.datetime.now(self.timezone).date()


def format_bytes(n: int) -> str:
    if n >= 1024 * 1024:
        return f"{n / 1024 / 1024:,.1f}".replace(",", " ").replace(".", ",") + " МБ"
    if n >= 1024:
        return f"{n / 1024:,.0f}".replace(",", " ") + " КБ"
    return f"{n} Б"


class StorageQuotaService:
    """Квота (МБ) на обезличенный текст пользователя: сообщения его диалогов + источники его проектов. Мягкая:
    превышение не мешает читать, только не даёт добавить новое содержимое."""

    def __init__(self, conn: sqlite3.Connection, limit_categories: LimitCategoryRepository):
        self.conn = conn
        self.limit_categories = limit_categories

    def used_bytes(self, owner_key: str) -> int:
        # Как в PHP: LENGTH(content) + LENGTH(NULL) = NULL, поэтому несжатые сообщения в сумму не входят.
        messages = self.conn.execute(
            "SELECT COALESCE(SUM(LENGTH(m.content) + LENGTH(m.content_z)), 0) FROM llm_messages m "
            "JOIN llm_conversations c ON c.id = m.conversation_id WHERE c.owner_key = ?", (owner_key,),
        ).fetchone()[0]
        sources = self.conn.execute(
            "SELECT COALESCE(SUM(LENGTH(s.content_z)), 0) FROM llm_project_sources s "
            "JOIN llm_projects p ON p.id = s.project_id WHERE p.owner_key = ?", (owner_key,),
        ).fetchone()[0]
        return int(messages) + int(sources)

    def effective_limit_mb(self, owner_key: str, group_ids: list[int] | None = None) -> dict:
        """{limit_mb (None — без ограничения), source}."""
        row = self.conn.execute("SELECT limit_category_id FROM llm_users WHERE owner_key = ?", (owner_key,)).fetchone()
        if row is not None and row[0] is not None:
            c = self.limit_categories.find(int(row[0]))
            if c is not None:
                return {"limit_mb": c["storage_mb"], "source": "category:" + c["name"]}
        best = None
        for c in _group_categories(self.conn, self.limit_categories, group_ids or []):
            # None (без ограничения) — самый щедрый вариант; иначе больше мегабайт — щедрее.
            if best is None or (c["storage_mb"] is None and best["storage_mb"] is not None) or (
                    c["storage_mb"] is not None and best["storage_mb"] is not None and c["storage_mb"] > best["storage_mb"]):
                best = c
        if best is not None:
            return {"limit_mb": best["storage_mb"], "source": "group:" + best["name"]}
        d = self.limit_categories.default_category()
        if d is not None:
            return {"limit_mb": d["storage_mb"], "source": "default:" + d["name"]}
        return {"limit_mb": None, "source": "none"}

    def status(self, owner_key: str, group_ids: list[int] | None = None) -> dict:
        """{used_bytes, limit_bytes, remaining_bytes, percent, source}."""
        used = self.used_bytes(owner_key)
        limit = self.effective_limit_mb(owner_key, group_ids)
        limit_bytes = limit["limit_mb"] * 1024 * 1024 if limit["limit_mb"] is not None else None
        return {
            "used_bytes": used, "limit_bytes": limit_bytes,
            "remaining_bytes": max(0, limit_bytes - used) if limit_bytes is not None else None,
            "percent": min(100.0, used / limit_bytes * 100) if limit_bytes else None,
            "source": limit["source"],
        }

    def assert_can_store(self, owner_key: str, additional_bytes: int, group_ids: list[int] | None = None) -> None:
        """ChatException 507, если добавление превысит квоту."""
        s = self.status(owner_key, group_ids)
        if s["limit_bytes"] is not None and s["used_bytes"] + additional_bytes > s["limit_bytes"]:
            raise ChatException(
                "storage_quota_exceeded",
                f"Превышена квота хранения: занято {format_bytes(s['used_bytes'])} из {format_bytes(s['limit_bytes'])}. "
                "Освободите место в разделе «Управление данными» (удалите старые чаты или источники проектов) и повторите отправку.",
                507,
            )
