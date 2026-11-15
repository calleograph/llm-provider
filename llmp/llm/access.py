"""Права на нейросети: «доменная группа → провайдеры» (llm_group_providers) плюс индивидуальные (llm_user_providers).

Итоговый набор — объединение прав всех разрешённых групп пользователя. Проверка — только на сервере по данным БД на
момент запроса: отключение группы/нейросети или снятие права действует сразу, подмена provider_id ничего не даёт."""

from __future__ import annotations

import sqlite3

from .. import db
from ..chat.core import ChatUser
from .errors import LlmException


def _unique_ints(values) -> list[int]:
    return list(dict.fromkeys(int(v) for v in values))


def _row(r, base_only: bool = False) -> dict:
    """base_only — доступ только к базовой модели (модель по умолчанию нейросети по умолчанию), без явного права на нейросеть."""
    return {"id": int(r["id"]), "name": str(r["name"]), "model": str(r["model"]), "provider_type": str(r["provider_type"]),
            "is_default": bool(r["is_default"]), "base_only": base_only}


class UserProviderRepository:
    """Индивидуальный доступ к нейросети. Не расширяет круг тех, кто может войти в чат — только список нейросетей."""

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def providers_for(self, owner_key: str) -> list[int]:
        return [int(r[0]) for r in self.conn.execute("SELECT provider_id FROM llm_user_providers WHERE owner_key = ?", (owner_key,))]

    def set_providers(self, owner_key: str, provider_ids: list[int]) -> None:
        before = self.providers_for(owner_key)
        valid = {int(r[0]) for r in self.conn.execute("SELECT id FROM llm_providers")}
        after = [p for p in _unique_ints(provider_ids) if p in valid]
        with db.transaction(self.conn):
            for p in (x for x in after if x not in before):
                self.conn.execute("INSERT OR IGNORE INTO llm_user_providers (owner_key, provider_id) VALUES (?, ?)", (owner_key, p))
            for p in (x for x in before if x not in after):
                self.conn.execute("DELETE FROM llm_user_providers WHERE owner_key = ? AND provider_id = ?", (owner_key, p))

    def matrix(self) -> dict[str, list[int]]:
        out: dict[str, list[int]] = {}
        for r in self.conn.execute("SELECT owner_key, provider_id FROM llm_user_providers"):
            out.setdefault(str(r[0]), []).append(int(r[1]))
        return out


