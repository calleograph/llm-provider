"""Разделы админки «Подключения LLM API», «Модели и резервирование», «Обезличивание», «Почта»: вызов handle()
напрямую с собранной AdminPage. Для каждого POST-действия — отказ без CSRF, эффект в БД, flash и запись аудита."""

from __future__ import annotations

import io
import json
import time

import pytest
from starlette.datastructures import Headers, UploadFile

from kit import create_provider
from kit_admin_llm import AdminBench
from llmp import secretbox
from llmp.http import FakeHttpTransport, HttpResponse, HttpTransportError
from llmp.mail import MailQueue, MailSettings
from llmp.mail.queue import TEMPLATES

TEMPLATE = next(iter(TEMPLATES))
from llmp.settings import AppSettings
from llmp.web.admin.base import FORM_STALE
from llmp.web.admin.sections import anonymization, llm_models, llm_providers, mail


def chat_ok(text: str = "OK") -> HttpResponse:
    return HttpResponse(200, json.dumps({"model": "m-1", "choices": [{"message": {"content": text}, "finish_reason": "stop"}],
                                         "usage": {"prompt_tokens": 3, "completion_tokens": 1}}))


def anonymizer_ok(body: dict) -> HttpResponse:
    return HttpResponse(200, json.dumps({
        "texts": [t.replace("Петров Сергей", "Сергей П.") for t in body["texts"]], "stats": {"person": 1}, "replaced": 1,
        "warnings": ["Проверьте таблицы"], "verified": True,
        "replacements": [{"original": "Петров Сергей", "replacement": "Сергей П.", "category": "person", "rule": "ner", "evidence": "имя"}],
        "candidates": [{"original": "Роза", "reason": "обычное слово"}],
    }, ensure_ascii=False))


class Handler:
    """Заглушка сети: LLM-провайдеры и встроенный обезличиватель."""

    def __init__(self):
        self.llm = chat_ok
        self.anon = anonymizer_ok

    def __call__(self, r):
        if r.url.endswith("/readyz"):
            return HttpResponse(200, '{"ready":true}')
        if r.url.endswith("/v1/anonymize"):
            return self.anon(json.loads(r.body))
        return self.llm()


@pytest.fixture
def env(conn, monkeypatch):
    monkeypatch.setenv("ANONYMIZER_URL", "http://anonymizer:8091")
    monkeypatch.setenv("INTERNAL_API_TOKEN", "test-internal-token")
    h = Handler()
    b = AdminBench(conn, FakeHttpTransport(h))
    b.handler = h
    return b


def snapshot(conn) -> list:
    return [[tuple(r) for r in conn.execute(f"SELECT * FROM {t} ORDER BY 1")] for t in ("llm_providers", "llm_models", "llm_model_health", "app_settings", "audit_log", "mail_queue")]


def provider_form(**over) -> dict:
    form = {"name": "Polza", "provider_type": "openai_compatible", "base_url": "https://polza.ai/api/v1", "endpoint": "/chat/completions",
            "model": "openai/gpt-4o", "api_key": "sk-new-key-0000", "connect_timeout": "10", "response_timeout": "120", "max_tokens": "2048",
            "is_enabled": "1", "allow_fallbacks": "1"}
    form.update(over)
    return {k: v for k, v in form.items() if v is not None}


def last_audit(b) -> dict:
    return b.audit()[-1]


# ---------------- CSRF: каждое POST-действие без токена ничего не меняет ----------------
CSRF_CASES = [
    (llm_providers, "create_provider", provider_form()),
    (llm_providers, "update_provider", provider_form(id="1", name="X")),
    (llm_providers, "toggle_provider", {"id": "1"}),
    (llm_providers, "set_default_provider", {"id": "1"}),
    (llm_providers, "delete_provider", {"id": "1"}),
    (llm_providers, "test_provider", {"id": "1"}),
    (llm_providers, "save_chat_settings", {"history_max_chars": "5000", "max_message_chars": "1000"}),
    (llm_models, "save_model", {"provider_id": "1", "model": "x/y", "display_name": "Y", "context_window": "32000", "max_output_tokens": "100"}),
    (llm_models, "delete_model", {"id": "1"}),
    (llm_models, "check_model", {"id": "1"}),
    (llm_models, "generate_model", {"id": "1"}),
    (llm_models, "reset_cooldown", {"id": "1"}),
    (llm_models, "save_fallback_settings", {}),
    (anonymization, "save_anonymization", {"internal_profile": "strict", "internal_timeout": "60"}),
    (anonymization, "check_anonymization", {}),
    (anonymization, "save_file_processing", {"max_upload_mb": "5"}),
    (anonymization, "lab_anonymization", {"lab_text": "Петров Сергей"}),
    (mail, "save_mail", {"host": "smtp.example.com", "port": "587", "security": "starttls", "auth": "auto", "timeout": "10"}),
    (mail, "check_mail", {}),
    (mail, "test_mail", {"to": "a@example.com"}),
    (mail, "process_queue", {}),
]


