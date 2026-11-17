"""Вход в админку: единственный локальный аварийный администратор (LocalAdminRepository), доменный администратор —
член группы AD (DomainAdminAuthenticator), состояние входа в серверной сессии (AdminSession)."""

from __future__ import annotations

import hashlib
import hmac
import secrets
import sqlite3
import time

from . import passwords
from .db import now_iso
from .ldap.client import LdapClient, LdapConnectionConfig
from .security import MAX_ADMIN_IDLE_TIMEOUT_SECONDS, Session, enforce_admin_idle_timeout


class LocalAdminRepository:
    """Таблица local_admin — ровно одна строка (id = 1, CHECK): второго локального администратора быть не может."""

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def exists(self) -> bool:
        return self.conn.execute("SELECT COUNT(*) FROM local_admin").fetchone()[0] > 0

    def get(self) -> dict | None:
        row = self.conn.execute("SELECT * FROM local_admin WHERE id = 1").fetchone()
        return dict(row) if row is not None else None

    def create(self, username: str, password: str) -> None:
        if self.exists():
            raise RuntimeError("Локальный администратор уже создан.")
        self.conn.execute(
            "INSERT INTO local_admin (id, username, password_hash, updated_at) VALUES (1, ?, ?, ?)",
            (username, self.hash_password(password), now_iso()),
        )

    def verify_password(self, username: str, password: str) -> bool:
        admin = self.get()
        if admin is None:
            return False
        if not hmac.compare_digest(str(admin["username"]).encode(), username.encode()):
            return False
        return passwords.verify_password(password, str(admin["password_hash"]))

    def change_password(self, new_password: str) -> None:
        if not self.exists():
            raise RuntimeError("Локальный администратор не создан.")
        self.conn.execute("UPDATE local_admin SET password_hash = ?, updated_at = ? WHERE id = 1", (self.hash_password(new_password), now_iso()))

    def credential_stamp(self) -> str | None:
        """Отпечаток текущего пароля (меняется при смене и сбросе) — для завершения открытых сессий."""
        admin = self.get()
        return hashlib.sha256(str(admin["password_hash"]).encode()).hexdigest() if admin is not None else None

    def username(self) -> str | None:
        admin = self.get()
        return admin["username"] if admin is not None else None

    @staticmethod
    def hash_password(password: str) -> str:
        return passwords.hash_password(password)

    @staticmethod
    def generate_strong_password(length: int = 28) -> str:
        alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^&*-_=+"
        return "".join(secrets.choice(alphabet) for _ in range(length))


def admin_users(conn, config_id: int) -> list[str]:
    """Отдельные доменные администраторы подключения (sAMAccountName в нижнем регистре) — в дополнение к группе AD."""
    from .settings import AppSettings
    return [u for u in (AppSettings(conn).get(f"admin_users_{config_id}") or "").split("\n") if u]


def set_admin_users(conn, config_id: int, users: list[str]) -> None:
    from .settings import AppSettings
    AppSettings(conn).set(f"admin_users_{config_id}", "\n".join(sorted({u.lower() for u in users if u})))


class DomainAdminAuthenticator:
    """Доменная учётная запись, входящая (прямо или через вложенные группы) в группу администраторов AD или указанная
    в списке отдельных администраторов. Исключения LdapException (неверный пароль, блокировка, недоступность) — вызывающему."""

    def __init__(self, config: LdapConnectionConfig, ca_bundle_builder, users: list[str] | None = None):
        self.config = config
        self.ca_bundle_builder = ca_bundle_builder
        self.users = {u.lower() for u in users or []}

    def authenticate(self, username: str, password: str) -> bool:
        sam = username.strip(" \t\n\r\0\x0b")
        client = LdapClient(self.config, self.ca_bundle_builder.current_bundle_path())
        try:
            client.connect()
            client.bind(f"{sam}@{self.config.domain}", password)
            user_dn = client.find_user_dn_by_sam_account_name(sam)
            if user_dn is None:
                return False
            if sam.lower() in self.users:                    # пароль уже проверен bind-ом под самой учёткой
                return True
            return bool(self.config.admin_group_dn) and client.is_member_of_group_recursive(user_dn, self.config.admin_group_dn)
        finally:
            client.close()


class AdminSession:
    """Метод входа ('local' | 'domain') — для интерфейса и чтобы смену пароля локального админа разрешать только ему."""

    # предельный срок при любой активности: доменный админ, исключённый из группы AD, теряет доступ не позже
    # (пароль для повторной проверки группы не хранится)
    MAX_LIFETIME_SECONDS = MAX_ADMIN_IDLE_TIMEOUT_SECONDS

    @staticmethod
    def login(s: Session, username: str, method: str, credential_stamp: str | None = None) -> None:
        """credential_stamp — отпечаток пароля локального админа: смена или сброс пароля завершают его сессии."""
        s.regenerate()
        now = int(time.time())
        s.update(admin_username=username, admin_method=method, admin_last_activity=now, admin_login_at=now, admin_stamp=credential_stamp)

    @staticmethod
    def is_logged_in(s: Session, idle_timeout_seconds: int | None = None, credential_stamp: str | None = None) -> bool:
        if not s.get("admin_username"):
            return False
        now = int(time.time())
        s.setdefault("admin_login_at", now)
        stamp = s.get("admin_stamp")
        if now - int(s["admin_login_at"]) > AdminSession.MAX_LIFETIME_SECONDS or (
            stamp is not None and (credential_stamp is None or not hmac.compare_digest(str(stamp).encode(), credential_stamp.encode()))
        ):
            s.destroy()
            return False
        return enforce_admin_idle_timeout(s, idle_timeout_seconds)

    @staticmethod
    def refresh_stamp(s: Session, credential_stamp: str | None) -> None:
        """После смены пароля в своей сессии — эта сессия остаётся, остальные завершаются."""
        if s.get("admin_stamp") is not None:
            s["admin_stamp"] = credential_stamp

    @staticmethod
    def username(s: Session) -> str | None:
        return s.get("admin_username")

    @staticmethod
    def method(s: Session) -> str | None:
        return s.get("admin_method")

    @staticmethod
    def logout(s: Session) -> None:
        s.destroy()
