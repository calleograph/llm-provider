"""Домены AD с точки зрения LLM-чата: параметры модуля в ldap_configs (LlmDomainRepository), разрешённые субъекты
доступа llm_domain_groups (DomainGroupRepository) и вход пользователя чата с проверкой групп (ChatAuthenticator)."""

from __future__ import annotations

import re
import sqlite3
from dataclasses import dataclass

from . import secretbox
from .db import now_iso
from .ldap.client import LdapClient, LdapConnectionConfig, LdapException

_PHP_TRIM = " \t\n\r\0\x0b"
_CONTROL = re.compile(r"[\x00-\x1f]")


def _valid_utf8(value: str) -> bool:
    try:
        value.encode("utf-8")
        return True
    except UnicodeEncodeError:
        return False


@dataclass(frozen=True)
class LlmDomain:
    """LDAPS-конфигурация плюс параметры модуля. Пароль сервисной учётки здесь не хранится — только признак."""

    config: LdapConnectionConfig
    llm_enabled: bool
    service_username: str | None
    has_service_password: bool

    def id(self) -> int:
        return self.config.id

    def has_service_account(self) -> bool:
        return bool(self.service_username) and self.has_service_password


class LlmDomainRepository:
    """Только колонки модуля; LDAPS-параметры меняются через llmp.ldap.config.LdapConfigRepository."""

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    @staticmethod
    def _from_row(row) -> LlmDomain:
        d = dict(row)
        return LlmDomain(
            config=LdapConnectionConfig.from_row(d),
            llm_enabled=bool(d.get("llm_enabled") or False),
            service_username=str(d["service_username"]) if d.get("service_username") not in (None, "") else None,
            has_service_password=bool(d.get("service_password_encrypted")),
        )

    def all(self) -> list[LlmDomain]:
        return [self._from_row(r) for r in self.conn.execute("SELECT * FROM ldap_configs ORDER BY name")]

    def enabled_for_chat(self) -> list[LlmDomain]:
        return [d for d in self.all() if d.llm_enabled]

    def find(self, domain_id: int) -> LlmDomain | None:
        row = self.conn.execute("SELECT * FROM ldap_configs WHERE id = ?", (domain_id,)).fetchone()
        return self._from_row(row) if row is not None else None

    def save_llm_settings(self, domain_id: int, llm_enabled: bool, service_username: str, new_service_password: str | None,
                          clear_service_password: bool) -> None:
        """new_service_password None/'' — оставить сохранённый."""
        sets = "llm_enabled = ?, service_username = ?, updated_at = ?"
        params: list = [1 if llm_enabled else 0, service_username or None, now_iso()]
        if clear_service_password or service_username == "":
            sets += ", service_password_encrypted = NULL"
        elif new_service_password:
            sets += ", service_password_encrypted = ?"
            params.append(secretbox.encrypt(new_service_password))
        self.conn.execute(f"UPDATE ldap_configs SET {sets} WHERE id = ?", (*params, domain_id))

    def service_password(self, domain_id: int) -> str | None:
        row = self.conn.execute("SELECT service_password_encrypted FROM ldap_configs WHERE id = ?", (domain_id,)).fetchone()
        encrypted = row[0] if row is not None else None
        return secretbox.decrypt(encrypted) if isinstance(encrypted, str) and encrypted != "" else None