@pytest.mark.parametrize("module,action,form", CSRF_CASES, ids=[f"{m.__name__.rsplit('.', 1)[-1]}:{a}" for m, a, _ in CSRF_CASES])
def test_post_without_csrf_is_rejected_and_changes_nothing(env, conn, module, action, form):
    create_provider(conn, "Polza")
    conn.execute("INSERT INTO llm_model_health (model_id, cooldown_until) VALUES (1, ?)", (int(time.time()) + 600,))
    before = snapshot(conn)
    page, html = env.run(module, action, form, csrf=False)
    assert page.flash_error == FORM_STALE and page.flash_success is None
    assert snapshot(conn) == before
    # живая проверка готовности сервиса (GET /readyz, без данных) — не действие формы
    assert [r for r in env.transport.requests if not r.url.endswith("/readyz")] == []
    assert 'name="csrf"' in html


def test_wrong_csrf_token_is_rejected(env, conn):
    page, _ = env.run(llm_models, "save_fallback_settings", [("csrf", "bad")], csrf=False)
    assert page.flash_error == FORM_STALE


# ---------------- Подключения LLM API ----------------
def test_providers_get_renders_cards_and_data_attributes(env, conn):
    pid = create_provider(conn, "Polza")
    page, html = env.run(llm_providers)
    assert page.page_title == "Подключения LLM API"
    for part in (f'id="provider-{pid}"', "data-provider-type", "data-defaults=", "data-provider-form", 'data-reveal="newProviderCard"',
                 f'id="api_key-{pid}"', 'name="styles_allowed[]"', f'form="deleteProvider{pid}"', "data-confirm-title=\"Удалить нейросеть?\"",
                 'action="index.php?section=llm_providers&action=update_provider#provider-%d"' % pid, "сохранён (…3456)",
                 '<span class="field-code">CHAT_SYSTEM_PROMPT</span>', 'id="newProviderCard" hidden'):
        assert part in html, part
    defaults = json.loads(html.split('data-defaults="', 1)[1].split('"', 1)[0].replace("&#34;", '"'))
    assert list(defaults) == ["openai", "anthropic", "openai_compatible"]
    assert defaults["anthropic"] == {"base_url": "https://api.anthropic.com", "endpoint": "/v1/messages", "api_version": "2023-06-01",
                                     "model_placeholder": "claude-sonnet-5", "label": "Anthropic API"}


def test_create_provider(env, conn):
    page, html = env.run(llm_providers, "create_provider", provider_form(extra_headers="X-Org: corp"))
    assert page.flash_success.startswith("Нейросеть «Polza» добавлена.")
    row = conn.execute("SELECT * FROM llm_providers WHERE name = 'Polza'").fetchone()
    assert secretbox.decrypt(row["api_key_encrypted"]) == "sk-new-key-0000" and row["extra_header_names"] == "X-Org"
    a = last_audit(env)
    assert a["action"] == "llm_provider_create" and a["success"] == 1
    assert "key_changed=1" in a["details"] and "extra_header_count=1" in a["details"] and "sk-new" not in a["details"]
    assert f'id="provider-{row["id"]}"' in html


def test_create_provider_with_errors_keeps_form_open(env, conn):
    create_provider(conn, "Polza")
    page, html = env.run(llm_providers, "create_provider", provider_form(base_url="http://x", api_key="bad key"))
    assert page.flash_error == "Проверьте выделенные поля формы."
    assert conn.execute("SELECT COUNT(*) FROM llm_providers").fetchone()[0] == 1
    assert 'id="newProviderCard">' in html and "Нейросеть с таким названием уже есть." in html
    assert "Base URL должен быть корректным https:// адресом." in html and "Ключ содержит недопустимые символы." in html
    assert 'value="http://x"' in html and env.audit() == []


