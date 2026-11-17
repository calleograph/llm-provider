"""Хранение диалогов и проектов, лимиты токенов и квота хранения, автоочистка (аналог репозиторной части
tests/LlmProjectsAndLimitsTest.php, tests/InactivityCleanupTest.php, tests/LlmChatServiceTest.php)."""

from __future__ import annotations

import datetime as dt
import time

import pytest

import kit
from llmp import compact
from llmp.anon.text import AnonymizedText
from llmp.chat import (
    ChatException, ChatSettings, ChatUser, ConversationRepository, InactivityCleanupService, LimitCategoryRepository,
    ProjectRepository, StorageQuotaService, UsageLimiter, session_current, session_login, session_logout,
)
from llmp.chat.projects import DEFAULT_SETTINGS
from llmp.security import Session
from llmp.settings import AppSettings

OWNER = "ivanov@corp.local"
STRANGER = "petrov@corp.local"


@pytest.fixture
def env(conn):
    domain_id = kit.create_domain(conn, "Corp", "corp.local")
    provider_id = kit.create_provider(conn, "GPT")
    group_id = kit.create_group(conn, domain_id, "Users", "CN=U,DC=corp,DC=local")
    user = ChatUser("ivanov", domain_id, "corp.local", [group_id], ["Users"])
    categories = LimitCategoryRepository(conn)
    usage = UsageLimiter(conn, ChatSettings(AppSettings(conn)), categories)
    usage.touch_profile(user)

    class E:
        pass
    e = E()
    e.conn, e.domain_id, e.provider_id, e.group_id, e.user = conn, domain_id, provider_id, group_id, user
    e.conversations, e.projects, e.categories, e.usage = ConversationRepository(conn), ProjectRepository(conn), categories, usage
    e.storage = StorageQuotaService(conn, categories)
    return e


def anon(text: str) -> AnonymizedText:
    return AnonymizedText.from_storage(text)


# ---- LlmProjectsAndLimitsTest ----

def test_compact_text_round_trip_and_saving():
    """Длинный текст сжимается в несколько раз и читается обратно; короткий хранится как есть."""
    long = "Длинный обезличенный ответ модели с повторяющимся текстом. " * 200
    text, blob = compact.encode(long)
    assert text == "" and len(blob) < len(long.encode()) / 5
    assert compact.decode(text, blob) == long
    assert compact.encode("коротко") == ("коротко", None)
    assert compact.unpack(compact.pack(long)) == long


def test_long_messages_are_stored_compressed_and_read_back(env):
    """Длинное сообщение хранится сжатым и читается обратно."""
    cid = env.conversations.create(OWNER, "Т", env.provider_id)
    long = "[PERSON] обсуждает договор поставки. " * 300
    env.conversations.add_message(cid, "user", long, env.provider_id, None, None, None)
    row = env.conn.execute("SELECT content, length(content_z) AS z FROM llm_messages").fetchone()
    assert row["content"] == "" and row["z"] > 0
    assert env.conversations.messages(cid, OWNER)[0]["content"] == long


def test_pin_rename_move_and_search(env):
    """Закрепление, переименование, перенос в проект, поиск по заголовку и по сжатому тексту; чужое не меняется."""
    repo, owner = env.conversations, OWNER
    a = repo.create(owner, "Отчёт за квартал", env.provider_id)
    b = repo.create(owner, "Письмо", env.provider_id)
    repo.add_message(b, "user", "текст " * 300 + "уникальнаяфраза договора", env.provider_id, None, None, None)
    project_id = env.projects.create(owner, "Проект")

    assert repo.set_pinned(a, owner, True)
    assert repo.rename(b, owner, "Письмо поставщику")
    assert repo.move_to_project(b, owner, project_id)
    assert not repo.set_pinned(a, STRANGER, False), "чужой диалог не меняется"

    listing = {c["id"]: c for c in repo.list_for_owner(owner)}
    assert listing[a]["pinned"] is True
    assert listing[b]["project_id"] == project_id

    assert [c["id"] for c in repo.search(owner, "квартал")] == [a]
    found = repo.search(owner, "уникальнаяфраза")
    assert [c["id"] for c in found] == [b], "поиск находит текст в сжатом сообщении"
    assert "уникальнаяфраза" in found[0]["snippet"]
    assert repo.search(STRANGER, "квартал") == []