class DomainGroupRepository:
    """subject_type='group': group_ref — полный DN или CN; 'user': group_ref — sAMAccountName («группа из одного»)."""

    SUBJECT_GROUP = "group"
    SUBJECT_USER = "user"

    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    def all(self) -> list[dict]:
        return [dict(r) for r in self.conn.execute(
            "SELECT g.*, c.name AS domain_name, c.domain AS domain_fqdn FROM llm_domain_groups g "
            "JOIN ldap_configs c ON c.id = g.ldap_config_id ORDER BY c.name, g.display_name"
        )]

    def for_domain(self, domain_id: int, enabled_only: bool = False) -> list[dict]:
        sql = "SELECT * FROM llm_domain_groups WHERE ldap_config_id = ?" + (" AND is_enabled = 1" if enabled_only else "") + " ORDER BY display_name"
        return [dict(r) for r in self.conn.execute(sql, (domain_id,))]

    def find(self, group_id: int) -> dict | None:
        row = self.conn.execute("SELECT * FROM llm_domain_groups WHERE id = ?", (group_id,)).fetchone()
        return dict(row) if row is not None else None

    def ref_exists(self, domain_id: int, group_ref: str, exclude_id: int | None = None) -> bool:
        return self.conn.execute(
            "SELECT COUNT(*) FROM llm_domain_groups WHERE ldap_config_id = ? AND lower(group_ref) = lower(?) AND id != ?",
            (domain_id, group_ref, exclude_id or 0),
        ).fetchone()[0] > 0

    def create(self, domain_id: int, display_name: str, group_ref: str, enabled: bool, actor: str, subject_type: str = SUBJECT_GROUP) -> int:
        now = now_iso()
        cur = self.conn.execute(
            "INSERT INTO llm_domain_groups (ldap_config_id, display_name, group_ref, is_enabled, created_by, created_at, updated_at, subject_type) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            (domain_id, display_name, group_ref, 1 if enabled else 0, actor, now, now, subject_type),
        )
        return int(cur.lastrowid)

    def update(self, group_id: int, display_name: str, group_ref: str, enabled: bool) -> None:
        self.conn.execute(
            "UPDATE llm_domain_groups SET display_name = ?, group_ref = ?, is_enabled = ?, updated_at = ? WHERE id = ?",
            (display_name, group_ref, 1 if enabled else 0, now_iso(), group_id),
        )

    def set_limit_category(self, group_id: int, category_id: int | None) -> None:
        """None — группа без собственной категории лимита."""
        self.conn.execute("UPDATE llm_domain_groups SET limit_category_id = ?, updated_at = ? WHERE id = ?", (category_id, now_iso(), group_id))

    def set_allow_unanonymized(self, group_id: int, allowed: bool) -> None:
        self.conn.execute("UPDATE llm_domain_groups SET allow_unanonymized = ?, updated_at = ? WHERE id = ?", (1 if allowed else 0, now_iso(), group_id))

    def delete(self, group_id: int) -> None:
        self.conn.execute("DELETE FROM llm_domain_groups WHERE id = ?", (group_id,))

    @staticmethod
    def validate_display_name(display_name: str) -> str | None:
        if display_name == "":
            return "укажите отображаемое имя"
        if not _valid_utf8(display_name) or len(display_name) > 200 or _CONTROL.search(display_name):
            return "недопустимое отображаемое имя"
        return None

    @staticmethod
    def is_distinguished_name(group_ref: str) -> bool:
        """DN распознаётся по наличию «=», иначе это CN."""
        return "=" in group_ref

    @staticmethod
    def validate_sam_account_name(sam: str) -> str | None:
        if sam == "":
            return "Укажите доменное имя пользователя (sAMAccountName)."
        if not re.fullmatch(r"[A-Za-z0-9._-]{1,64}", sam):
            return "Доменное имя пользователя — латиница, цифры, . _ - (до 64 символов), без домена и без @."
        return None

    @staticmethod
    def validate_ref(group_ref: str) -> str | None:
        if group_ref == "":
            return "Укажите CN или DN группы."
        if not _valid_utf8(group_ref) or len(group_ref) > 512 or _CONTROL.search(group_ref):
            return "Недопустимое значение CN/DN."
        if DomainGroupRepository.is_distinguished_name(group_ref) and not re.match(r"[A-Za-z]+=.+", group_ref):
            return "DN должен иметь вид CN=Группа,OU=...,DC=example,DC=local."
        return None


