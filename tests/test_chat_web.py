"""HTTP-уровень чата (public/index.php, public/api.php): вход, кука сессии, CSRF, JSON-контракт api.php, загрузки,
обслуживание контекста после ответа. Внешние сервисы — FakeHttpTransport через app.state.transport."""

from __future__ import annotations

import json
import os

import pytest
from fastapi.testclient import TestClient

from kit import create_domain, create_provider
from kit_anon import ANON_HOST, service_reply, use_service
from llmp import db, paths
from llmp.chat import ChatUser, session_login
from llmp.domain import DomainGroupRepository
from llmp.http import FakeHttpTransport, HttpResponse
from llmp.module import LlmModule
from llmp.security import COOKIE, Session, SessionStore, csrf_token
from llmp.web import chat_routes
from llmp.web.app import create_app


class Web:
    def __init__(self, tmp_path):
        self.path = tmp_path / "app.sqlite"
        self.app = create_app(self.path)
        self.conn = db.connect(self.path)
        domain_id = create_domain(self.conn, "Corp", "corp.local")
        self.provider_id = create_provider(self.conn, "Polza")
        group_id = DomainGroupRepository(self.conn).create(domain_id, "LLM", "CN=LLM,DC=corp,DC=local", True, "t")
        module = LlmModule(self.conn)
        module.access.set_group_providers(group_id, [self.provider_id])
        self.module = module
        self.user = ChatUser("ivanov", domain_id, "corp.local", [group_id], ["LLM"])
        self.answer = "Ответ"
        self.transport = FakeHttpTransport(self.handle)
        self.app.state.transport = self.transport
        self.client = TestClient(self.app, follow_redirects=False)
        self.csrf = ""

    def handle(self, r):
        if ANON_HOST in r.url:
            return service_reply(r, lambda t: t.replace("Иванов", "[PERSON]"))
        body = json.loads(r.body)
        text = "## Текущая задача\nНет" if "справочную сводку" in body["messages"][0]["content"] else self.answer
        return HttpResponse(200, json.dumps({"choices": [{"message": {"content": text}, "finish_reason": "stop"}]}, ensure_ascii=False))

    def login(self) -> str:
        s = Session(None)
        session_login(s, self.user)
        self.csrf = csrf_token(s)
        self.client.cookies.set(COOKIE, SessionStore(self.conn).save(s))
        return self.csrf

    def post(self, action: str, data=None, files=None, **kw):
        headers = {"X-CSRF-Token": self.csrf, **kw.pop("headers", {})}
        return self.client.post("/api.php", params={"action": action}, data=data or {}, files=files, headers=headers, **kw)

    def get(self, action: str, **params):
        return self.client.get("/api.php", params={"action": action, **params})


@pytest.fixture
def web(tmp_path, monkeypatch):
    use_service(monkeypatch)
    w = Web(tmp_path)
    yield w
    w.client.close()
    w.conn.close()


def test_api_without_session_is_401_json(web):
    r = web.get("bootstrap")
    assert r.status_code == 401
    assert r.json() == {"error": "Сеанс завершён. Войдите снова.", "code": "unauthorized"}


def test_login_page_over_plain_http_sets_cookie_without_secure(web):
    r = web.client.get("/index.php")
    assert r.status_code == 200
    assert 'action="index.php?action=login"' in r.text
    assert 'name="csrf" value="' in r.text
    assert 'name="domain_id" value="' in r.text, "один домен — скрытое поле"
    assert 'href="assets/css/app.css?v=' in r.text and 'src="assets/js/app.js?v=' in r.text
    cookie = r.headers["set-cookie"]
    assert cookie.startswith(COOKIE + "=") and "httponly" in cookie.lower() and "samesite=strict" in cookie.lower()
    assert "secure" not in cookie.lower().replace("samesite", ""), "по http кука без Secure — вход по http://IP:порт работает"
    assert web.client.get("/").status_code == 200


def test_cookie_is_secure_behind_https_proxy(web):
    r = web.client.get("/index.php", headers={"X-Forwarded-Proto": "https"})
    assert "; secure" in r.headers["set-cookie"].lower()


def test_login_form_errors(web):
    web.client.get("/index.php")
    r = web.client.post("/index.php?action=login", data={"username": "ivanov", "password": "x", "csrf": "bad"})
    assert r.status_code == 200 and "Форма устарела, обновите страницу и попробуйте снова." in r.text
    assert 'value="ivanov"' in r.text, "логин подставляется обратно"
    token = web.client.get("/index.php").text.split('name="csrf" value="')[1].split('"')[0]
    r = web.client.post("/index.php?action=login", data={"username": "ivanov", "password": "", "csrf": token})
    assert "Введите логин и пароль." in r.text