def test_project_context_is_anonymized_and_owner_only(env):
    """Репозиторная часть testProjectContextIsSentAsAnonymizedSystemPrompt: контекст проекта и его изоляция."""
    pid = env.projects.create(OWNER, "Договоры")
    env.projects.add_source(pid, "Регламент", "text", None, anon("Ответственный — [PERSON]"))
    parts = env.projects.context_parts(pid, OWNER)
    assert parts == {"instructions": "", "sources": [{"title": "Регламент", "text": "Ответственный — [PERSON]"}], "state": "", "state_updated_at": None,
                     "description": "", "settings": DEFAULT_SETTINGS}
    assert env.projects.find_owned(pid, STRANGER) is None
    assert env.projects.context_parts(pid, STRANGER) is None


def test_source_text_returns_anonymized_content_only_to_owner(env):
    """Текст источника — только владельцу; несуществующий — None."""
    pid = env.projects.create(OWNER, "Договоры")
    sid = env.projects.add_source(pid, "Регламент", "text", None, anon("Ответственный — [PERSON]"))
    assert env.projects.source_text(sid, OWNER) == "Ответственный — [PERSON]"
    assert env.projects.source_text(sid, STRANGER) is None
    assert env.projects.source_text(999999, OWNER) is None


def test_project_instruction_blocks_are_separate_from_sources(env):
    """Репозиторная часть testProjectInstructionBlocksAreAnonymizedAndSeparateFromSources."""
    pid = env.projects.create(OWNER, "Договоры")
    iid = env.projects.add_source(pid, "Стиль", ProjectRepository.TYPE_INSTRUCTION, None, anon('Обращайся к [PERSON] на "вы"'))
    env.projects.add_source(pid, "Регламент", "text", None, anon("Ответственный — [PERSON]"))
    assert env.projects.instruction_count(pid) == 1
    assert env.projects.source_count(pid) == 1
    assert len(env.projects.instructions(pid, OWNER)) == 1
    assert len(env.projects.sources(pid, OWNER)) == 1
    assert env.projects.total_source_chars(pid) == len("Ответственный — [PERSON]")
    parts = env.projects.context_parts(pid, OWNER)
    assert parts["instructions"] == '[Инструкция: Стиль]\nОбращайся к [PERSON] на "вы"'
    assert parts["sources"] == [{"title": "Регламент", "text": "Ответственный — [PERSON]"}]
    assert env.projects.source_text(iid, OWNER) == 'Обращайся к [PERSON] на "вы"'
    assert env.projects.list_for_owner(OWNER)[0]["sources"] == 2


def test_deleting_project_keeps_chats(env):
    """Удаление проекта оставляет его чаты вне проекта."""
    pid = env.projects.create(OWNER, "P")
    cid = env.conversations.create(OWNER, "Чат", env.provider_id, pid)
    assert env.projects.delete(pid, OWNER)
    conv = env.conversations.find_owned(cid, OWNER)
    assert conv is not None and conv["project_id"] is None


