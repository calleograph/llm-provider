"""Дополнительно к PHP-набору: CRUD провайдеров и моделей, проверка подключения из админки."""

from __future__ import annotations

from kit import create_provider, database
from kit_llm import ok
from llmp.http import FakeHttpTransport, HttpResponse
from llmp.llm import AccessPolicy, AdapterRegistry, LlmGateway, ModelHealthRepository, ModelRepository, ProviderRepository, ProviderValidator


def test_provider_create_and_gateway_snapshot():
    """Ключ и заголовки шифруются, наружу — только подсказка; снимок для шлюза расшифрован."""
    conn = database()
    repo = ProviderRepository(conn)
    data = ProviderValidator.normalize({"name": "Polza", "provider_type": "openai_compatible", "base_url": "https://polza.ai/api/v1",
                                        "endpoint": "/chat/completions", "model": "openai/gpt-4o", "is_enabled": "1", "allow_fallbacks": "1"})
    pid = repo.create(data, "sk-0123456789abcd", {"X-Org": "corp"})
    row = repo.find(pid)
    assert row["api_key_hint"] == "…abcd" and row["extra_header_names"] == "X-Org" and row["has_api_key"]
    cfg = repo.load_for_gateway(pid)
    assert cfg.api_key == "sk-0123456789abcd" and cfg.extra_headers == {"X-Org": "corp"} and cfg.api_version is None
    assert "sk-0123" not in repr(cfg)
    assert ModelRepository(conn).default_for(pid) is not None, "модель по умолчанию создана"


def test_model_delete_cleans_fallback_chains_and_keeps_default():
    """Модель по умолчанию не удаляется; удалённая исчезает из чужих цепочек."""
    conn = database()
    pid = create_provider(conn, "A")
    models = ModelRepository(conn)
    default = models.default_for(pid)
    extra = models.create({"provider_id": pid, "model": "x-1", "display_name": "X", "context_window": 8000, "max_output_tokens": 512,
                           "is_enabled": 1, "fallback_ids": []})
    models.update(default, {**models.find(default), "fallback_ids": [extra], "price_in": None, "price_out": None})
    assert models.find(default)["fallback_ids"] == [extra]
    assert not models.delete(default)
    assert models.delete(extra)
    assert models.find(default)["fallback_ids"] == []


def test_test_connection_records_check():
    """Проверка подключения: успех и ошибка записываются в last_check_*."""
    conn = database()
    pid = create_provider(conn, "A")
    responses = [ok("OK"), HttpResponse(401, '{"error":{"message":"bad key"}}')]
    gw = LlmGateway(ProviderRepository(conn), AccessPolicy(conn), AdapterRegistry.with_defaults(), FakeHttpTransport(lambda r: responses.pop(0)),
                    lambda: None, None, ModelRepository(conn), ModelHealthRepository(conn))
    good = gw.test_connection(pid)
    assert good["ok"] and good["message"] == "Подключение успешно, модель m, finish_reason=stop."
    bad = gw.test_connection(pid)
    assert not bad["ok"] and bad["message"].startswith("Ключ отклонён: HTTP 401")
    assert ProviderRepository(conn).find(pid)["last_check_ok"] == 0