class AccessPolicy:
    def __init__(self, conn: sqlite3.Connection, user_providers: UserProviderRepository | None = None):
        self.conn = conn
        self.user_providers = user_providers

    def allowed_providers(self, group_ids: list[int], domain_id: int, owner_key: str | None = None) -> dict[int, dict]:
        """group_ids — группы, членство в которых подтверждено при входе. Результат по id провайдера, по имени."""
        group_ids = _unique_ints(group_ids)
        result: dict[int, dict] = {}
        if group_ids:
            rows = self.conn.execute(
                "SELECT DISTINCT p.id, p.name, p.model, p.provider_type, p.is_default FROM llm_group_providers gp "
                "JOIN llm_domain_groups g ON g.id = gp.group_id JOIN ldap_configs c ON c.id = g.ldap_config_id "
                "JOIN llm_providers p ON p.id = gp.provider_id "
                f"WHERE gp.group_id IN ({','.join('?' * len(group_ids))}) AND g.is_enabled = 1 AND g.ldap_config_id = ? "
                "AND c.llm_enabled = 1 AND p.is_enabled = 1 ORDER BY p.name",
                (*group_ids, domain_id),
            )
            for r in rows:
                result[int(r["id"])] = _row(r)
        # Индивидуальный доступ расширяет набор поверх групповых прав, но не заменяет требование членства хотя бы в одной
        # разрешённой группе домена для входа в чат (проверяется отдельно, до вызова).
        if owner_key is not None and self.user_providers is not None:
            ids = self.user_providers.providers_for(owner_key)
            if ids:
                for r in self.conn.execute(
                    f"SELECT id, name, model, provider_type, is_default FROM llm_providers WHERE id IN ({','.join('?' * len(ids))}) AND is_enabled = 1", ids
                ):
                    result[int(r["id"])] = _row(r)
        # Базовая модель — всем, кого пустили в чат (включённый субъект домена с включённым чатом): добавленному в чат
        # человеку не нужно отдельно выдавать права, чтобы он мог работать. Остальные модели — по явным правам.
        if group_ids and self.conn.execute(
                f"SELECT EXISTS (SELECT 1 FROM llm_domain_groups g JOIN ldap_configs c ON c.id = g.ldap_config_id WHERE g.id IN ({','.join('?' * len(group_ids))}) "
                "AND g.is_enabled = 1 AND g.ldap_config_id = ? AND c.llm_enabled = 1)", (*group_ids, domain_id)).fetchone()[0]:
            r = self.conn.execute("SELECT id, name, model, provider_type, is_default FROM llm_providers WHERE is_default = 1 AND is_enabled = 1").fetchone()
            if r is not None and int(r["id"]) not in result:
                result[int(r["id"])] = _row(r, base_only=True)
        return dict(sorted(result.items(), key=lambda kv: kv[1]["name"]))

    def is_allowed(self, user: ChatUser, provider_id: int) -> bool:
        return provider_id in self.allowed_providers(user.group_ids, user.domain_id, user.owner_key())

    def can_skip_anonymization(self, group_ids: list[int], owner_key: str | None = None) -> bool:
        """Отправка без обезличивания: галочка хотя бы у одной разрешённой группы ЛИБО личное разрешение
        (llm_users.allow_unanonymized) — та же модель объединения прав, что и у провайдеров."""
        group_ids = _unique_ints(group_ids)
        if group_ids and self.conn.execute(
            f"SELECT COUNT(*) FROM llm_domain_groups WHERE id IN ({','.join('?' * len(group_ids))}) AND is_enabled = 1 AND allow_unanonymized = 1",
            group_ids,
        ).fetchone()[0] > 0:
            return True
        if owner_key is not None:
            row = self.conn.execute("SELECT allow_unanonymized FROM llm_users WHERE owner_key = ?", (owner_key,)).fetchone()
            if row is not None and int(row[0] or 0) == 1:
                return True
        return False

    def assert_allowed(self, user: ChatUser, provider_id: int) -> None:
        if not self.is_allowed(user, provider_id):
            raise LlmException("access_denied", "Нейросеть недоступна пользователю.")

    def matrix(self) -> dict[int, list[int]]:
        """group_id → provider_id[]."""
        out: dict[int, list[int]] = {}
        for r in self.conn.execute("SELECT group_id, provider_id FROM llm_group_providers"):
            out.setdefault(int(r[0]), []).append(int(r[1]))
        return out

    def providers_for_group(self, group_id: int) -> list[int]:
        return self.matrix().get(group_id, [])

    def set_group_providers(self, group_id: int, provider_ids: list[int]) -> dict[str, list[int]]:
        """Заменяет права одной группы; возвращает {'added': […], 'removed': […]}."""
        before = self.providers_for_group(group_id)
        valid = {int(r[0]) for r in self.conn.execute("SELECT id FROM llm_providers")}
        after = [p for p in _unique_ints(provider_ids) if p > 0 and p in valid]
        added = [p for p in after if p not in before]
        removed = [p for p in before if p not in after]
        with db.transaction(self.conn):
            for p in added:
                self.conn.execute("INSERT OR IGNORE INTO llm_group_providers (group_id, provider_id) VALUES (?, ?)", (group_id, p))
            for p in removed:
                self.conn.execute("DELETE FROM llm_group_providers WHERE group_id = ? AND provider_id = ?", (group_id, p))
        return {"added": added, "removed": removed}
