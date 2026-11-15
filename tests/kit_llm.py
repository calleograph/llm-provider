"""Помощники тестов LLM-ядра: стенд резервирования на заглушках (аналог setUp tests/LlmFailoverTest.php).

Каждая «модель» — свой адрес провайдера (primary/backup/forbidden.example.com), сценарий ответов — очередь по хосту."""

from __future__ import annotations

import json
from urllib.parse import urlsplit

from kit import create_domain, create_group, create_provider, database
from llmp.anon.text import AnonymizedText
from llmp.chat.core import ChatUser
from llmp.http import FakeHttpTransport, HttpRequest, HttpResponse
from llmp.llm import (AccessPolicy, AdapterRegistry, LlmGateway, ModelHealthRepository, ModelRepository, ProviderRepository,
                      UserProviderRepository)
from llmp.logs import DiagnosticLogger


def ok(text: str, finish: str = "stop") -> HttpResponse:
    return HttpResponse(200, json.dumps({"model": "m", "choices": [{"message": {"content": text}, "finish_reason": finish}],
                                         "usage": {"prompt_tokens": 10, "completion_tokens": 5}}, ensure_ascii=False))


class FailoverBench:
    def __init__(self) -> None:
        self.conn = database()
        domain_id = create_domain(self.conn, "Corp", "corp.local")
        self.primary = self._provider("primary")
        self.backup = self._provider("backup")
        self.forbidden = self._provider("forbidden")
        self.group_id = create_group(self.conn, domain_id, "LLM", "CN=LLM,DC=corp,DC=local")
        self.script: dict[str, list] = {}
        self.sleeps: list[float] = []
        self.alerts: list[tuple] = []
        self.transport = FakeHttpTransport(self._handle)
        self.models = ModelRepository(self.conn)
        self.health = ModelHealthRepository(self.conn)
        self.access = AccessPolicy(self.conn, UserProviderRepository(self.conn))
        self.gateway = LlmGateway(ProviderRepository(self.conn), self.access, AdapterRegistry.with_defaults(), self.transport, lambda: None,
                                  DiagnosticLogger(self.conn), self.models, self.health, self.sleeps.append,
                                  lambda t, v, k: self.alerts.append((t, v, k)))
        self.access.set_group_providers(self.group_id, [self.primary, self.backup])
        self.user = ChatUser("ivanov", domain_id, "corp.local", [self.group_id], ["LLM"])
        # Цепочка: primary → forbidden (нет прав) → backup; автоматическое резервирование выключено — только явная цепочка.
        self.auto_fallback = False
        self.set_chain(self.primary, [self.forbidden, self.backup])

    def _provider(self, host: str) -> int:
        pid = create_provider(self.conn, host.capitalize())
        self.conn.execute("UPDATE llm_providers SET base_url = ? WHERE id = ?", (f"https://{host}.example.com/v1", pid))
        return pid

    def _handle(self, r: HttpRequest) -> HttpResponse:
        queue = self.script.setdefault(urlsplit(r.url).hostname or "", [])
        nxt = queue.pop(0) if queue else ok("ответ " + (urlsplit(r.url).hostname or ""))
        if isinstance(nxt, BaseException):
            raise nxt
        return nxt

    def set_chain(self, provider_id: int, provider_ids: list[int]) -> None:
        ids = [self.models.default_for(p) for p in provider_ids]
        self.conn.execute("UPDATE llm_models SET fallback_ids = ? WHERE id = ?", (json.dumps(ids), self.models.default_for(provider_id)))

    def allowed_models(self) -> dict[int, dict]:
        return self.models.allowed_for(self.access.allowed_providers(self.user.group_ids, self.user.domain_id, self.user.owner_key()))

    def send(self, model_id: int | None = None, text: str = "Привет"):
        """Вызов шлюза так, как его делает ChatService::send() (без контекста и сохранения сообщений)."""
        history = [{"role": "user", "content": AnonymizedText.from_gateway(text)}]
        mid = model_id if model_id is not None else self.models.default_for(self.primary)
        return self.gateway.complete_with_failover(self.user, mid, history, None, None, self.allowed_models(), self.auto_fallback, 540, 0)

    def calls_to(self, host: str) -> int:
        return len(self.transport.requests_to(host + ".example.com"))
