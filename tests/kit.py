"""Тестовые помощники (аналог tests/LlmTestKit.php): БД со схемой, домен AD, провайдер LLM.

Вставки — прямым SQL с теми же значениями, что у PHP-набора: тесты разных модулей не зависят от того,
в каком порядке перенесены репозитории."""

from __future__ import annotations

import json
import sqlite3

from llmp import db, secretbox

PROVIDER_DEFAULTS = {
    "openai": ("https://api.openai.com/v1", "/chat/completions", ""),
    "anthropic": ("https://api.anthropic.com", "/v1/messages", "2023-06-01"),
    "openai_compatible": ("https://polza.ai/api/v1", "/chat/completions", ""),
}


def database() -> sqlite3.Connection:
    return db.connect(":memory:", migrate_schema=True)


def create_domain(conn: sqlite3.Connection, name: str, fqdn: str, llm_enabled: bool = True) -> int:
    now = db.now_iso()
    cur = conn.execute(
        "INSERT INTO ldap_configs (name, is_active, ldaps_url, host, port, base_dn, domain, netbios_domain, admin_group_dn, protocol_version, "
        "starttls, network_timeout, ca_bundle_file, created_at, updated_at, llm_enabled, service_username) VALUES (?, 0, ?, ?, 636, ?, ?, ?, '', 3, 0, 5, NULL, ?, ?, ?, '')",
        (name, f"ldaps://dc.{fqdn}:636/", f"dc.{fqdn}", "DC=" + fqdn.replace(".", ",DC="), fqdn, fqdn.split(".")[0].upper(), now, now, 1 if llm_enabled else 0),
    )
    return int(cur.lastrowid)


def create_provider(conn: sqlite3.Connection, name: str, ptype: str = "openai_compatible", enabled: bool = True,
                    api_key: str = "sk-test-key-123456") -> int:
    base, endpoint, version = PROVIDER_DEFAULTS[ptype]
    now = db.now_iso()
    cur = conn.execute(
        "INSERT INTO llm_providers (name, provider_type, base_url, endpoint, api_key_encrypted, api_key_hint, model, api_version, connect_timeout, "
        "response_timeout, max_tokens, routing_provider, sort_by_price, max_price_per_mtoken, allow_fallbacks, use_corporate_ca, is_enabled, created_at, updated_at) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 5, 30, 512, '', 0, NULL, 1, 0, ?, ?, ?)",
        (name, ptype, base, endpoint, secretbox.encrypt(api_key) if api_key else None, ("…" + api_key[-4:]) if api_key else None,
         "claude-sonnet-5" if ptype == "anthropic" else "openai/gpt-4o", version, 1 if enabled else 0, now, now),
    )
    db.seed_default_models(conn)
    return int(cur.lastrowid)


def create_group(conn: sqlite3.Connection, domain_id: int, name: str, ref: str, enabled: bool = True) -> int:
    now = db.now_iso()
    cur = conn.execute(
        "INSERT INTO llm_domain_groups (ldap_config_id, display_name, group_ref, is_enabled, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 't', ?, ?)",
        (domain_id, name, ref, 1 if enabled else 0, now, now),
    )
    return int(cur.lastrowid)


def json_body(request) -> dict:
    return json.loads(request.body) if request.body else {}