def test_logout_requires_csrf_and_ends_the_chat_session(web):
    web.login()
    assert web.client.post("/index.php?action=logout", data={"csrf": "bad"}).headers["location"] == "index.php"
    assert web.get("bootstrap").status_code == 200, "без верного CSRF выхода нет"
    r = web.client.post("/index.php?action=logout", data={"csrf": web.csrf})
    assert (r.status_code, r.headers["location"]) == (302, "index.php")
    assert web.get("bootstrap").status_code == 401
    assert "CHAT_LOGOUT_SUCCESS" in "".join(r[0] for r in web.conn.execute("SELECT result FROM app_log"))


def test_app_page_structure_and_https_hint(web, monkeypatch):
    web.login()
    page = web.client.get("/index.php").text
    assert 'id="chatApp"' in page and f'data-csrf="{web.csrf}"' in page and 'data-ready="1"' in page
    assert 'data-can-skip-anonymization="0"' in page and "data-insecure" not in page, "голосовой ввод выключен — подсказки нет"
    order = [page.index(f'src="assets/js/{n}.js?v=') for n in ("app", "dictation", "chat")]
    assert order == sorted(order)
    assert 'id="chatMic"' in page and 'class="gpt-mic-insecure"' in page
    assert "ivanov@corp.local" in page

    monkeypatch.setenv("ASR_ENABLED", "1")
    monkeypatch.setenv("DICTATION_SECRET", "s" * 32)
    web.module.chat_settings.save({"dictation_enabled": "1"})
    assert 'data-insecure="1"' in web.client.get("/index.php").text, "по http кнопка объясняет, что нужен HTTPS"
    assert "data-insecure" not in web.client.get("/index.php", headers={"X-Forwarded-Proto": "https"}).text


def test_csrf_is_required_for_post(web):
    web.login()
    r = web.client.post("/api.php", params={"action": "project_create"}, data={"name": "P"})
    assert (r.status_code, r.json()) == (403, {"error": "Сеанс устарел, обновите страницу.", "code": "csrf"})
    r = web.client.post("/api.php", params={"action": "project_create"}, data={"name": "P", "csrf": web.csrf})
    assert r.status_code == 200 and r.json()["projects"][0]["name"] == "P"


def test_bootstrap_send_and_conversation(web):
    web.login()
    boot = web.get("bootstrap").json()
    assert boot["user"] == {"name": "ivanov@corp.local", "groups": ["LLM"]}
    assert [p["id"] for p in boot["providers"]] == [web.provider_id]
    assert boot["conversations"] == [] and boot["can_skip_anonymization"] is False
    assert boot["dictation"]["enabled"] is False and "balanced" in boot["styles"]

    r = web.post("send", {"text": "Письмо для Иванов", "provider_id": str(web.provider_id)})
    assert r.status_code == 200, r.text
    data = r.json()
    assert "maintenance" not in data
    assert data["user_message"]["content"] == "Письмо для [PERSON]"
    assert data["assistant_message"]["content"] == "Ответ"
    cid = data["conversation"]["id"]

    conv = web.get("conversation", id=str(cid)).json()
    assert conv["conversation"]["title"] == "Письмо для [PERSON]"
    assert [m["role"] for m in conv["messages"]] == ["user", "assistant"]
    assert web.get("conversation", id="999").json()["code"] == "not_found"
    assert web.get("search", q="письмо").json()["results"][0]["id"] == cid

    r = web.post("bulk_delete_conversations", {"ids[]": [str(cid), "999"]})
    assert r.json()["deleted"] == 1 and r.json()["conversations"] == []


def test_send_errors_use_error_code_format(web):
    web.login()
    r = web.post("send", {"text": "x", "provider_id": "999"})
    assert (r.status_code, r.json()["code"]) == (403, "access_denied")
    r = web.post("send", {"text": "", "provider_id": str(web.provider_id)})
    assert (r.status_code, r.json()["code"]) == (400, "empty")
    assert web.get("nope").json() == {"error": "Неизвестное действие.", "code": "unknown_action"}
    assert web.get("nope").status_code == 404


def test_upload_goes_through_temp_file_and_is_removed(web):
    web.login()
    files = [("files[]", ("note.txt", "Отчёт подготовил Иванов.".encode(), "text/plain"))]
    r = web.post("send", {"text": "Сократи", "provider_id": str(web.provider_id)}, files=files)
    assert r.status_code == 200, r.text
    msg = r.json()["user_message"]
    assert msg["attachment_type"] == "txt" and "[Вложение: TXT]" in msg["content"] and "Иванов" not in msg["content"]
    leftovers = [f for f in os.listdir(paths.llm_tmp())] if paths.llm_tmp().exists() else []
    assert leftovers == [], "временные файлы загрузки удалены"

    many = [("files[]", (f"n{i}.txt", b"x", "text/plain")) for i in range(6)]
    assert web.post("send", {"text": "x", "provider_id": str(web.provider_id)}, files=many).json()["code"] == "too_many_files"
    two = [("file[]", ("a.txt", b"a", "text/plain")), ("file[]", ("b.txt", b"b", "text/plain"))]
    assert web.post("project_source_add", {"project_id": "1"}, files=two).json()["code"] == "multiple_files"