def test_update_provider_base_url_change_without_key_clears_key(env, conn):
    pid = create_provider(conn, "Polza")
    page, _ = env.run(llm_providers, "update_provider", provider_form(id=str(pid), base_url="https://other.example.com/v1", api_key=""))
    assert page.flash_success == "Нейросеть «Polza» сохранена. Адрес изменился — сохранённый ключ API сброшен, введите его заново."
    row = conn.execute("SELECT * FROM llm_providers WHERE id = ?", (pid,)).fetchone()
    assert row["api_key_encrypted"] is None and row["base_url"] == "https://other.example.com/v1"
    a = last_audit(env)
    assert a["action"] == "llm_provider_update" and "changed=base_url,connect_timeout,response_timeout,max_tokens" in a["details"]
    assert "key_changed=0" in a["details"] and "headers_changed=0" in a["details"]


def test_update_provider_with_new_key_keeps_address_notice_off(env, conn):
    pid = create_provider(conn, "Polza")
    page, _ = env.run(llm_providers, "update_provider", provider_form(id=str(pid), base_url="https://other.example.com/v1"))
    assert page.flash_success == "Нейросеть «Polza» сохранена."
    row = conn.execute("SELECT * FROM llm_providers WHERE id = ?", (pid,)).fetchone()
    assert secretbox.decrypt(row["api_key_encrypted"]) == "sk-new-key-0000"


def test_update_unknown_provider(env, conn):
    page, _ = env.run(llm_providers, "update_provider", provider_form(id="99"))
    assert page.flash_error == "Подключение не найдено." and env.audit() == []


def test_toggle_default_delete_provider(env, conn):
    pid = create_provider(conn, "Polza")
    page, _ = env.run(llm_providers, "toggle_provider", {"id": str(pid)})
    assert page.flash_success == "Нейросеть «Polza» отключена."
    assert conn.execute("SELECT is_enabled FROM llm_providers").fetchone()[0] == 0
    assert last_audit(env)["action"] == "llm_provider_toggle" and "enabled=0" in last_audit(env)["details"]

    page, html = env.run(llm_providers, "set_default_provider", {"id": str(pid)})
    assert page.flash_success.startswith("Нейросеть «Polza» теперь открывается по умолчанию")
    assert conn.execute("SELECT is_default FROM llm_providers").fetchone()[0] == 1 and "Снять «по умолчанию»" in html
    assert last_audit(env)["action"] == "llm_provider_default_set"
    page, _ = env.run(llm_providers, "set_default_provider", {"id": str(pid)})
    assert "больше не открывается по умолчанию" in page.flash_success
    assert conn.execute("SELECT is_default FROM llm_providers").fetchone()[0] == 0
    assert last_audit(env)["action"] == "llm_provider_default_clear"

    page, html = env.run(llm_providers, "delete_provider", {"id": str(pid)})
    assert page.flash_success == "Нейросеть «Polza» удалена вместе с назначенными правами."
    assert conn.execute("SELECT COUNT(*) FROM llm_providers").fetchone()[0] == 0
    assert last_audit(env)["action"] == "llm_provider_delete" and "Нейросети ещё не добавлены" in html


def test_test_provider_shows_result_and_records_check(env, conn):
    pid = create_provider(conn, "Polza")
    page, html = env.run(llm_providers, "test_provider", {"id": str(pid)})
    assert page.flash_success is None and page.flash_error is None
    assert conn.execute("SELECT last_check_ok FROM llm_providers").fetchone()[0] == 1
    assert 'alert alert-success llm-result' in html and "Подключение успешно, модель m-1" in html
    a = last_audit(env)
    assert a["action"] == "llm_provider_test" and a["success"] == 1 and "duration_ms=" in a["details"]