def test_usage_is_recorded_and_daily_limit_blocks(env):
    """Лимитерная часть testUsageIsRecordedAndDailyLimitBlocksBeforeAnyRequest: одна строка в день, отказ 429."""
    d = env.categories.default_category()
    env.categories.update(d["id"], d["name"], "day", 1500, d["storage_mb"], True)
    env.usage.assert_can_send(OWNER, env.user.group_ids)
    env.usage.record(OWNER, 1000)
    s = env.usage.status(OWNER, env.user.group_ids)
    assert (s["used"], s["limit"], s["remaining"]) == (1000, 1500, 500)
    env.usage.assert_can_send(OWNER, env.user.group_ids)   # 1000 < 1500 — ещё разрешено
    env.usage.record(OWNER, 1000)
    with pytest.raises(ChatException) as e:
        env.usage.assert_can_send(OWNER, env.user.group_ids)
    assert (e.value.error_code, e.value.http_status) == ("limit_exceeded", 429)
    assert str(e.value).startswith("Исчерпан лимит на день: 2 000 из 1 500 токенов. Лимит обновится ")
    assert env.conn.execute("SELECT COUNT(*), SUM(requests) FROM llm_usage").fetchone()[:] == (1, 2)


def test_individual_limit_overrides_default_and_block_works(env):
    """Индивидуальный лимит важнее категории по умолчанию; блокировка — отказ 403."""
    d = env.categories.default_category()
    env.categories.update(d["id"], d["name"], "day", 10, d["storage_mb"], True)
    env.usage.set_individual_limit(OWNER, UsageLimiter.PERIOD_NONE, None)
    assert env.usage.status(OWNER)["limit"] is None
    env.usage.record(OWNER, 1000)
    env.usage.assert_can_send(OWNER)
    env.usage.set_individual_limit(OWNER, "month", 5000)
    s = env.usage.status(OWNER)
    assert [s["period"], s["limit"], s["individual"]] == ["month", 5000, True]
    env.usage.set_blocked(OWNER, True)
    with pytest.raises(ChatException) as e:
        env.usage.assert_can_send(OWNER)
    assert (e.value.error_code, e.value.http_status) == ("user_blocked", 403)


def test_period_bounds(env):
    """Неделя с понедельника и длится 7 дней; месяц с 1-го числа."""
    week_start, week_reset = env.usage.period_bounds("week")
    assert dt.date.fromisoformat(week_start).isoweekday() == 1
    assert (dt.date.fromisoformat(week_reset) - dt.date.fromisoformat(week_start)).days == 7
    assert env.usage.period_bounds("month")[0].endswith("-01")


def test_retention_deletes_only_old_unpinned(env):
    """Срок хранения удаляет только старые незакреплённые диалоги."""
    repo = env.conversations
    old = repo.create(OWNER, "Старый", env.provider_id)
    pinned = repo.create(OWNER, "Закреплённый", env.provider_id)
    repo.set_pinned(pinned, OWNER, True)
    env.conn.execute("UPDATE llm_conversations SET updated_at = '2020-01-01T00:00:00+00:00'")
    assert repo.delete_older_than(OWNER, 30) == 1
    assert repo.find_owned(old, OWNER) is None
    assert repo.find_owned(pinned, OWNER) is not None


# ---- InactivityCleanupTest ----

def test_content_and_its_summaries_are_erased(conn):
    """Автоочистка стирает сообщения, сводки и состояние проекта; структура истории остаётся."""
    provider_id = kit.create_provider(conn, "Polza")
    conn.execute("INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at) VALUES ('old', 'old', 'corp.local', '2020-01-01', '2020-01-01')")
    projects, conversations = ProjectRepository(conn), ConversationRepository(conn)
    pid = projects.create("old", "Договоры")
    projects.add_source(pid, "Р", "text", None, anon("Сергей П."))
    cid = conversations.create("old", "Чат", provider_id, pid)
    conversations.add_message(cid, "user", "Сергей П., срок пятница", provider_id, None, None, None)
    conn.execute("INSERT INTO llm_conversation_summaries (conversation_id, covered_until_id, summary_z, chars, model_id, updated_at) VALUES (?, 1, ?, 10, 1, 'x')",
                 (cid, compact.pack("Сводка: срок пятница")))
    conn.execute("UPDATE llm_projects SET state_z = ? WHERE id = ?", (compact.pack("Состояние: срок пятница"), pid))

    result = InactivityCleanupService(conn).cleanup_inactive(90)

    assert result == {"owners": 1, "messages": 1, "sources": 1}
    assert conn.execute("SELECT COUNT(*) FROM llm_conversation_summaries").fetchone()[0] == 0
    assert conn.execute("SELECT state_z FROM llm_projects").fetchone()[0] is None
    assert conn.execute("SELECT COUNT(*) FROM llm_conversations").fetchone()[0] == 1
    assert InactivityCleanupService(conn).cleanup_inactive(90) == {"owners": 1, "messages": 0, "sources": 0}, "очищенное не трогается повторно"