def test_body_over_limit_is_413(web, monkeypatch):
    web.login()
    monkeypatch.setattr(chat_routes, "POST_MAX_BYTES", 10)
    r = web.post("send", {"text": "длинный текст запроса"})
    assert (r.status_code, r.json()) == (413, {"error": "Файл превышает допустимый размер.", "code": "file_too_large"})


def test_unexpected_error_is_500_and_logged(web, monkeypatch):
    web.login()

    def boom(*a, **kw):
        raise RuntimeError("сбой")

    monkeypatch.setattr("llmp.chat.conversations.ConversationRepository.search", boom)
    r = web.get("search", q="abc")
    assert (r.status_code, r.json()) == (500, {"error": "Внутренняя ошибка сервиса. Запрос не выполнен.", "code": "internal"})
    row = web.conn.execute("SELECT source, event_code, message FROM diagnostic_log ORDER BY id DESC").fetchone()
    assert (row[0], row[1]) == ("php_error", "RuntimeError") and "(chat api search)" in row[2]


def test_projects_api(web):
    web.login()
    pid = web.post("project_create", {"name": "Договоры"}).json()["id"]
    r = web.post("project_update", {"id": str(pid), "description": "Для Иванов", "pinned": "1", "instructions": "Пиши Иванов кратко",
                                    "memory": "Иванов согласовал срок", "settings": '{"use_sources": false, "sources_share": 60, "style": ""}'})
    assert r.json()["description"] == "Для [PERSON]" and r.json()["projects"][0]["pinned"] is True
    assert r.json()["instructions"] == "Пиши [PERSON] кратко" and r.json()["memory"] == "[PERSON] согласовал срок"
    assert r.json()["settings"] == {"model_id": 0, "style": "", "use_memory": True, "use_sources": False, "sources_share": 60}
    assert web.post("project_update", {"id": str(pid), "settings": "[1]"}).json()["code"] == "invalid_settings"
    assert web.post("project_update", {"id": str(pid), "settings": '{"model_id": 999}'}).json()["code"] == "access_denied"
    assert web.post("project_update", {"id": str(pid), "settings": '{"style": "нет-такого"}'}).json()["code"] == "style_denied"
    inst = web.post("project_instruction_add", {"project_id": str(pid), "title": "Тон", "text": "Кратко"}).json()["instruction"]
    src = web.post("project_source_add", {"project_id": str(pid), "title": "Регламент", "text": "Срок 3 дня"}).json()["source"]
    project = web.get("project", id=str(pid)).json()
    assert project["project"]["description"] == "Для [PERSON]" and project["project"]["instructions"] == "Пиши [PERSON] кратко"
    assert project["project"]["state"] == "[PERSON] согласовал срок" and project["project"]["settings"]["sources_share"] == 60
    assert project["context_tokens"]["memory"] > 0 and project["context_tokens"]["sources"] > 0 and project["memory_limit"] == 4000
    assert web.post("project_update", {"id": str(pid), "memory": ""}).json()["memory"] == ""
    assert web.get("project", id=str(pid)).json()["project"]["state"] == ""
    assert [i["id"] for i in project["instructions"]] == [inst["id"]] and [s["id"] for s in project["sources"]] == [src["id"]]
    assert web.get("project_source_text", id=str(src["id"])).json() == {"text": "Срок 3 дня"}
    assert web.post("project_source_delete", {"id": str(src["id"])}).json() == {"ok": True}
    assert web.post("project_update", {"id": "999"}).json()["code"] == "not_found"
    assert web.post("project_create", {"name": "  "}).json()["code"] == "invalid_name"
    assert web.post("project_delete", {"id": str(pid)}).json()["ok"] is True


def test_context_maintenance_runs_after_the_response_on_its_own_connection(web):
    """Сводка — фоновой задачей после ответа (fastcgi_finish_request PHP); соединение запроса к этому моменту закрыто."""
    web.login()
    web.conn.execute("UPDATE llm_models SET context_window = 6000, max_output_tokens = 500")
    web.answer = "ответ " * 300
    cid = None
    for i in range(9):
        r = web.post("send", {"text": f"Вопрос {i} " + "уточнение " * 60, "provider_id": str(web.provider_id),
                              "conversation_id": str(cid or 0)})
        assert r.status_code == 200, r.text
        assert "maintenance" not in r.json()
        cid = r.json()["conversation"]["id"]
    assert web.conn.execute("SELECT COUNT(*) FROM llm_conversation_summaries").fetchone()[0] == 1
