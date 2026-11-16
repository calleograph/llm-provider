"""ChatService сквозь фейковые внешние сервисы: пользователь → обезличивание → LLM → история (tests/LlmChatServiceTest.php)
и сценарии, отложенные до этапа чата в tests/parity/{llm_core,anon_files,chat_context}.md."""

from __future__ import annotations

import json

import pytest

from kit import create_domain, create_provider, database
from kit_anon import ANON_HOST, service_reply, use_service
from kit_llm import FailoverBench, ok
from llmp.anon.text import AnonymizedText
from llmp.chat import ChatException, ChatUser, ConversationRepository
from llmp.chat.service import ChatService
from llmp.domain import DomainGroupRepository
from llmp.http import FakeHttpTransport, HttpResponse
from llmp.llm import failover
from llmp.llm.errors import LlmException
from llmp import prompt
from llmp.module import LlmModule

RAW = "Подготовь письмо для Иванов Иван Иванович, паспорт 4510 123456"
ANON = "Подготовь письмо для [PERSON_1], паспорт [PASSPORT_1]"


def rows(conn, sql: str) -> list:
    return [dict(r) for r in conn.execute(sql)]


def scalar(conn, sql: str):
    return conn.execute(sql).fetchone()[0]


def results(conn) -> str:
    return "\n".join(r["result"] for r in rows(conn, "SELECT result FROM app_log"))


class Env:
    """Стенд tests/LlmChatServiceTest.php: встроенный сервис обезличивания, провайдер Polza и запрещённый Anthropic."""

    def __init__(self, replace=None, answer=None):
        self.conn = database()
        domain_id = create_domain(self.conn, "Corp", "corp.local")
        self.provider_id = create_provider(self.conn, "Polza GPT-4o")
        self.forbidden_provider_id = create_provider(self.conn, "Anthropic", "anthropic")
        self.groups = DomainGroupRepository(self.conn)
        self.group_id = self.groups.create(domain_id, "LLM Users", "CN=LLM-Users,DC=corp,DC=local", True, "test")
        self.anonymizer_down = False
        self.replace = replace or (lambda t: t.replace("Иванов Иван Иванович", "[PERSON_1]").replace("4510 123456", "[PASSPORT_1]"))
        self.answer = answer or (lambda body: {"model": "openai/gpt-4o", "choices": [{"message": {"content": "Уважаемый [PERSON_1], …"}, "finish_reason": "stop"}]})
        self.transport = FakeHttpTransport(self.handle)
        self.module = LlmModule(self.conn, transport=self.transport)
        self.module.access.set_group_providers(self.group_id, [self.provider_id])
        self.user = ChatUser("ivanov", domain_id, "corp.local", [self.group_id], ["LLM Users"])

    def handle(self, r):
        if ANON_HOST in r.url:
            if self.anonymizer_down:
                return HttpResponse(503, "{}")
            # профиль custom со всеми keep — маскирование только секретов (отправка без обезличивания)
            return service_reply(r, (lambda t: t) if json.loads(r.body)["profile"] == "custom" else self.replace)
        return HttpResponse(200, json.dumps(self.answer(json.loads(r.body)), ensure_ascii=False))

    @property
    def chat(self) -> ChatService:
        return self.module.chat_service

    def send(self, text: str = RAW, conversation_id=None, project_id=None, skip=False, **kw):
        return self.chat.send(self.user, "10.0.0.1", conversation_id, kw.pop("provider_id", self.provider_id), text, kw.pop("uploads", []),
                              project_id, skip, **kw)

    def llm_bodies(self) -> list[str]:
        return [r.body for r in self.transport.requests_to("polza.ai")]

    def all_stored_text(self) -> str:
        return "".join(json.dumps(rows(self.conn, f"SELECT * FROM {t}"), ensure_ascii=False, default=str)
                       for t in ("llm_messages", "llm_conversations", "app_log", "audit_log", "diagnostic_log", "app_settings"))


@pytest.fixture
def env(monkeypatch):
    use_service(monkeypatch)
    return Env()


# ---------------- tests/LlmChatServiceTest.php ----------------

