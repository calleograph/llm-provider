"""Соединение с AD через LDAPS (ldap3) — перенос src/Ldap/LdapClient.php, LdapConnectionConfig, LdapErrorCode, LdapException.

Принципиально, как у PHP-версии: сертификат сервера проверяется всегда (CERT_REQUIRED) и только по собственному CA bundle
приложения (системное хранилище не используется); реферралы выключены; таймауты из конфигурации; StartTLS — только для
ldap:// с явно включённым флагом, открытое ldap:// без шифрования не допускается. Операций смены/сброса пароля нет."""

from __future__ import annotations

import os
import ssl
from dataclasses import dataclass
from urllib.parse import urlsplit

from ldap3 import ANONYMOUS, BASE, NO_ATTRIBUTES, NONE, SIMPLE, SUBTREE, Connection, Server, Tls
from ldap3.core.exceptions import LDAPException, LDAPStartTLSError
from ldap3.utils.conv import escape_filter_chars


class LdapErrorCode:
    """Безопасные для пользователя коды ошибок; тексты — llmp/errors.py."""

    INVALID_CREDENTIALS = "invalid_credentials"
    ACCOUNT_LOCKED = "account_locked"
    ACCOUNT_DISABLED = "account_disabled"
    PASSWORD_EXPIRED = "password_expired"
    PASSWORD_MUST_CHANGE = "password_must_change"   # data 773: «сменить пароль при следующем входе», не неверный пароль
    ACCOUNT_RESTRICTION = "account_restriction"     # data 52f: ограничение входа (часто «Protected Users»)
    USER_NOT_FOUND = "user_not_found"
    SERVER_UNAVAILABLE = "server_unavailable"
    TLS_ERROR = "tls_error"
    INSUFFICIENT_ACCESS = "insufficient_access"
    TIMEOUT = "timeout"
    UNKNOWN = "unknown"


class LdapException(RuntimeError):
    """error_code — безопасен для показа; текст исключения — только во внутренний журнал."""

    def __init__(self, error_code: str, technical_message: str):
        super().__init__(technical_message)
        self.error_code = error_code


@dataclass(frozen=True)
class LdapConnectionConfig:
    """Снимок одной строки ldap_configs."""

    id: int
    name: str
    is_active: bool
    ldaps_url: str
    host: str
    port: int
    base_dn: str
    domain: str
    netbios_domain: str
    admin_group_dn: str
    protocol_version: int
    start_tls: bool
    network_timeout: int
    ca_bundle_file: str | None

    @classmethod
    def from_row(cls, row) -> "LdapConnectionConfig":
        return cls(
            id=int(row["id"]), name=str(row["name"]), is_active=bool(row["is_active"]), ldaps_url=str(row["ldaps_url"]),
            host=str(row["host"]), port=int(row["port"]), base_dn=str(row["base_dn"]), domain=str(row["domain"]),
            netbios_domain=str(row["netbios_domain"]), admin_group_dn=str(row["admin_group_dn"]),
            protocol_version=int(row["protocol_version"]), start_tls=bool(row["starttls"]),
            network_timeout=int(row["network_timeout"]),
            ca_bundle_file=str(row["ca_bundle_file"]) if row["ca_bundle_file"] is not None else None,
        )


def escape_filter(value: str) -> str:
    """Как ldap_escape(..., LDAP_ESCAPE_FILTER): \\ * ( ) NUL → \\5c \\2a \\28 \\29 \\00."""
    return escape_filter_chars(value)


def classify_bind_error(errno: int, diagnostic: str) -> str:
    # AD отвечает 49 (invalidCredentials) с data-кодом в diagnostic message
    if errno == 49:
        if "data 775" in diagnostic:
            return LdapErrorCode.ACCOUNT_LOCKED
        if "data 533" in diagnostic:
            return LdapErrorCode.ACCOUNT_DISABLED
        if "data 532" in diagnostic:
            return LdapErrorCode.PASSWORD_EXPIRED
        if "data 773" in diagnostic:
            return LdapErrorCode.PASSWORD_MUST_CHANGE
        if "data 52f" in diagnostic:
            return LdapErrorCode.ACCOUNT_RESTRICTION
        return LdapErrorCode.INVALID_CREDENTIALS
    if errno in (52, -1, 81):   # unavailable / server down
        return LdapErrorCode.SERVER_UNAVAILABLE
    if errno == 50:
        return LdapErrorCode.INSUFFICIENT_ACCESS
    return LdapErrorCode.UNKNOWN


def _transport_code(exc: Exception) -> str:
    """Сбой сокета/TLS у ldap3 (у ext-ldap все они — errno -1); различаем TLS и таймаут — вызывающим это не мешает."""
    text = str(exc).lower()
    if isinstance(exc, LDAPStartTLSError) or "ssl" in text or "certificate" in text:
        return LdapErrorCode.TLS_ERROR
    if "timed out" in text or "timeout" in text:
        return LdapErrorCode.TIMEOUT
    return LdapErrorCode.SERVER_UNAVAILABLE