def test_save_chat_settings(env, conn):
    page, _ = env.run(llm_providers, "save_chat_settings", [
        ("system_prompt", " Будь краток\r\n "), ("history_max_chars", "5000"), ("max_message_chars", "1000"),
        ("style_default", "technical"), ("styles_allowed[]", "concise"), ("styles_allowed[]", "bogus"), ("summary_model_id", "-3")])
    assert page.flash_success == "Общие параметры чата сохранены."
    s = AppSettings(conn)
    assert s.get("llm_chat_system_prompt") == "Будь краток" and s.get("llm_chat_styles_allowed") == "concise,technical"
    assert s.get("llm_chat_summary_model_id") == "0" and s.get("llm_chat_history_max_chars") == "5000"
    assert last_audit(env)["details"] == "history_max_chars=5000; max_msg_chars=1000; system_instruction_chars=11"

    page, _ = env.run(llm_providers, "save_chat_settings", {"history_max_chars": "10", "max_message_chars": "1000"})
    assert page.flash_error == "Общие параметры не сохранены: Допустимый диапазон: 1000–2000000."
    assert s.get("llm_chat_history_max_chars") == "5000"


# ---------------- Модели и резервирование ----------------
def test_models_get_renders_table_and_form(env, conn):
    create_provider(conn, "Polza")
    page, html = env.run(llm_models)
    assert page.page_title == "Модели и резервирование"
    for part in ('class="data-table models-table"', 'id="model-form"', "Добавить модель", 'name="fallback_ids[]"', "data-autosubmit",
                 "Не проверялась", "по умолчанию", "Сбоев не было.", 'href="index.php?section=llm_models&edit=1#model-form"'):
        assert part in html, part
    _, html = env.run(llm_models, query={"edit": "1"})
    assert "Изменить модель" in html and 'id="provider_id" name="provider_id" disabled' in html
    assert "Удалить модель" not in html, "модель по умолчанию удаляется вместе с подключением"


def model_form(**over) -> dict:
    form = {"id": "0", "provider_id": "1", "model": "openai/gpt-4o-mini", "display_name": "Быстрая", "context_window": "32000",
            "max_output_tokens": "1024", "temperature": "0.5", "priority": "10", "is_enabled": "1", "price_in": "1.5", "price_out": ""}
    form.update(over)
    return form


def test_save_model_create_update_and_duplicate(env, conn):
    create_provider(conn, "Polza")
    page, _ = env.run(llm_models, "save_model", list(model_form().items()) + [("fallback_ids[]", "1")])
    assert page.flash_success == "Модель сохранена."
    row = conn.execute("SELECT * FROM llm_models WHERE model = 'openai/gpt-4o-mini'").fetchone()
    assert row["display_name"] == "Быстрая" and row["temperature"] == 0.5 and row["fallback_ids"] == "[1]" and row["price_in_per_mtoken"] == 1.5
    assert last_audit(env)["action"] == "llm_model_save" and "fallbacks=1" in last_audit(env)["details"]

    page, html = env.run(llm_models, "save_model", model_form(id=str(row["id"]), display_name="Быстрая 2"))
    assert page.flash_success == "Модель сохранена."
    assert conn.execute("SELECT display_name FROM llm_models WHERE id = ?", (row["id"],)).fetchone()[0] == "Быстрая 2"

    page, html = env.run(llm_models, "save_model", model_form())
    assert page.flash_error == "У этого подключения уже есть модель с таким идентификатором."
    assert 'value="Быстрая"' in html


def test_save_model_validation(env, conn):
    create_provider(conn, "Polza")
    page, html = env.run(llm_models, "save_model", model_form(provider_id="77", max_output_tokens="64000", temperature="5"))
    assert page.flash_error == "Проверьте выделенные поля."
    assert "Выберите подключение." in html and "Температура: от 0 до 2 или пусто." in html
    assert "Длина ответа должна быть меньше контекстного окна." in html
    assert conn.execute("SELECT COUNT(*) FROM llm_models").fetchone()[0] == 1 and env.audit() == []


def test_delete_model(env, conn):
    create_provider(conn, "Polza")
    env.run(llm_models, "save_model", model_form())
    extra = conn.execute("SELECT id FROM llm_models WHERE is_default = 0").fetchone()[0]
    _, html = env.run(llm_models, query={"edit": str(extra)})
    assert 'data-confirm="Удалить модель? Она исчезнет и из цепочек резервирования."' in html
    page, _ = env.run(llm_models, "delete_model", {"id": str(extra)})
    assert page.flash_success == "Модель удалена." and last_audit(env)["action"] == "llm_model_delete"
    page, _ = env.run(llm_models, "delete_model", {"id": "1"})
    assert page.flash_error.startswith("Модель по умолчанию удаляется вместе с подключением")
    assert last_audit(env)["success"] == 0 and conn.execute("SELECT COUNT(*) FROM llm_models").fetchone()[0] == 1