# ---- LlmChatServiceTest (репозиторная часть) ----

def test_unanonymized_flag_is_stored_and_read_back(env):
    """Сообщение без обезличивания хранится открытым с флагом unanonymized (часть testSkipAnonymization*)."""
    cid = env.conversations.create(OWNER, "Т", env.provider_id)
    env.conversations.add_message(cid, "user", "Иванов Иван Иванович", env.provider_id, None, None, None, unanonymized=True)
    env.conversations.add_message(cid, "assistant", "Ответ", env.provider_id, "gpt", None, None, tokens=5)
    msgs = env.conversations.messages(cid, OWNER)
    assert [m["unanonymized"] for m in msgs] == [True, False]
    assert set(msgs[0]) == {"id", "role", "content", "provider_id", "model", "attachment_type", "attachment_size", "unanonymized", "context_report", "created_at", "can_flag"}
    assert env.conversations.messages(cid, STRANGER) == []


def test_personal_unanonymized_grant_upserts_profile(env):
    """Персональное право «без обезличивания» создаёт профиль, если его ещё нет."""
    env.usage.set_allow_unanonymized("new@corp.local", True)
    assert env.conn.execute("SELECT username, domain_fqdn, allow_unanonymized FROM llm_users WHERE owner_key = 'new@corp.local'").fetchone()[:] == ("new", "corp.local", 1)


# ---- новые сценарии порта ----

def test_group_categories_compare_normalised_per_month(env):
    """Категории групп сравниваются по токенам в месяц: день 10000 (≈300000/мес) щедрее месяца 200000."""
    day = env.categories.create("Дневной", "day", 10000, None, False)
    month = env.categories.create("Месячный", "month", 200000, None, False)
    g2 = kit.create_group(env.conn, env.domain_id, "Others", "CN=O,DC=corp,DC=local")
    env.conn.execute("UPDATE llm_domain_groups SET limit_category_id = ? WHERE id = ?", (month, env.group_id))
    env.conn.execute("UPDATE llm_domain_groups SET limit_category_id = ? WHERE id = ?", (day, g2))
    for groups in ([env.group_id, g2], [g2, env.group_id]):
        lim = env.usage.effective_limit(OWNER, groups)
        assert (lim["period"], lim["tokens"], lim["source"], lim["individual"]) == ("day", 10000, "group:Дневной", False)
    none = env.categories.create("Без лимита", "none", None, None, False)
    env.conn.execute("UPDATE llm_domain_groups SET limit_category_id = ? WHERE id = ?", (none, env.group_id))
    assert env.usage.effective_limit(OWNER, [g2, env.group_id])["tokens"] is None