class LdapClient:
    classify_bind_error = staticmethod(classify_bind_error)

    def __init__(self, config: LdapConnectionConfig, ca_bundle_path: str):
        self.config = config
        self.ca_bundle_path = ca_bundle_path
        self._conn: Connection | None = None

    def connect(self) -> None:
        # без CA bundle не подключаемся (fail-closed), проверку сертификата не пропускаем
        if not os.path.isfile(self.ca_bundle_path):
            raise LdapException(LdapErrorCode.TLS_ERROR, "CA bundle не найден: " + self.ca_bundle_path)
        uri = self.config.ldaps_url
        parts = urlsplit(uri)
        scheme = parts.scheme.lower()
        try:
            host, port = parts.hostname, parts.port
        except ValueError:
            host = port = None
        if not host:
            raise LdapException(LdapErrorCode.SERVER_UNAVAILABLE, "ldap_connect вернул false для " + uri)
        is_ldaps = scheme == "ldaps"
        if not is_ldaps and not (scheme == "ldap" and self.config.start_tls):
            raise LdapException(LdapErrorCode.TLS_ERROR, "Разрешены только ldaps:// или ldap:// со StartTLS: " + uri)
        timeout = self.config.network_timeout
        try:
            # явный протокол — ldap3 строит SSLContext без create_default_context: без VERIFY_X509_STRICT/PARTIAL_CHAIN
            # Python 3.13+, иначе отвергались бы корпоративные CA, которые принимает OpenSSL у PHP (например, без AKI
            # или с некритичным basicConstraints). Имя хоста ldap3 сверяет с сертификатом сам.
            tls = Tls(validate=ssl.CERT_REQUIRED, version=ssl.PROTOCOL_TLS_CLIENT, ca_certs_file=self.ca_bundle_path, sni=host)
        except LDAPException:
            raise LdapException(LdapErrorCode.TLS_ERROR, "Не удалось установить CA bundle для TLS.") from None
        server = Server(host, port=port or (636 if is_ldaps else 389), use_ssl=is_ldaps, tls=tls, get_info=NONE, connect_timeout=timeout)
        conn = Connection(server, version=self.config.protocol_version, auto_referrals=False, receive_timeout=timeout,
                          raise_exceptions=False, read_only=True)
        if not is_ldaps:
            try:
                conn.open(read_server_info=False)
                if not conn.start_tls(read_server_info=False):
                    raise LDAPStartTLSError(str(conn.result))
            except LDAPException as exc:
                raise LdapException(LdapErrorCode.TLS_ERROR, f"StartTLS не удался: {exc}") from None
        self._conn = conn

    def handle(self) -> Connection:
        if self._conn is None:
            raise LdapException(LdapErrorCode.SERVER_UNAVAILABLE, "LDAP-соединение не установлено.")
        return self._conn

    def bind(self, dn: str, password: str) -> None:
        """Simple bind; пустые DN и пароль — анонимный bind (диагностика TLS). DN с пустым паролем не отправляется:
        AD принял бы его как анонимный («unauthenticated bind») — это не проверка пароля."""
        conn = self.handle()
        if dn == "" and password == "":
            conn.authentication, conn.user, conn.password = ANONYMOUS, None, None
        elif password == "":
            raise LdapException(LdapErrorCode.INVALID_CREDENTIALS, "bind failed errno=49 diag=empty password")
        else:
            conn.authentication, conn.user, conn.password = SIMPLE, dn, password
        try:
            ok = conn.bind(read_server_info=False)
        except LDAPException as exc:
            raise LdapException(_transport_code(exc), f"bind failed: {type(exc).__name__}: {exc}") from None
        finally:
            conn.password = None
        if not ok:
            result = conn.result or {}
            errno = int(result.get("result", -1))
            diagnostic = str(result.get("message") or "")
            raise LdapException(classify_bind_error(errno, diagnostic), f"bind failed errno={errno} diag={diagnostic}")

    def _search(self, base: str, flt: str, scope, attributes, error_prefix: str) -> list[str]:
        conn = self.handle()
        try:
            conn.search(base, flt, search_scope=scope, attributes=attributes)
        except LDAPException as exc:
            raise LdapException(LdapErrorCode.UNKNOWN, error_prefix + str(exc)) from None
        result = conn.result or {}
        if result.get("result") != 0:
            raise LdapException(LdapErrorCode.UNKNOWN, error_prefix + str(result.get("description") or result.get("message") or ""))
        # ссылки-реферралы (searchResRef) не считаются записями — как ldap_get_entries
        return [str(r["dn"]) for r in (conn.response or []) if r.get("type") == "searchResEntry"]

    def _read_exists(self, dn: str, flt: str) -> bool:
        """Аналог ldap_read: любая ошибка (нет объекта, неверный DN, сеть) — False."""
        try:
            return bool(self._search(dn, flt, BASE, NO_ATTRIBUTES, ""))
        except LdapException:
            return False

    def find_user_dn_by_sam_account_name(self, sam_account_name: str) -> str | None:
        flt = f"(&(objectClass=user)(objectCategory=person)(sAMAccountName={escape_filter(sam_account_name)}))"
        entries = self._search(self.config.base_dn, flt, SUBTREE, ["distinguishedName"], "Поиск пользователя не удался: ")
        if not entries:
            return None
        if len(entries) > 1:
            raise LdapException(LdapErrorCode.UNKNOWN, "Поиск вернул несколько записей для одного sAMAccountName.")
        return entries[0]

    def is_member_of_group_recursive(self, user_dn: str, group_dn: str) -> bool:
        """Прямое и вложенное членство: LDAP_MATCHING_RULE_IN_CHAIN на чтении самой группы."""
        self.handle()
        return self._read_exists(group_dn, f"(member:1.2.840.113556.1.4.1941:={escape_filter(user_dn)})")

    def find_group_dn_by_cn(self, cn: str) -> str | None:
        """Не ровно одна группа — None (неоднозначность = «не найдена», fail closed)."""
        flt = f"(&(objectClass=group)(cn={escape_filter(cn)}))"
        entries = self._search(self.config.base_dn, flt, SUBTREE, ["distinguishedName"], "Поиск группы не удался: ")
        return entries[0] if len(entries) == 1 else None

    def group_exists(self, group_dn: str) -> bool:
        self.handle()
        return self._read_exists(group_dn, "(objectClass=group)")

    def close(self) -> None:
        if self._conn is not None:
            try:
                self._conn.unbind()
            except LDAPException:
                pass
            self._conn = None