@pytest.mark.parametrize("action,expected", [("check_model", "Подключение успешно"), ("generate_model", "Генерация успешна")])
def test_check_and_generate_model(env, conn, action, expected):
    create_provider(conn, "Polza")
    page, html = env.run(llm_models, action, {"id": "1"})
    assert expected in html and '<div class="muted">OK</div>' in html
    assert conn.execute("SELECT success_count FROM llm_model_health WHERE model_id = 1").fetchone()[0] == 1
    a = last_audit(env)
    assert a["action"] == "llm_model_check" and a["success"] == 1 and f"mode={action}" in a["details"]


def test_failed_check_appears_in_incidents(env, conn):
    create_provider(conn, "Polza")
    env.handler.llm = lambda: HttpResponse(500, '{"error":{"message":"boom"}}')
    page, html = env.run(llm_models, "check_model", {"id": "1"})
    assert "alert alert-error" in html and "проверка администратора" in html and last_audit(env)["success"] == 0
    assert "Последний запрос с ошибкой" in html


def test_reset_cooldown(env, conn):
    create_provider(conn, "Polza")
    conn.execute("INSERT INTO llm_model_health (model_id, cooldown_until, consecutive_failures) VALUES (1, ?, 3)", (int(time.time()) + 600,))
    _, html = env.run(llm_models)
    assert "Пауза до " in html and "Снять паузу" in html
    page, html = env.run(llm_models, "reset_cooldown", {"id": "1"})
    assert page.flash_success == "Пауза снята — модель снова первая в своей цепочке."
    assert tuple(conn.execute("SELECT cooldown_until, consecutive_failures FROM llm_model_health").fetchone()) == (0, 0)
    assert last_audit(env)["action"] == "llm_model_reset_cooldown" and "Снять паузу" not in html


def test_save_fallback_settings(env, conn):
    page, html = env.run(llm_models, "save_fallback_settings", {})
    assert page.flash_success == "Настройки резервирования сохранены."
    assert AppSettings(conn).get("llm_chat_fallback_auto") == "0"
    assert last_audit(env)["details"] == "fallback_auto=0"
    env.run(llm_models, "save_fallback_settings", {"fallback_auto": "1"})
    assert AppSettings(conn).get("llm_chat_fallback_auto") == "1"


# ---------------- Обезличивание ----------------
def test_anonymization_get_renders_engine_status_and_lab(env, conn):
    page, html = env.run(anonymization)
    assert page.page_title == "Обезличивание"
    for part in ('id="lab"', 'name="internal_action[person]"', 'enctype="multipart/form-data"', 'accept=".txt', 'name="ocr_languages"',
                 '<div class="value status-ok">Готов</div>', "Временный каталог:</span> <span class=\"value status-ok\">"):
        assert part in html, part
    for gone in ("DocClean", "data-anon-profile", 'name="base_url"', 'name="secret"', 'name="tesseract_path"', "ext-zip", "ext-curl"):
        assert gone not in html, gone


def test_anonymization_page_explains_unreachable_service(env, conn):
    def down(r):
        raise HttpTransportError("connect", "refused")
    env.transport.handler = down
    page, html = env.run(anonymization)
    assert "Недоступен — чат заблокирован" in html and "./scripts/ctl.sh status" in html


def test_save_internal_anonymization(env, conn):
    page, _ = env.run(anonymization, "save_anonymization", {
        "internal_profile": "custom", "internal_person_format": "role", "internal_org_scope": "all",
        "internal_timeout": "60", "internal_terms": "Альбатрос;project;\r\nCRM;project;Система", "internal_exceptions": "",
        "internal_domains": "corp.example.com", "internal_action[person]": "keep", "internal_action[bogus]": "keep", "internal_action[email]": "x"})
    assert page.flash_success == "Настройки встроенного обезличивания сохранены."
    s = AppSettings(conn)
    assert s.get("anon_internal_custom_actions") == '{"person":"keep"}' and s.get("anon_internal_terms") == "Альбатрос;project;\nCRM;project;Система"
    a = last_audit(env)
    assert a["action"] == "anonymization_settings_update"
    assert a["details"] == "engine_profile=custom; terms=2; exceptions=0; domains=1"


