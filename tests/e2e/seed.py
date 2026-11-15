"""Сквозные проверки без AD: готовая сессия пользователя чата и провайдер-заглушка LLM. Только для тестового стенда.

    docker compose exec -T app python /srv/app/tests/e2e/seed.py session [логин] [provider_id] [--admin]
    docker compose exec -T app python /srv/app/tests/e2e/seed.py llm
Печатает JSON (кука сессии и CSRF / id провайдера и моделей)."""

from __future__ import annotations

import json
import sys

from llmp import db, paths
from llmp.chat import ChatUser, session_login
from llmp.domain import DomainGroupRepository, LlmDomainRepository
from llmp.ldap.config import LdapConfigRepository
from llmp.llm.models import ModelRepository
from llmp.llm.providers import ProviderRepository
from llmp.security import Session, SessionStore, csrf_token

GROUP_REF = "CN=LLM-E2E,DC=corp,DC=local"


def seed_session(args: list[str]) -> dict:
    as_admin = "--admin" in args
    args = [a for a in args if a != "--admin"]
    login = args[0] if args else "e2e.user"
    provider_id = int(args[1]) if len(args) > 1 else None
    conn = db.connect(migrate_schema=True)
    row = conn.execute("SELECT id FROM ldap_configs WHERE domain = 'corp.local'").fetchone()
    if row:
        domain_id = row[0]
    else:
        domain_id = LdapConfigRepository(conn).create({
            "name": "Тестовый домен", "ldaps_url": "ldaps://dc1.corp.local:636/", "host": "dc1.corp.local", "port": 636,
            "base_dn": "DC=corp,DC=local", "domain": "corp.local", "netbios_domain": "CORP", "admin_group_dn": "",
            "protocol_version": 3, "starttls": False, "network_timeout": 5, "ca_bundle_file": None,
        })
        LlmDomainRepository(conn).save_llm_settings(domain_id, True, "", None, False)
    row = conn.execute("SELECT id FROM llm_domain_groups WHERE group_ref = ?", (GROUP_REF,)).fetchone()
    group_id = row[0] if row else DomainGroupRepository(conn).create(domain_id, "LLM E2E", GROUP_REF, True, "e2e")
    if provider_id is not None:
        conn.execute("INSERT OR IGNORE INTO llm_group_providers (group_id, provider_id) VALUES (?, ?)", (group_id, provider_id))
    s = Session(None)
    session_login(s, ChatUser(login, domain_id, "corp.local", [group_id], ["LLM E2E"]))
    if as_admin:
        import time
        s.update(admin_username=login, admin_method="e2e", admin_last_activity=int(time.time()), admin_login_at=int(time.time()))
    csrf = csrf_token(s)
    sid = SessionStore(conn).save(s)
    return {"cookie": f"ADPWD_SESSION={sid}", "session": sid, "csrf": csrf, "group_id": group_id, "domain_id": domain_id}


def seed_llm() -> dict:
    conn = db.connect(migrate_schema=True)
    providers = ProviderRepository(conn)
    row = conn.execute("SELECT id FROM llm_providers WHERE name = 'Заглушка E2E'").fetchone()
    pid = row[0] if row else providers.create({
        "name": "Заглушка E2E", "provider_type": "openai_compatible", "base_url": "https://llm.example.com:8443/v1", "endpoint": "/chat/completions",
        "model": "stub-empty", "api_version": "", "connect_timeout": 5, "response_timeout": 20, "max_tokens": 512,
        "routing_provider": "", "sort_by_price": False, "max_price_per_mtoken": "", "allow_fallbacks": True, "use_corporate_ca": False, "is_enabled": True,
    }, "sk-e2e-not-a-real-key", None)
    models = ModelRepository(conn)
    ids = {"stub-empty": models.default_for(pid)}
    for name in ("stub-429", "stub-500", "stub-ok", "stub-drop", "stub-badjson", "stub-slow"):
        r = conn.execute("SELECT id FROM llm_models WHERE provider_id = ? AND model = ?", (pid, name)).fetchone()
        ids[name] = r[0] if r else models.create({"provider_id": pid, "model": name, "display_name": name, "context_window": 32000,
                                                  "max_output_tokens": 512, "priority": 100, "is_enabled": 1, "fallback_ids": []})
    conn.execute("UPDATE llm_models SET fallback_ids = ?, context_window = 32000 WHERE id = ?", (json.dumps([ids["stub-429"], ids["stub-ok"]]), ids["stub-empty"]))
    conn.execute("INSERT INTO app_settings (key, value) VALUES ('llm_chat_fallback_auto', '0') ON CONFLICT(key) DO UPDATE SET value = '0'")
    return {"provider_id": pid, "models": ids}


if __name__ == "__main__":
    paths.ensure_directories()
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "session":
        print(json.dumps(seed_session(sys.argv[2:])))
    elif cmd == "llm":
        print(json.dumps(seed_llm()))
    else:
        print(__doc__, file=sys.stderr)
        sys.exit(2)