def test_main_instruction_goes_first_and_settings_and_memory_round_trip(env):
    """Основная инструкция (бывшее единое поле, без переноса в блоки) идёт перед блоками; настройки и память — только владельцу."""
    pid = env.projects.create(OWNER, "Договоры")
    assert env.projects.set_instructions(pid, OWNER, anon("Отвечай кратко"))
    env.projects.add_source(pid, "Тон", ProjectRepository.TYPE_INSTRUCTION, None, anon("На «вы»"))
    assert env.projects.context_parts(pid, OWNER)["instructions"] == "Отвечай кратко\n\n[Инструкция: Тон]\nНа «вы»"
    assert env.projects.instruction_count(pid) == 1

    assert env.projects.set_settings(pid, STRANGER, {"use_memory": False}) is False
    assert env.projects.set_settings(pid, OWNER, {**DEFAULT_SETTINGS, "use_memory": False, "evil": 1})
    assert env.projects.find_owned(pid, OWNER)["settings"] == {**DEFAULT_SETTINGS, "use_memory": False}
    env.conn.execute("UPDATE llm_projects SET settings = ? WHERE id = ?", ('{"sources_share": "много", "style": 5}', pid))
    assert env.projects.find_owned(pid, OWNER)["settings"] == DEFAULT_SETTINGS, "испорченные значения — по умолчанию"

    assert env.projects.set_memory(pid, STRANGER, anon("Чужое")) is False
    assert env.projects.set_memory(pid, OWNER, anon("Срок — пятница"))
    parts = env.projects.context_parts(pid, OWNER)
    assert parts["state"] == "Срок — пятница" and parts["state_updated_at"]
    assert env.projects.set_memory(pid, OWNER, anon(""))
    assert tuple(env.conn.execute("SELECT state_z, state_updated_at FROM llm_projects WHERE id = ?", (pid,)).fetchone()) == (None, None)


def test_usage_refund_cannot_go_below_zero(env):
    """Возврат брони больше расхода не опускает итог дня ниже нуля, и первая запись тоже не отрицательна."""
    env.usage.record(OWNER, 300)
    env.usage.record(OWNER, -1000, 0)
    assert env.usage.used_since(OWNER, "2000-01-01") == 0
    env.usage.record(STRANGER, -50)
    assert env.usage.used_since(STRANGER, "2000-01-01") == 0
    env.usage.record(OWNER, 200)
    assert env.usage.used_since(OWNER, "2000-01-01") == 200


def test_storage_quota_status_and_refusal(env):
    """Квота хранения: категория по умолчанию 50 МБ, щедрейшая групповая, отказ 507 при превышении."""
    s = env.storage.status(OWNER, env.user.group_ids)
    assert s["limit_bytes"] == 50 * 1024 * 1024 and s["source"] == "default:Базовый" and s["percent"] == 0.0
    small = env.categories.create("Малый", "day", 100, 1, False)
    env.usage.set_limit_category(OWNER, small)
    cid = env.conversations.create(OWNER, "Т", env.provider_id)
    env.conversations.add_message(cid, "user", "абв " * 2000, env.provider_id, None, None, None)
    used = env.storage.used_bytes(OWNER)
    assert used > 0
    env.storage.assert_can_store(OWNER, 1024 * 1024 - used)
    with pytest.raises(ChatException) as e:
        env.storage.assert_can_store(OWNER, 1024 * 1024)
    assert (e.value.error_code, e.value.http_status) == ("storage_quota_exceeded", 507)
    assert "из 1,0 МБ" in str(e.value)
    env.usage.set_limit_category(OWNER, None)
    unlimited = env.categories.create("Безразмерный", "day", 100, None, False)
    env.conn.execute("UPDATE llm_domain_groups SET limit_category_id = ? WHERE id = ?", (unlimited, env.group_id))
    assert env.storage.effective_limit_mb(OWNER, env.user.group_ids) == {"limit_mb": None, "source": "group:Безразмерный"}


def test_limit_categories_default_cannot_be_deleted(env):
    """Категорию по умолчанию удалить нельзя; новая категория по умолчанию снимает флаг со старой."""
    d = env.categories.default_category()
    assert env.categories.delete(d["id"]) is False
    new = env.categories.create("Расширенный", "week", 5000, 0, True)
    assert env.categories.default_category()["id"] == new
    assert env.categories.find(new)["storage_mb"] is None
    assert env.categories.name_exists("Расширенный") and not env.categories.name_exists("Расширенный", new)
    assert env.categories.delete(d["id"]) is True
    assert [c["name"] for c in env.categories.all()] == ["Расширенный"]