def test_only_anonymized_text_leaves_and_is_stored(env):
    result = env.send()
    bodies = env.llm_bodies()
    assert len(bodies) == 1
    assert "[PERSON_1]" in bodies[0] and "Иванов" not in bodies[0] and "4510" not in bodies[0]
    assert result["user_message"]["content"] == ANON
    assert result["assistant_message"]["content"] == "Уважаемый [PERSON_1], …"

    stored = env.all_stored_text()
    assert "Иванов" not in stored, "исходный текст не сохраняется ни в истории, ни в журналах"
    assert "4510" not in stored
    assert "sk-test-key" not in stored, "API-ключ хранится только зашифрованным"
    for event in ("LLM_PROVIDER_SELECT_SUCCESS", "ANONYMIZATION_SUCCESS", "LLM_REQUEST_SUCCESS", "CONVERSATION_CREATE_SUCCESS"):
        assert event in results(env.conn)


def test_follow_up_sends_anonymized_history(env):
    first = env.send()
    env.send("Сделай короче", first["conversation"]["id"])
    body = env.llm_bodies()[1]
    contents = [m["content"] for m in json.loads(body)["messages"]]
    assert ANON in contents
    assert "Уважаемый [PERSON_1], …" in contents
    assert contents[-1] == "Сделай короче"
    assert "Иванов" not in body


def test_anonymization_failure_blocks_external_request(env):
    env.anonymizer_down = True
    with pytest.raises(ChatException) as e:
        env.send()
    assert e.value.error_code == "anonymization_failed"
    assert env.llm_bodies() == [], "при ошибке обезличивания запрос наружу не отправляется"
    assert scalar(env.conn, "SELECT COUNT(*) FROM llm_conversations") == 0
    assert "ANONYMIZATION_FAILED" in env.all_stored_text()
    assert "Иванов" not in env.all_stored_text()


def test_not_configured_anonymization_blocks_everything(env, monkeypatch):
    monkeypatch.delenv("ANONYMIZER_URL")
    with pytest.raises(ChatException):
        env.send("Привет")
    assert env.transport.requests == []


def test_tampered_provider_id_is_rejected_before_any_request(env):
    with pytest.raises(ChatException) as e:
        env.send(provider_id=env.forbidden_provider_id)
    assert (e.value.error_code, e.value.http_status) == ("access_denied", 403)
    assert env.transport.requests == []


def test_foreign_conversation_is_not_accessible(env):
    first = env.send("Привет")
    stranger = ChatUser("petrov", env.user.domain_id, "corp.local", env.user.group_ids, [])
    with pytest.raises(ChatException) as e:
        env.chat.send(stranger, "10.0.0.2", first["conversation"]["id"], env.provider_id, "Покажи историю", [])
    assert (e.value.error_code, e.value.http_status) == ("not_found", 404)


def test_gateway_rechecks_permissions(env):
    with pytest.raises(LlmException):
        env.module.gateway.complete(env.user, env.forbidden_provider_id, [{"role": "user", "content": AnonymizedText.from_storage("x")}])


def test_skip_anonymization_denied_without_group_permission(env):
    with pytest.raises(ChatException) as e:
        env.send(skip=True)
    assert (e.value.error_code, e.value.http_status) == ("access_denied", 403)
    assert env.transport.requests == [], "при отказе в праве запрос наружу не отправляется"
    assert "LLM_UNANONYMIZED_DENIED" in results(env.conn)


def test_skip_anonymization_sends_raw_text_when_permitted(env):
    env.groups.set_allow_unanonymized(env.group_id, True)
    result = env.send(skip=True)
    assert [json.loads(c.body)["profile"] for c in env.transport.requests_to(ANON_HOST)] == ["custom"], \
        "обезличивание не вызывалось — только маскирование секретов"
    bodies = env.llm_bodies()
    assert len(bodies) == 1 and "Иванов Иван Иванович" in bodies[0] and "4510" in bodies[0]
    assert result["user_message"]["content"] == RAW
    assert result["user_message"]["unanonymized"] is True
    assert scalar(env.conn, "SELECT content FROM llm_messages WHERE role = 'user'") == RAW
    assert scalar(env.conn, "SELECT unanonymized FROM llm_messages WHERE role = 'user'") == 1
    assert scalar(env.conn, "SELECT unanonymized FROM llm_messages WHERE role = 'assistant'") == 1, "ответ помечается так же"
    assert "LLM_SEND_UNANONYMIZED" in results(env.conn)


def test_skip_anonymization_false_still_anonymizes_even_with_permission(env):
    env.groups.set_allow_unanonymized(env.group_id, True)
    result = env.send(skip=False)
    assert result["user_message"]["content"] == ANON
    assert result["user_message"]["unanonymized"] is False
    assert "Иванов" not in env.all_stored_text()


