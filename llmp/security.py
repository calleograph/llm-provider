"""Сессии, CSRF, ограничение попыток входа, адрес клиента за доверенным прокси.

Сессии — на сервере (таблица web_sessions), в куке только случайный идентификатор. Кука ADPWD_SESSION: HttpOnly,
SameSite=Strict; Secure — только если запрос пришёл по HTTPS (напрямую или X-Forwarded-Proto: https от прокси).
Поэтому первый вход по http://IP:порт работает, а за HTTPS-прокси кука сразу защищённая."""

from __future__ import annotations

import hmac
import ipaddress
import json
import secrets
import sqlite3
import time

COOKIE = "ADPWD_SESSION"
DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS = 14400
MAX_ADMIN_IDLE_TIMEOUT_SECONDS = 86400
SESSION_LIFETIME_SECONDS = MAX_ADMIN_IDLE_TIMEOUT_SECONDS + 600


class Session(dict):
    def __init__(self, sid: str | None, data: dict | None = None):
        super().__init__(data or {})
        self.sid = sid
        self.regenerated = False
        self.destroyed = False

    def regenerate(self) -> None:
        """Новый идентификатор при входе (защита от фиксации сессии); прежняя запись удаляется при сохранении."""
        self.regenerated = True

    def destroy(self) -> None:
        self.clear()
        self.destroyed = True


class SessionStore:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def load(self, sid: str | None) -> Session:
        if sid and len(sid) == 64:
            row = self.conn.execute("SELECT data, updated_at FROM web_sessions WHERE id = ?", (sid,)).fetchone()
            if row is not None and time.time() - row[1] <= SESSION_LIFETIME_SECONDS:
                return Session(sid, json.loads(row[0]))
        return Session(None)

    def save(self, s: Session) -> str | None:
        """Возвращает идентификатор для куки; None — куку удалить."""
        if s.sid and (s.regenerated or s.destroyed):
            self.conn.execute("DELETE FROM web_sessions WHERE id = ?", (s.sid,))
        if s.destroyed or not s:
            return None
        sid = s.sid if s.sid and not s.regenerated else secrets.token_hex(32)
        self.conn.execute(
            "INSERT INTO web_sessions (id, data, updated_at) VALUES (?, ?, ?) ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at",
            (sid, json.dumps(dict(s), ensure_ascii=False), int(time.time())),
        )
        return sid

    def purge_expired(self) -> None:
        self.conn.execute("DELETE FROM web_sessions WHERE updated_at < ?", (int(time.time()) - SESSION_LIFETIME_SECONDS,))


# ---- CSRF ----
def csrf_token(s: Session) -> str:
    if not s.get("csrf_token"):
        s["csrf_token"] = secrets.token_hex(32)
    return s["csrf_token"]


def csrf_verify(s: Session, submitted: str | None) -> bool:
    expected = s.get("csrf_token", "")
    return bool(expected) and isinstance(submitted, str) and hmac.compare_digest(expected, submitted)


def csrf_rotate(s: Session) -> None:
    s["csrf_token"] = secrets.token_hex(32)


def enforce_admin_idle_timeout(s: Session, idle_timeout_seconds: int | None = None) -> bool:
    timeout = min(idle_timeout_seconds, MAX_ADMIN_IDLE_TIMEOUT_SECONDS) if idle_timeout_seconds and idle_timeout_seconds > 0 else DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS
    now = int(time.time())
    last = s.get("admin_last_activity")
    if last is not None and now - int(last) > timeout:
        s.destroy()
        return False
    s["admin_last_activity"] = now
    return True


# ---- ограничение попыток ----
class RateLimiter:
    WINDOW_SECONDS = 900
    MAX_ATTEMPTS_BEFORE_DELAY = 5

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def record_attempt(self, bucket: str) -> None:
        now = int(time.time())
        self.conn.execute("INSERT INTO rate_limit (bucket_key, attempted_at) VALUES (?, ?)", (bucket, now))
        # старые записи всех адресов, а не только текущего — таблица не растёт от разовых адресов
        self.conn.execute("DELETE FROM rate_limit WHERE attempted_at <= ?", (now - self.WINDOW_SECONDS,))

    def recent_attempt_count(self, bucket: str) -> int:
        return self.conn.execute(
            "SELECT COUNT(*) FROM rate_limit WHERE bucket_key = ? AND attempted_at > ?", (bucket, int(time.time()) - self.WINDOW_SECONDS)
        ).fetchone()[0]

    def retry_after_seconds(self, bucket: str) -> int:
        count = self.recent_attempt_count(bucket)
        if count < self.MAX_ATTEMPTS_BEFORE_DELAY:
            return 0
        return min(300, 2 ** (count - self.MAX_ATTEMPTS_BEFORE_DELAY + 1))

    def reset_on_success(self, bucket: str) -> None:
        self.conn.execute("DELETE FROM rate_limit WHERE bucket_key = ?", (bucket,))


# ---- адрес клиента ----
def _trusted(ip: str, proxies: list[str]) -> bool:
    try:
        addr = ipaddress.ip_address(ip)
    except ValueError:
        return False
    for p in proxies:
        p = p.strip()
        if not p:
            continue
        try:
            if "/" in p:
                if addr in ipaddress.ip_network(p, strict=False):
                    return True
            elif addr == ipaddress.ip_address(p):
                return True
        except ValueError:
            continue
    return False


def client_ip(remote_addr: str | None, forwarded_for: str | None, trusted_proxies: list[str]) -> str:
    """X-Forwarded-For учитывается только от доверенного прокси; берётся первый адрес цепочки, если он корректен."""
    remote = remote_addr or "0.0.0.0"
    if not forwarded_for or not _trusted(remote, trusted_proxies):
        return remote
    candidate = forwarded_for.split(",")[0].strip()
    try:
        ipaddress.ip_address(candidate)
        return candidate
    except ValueError:
        return remote


def is_https(scheme: str, forwarded_proto: str | None) -> bool:
    """Только для флага Secure куки: подделка заголовка по http лишь заставит браузер не вернуть куку — вреда нет,
    поэтому доверенность прокси здесь не проверяется (как в версии 1.x)."""
    return scheme == "https" or (forwarded_proto or "").lower() == "https"