def test_preferences_dialog_overrides_personal_style(env):
    """Стиль диалога важнее личного; тексты инструкций хранятся сжатыми."""
    cid = env.conversations.create(OWNER, "Т", env.provider_id)
    env.conversations.set_user_preferences(OWNER, "concise", "Личное")
    assert env.conversations.preferences(cid, OWNER) == {"style": "concise", "dialog": "", "personal": "Личное"}
    assert env.conversations.set_conversation_preferences(cid, OWNER, "technical", "Для диалога")
    assert env.conversations.preferences(cid, OWNER) == {"style": "technical", "dialog": "Для диалога", "personal": "Личное"}
    assert not env.conversations.set_conversation_preferences(cid, STRANGER, None, None)
    env.conversations.set_model(cid, 3)
    mid = env.conversations.add_message(cid, "assistant", "x", None, None, None, None)
    env.conversations.set_context_report(mid, {"window": 8000, "total": 10})
    assert env.conversations.messages(cid, OWNER)[0]["context_report"] == '{"window":8000,"total":10}'
    assert env.conversations.find_owned(cid, OWNER)["model_id"] == 3


def test_bulk_delete_only_own_conversations(env):
    """Массовое удаление затрагивает только свои диалоги."""
    a = env.conversations.create(OWNER, "A", env.provider_id)
    b = env.conversations.create(OWNER, "B", env.provider_id)
    foreign = env.conversations.create(STRANGER, "C", env.provider_id)
    assert env.conversations.delete_many([a, foreign, a], OWNER) == 1
    assert env.conversations.delete_all_for_owner(OWNER) == 1
    assert env.conversations.find_owned(b, OWNER) is None and env.conversations.find_owned(foreign, STRANGER) is not None


def test_chat_session_login_timeouts_and_logout(env, monkeypatch):
    """Вход в ключ llm_chat, продление по активности, выход по простою 4 ч и абсолютному сроку 12 ч."""
    s = Session("x" * 64)
    session_login(s, env.user)
    assert s.regenerated and session_current(s) == env.user
    now = time.time()
    monkeypatch.setattr(time, "time", lambda: now + 14000)
    assert session_current(s) is not None, "активность продлевает простой"
    monkeypatch.setattr(time, "time", lambda: now + 14000 + 14401)
    assert session_current(s) is None and "llm_chat" not in s
    session_login(s, env.user)
    s["llm_chat"]["login_at"] -= 43201
    assert session_current(s) is None
    session_login(s, env.user)
    s["csrf_token"] = "t"
    session_logout(s)
    assert "llm_chat" not in s and s["csrf_token"] == "t"


def test_chat_settings_defaults_and_validation(conn):
    """Значения по умолчанию, сохранение только известных ключей, проверка диапазонов."""
    cs = ChatSettings(AppSettings(conn))
    assert cs.get("project_sources_max_files") == "3" and cs.get("styles_allowed").startswith("balanced,")
    cs.save({"project_sources_max_files": 2, "unknown": "x"})
    assert cs.all()["project_sources_max_files"] == "2" and "unknown" not in cs.all()
    errors = ChatSettings.validate({"system_prompt": "a" * 8001, "limit_period": "year", "retention_days": "5000", "history_max_chars": "abc"})
    assert errors == {"system_prompt": "Не длиннее 8000 символов.", "limit_period": "Недопустимый период.",
                      "history_max_chars": "Допустимый диапазон: 1000–2000000.", "retention_days": "Допустимый диапазон: 0–3650."}
    assert ChatSettings.validate({"limit_period": "week", "limit_tokens": "0"}) == {}


def test_chat_user_owner_key():
    """Ключ владельца — «user@fqdn» в нижнем регистре; имя для журнала — как есть."""
    u = ChatUser("Ivanov", 1, "Corp.Local")
    assert (u.owner_key(), u.log_name()) == ("ivanov@corp.local", "Ivanov@Corp.Local")
