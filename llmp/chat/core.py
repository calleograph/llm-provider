"""Пользователь чата, его вход в общей сессии и ошибка, безопасная для показа пользователю (как в версии 1.x)."""

from __future__ import annotations

import time
from dataclasses import dataclass, field

from ..security import Session


class ChatException(RuntimeError):
    """error_code — для журнала и клиента, user_message — готовый текст без технических подробностей."""

    def __init__(self, error_code: str, user_message: str, http_status: int = 400):
        super().__init__(user_message)
        self.error_code = error_code
        self.user_message = user_message
        self.http_status = http_status


@dataclass(frozen=True)
class ChatUser:
    """Состав групп фиксируется при входе; права на нейросети вычисляются заново на каждом запросе."""

    username: str
    domain_id: int
    domain_fqdn: str
    group_ids: list[int] = field(default_factory=list)
    group_names: list[str] = field(default_factory=list)

    def owner_key(self) -> str:
        """Ключ владельца истории: "sam@домен" в нижнем регистре."""
        return f"{self.username}@{self.domain_fqdn}".lower()

    def log_name(self) -> str:
        return f"{self.username}@{self.domain_fqdn}"


# Ключ 'llm_chat' в общей сессии: выход из чата не завершает сеанс администратора и наоборот.
SESSION_KEY = "llm_chat"
IDLE_TIMEOUT_SECONDS = 14400
ABSOLUTE_TIMEOUT_SECONDS = 43200


def session_login(s: Session, user: ChatUser) -> None:
    s.regenerate()
    now = int(time.time())
    s[SESSION_KEY] = {
        "username": user.username, "domain_id": user.domain_id, "domain_fqdn": user.domain_fqdn,
        "group_ids": list(user.group_ids), "group_names": list(user.group_names), "login_at": now, "last_activity": now,
    }


def session_current(s: Session) -> ChatUser | None:
    data = s.get(SESSION_KEY)
    if not isinstance(data, dict) or not data.get("username"):
        return None
    now = int(time.time())
    if now - int(data.get("last_activity") or 0) > IDLE_TIMEOUT_SECONDS or now - int(data.get("login_at") or 0) > ABSOLUTE_TIMEOUT_SECONDS:
        del s[SESSION_KEY]
        return None
    s[SESSION_KEY] = {**data, "last_activity": now}
    return ChatUser(
        username=str(data["username"]), domain_id=int(data["domain_id"]), domain_fqdn=str(data["domain_fqdn"]),
        group_ids=[int(g) for g in data.get("group_ids") or []], group_names=[str(g) for g in data.get("group_names") or []],
    )


def session_logout(s: Session) -> None:
    s.pop(SESSION_KEY, None)
    s.regenerate()