def test_skip_anonymization_permitted_by_personal_grant_without_group_right(env):
    env.module.usage.set_allow_unanonymized(env.user.owner_key(), True)
    result = env.send(skip=True)
    assert result["user_message"]["content"] == RAW
    assert result["user_message"]["unanonymized"] is True
    assert "Иванов Иван Иванович" in env.llm_bodies()[0]


# ---------------- новые проверки send() ----------------

def test_usage_reservation_is_replaced_by_actual_and_storage_quota_blocks_before_llm(env):
    """Бронь контекста заменяется фактическим расходом; превышение квоты хранения — до обращения к LLM."""
    env.answer = lambda body: {"choices": [{"message": {"content": "Ответ"}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 70, "completion_tokens": 30}}
    result = env.send("Привет")
    assert result["usage"]["used"] == 100
    assert scalar(env.conn, "SELECT requests FROM llm_usage") == 1, "бронь не считается отдельным запросом"

    default = env.module.limit_categories.default_category()
    env.module.limit_categories.update(default["id"], default["name"], default["period"], default["tokens"], 1, True)
    env.module.chat_settings.save({"max_message_chars": "500000"})
    env.transport.requests.clear()
    with pytest.raises(ChatException) as e:
        env.send("я" * 300000)   # 300 000 знаков = 600 000 байт UTF-8, ×2 на ответ — больше 1 МБ
    assert (e.value.error_code, e.value.http_status) == ("storage_quota_exceeded", 507)
    assert env.llm_bodies() == []


def test_message_limits_and_empty_input(env):
    with pytest.raises(ChatException) as e:
        env.send("   ")
    assert e.value.error_code == "empty"
    with pytest.raises(ChatException) as e:
        env.send("x" * 20001)
    assert e.value.error_code == "message_too_long"
    with pytest.raises(ChatException) as e:
        env.send("x", uploads=[{}] * 6)
    assert e.value.error_code == "too_many_files"
    assert env.transport.requests == []


def test_preferences_and_styles(env):
    """Стиль и инструкции: обезличиваются, недоступный стиль отклоняется."""
    allowed = env.chat.allowed_styles()
    style = next(iter(allowed))
    prefs = env.chat.set_user_preferences(env.user, "ip", style, "Пиши для Иванов Иван Иванович")
    assert prefs == {"style": style, "instructions": "Пиши для [PERSON_1]"}
    with pytest.raises(ChatException) as e:
        env.chat.set_user_preferences(env.user, "ip", "no-such-style", "")
    assert e.value.error_code == "style_denied"
    with pytest.raises(ChatException) as e:
        env.chat.set_conversation_preferences(env.user, "ip", 999, None, "")
    assert e.value.error_code == "not_found"


# ---------------- LlmAccessPolicyTest::testConversationsAreIsolatedByOwner ----------------

def test_conversations_are_isolated_by_owner(conn):
    provider = create_provider(conn, "OpenAI", "openai")
    repo = ConversationRepository(conn)
    mine = repo.create("ivanov@corp.local", "Мой", provider)
    repo.add_message(mine, "user", "[PERSON_1] вопрос", provider, None, None, None)
    assert repo.find_owned(mine, "petrov@corp.local") is None
    assert repo.messages(mine, "petrov@corp.local") == []
    assert repo.delete(mine, "petrov@corp.local") is False
    assert repo.list_for_owner("petrov@corp.local") == []
    assert len(repo.messages(mine, "ivanov@corp.local")) == 1
    assert repo.delete(mine, "ivanov@corp.local") is True


# ---------------- LlmProjectsAndLimitsTest (сценарии через ChatService) ----------------

@pytest.fixture
def penv(monkeypatch):
    use_service(monkeypatch)
    e = Env(replace=lambda t: t.replace("Иванов", "[PERSON]"),
            answer=lambda body: {"choices": [{"message": {"content": "Ответ"}, "finish_reason": "stop"}],
                                 "usage": {"prompt_tokens": 700, "completion_tokens": 300}})
    e.module.usage.touch_profile(e.user)
    return e


def test_project_context_is_sent_as_anonymized_system_prompt(penv):
    owner = penv.user.owner_key()
    pid = penv.module.projects.create(owner, "Договоры")
    assert penv.chat.set_project_instructions(penv.user, "ip", pid, "Отвечай про Иванов") == "Отвечай про [PERSON]"
    penv.chat.add_project_source(penv.user, "ip", pid, "Регламент", "Ответственный — Иванов", None)

    result = penv.send("Кто ответственный?", project_id=pid)
    assert result["conversation"]["project_id"] == pid
    body = json.loads(penv.llm_bodies()[0])
    system = body["messages"][0]
    assert system["role"] == "system"
    assert "Отвечай про [PERSON]" in system["content"]
    assert "[Источник 1: Регламент]" in system["content"]
    assert "Ответственный — [PERSON]" in system["content"]
    assert "Иванов" not in json.dumps(body, ensure_ascii=False)
    assert "Иванов" not in json.dumps(rows(penv.conn, "SELECT * FROM llm_project_sources"), ensure_ascii=False, default=str)
    assert penv.module.projects.find_owned(pid, "petrov@corp.local") is None
    assert penv.module.projects.context_parts(pid, "petrov@corp.local") is None


def test_every_project_field_reaching_the_llm_is_anonymized_and_settings_apply(penv):
    """Описание, основная инструкция, блок (название и текст), материал (название и текст), память — всё обезличено;
    выключатели настроек убирают материалы и память, стиль проекта действует, если у чата нет своего."""
    owner = penv.user.owner_key()
    pid = penv.module.projects.create(owner, "Иванов и партнёры")   # название в модель не уходит
    penv.chat.set_project_description(penv.user, "ip", pid, "Договор Иванов")
    penv.chat.set_project_instructions(penv.user, "ip", pid, "Главное: Иванов")
    penv.chat.add_project_instruction(penv.user, "ip", pid, "Тон Иванов", "Иванов — на вы")
    penv.chat.add_project_source(penv.user, "ip", pid, "Письмо Иванов", "Подписант Иванов", None)
    assert penv.chat.set_project_memory(penv.user, "ip", pid, "Иванов утвердил смету") == "[PERSON] утвердил смету"
    with pytest.raises(ChatException) as e:
        penv.chat.set_project_memory(penv.user, "ip", pid, "я" * 4001)
    assert e.value.error_code == "too_long"

    penv.send("Что дальше?", project_id=pid)
    body = json.dumps(json.loads(penv.llm_bodies()[0]), ensure_ascii=False)
    assert "Иванов" not in body
    for part in ("Договор [PERSON]", "Главное: [PERSON]", "[Инструкция: Тон [PERSON]]", "Письмо [PERSON]", "Подписант [PERSON]", "[PERSON] утвердил смету"):
        assert part in body, part
    system = json.loads(penv.llm_bodies()[0])["messages"][0]["content"]
    assert system.index("Главное: [PERSON]") < system.index("[Инструкция: Тон [PERSON]]"), "основная инструкция — перед блоками"
    assert system.index("Подписант [PERSON]") < system.index("[PERSON] утвердил смету"), "материалы целиком — перед изменчивой памятью"

    penv.chat.set_project_settings(penv.user, "ip", pid, json.dumps({"use_memory": False, "use_sources": False, "style": "concise"}))
    penv.transport.requests.clear()
    penv.send("Ещё вопрос", project_id=pid)
    system = json.loads(penv.llm_bodies()[0])["messages"][0]["content"]
    assert "Подписант" not in system and "утвердил смету" not in system
    assert "Главное: [PERSON]" in system, "инструкции не выключаются"
    assert prompt.STYLES["concise"][1] in system


def test_project_memory_is_not_updated_when_disabled(penv, monkeypatch):
    pid = penv.module.projects.create(penv.user.owner_key(), "Договоры")
    called = []
    monkeypatch.setattr(penv.chat.summaries, "update", lambda *a, **k: True)
    monkeypatch.setattr(penv.chat.summaries, "get", lambda cid: {"text": "Сводка", "covered_until_id": 1})
    monkeypatch.setattr(penv.chat.summaries, "update_project_state", lambda *a, **k: called.append(1) or True)
    cid = penv.send("Привет", project_id=pid)["conversation"]["id"]
    task = {"conversation_id": cid, "model_id": penv.module.models.default_for(penv.provider_id), "project_id": pid}
    penv.chat.maintain_context(penv.user, task)
    assert called == [1]
    penv.chat.set_project_settings(penv.user, "ip", pid, json.dumps({"use_memory": False}))
    penv.chat.maintain_context(penv.user, task)
    assert called == [1], "память выключена — не обновляется"


def test_project_source_count_limit_blocks_before_processing_file(penv):
    pid = penv.module.projects.create(penv.user.owner_key(), "Договоры")
    penv.module.chat_settings.save({"project_sources_max_files": "2"})
    penv.chat.add_project_source(penv.user, "ip", pid, "Первый", "Текст один", None)
    penv.chat.add_project_source(penv.user, "ip", pid, "Второй", "Текст два", None)
    with pytest.raises(ChatException) as e:
        penv.chat.add_project_source(penv.user, "ip", pid, "Третий", "Текст три", None)
    assert e.value.error_code == "sources_limit"
    assert penv.module.projects.source_count(pid) == 2


def test_project_source_chars_limit_blocks_too_long_source(penv):
    pid = penv.module.projects.create(penv.user.owner_key(), "Договоры")
    penv.module.chat_settings.save({"project_sources_max_chars": "1000"})
    with pytest.raises(ChatException) as e:
        penv.chat.add_project_source(penv.user, "ip", pid, "Большой", "текст " * 500, None)
    assert e.value.error_code == "sources_limit"


def test_project_instruction_blocks_are_anonymized_and_separate_from_sources(penv):
    owner = penv.user.owner_key()
    pid = penv.module.projects.create(owner, "Договоры")
    instruction = penv.chat.add_project_instruction(penv.user, "ip", pid, "Стиль", 'Обращайся к Иванов на "вы"')
    penv.chat.add_project_source(penv.user, "ip", pid, "Регламент", "Ответственный — Иванов", None)
    projects = penv.module.projects
    assert projects.instruction_count(pid) == 1 and projects.source_count(pid) == 1
    assert len(projects.instructions(pid, owner)) == 1 and len(projects.sources(pid, owner)) == 1

    penv.send("Кто ответственный?", project_id=pid)
    body = json.loads(penv.llm_bodies()[0])
    system = body["messages"][0]["content"]
    assert "[Инструкция: Стиль]" in system
    assert 'Обращайся к [PERSON] на "вы"' in system
    assert "[Источник 1: Регламент]" in system
    assert "Иванов" not in json.dumps(body, ensure_ascii=False)
    assert projects.source_text(instruction["id"], owner) == 'Обращайся к [PERSON] на "вы"'


def test_project_instruction_limits_are_independent_from_source_limits(penv):
    pid = penv.module.projects.create(penv.user.owner_key(), "Договоры")
    penv.module.chat_settings.save({"project_instructions_max_blocks": "1", "project_instruction_max_chars": "20"})
    penv.chat.add_project_instruction(penv.user, "ip", pid, "Первая", "Короткий текст")
    with pytest.raises(ChatException) as e:
        penv.chat.add_project_instruction(penv.user, "ip", pid, "Вторая", "Ещё текст")
    assert e.value.error_code == "instructions_limit"
    penv.module.chat_settings.save({"project_instructions_max_blocks": "5"})
    with pytest.raises(ChatException) as e:
        penv.chat.add_project_instruction(penv.user, "ip", pid, "Длинная", "текст " * 20)
    assert e.value.error_code == "too_long"


def test_usage_is_recorded_and_daily_limit_blocks_before_any_request(penv):
    categories = penv.module.limit_categories
    default = categories.default_category()
    categories.update(default["id"], default["name"], "day", 1500, default["storage_mb"], True)
    first = penv.send("Первый")
    assert first["usage"]["used"] == 1000
    assert first["usage"]["limit"] == 1500
    penv.send("Второй")
    before = len(penv.transport.requests)
    with pytest.raises(ChatException) as e:
        penv.send("Третий")
    assert (e.value.error_code, e.value.http_status) == ("limit_exceeded", 429)
    assert len(penv.transport.requests) == before, "при исчерпанном лимите ничего не отправляется"
    assert scalar(penv.conn, "SELECT COUNT(*) FROM llm_usage") == 1
    assert "LLM_LIMIT_EXCEEDED" in results(penv.conn)


def test_gateway_context_must_be_anonymized_text(penv):
    penv.send("Разогрев")
    result = penv.module.gateway.complete(penv.user, penv.provider_id, [{"role": "user", "content": AnonymizedText.from_storage("x")}], "S",
                                          AnonymizedText.from_storage("Контекст"))
    assert result.text == "Ответ"
    assert json.loads(penv.transport.requests[-1].body)["messages"][0]["content"] == "S\n\nКонтекст"


# ---------------- LlmContextTest::testChatUsesModelWindowSummarisesAfterTheAnswerAndReusesTheSummary ----------------

def test_chat_uses_model_window_summarises_after_the_answer_and_reuses_the_summary(monkeypatch):
    use_service(monkeypatch)
    calls = {"summary": 0}

    def answer(body):
        is_summary = "справочную сводку" in body["messages"][0]["content"]
        calls["summary"] += is_summary
        text = "## Текущая задача\nНет\n## Факты и значения\nСрок — пятница" if is_summary else "ответ " * 300
        return {"choices": [{"message": {"content": text}, "finish_reason": "stop"}]}

    e = Env(replace=lambda t: t, answer=answer)
    e.conn.execute("UPDATE llm_models SET context_window = 6000, max_output_tokens = 500")
    first = e.send("Начнём. Срок — пятница.")
    cid = first["conversation"]["id"]
    maintenance = None
    for i in range(8):
        r = e.send(f"Вопрос {i} " + "уточнение " * 60, cid)
        maintenance = r["maintenance"] or maintenance
    assert maintenance is not None, "история переросла окно — нужна сводка"
    assert r["assistant_message"]["context_report"]["total"] <= r["assistant_message"]["context_report"]["budget"]
    used_before = e.module.usage.status(e.user.owner_key())["used"]
    e.chat.maintain_context(e.user, maintenance)
    assert calls["summary"] == 1
    assert scalar(e.conn, "SELECT COUNT(*) FROM llm_conversation_summaries") == 1
    assert e.module.usage.status(e.user.owner_key())["used"] > used_before, "токены сводки списаны с лимита"

    e.transport.requests.clear()
    e.send("Какой срок?", cid)
    system = json.loads(e.llm_bodies()[0])["messages"][0]["content"]
    assert "Срок — пятница" in system, "сводка подставлена в контекст"
    assert "справочная сводка ранней части диалога" in system.lower()
    assert scalar(e.conn, f"SELECT COUNT(*) FROM llm_messages WHERE conversation_id = {cid}") - 1 == 19, "полная история сохранена"

    e.conn.execute("UPDATE llm_models SET context_window = 1100, max_output_tokens = 500")
    with pytest.raises(ChatException) as ex:
        e.send("длинный вопрос " * 200, cid)
    assert ex.value.error_code == "context_overflow"


# ---------------- LlmInternalAnonymizationTest (сценарии через ChatService) ----------------

def _internal_replace(t: str) -> str:
    for a, b in (("Петров Сергей", "Сергей П."), ("Петрову Сергею", "Сергею П."), ("S3cr3t-Value-77", "[секрет удалён]")):
        t = t.replace(a, b)
    return t


class InternalEnv(Env):
    def __init__(self):
        super().__init__(answer=lambda body: {"model": "m", "choices": [{"message": {"content": "Готово, Сергей П."}, "finish_reason": "stop"}]})
        self.anonymizer = lambda body: HttpResponse(200, json.dumps({"texts": [_internal_replace(t) for t in body["texts"]], "stats": {"person": 2},
                                                                     "replaced": 2, "warnings": [], "verified": True}, ensure_ascii=False))

    def handle(self, r):
        if "anonymizer:8091" in r.url:
            return HttpResponse(200, '{"ready":true}') if r.url.endswith("/readyz") else self.anonymizer(json.loads(r.body))
        return HttpResponse(200, json.dumps(self.answer(json.loads(r.body)), ensure_ascii=False))

    def allow_unanonymized(self):
        self.conn.execute("INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at, allow_unanonymized) "
                          "VALUES (?, 'ivanov', 'corp.local', '2026-01-01', '2026-01-01', 1)", (self.user.owner_key(),))


@pytest.fixture
def ienv(monkeypatch):
    use_service(monkeypatch)
    return InternalEnv()


def test_chat_message_and_attachment_go_in_one_call_with_one_session_of_replacements(ienv, tmp_path):
    src = tmp_path / "up.txt"
    src.write_text("Отчёт подготовил Петров Сергей.")
    m = ienv.module
    chat = ChatService(m.access, m.conversations, m.anonymizer, m.gateway, m.file_processor, m.chat_settings, m.events, m.projects,
                       m.usage, m.storage_quota, m.models, None, False)
    result = chat.send(ienv.user, "10.0.0.1", None, ienv.provider_id, "Напиши Петрову Сергею",
                       [{"tmp_name": str(src), "name": "note.txt", "size": src.stat().st_size, "error": 0}])

    calls = ienv.transport.requests_to("/v1/anonymize")
    assert len(calls) == 1, "сообщение и вложение — одним вызовом"
    body = json.loads(calls[0].body)
    assert body["texts"] == ["Напиши Петрову Сергею", "Отчёт подготовил Петров Сергей."]
    assert body["profile"] == "basic"
    assert calls[0].headers["X-Internal-Token"] == "test-internal-token"
    llm = ienv.llm_bodies()[0]
    assert "Петров" not in llm and "Сергею П." in llm and "Сергей П." in llm
    assert "Петров" not in result["user_message"]["content"]
    assert result["user_message"]["attachment_type"] == "txt"
    log = json.dumps(rows(ienv.conn, "SELECT * FROM app_log") + rows(ienv.conn, "SELECT * FROM diagnostic_log"), ensure_ascii=False)
    assert "Петров" not in log
    assert "person:2" in log, "в журнале — категории и число замен"
    assert src.exists(), "файл не из HTTP-загрузки копируется, а не переносится"


@pytest.mark.parametrize("response", [
    HttpResponse(422, '{"detail":{"code":"verification_failed","message":"leak"}}'),
    HttpResponse(503, '{"detail":"nlp_unavailable"}'),
    HttpResponse(500, '{"detail":"anonymizer_failed"}'),
    HttpResponse(200, '{"texts":["x"],"verified":false}'),
    HttpResponse(200, '{"texts":[],"verified":true}'),
    HttpResponse(200, '{"texts":["   "],"verified":true}'),
    HttpResponse(200, "<html>"),
], ids=["verification failed", "models not ready", "engine error", "not verified", "wrong count", "empty result", "not json"])
def test_chat_any_anonymization_failure_blocks_the_request_to_the_llm(ienv, response):
    ienv.anonymizer = lambda body: response
    with pytest.raises(ChatException) as e:
        ienv.send("Напиши Петрову Сергею")
    assert (e.value.error_code, e.value.http_status) == ("anonymization_failed", 503)
    assert ienv.llm_bodies() == [], "при сбое обезличивания в LLM не уходит ничего"
    assert scalar(ienv.conn, "SELECT COUNT(*) FROM llm_messages") == 0
    assert scalar(ienv.conn, "SELECT COALESCE(SUM(tokens), 0) FROM llm_usage") == 0


def test_chat_unanonymized_send_still_masks_secrets(ienv):
    ienv.allow_unanonymized()
    ienv.send("Петров Сергей, пароль S3cr3t-Value-77", skip=True)
    call = json.loads(ienv.transport.requests_to("/v1/anonymize")[0].body)
    assert call["profile"] == "custom"
    assert call["settings"]["custom_actions"]["person"] == "keep", "без обезличивания — категории остаются, только секреты"
    llm = ienv.llm_bodies()[0]
    assert "S3cr3t-Value-77" not in llm


def test_project_titles_reach_the_model_only_anonymized(ienv):
    pid = ienv.module.projects.create(ienv.user.owner_key(), "Договоры")
    ienv.chat.add_project_instruction(ienv.user, "ip", pid, "Петров Сергей: тон", "Пиши кратко")
    ienv.chat.add_project_source(ienv.user, "ip", pid, "Договор Петров Сергей", "Срок оплаты 10 дней", None)
    ienv.chat.send(ienv.user, "ip", None, ienv.provider_id, "Какой срок?", [], pid)
    llm = ienv.llm_bodies()[0]
    assert "Срок оплаты" in llm, "источник передан"
    assert "Петров Сергей" not in llm, "заголовки источников и инструкций — тоже обезличены"
    assert "Петров Сергей" not in scalar(ienv.conn, "SELECT GROUP_CONCAT(title) FROM llm_project_sources")


def test_reply_to_unanonymized_message_is_never_resent(ienv):
    ienv.allow_unanonymized()
    first = ienv.send("Петров Сергей", skip=True)
    assert scalar(ienv.conn, "SELECT COUNT(*) FROM llm_messages WHERE unanonymized = 1") == 2, "вопрос и ответ помечены"
    ienv.transport.requests.clear()
    ienv.send("Дальше", first["conversation"]["id"])
    assert "Готово, Сергей П." not in ienv.llm_bodies()[0], "ответ на сообщение без обезличивания повторно не уходит"


# ---------------- LlmFailoverTest: проверки на уровне чата ----------------

@pytest.fixture
def bench(monkeypatch):
    use_service(monkeypatch)
    b = FailoverBench()
    route = b.transport.handler
    b.transport.handler = lambda r: service_reply(r) if ANON_HOST in r.url else route(r)
    b.module = LlmModule(b.conn, transport=b.transport, sleeper=b.sleeps.append)
    b.module.chat_settings.save({"fallback_auto": "0"})
    b.chat_send = lambda **kw: b.module.chat_service.send(b.user, "10.0.0.1", None, b.primary, "Привет", [], **kw)
    return b


def test_chat_empty_answer_goes_to_backup_and_nothing_empty_is_saved(bench):
    bench.script["primary.example.com"] = [ok(""), ok("   \n")]
    result = bench.chat_send()
    msg = result["assistant_message"]
    assert (msg["content"], msg["provider"], msg["fallback_from"]) == ("ответ backup.example.com", "Backup", "openai/gpt-4o")
    assert scalar(bench.conn, "SELECT COUNT(*) FROM llm_messages WHERE role = 'assistant' AND content = ''") == 0
    assert "LLM_FALLBACK_USED" in results(bench.conn)


def test_chat_content_refusal_code(bench):
    bench.script["primary.example.com"] = [ok("", "content_filter")]
    with pytest.raises(ChatException) as e:
        bench.chat_send()
    assert (e.value.error_code, e.value.http_status) == ("llm_content_refused", 502)


def test_chat_all_models_down_saves_and_bills_nothing(bench):
    bench.script["primary.example.com"] = [HttpResponse(503, "{}")] * 10
    bench.script["backup.example.com"] = [ok("")] * 10
    with pytest.raises(ChatException) as e:
        bench.chat_send()
    assert e.value.error_code == "llm_all_failed"
    assert "не отвечают" in str(e.value)
    assert scalar(bench.conn, "SELECT COUNT(*) FROM llm_messages") == 0
    assert len(bench.transport.requests_to("example.com")) <= failover.MAX_ATTEMPTS_TOTAL
    assert scalar(bench.conn, "SELECT tokens FROM llm_usage") == 0, "отказ не тарифицируется: бронь возвращена"
    assert scalar(bench.conn, "SELECT requests FROM llm_usage") == 0
    assert "LLM_REQUEST_FAILED" in results(bench.conn)


def test_chat_no_backup_configured_returns_the_real_reason(bench):
    bench.set_chain(bench.primary, [])
    bench.script["primary.example.com"] = [ok(""), ok("")]
    with pytest.raises(ChatException) as e:
        bench.chat_send()
    assert e.value.error_code == "llm_empty_response"


def test_chat_truncated_answer_is_flagged(bench):
    bench.script["primary.example.com"] = [ok("Начало ответа…", "length")]
    msg = bench.chat_send()["assistant_message"]
    assert msg["provider"] == "Primary" and msg["truncated"] is True and msg["fallback_from"] is None


def test_chat_explicit_model_must_belong_to_selected_provider(bench):
    foreign = bench.models.default_for(bench.forbidden)
    with pytest.raises(ChatException) as e:
        bench.chat_send(model_id=foreign)
    assert e.value.error_code == "access_denied"
    assert bench.transport.requests == [], "отказ до обращения к шлюзу"


def test_chat_unanonymized_send_stays_on_the_chosen_model(bench):
    """Без обезличивания резервные модели не применяются: сбой выбранной — отказ, в резерв ничего не уходит."""
    bench.module.usage.set_allow_unanonymized(bench.user.owner_key(), True)
    bench.script["primary.example.com"] = [HttpResponse(503, "{}")] * 10
    with pytest.raises(ChatException):
        bench.chat_send(skip_anonymization=True)
    assert bench.calls_to("backup") == 0
    assert scalar(bench.conn, "SELECT tokens FROM llm_usage") == 0


def test_chat_token_limit_closes_a_long_chat_before_any_request(env):
    """Каждое сообщение заново отправляет историю: чат, расходы которого с следующим запросом превысят лимит, не пишет в модель."""
    env.answer = lambda body: {"choices": [{"message": {"content": "Ответ"}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 700, "completion_tokens": 300}}
    env.module.chat_settings.save({"chat_token_limit": "2500"})
    first = env.send()
    cid = first["conversation"]["id"]
    assert (first["conversation"]["tokens_spent"], first["conversation"]["token_limit"]) == (1000, 2500)
    assert env.send("Дальше", cid)["conversation"]["tokens_spent"] == 2000
    sent = len(env.llm_bodies())
    with pytest.raises(ChatException) as e:       # 2000 + следующий запрос (>500) уже выше 2500
        env.send("Ещё", cid)
    assert (e.value.error_code, e.value.http_status) == ("chat_limit", 429) and "Начните новый чат" in e.value.user_message
    assert len(env.llm_bodies()) == sent, "в нейросеть ничего не уходит"
    assert scalar(env.conn, f"SELECT tokens_spent FROM llm_conversations WHERE id = {cid}") == 2000
    assert env.send("Новая тема")["conversation"]["tokens_spent"] == 1000, "новый чат начинает с нуля"
    env.module.chat_settings.save({"chat_token_limit": "0"})
    assert env.send("Снова", cid)["conversation"]["tokens_spent"] == 3000, "0 — без ограничения"
