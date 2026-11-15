"""Права «группа → нейросети»: объединение по группам, статусы группы/домена/нейросети, чужие провайдеры
(tests/LlmAccessPolicyTest.php). Изоляция диалогов по владельцу — этап чата (ConversationRepository)."""

from __future__ import annotations

import pytest

from kit import create_domain, create_group, create_provider, database
from llmp.chat.core import ChatUser
from llmp.llm.access import AccessPolicy
from llmp.llm.errors import LlmException
from llmp.llm.providers import ProviderRepository


@pytest.fixture
def env():
    conn = database()
    e = type("Env", (), {})()
    e.conn = conn
    e.policy = AccessPolicy(conn)
    e.domain_id = create_domain(conn, "Corp", "corp.local")
    e.open_ai = create_provider(conn, "OpenAI", "openai")
    e.anthropic = create_provider(conn, "Anthropic", "anthropic")
    e.compatible = create_provider(conn, "Polza", "openai_compatible")

    def group(name, providers, enabled=True, domain_id=None):
        gid = create_group(conn, domain_id or e.domain_id, name, f"CN={name},DC=corp,DC=local", enabled)
        e.policy.set_group_providers(gid, providers)
        return gid

    e.group = group
    e.user = lambda group_ids, domain_id=None: ChatUser("ivanov", domain_id or e.domain_id, "corp.local", group_ids, [])
    yield e
    conn.close()


def test_single_group_sees_only_its_providers(env):
    """Одна группа видит только свои нейросети."""
    g = env.group("OnlyOpenAI", [env.open_ai])
    assert list(env.policy.allowed_providers([g], env.domain_id)) == [env.open_ai]
    assert not env.policy.is_allowed(env.user([g]), env.anthropic)


def test_union_across_several_groups(env):
    """Права нескольких групп объединяются."""
    g1 = env.group("A", [env.open_ai])
    g2 = env.group("B", [env.anthropic, env.open_ai])
    assert sorted(env.policy.allowed_providers([g1, g2], env.domain_id)) == sorted([env.open_ai, env.anthropic])
    assert not env.policy.is_allowed(env.user([g1, g2]), env.compatible)


def test_disabled_group_provider_or_domain_grant_nothing(env):
    """Выключенная группа, нейросеть или домен прав не дают."""
    disabled_group = env.group("Off", [env.compatible], False)
    assert env.policy.allowed_providers([disabled_group], env.domain_id) == {}

    g = env.group("On", [env.open_ai])
    ProviderRepository(env.conn).set_enabled(env.open_ai, False)
    assert env.policy.allowed_providers([g], env.domain_id) == {}

    ProviderRepository(env.conn).set_enabled(env.open_ai, True)
    # LlmDomainRepository::saveLlmSettings($id, false, …) — переносится этапом AD; здесь тот же эффект прямым SQL
    env.conn.execute("UPDATE ldap_configs SET llm_enabled = 0 WHERE id = ?", (env.domain_id,))
    assert env.policy.allowed_providers([g], env.domain_id) == {}


def test_group_of_another_domain_is_ignored(env):
    """Подмена group_id чужого домена в сессии не даёт прав."""
    other = create_domain(env.conn, "Other", "other.local")
    foreign = env.group("Foreign", [env.anthropic], True, other)
    assert env.policy.allowed_providers([foreign], env.domain_id) == {}


def test_assert_allowed_throws_for_tampered_provider_id(env):
    """Подменённый provider_id — access_denied."""
    g = env.group("A", [env.open_ai])
    with pytest.raises(LlmException):
        env.policy.assert_allowed(env.user([g]), env.anthropic)


def test_set_group_providers_reports_diff_and_ignores_unknown_ids(env):
    """Замена прав группы возвращает разницу и игнорирует неизвестные id."""
    g = env.group("A", [env.open_ai])
    diff = env.policy.set_group_providers(g, [env.anthropic, 99999])
    assert diff["added"] == [env.anthropic]
    assert diff["removed"] == [env.open_ai]
    assert env.policy.providers_for_group(g) == [env.anthropic]


def test_deleting_provider_removes_permissions(env):
    """Удаление нейросети снимает права на неё."""
    g = env.group("A", [env.open_ai, env.anthropic])
    ProviderRepository(env.conn).delete(env.open_ai)
    assert env.policy.providers_for_group(g) == [env.anthropic]


def test_changing_provider_address_drops_the_stored_key():
    """Смена адреса провайдера без нового ключа стирает сохранённый ключ."""
    conn = database()
    repo = ProviderRepository(conn)
    pid = create_provider(conn, "Polza")
    data = repo.find(pid)
    data["allow_fallbacks"] = bool(data["allow_fallbacks"])
    repo.update(pid, data, None, False)
    assert repo.find(pid)["has_api_key"], "прежний адрес — ключ остаётся"

    data["base_url"] = "https://attacker.example.com/v1"
    repo.update(pid, data, None, False)
    assert not repo.find(pid)["has_api_key"], "новый адрес — сохранённый ключ туда не отправится"


def test_admitted_user_always_gets_only_the_base_model(env):
    """Добавленному в чат доступна базовая модель (по умолчанию у нейросети по умолчанию) без отдельных прав; другие модели
    этой нейросети и другие нейросети — только по явным правам."""
    from llmp.llm.models import ModelRepository
    from llmp.db import seed_default_models
    seed_default_models(env.conn)
    models = ModelRepository(env.conn)
    base = models.default_for(env.compatible)
    pricey = models.create({"provider_id": env.compatible, "model": "big", "display_name": "big", "context_window": 32000, "max_output_tokens": 512, "priority": 1, "is_enabled": 1, "fallback_ids": []})
    ProviderRepository(env.conn).set_default(env.compatible)
    g = env.group("Новые", [])
    allowed = env.policy.allowed_providers([g], env.domain_id)
    assert list(allowed) == [env.compatible] and allowed[env.compatible]["base_only"]
    assert list(models.allowed_for(allowed)) == [base], "дорогая модель той же нейросети — не базовая"
    env.policy.set_group_providers(g, [env.compatible])
    assert sorted(models.allowed_for(env.policy.allowed_providers([g], env.domain_id))) == sorted([base, pricey])
    disabled = env.group("Выключенная", [], enabled=False)
    assert env.policy.allowed_providers([disabled], env.domain_id) == {}, "выключенный субъект базовую модель не получает"