def test_save_internal_anonymization_invalid(env, conn):
    page, html = env.run(anonymization, "save_anonymization", {"internal_profile": "nope", "internal_timeout": "1"})
    assert page.flash_error == "Проверьте выделенные поля формы."
    assert "Допустимый диапазон: 5–600." in html and env.audit() == []


def test_removed_external_fields_are_not_stored(env, conn):
    """Старая форма с полями внешнего API (DocClean/JSON): сохраняется только движок, адрес и ключ — нет."""
    page, _ = env.run(anonymization, "save_anonymization", {
        "profile": "docclean", "base_url": "https://docclean.example.local", "secret": "tok-123", "docclean_mode": "full",
        "internal_profile": "basic", "internal_person_format": "short", "internal_org_scope": "legal", "internal_timeout": "120"})
    assert page.flash_success == "Настройки встроенного обезличивания сохранены."
    stored = json.dumps([tuple(r) for r in conn.execute("SELECT * FROM app_settings")], ensure_ascii=False)
    assert "docclean" not in stored and "tok-123" not in stored and "anon_secret_encrypted" not in stored


def test_check_anonymization(env, conn):
    page, html = env.run(anonymization, "check_anonymization", {})
    assert "Сервис отвечает, результат обезличивания получен." in html
    assert AppSettings(conn).get("anon_last_check_ok") == "1"
    assert last_audit(env)["action"] == "anonymization_check" and last_audit(env)["success"] == 1


def test_save_file_processing(env, conn):
    form = {"max_upload_mb": "20", "max_extracted_chars": "100000", "ocr_languages": "rus", "ocr_max_pages": "10", "tool_timeout": "60"}
    page, _ = env.run(anonymization, "save_file_processing", form)
    assert page.flash_success == "Параметры обработки файлов сохранены."
    assert AppSettings(conn).get("files_max_upload_mb") == "20" and AppSettings(conn).get("files_ocr_enabled") == "0"
    assert last_audit(env)["details"] == "max_upload_mb=20; ocr_enabled=0; ocr_languages=rus"


def test_tool_paths_are_not_editable(env, conn):
    """Пути к инструментам зафиксированы в образе: поле из старой формы (/bin/sh) игнорируется, не сохраняется."""
    form = {"max_upload_mb": "20", "max_extracted_chars": "100000", "ocr_languages": "rus", "ocr_max_pages": "10",
            "tesseract_path": "/bin/sh", "tool_timeout": "60"}
    page, html = env.run(anonymization, "save_file_processing", form)
    assert page.flash_success == "Параметры обработки файлов сохранены."
    assert AppSettings(conn).get("files_tesseract_path") is None and "/bin/sh" not in html


def test_lab_shows_replacements_and_candidates(env, conn):
    page, html = env.run(anonymization, "lab_anonymization", {"lab_text": "Отчёт: Петров Сергей", "lab_profile": "strict"})
    body = json.loads(env.transport.requests_to("/v1/anonymize")[0].body)
    assert body["detail"] is True and body["profile"] == "strict"
    for part in ("Отчёт: Сергей П.", "<td>Петров Сергей</td><td>Сергей П.</td>", "<td>Люди (ФИО)</td>", "Роза (обычное слово)",
                 "По категориям: Люди (ФИО) — 1", 'class="alert alert-warn">Проверьте таблицы', "текст, 20 знаков", '<option value="strict" selected>'):
        assert part in html, part
    a = last_audit(env)
    assert a["action"] == "anonymization_lab" and a["details"] == "chars=20; format=текст; replaced=1"
    assert "Петров" not in a["details"]


def test_lab_with_uploaded_file(env, conn):
    page = env.page("anonymization", "lab_anonymization", {})
    data = "Записка: Петров Сергей".encode()
    upload = UploadFile(io.BytesIO(data), size=len(data), filename="note.txt", headers=Headers({"content-type": "text/plain"}))
    page.form = type(page.form)(list(page.form.multi_items()) + [("lab_file", upload)])
    html = anonymization.handle(page)
    assert "TXT, 22 знаков" in html and "Записка: Сергей П." in html


