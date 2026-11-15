"""Публичный маркер связи с AD: общий кэш результата (AdLinkStatusCache) и минимальная проверка LDAPS (AdLinkStatusChecker).

Кэш общий для всех посетителей: любое число анонимных обращений — не чаще одного реального подключения к AD за TTL.
Успех живёт 60 с, неудача — 5 с: кратковременный сбой не держит маркер красным, когда связь уже восстановилась."""

from __future__ import annotations

import ipaddress
import json
import socket
import time

from ..settings import AppSettings
from .client import LdapClient, LdapConnectionConfig, LdapErrorCode, LdapException

SETTINGS_KEY = "ad_link_status_cache"
TTL_SUCCESS_SECONDS = 60
TTL_FAILURE_SECONDS = 5
_LINK_DOWN = (LdapErrorCode.TLS_ERROR, LdapErrorCode.SERVER_UNAVAILABLE, LdapErrorCode.TIMEOUT)


def _is_ip(host: str) -> bool:
    try:
        ipaddress.ip_address(host)
        return True
    except ValueError:
        return False


def resolve(host: str) -> str:
    """Как gethostbyname PHP: при неудаче возвращает само имя."""
    try:
        return socket.gethostbyname(host)
    except (OSError, UnicodeError):
        return host


class AdLinkStatusCache:
    def __init__(self, settings: AppSettings):
        self.settings = settings

    def get(self) -> dict | None:
        """{'ok': bool, 'checked_at': int} или None — кэш пуст или устарел."""
        raw = self.settings.get(SETTINGS_KEY)
        if raw is None:
            return None
        try:
            decoded = json.loads(raw)
        except ValueError:
            return None
        if not isinstance(decoded, dict) or decoded.get("ok") is None or decoded.get("checked_at") is None:
            return None
        ok = bool(decoded["ok"])
        try:
            checked_at = int(decoded["checked_at"])
        except (TypeError, ValueError):
            return None
        if time.time() - checked_at > (TTL_SUCCESS_SECONDS if ok else TTL_FAILURE_SECONDS):
            return None
        return {"ok": ok, "checked_at": checked_at}

    def set(self, ok: bool) -> None:
        self.settings.set(SETTINGS_KEY, json.dumps({"ok": ok, "checked_at": int(time.time())}, separators=(",", ":")))


class AdLinkStatusChecker:
    """Без учётных данных: DNS, TCP и проверка TLS-сертификата по CA bundle (анонимный bind). Адрес — только из
    конфигурации администратора, пользовательского ввода нет. До 2 попыток — единичная задержка не даёт ложное «нет связи»."""

    TCP_TIMEOUT_SECONDS = 5
    MAX_ATTEMPTS = 2

    def __init__(self, ca_bundle_builder):
        self.ca_bundle_builder = ca_bundle_builder

    def is_reachable(self, config: LdapConnectionConfig) -> bool:
        return any(self._check_once(config) for _ in range(self.MAX_ATTEMPTS))

    def _check_once(self, config: LdapConnectionConfig) -> bool:
        if resolve(config.host) == config.host and not _is_ip(config.host):
            return False
        try:
            socket.create_connection((config.host, config.port), timeout=self.TCP_TIMEOUT_SECONDS).close()
        except OSError:
            return False
        try:
            ca_bundle_path = self.ca_bundle_builder.current_bundle_path()
        except Exception:  # noqa: BLE001
            return False
        client = LdapClient(config, ca_bundle_path)
        try:
            client.connect()
            try:
                client.bind("", "")
                return True
            except LdapException as exc:
                # отказ не по TLS/сети (например, анонимный bind запрещён политикой) — TLS-уровень пройден
                return exc.error_code not in _LINK_DOWN
        except Exception:  # noqa: BLE001
            return False
        finally:
            client.close()