class ChatAuthenticator:
    """Вход в чат: bind самого пользователя (проверка пароля) → поиск DN → членство (вкл. вложенные группы) в каждой
    включённой разрешённой группе домена. Поиск — под сервисной read-only учёткой, если задана, иначе правами пользователя.
    Вход разрешён, только если совпала хотя бы одна группа (решает вызывающий по непустому groups)."""

    def __init__(self, groups: DomainGroupRepository, domains: LlmDomainRepository, ca_bundle_builder, diagnostic_logger=None):
        self.groups = groups
        self.domains = domains
        self.ca_bundle_builder = ca_bundle_builder
        self.diagnostic_logger = diagnostic_logger

    def _service_bind(self, client: LdapClient, domain: LlmDomain) -> None:
        client.bind(self.service_principal(str(domain.service_username), domain.config.domain),
                    str(self.domains.service_password(domain.id())))

    def authenticate(self, domain: LlmDomain, sam_account_name: str, password: str) -> dict:
        """{'user_dn': str|None, 'groups': {id: display_name}, 'unresolved': [display_name], 'lookup': 'user'|'service'}.
        LdapException — неверный пароль, блокировка, недоступность LDAPS и т.п."""
        ca_bundle_path = self.ca_bundle_builder.current_bundle_path()
        user_client = LdapClient(domain.config, ca_bundle_path)
        service_client = None
        try:
            user_client.connect()
            user_client.bind(f"{sam_account_name}@{domain.config.domain}", password)

            lookup, lookup_mode = user_client, "user"
            if domain.has_service_account():
                try:
                    service_client = LdapClient(domain.config, ca_bundle_path)
                    service_client.connect()
                    self._service_bind(service_client, domain)
                    lookup, lookup_mode = service_client, "service"
                except Exception as exc:  # noqa: BLE001
                    # сервисная учётка недоступна — вход не блокируем, группы проверяем правами пользователя
                    if service_client is not None:
                        service_client.close()
                    service_client = None
                    if self.diagnostic_logger is not None:
                        self.diagnostic_logger.warning(
                            "chat_auth", "service_account_bind_failed",
                            "Сервисная учётка домена не прошла bind, членство проверяется правами пользователя.",
                            {"domain": domain.config.name, "error": exc.error_code if isinstance(exc, LdapException) else type(exc).__name__},
                        )

            user_dn = lookup.find_user_dn_by_sam_account_name(sam_account_name)
            if user_dn is None:
                return {"user_dn": None, "groups": {}, "unresolved": [], "lookup": lookup_mode}

            matched: dict[int, str] = {}
            unresolved: list[str] = []
            for group in self.groups.for_domain(domain.id(), True):
                ref = str(group["group_ref"])
                if (group.get("subject_type") or self.groups.SUBJECT_GROUP) == self.groups.SUBJECT_USER:
                    # «группа из одного» — прямое сравнение sAMAccountName без обращения к группам AD
                    if ref.lower() == sam_account_name.lower():
                        matched[int(group["id"])] = str(group["display_name"])
                    continue
                group_dn = ref if self.groups.is_distinguished_name(ref) else lookup.find_group_dn_by_cn(ref)
                if group_dn is None:
                    unresolved.append(str(group["display_name"]))
                    continue
                if lookup.is_member_of_group_recursive(user_dn, group_dn):
                    matched[int(group["id"])] = str(group["display_name"])

            if unresolved and self.diagnostic_logger is not None:
                self.diagnostic_logger.warning(
                    "chat_auth", "group_not_resolved", "Часть разрешённых групп не найдена в каталоге по CN.",
                    {"domain": domain.config.name, "groups": ", ".join(unresolved)},
                )
            return {"user_dn": user_dn, "groups": matched, "unresolved": unresolved, "lookup": lookup_mode}
        finally:
            user_client.close()
            if service_client is not None:
                service_client.close()

    def verify_groups(self, domain: LlmDomain) -> list[dict]:
        """Проверка групп домена из админки под сервисной учёткой: существует ли каждая и во что разрешается CN."""
        if not domain.has_service_account():
            raise LdapException("SERVICE_ACCOUNT_MISSING", "Для проверки групп задайте сервисную учётную запись домена.")
        client = LdapClient(domain.config, self.ca_bundle_builder.current_bundle_path())
        try:
            client.connect()
            self._service_bind(client, domain)
            result = []
            for group in self.groups.for_domain(domain.id()):
                ref = str(group["group_ref"])
                if (group.get("subject_type") or self.groups.SUBJECT_GROUP) == self.groups.SUBJECT_USER:
                    dn = client.find_user_dn_by_sam_account_name(ref)   # для пользователя — есть ли такая учётная запись
                elif self.groups.is_distinguished_name(ref):
                    dn = ref if client.group_exists(ref) else None
                else:
                    dn = client.find_group_dn_by_cn(ref)
                result.append({"id": int(group["id"]), "display_name": str(group["display_name"]), "group_ref": ref, "ok": dn is not None, "dn": dn})
            return result
        finally:
            client.close()

    @staticmethod
    def parse_login(login: str) -> dict | None:
        """«user», «DOMAIN\\user» или «user@domain.local» → {'sam', 'domain_hint'}; None — недопустимый формат."""
        login = login.strip(_PHP_TRIM)
        hint = None
        if "\\" in login:
            hint, login = login.split("\\", 1)
        elif "@" in login:
            login, hint = login.split("@", 1)
        if login == "" or len(login) > 64 or re.search(r'[\s"/\\\[\]:;|=,+*?<>@]', login):
            return None
        return {"sam": login, "domain_hint": hint.lower() if hint else None}

    @staticmethod
    def service_principal(service_username: str, domain: str) -> str:
        return service_username if "@" in service_username else f"{service_username}@{domain}"