def test_lab_errors(env, conn):
    page, html = env.run(anonymization, "lab_anonymization", {"lab_text": "  "})
    assert '<div class="alert alert-error">Вставьте текст или выберите файл.</div>' in html and env.audit() == []
    env.handler.anon = lambda body: HttpResponse(422, json.dumps({"detail": {"code": "verification_failed", "message": "остался телефон"}}, ensure_ascii=False))
    page, html = env.run(anonymization, "lab_anonymization", {"lab_text": "Петров Сергей"})
    assert "Повторная проверка нашла неочищенные данные" in html and "остался телефон" in html
    assert last_audit(env)["success"] == 0 and "reason=not_clean" in last_audit(env)["details"]


# ---------------- Почта ----------------
def mail_form(**over) -> dict:
    form = {"enabled": "1", "host": "smtp.example.com", "port": "587", "security": "starttls", "auth": "auto", "username": "bot",
            "password": "pw-1", "from_address": "llm@example.com", "from_name": "LLM", "timeout": "10", "helo": "", "alert_recipients": "it@example.com",
            "verify_cert": "1", "alerts_enabled": "1"}
    form.update(over)
    return {k: v for k, v in form.items() if v is not None}


def test_mail_get_renders(env, conn):
    page, html = env.run(mail)
    assert page.page_title == "Почта и уведомления"
    for part in ('id="mailCheck"', 'name="host"', 'name="to"', "Писем пока не было.", "хранится зашифрованным",
                 'action="index.php?section=mail&action=process_queue"'):
        assert part in html, part


def test_save_mail_and_password_cleared_on_host_change(env, conn):
    page, html = env.run(mail, "save_mail", mail_form())
    assert page.flash_success == "Настройки почты сохранены. Проверьте соединение."
    ms = MailSettings(AppSettings(conn))
    assert ms.password() == "pw-1" and ms.get("host") == "smtp.example.com"
    assert last_audit(env)["details"] == "host=smtp.example.com; port=587; security=starttls; verify_cert=1", "ключ password отфильтрован журналом, как в PHP"
    assert "сохранён — оставьте пустым, чтобы не менять" in html

    env.run(mail, "save_mail", mail_form(password=""))
    assert ms.password() == "pw-1", "тот же сервер — пароль остаётся"
    page, _ = env.run(mail, "save_mail", mail_form(host="smtp2.example.com", password=""))
    assert page.flash_success and not ms.has_password()


def test_save_mail_invalid(env, conn):
    page, html = env.run(mail, "save_mail", mail_form(port="0", password="a\nb"))
    assert page.flash_error == "Проверьте выделенные поля." and "Порт: 1–65535." in html and "Недопустимые символы." in html
    assert env.audit() == [] and AppSettings(conn).get("mail_host") is None


def test_check_mail_shows_stage_table(env, conn):
    env.run(mail, "save_mail", mail_form(host="127.0.0.1"))
    page, html = env.run(mail, "check_mail", {})
    assert "Остановка: адрес или порт запрещён." in html and "<th>Этап</th>" in html
    a = last_audit(env)
    assert a["action"] == "mail_check" and a["success"] == 0 and a["details"] == "code=forbidden_target"


def test_test_mail(env, conn):
    page, _ = env.run(mail, "test_mail", {"to": "not-an-address"})
    assert page.flash_error == "Укажите адрес получателя." and env.audit() == []
    env.run(mail, "save_mail", mail_form(host="127.0.0.1"))
    page, html = env.run(mail, "test_mail", {"to": "you@example.com"})
    assert page.flash_success is None and "Остановка:" in html
    assert last_audit(env)["action"] == "mail_test" and last_audit(env)["success"] == 0


def test_process_queue(env, conn):
    MailQueue(conn).enqueue(TEMPLATE, "it@example.com", {})
    page, html = env.run(mail, "process_queue", {})
    assert page.flash_success == "Обработка очереди: отправлено 0.", "почта не настроена — очередь ждёт"
    assert "<td>it@example.com</td>" in html and "<td>ждёт</td>" in html
