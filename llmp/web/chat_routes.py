"""Маршруты чата: index.php (вход, страница чата), api.php (JSON API). Поля запросов, ответы, коды и статусы — как у
public/index.php и public/api.php: браузерный код (chat.js, app.js, dictation.js) не меняется."""

from __future__ import annotations

import ipaddress
import logging
import os
import random
import re
import tempfile
import traceback

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse, RedirectResponse
from starlette.background import BackgroundTask
from starlette.concurrency import run_in_threadpool
from starlette.datastructures import FormData, UploadFile

from .. import compact, db, errors, paths
from ..chat import ChatException, ChatUser, session_current, session_login, session_logout
from ..chat.service import ChatService
from ..chat.settings import php_int
from ..domain import ChatAuthenticator
from ..ldap.client import LdapErrorCode as L, LdapException
from ..logs import LlmEventLog
from ..module import LlmModule
from ..security import RateLimiter, csrf_rotate, csrf_verify
from .core import Ctx, ctx, json_response, module_for, render, session_write_close

router = APIRouter()
log = logging.getLogger("llmp")

POST_MAX_BYTES = 130 * 1024 * 1024     # post_max_size PHP-образа (согласован с client_max_body_size nginx)
UPLOAD_MAX_BYTES = 25 * 1024 * 1024    # upload_max_filesize: больший файл — ошибка загрузки too_large
PHP_WS = " \t\n\r\0\x0b"
_CONTROL = re.compile(r"[\x00-\x1F\x7F]+")
_E = LlmEventLog


async def _form(request: Request) -> FormData:
    """Тело POST (form/multipart); битое тело — пустая форма (как пустой $_POST): дальше откажет проверка CSRF."""
    try:
        return await request.form(max_part_size=POST_MAX_BYTES)
    except Exception:  # noqa: BLE001
        return FormData()


def _s(form: FormData | None, name: str, default: str = "") -> str:
    """(string) ($_POST[name] ?? default): строковое поле формы."""
    v = form.get(name) if form is not None else None
    return v if isinstance(v, str) else default


def _has(form: FormData, name: str) -> bool:
    return isinstance(form.get(name), str)


# ======================= index.php =======================
@router.api_route("/", methods=["GET", "POST"])
@router.api_route("/index.php", methods=["GET", "POST"])
async def index(request: Request):
    form = await _form(request) if request.method == "POST" else None
    return await run_in_threadpool(_index, request, form)


def _index(request: Request, form: FormData | None):
    c = ctx(request)
    llm = module_for(request)
    events = llm.events
    action = request.query_params.get("action", "")
    domains = llm.domains.enabled_for_chat()
    login_error = None
    prefill_login = ""
    selected_domain_id = domains[0].id() if domains else 0

    # ---- Вход: доменная LDAPS-авторизация + проверка групп ----
    if form is not None and action == "login":
        login = _s(form, "username").strip(PHP_WS)
        password = _s(form, "password")
        selected_domain_id = php_int(_s(form, "domain_id", str(selected_domain_id)))
        prefill_login = login
        limiter = RateLimiter(c.conn)
        bucket = "chat_login:" + c.ip
        retry_after = limiter.retry_after_seconds(bucket)
        parsed = ChatAuthenticator.parse_login(login)

        domain = None
        if parsed is not None:
            hint = parsed["domain_hint"]
            for candidate in domains:
                matches_hint = hint is not None and hint in (candidate.config.domain.lower(), candidate.config.netbios_domain.lower())
                if matches_hint or (hint is None and candidate.id() == selected_domain_id):
                    domain = candidate
                    break

        if not csrf_verify(c.session, form.get("csrf")):
            login_error = "Форма устарела, обновите страницу и попробуйте снова."
        elif retry_after > 0:
            login_error = f"Слишком много попыток входа. Повторите через {retry_after} сек."
        elif parsed is None or password == "":
            login_error = "Введите логин и пароль."
        elif domain is None:
            login_error = "Вход в сервис ещё не настроен администратором." if not domains else "Домен не найден — выберите его из списка."
        else:
            limiter.record_attempt(bucket)
            log_name = parsed["sam"] + "@" + domain.config.domain
            dname = domain.config.name
            try:
                auth = llm.authenticator.authenticate(domain, parsed["sam"], password)
                if auth["user_dn"] is None:
                    login_error = "Неверный логин или пароль."
                    if parsed["sam"].lower() == "admin":   # частая ошибка: локальный администратор входит не здесь, а в админке
                        login_error = "Это вход в чат по учётной записи Windows. Локальный администратор входит по ссылке «Вход для администратора» ниже."
                    events.user(c.ip, log_name, _E.OP_AUTH, "CHAT_LOGIN_FAILED", {"domain": dname, "reason": "user_not_found"})
                elif not auth["groups"]:
                    login_error = ("Нет доступа к сервису: ваша учётная запись не входит ни в одну разрешённую группу. "
                                   "Обратитесь к администратору.")
                    events.user(c.ip, log_name, _E.OP_AUTH, "CHAT_LOGIN_FAILED",
                                {"domain": dname, "reason": "no_allowed_group", "unresolved_groups": auth["unresolved"] or ["—"]})
                elif llm.usage.is_blocked(log_name.lower()):
                    login_error = "Доступ к чату для вашей учётной записи приостановлен администратором."
                    events.user(c.ip, log_name, _E.OP_AUTH, "CHAT_LOGIN_FAILED", {"domain": dname, "reason": "blocked"})
                else:
                    user = ChatUser(parsed["sam"], domain.id(), domain.config.domain, list(auth["groups"]), list(auth["groups"].values()))
                    session_login(c.session, user)
                    # Профиль пользователя (лимиты, статистика) создаётся при первом входе.
                    llm.usage.touch_profile(user)
                    retention_days = php_int(llm.chat_settings.get("retention_days"))
                    if retention_days > 0:
                        llm.conversations.delete_older_than(user.owner_key(), retention_days)
                    if random.randint(1, 50) == 1:
                        llm.usage.purge_old_usage()
                        purge_days = php_int(llm.chat_settings.get("inactivity_content_purge_days"))
                        if purge_days > 0:
                            llm.inactivity_cleanup.cleanup_inactive(purge_days)
                    limiter.reset_on_success(bucket)
                    events.user(c.ip, user.log_name(), _E.OP_AUTH, "CHAT_LOGIN_SUCCESS", {
                        "domain": dname, "groups": list(auth["groups"].values()), "group_lookup": auth["lookup"],
                        "providers_available": len(llm.access.allowed_providers(user.group_ids, user.domain_id, user.owner_key())),
                    })
                    csrf_rotate(c.session)
                    return RedirectResponse("index.php", 302)
            except LdapException as e:
                if e.error_code in (L.INVALID_CREDENTIALS, L.USER_NOT_FOUND):
                    login_error = "Неверный логин или пароль."
                elif e.error_code in (L.PASSWORD_EXPIRED, L.PASSWORD_MUST_CHANGE):
                    login_error = ("Срок действия пароля истёк или требуется его смена. Смените пароль штатным способом "
                                   "(Ctrl+Alt+Del на рабочей станции или обратитесь к администратору) и войдите снова.")
                elif e.error_code == L.ACCOUNT_RESTRICTION:
                    login_error = "Для вашей учётной записи вход по паролю через LDAP ограничен политикой домена. Обратитесь к администратору."
                else:
                    login_error = errors.for_code(e.error_code)
                events.user(c.ip, log_name, _E.OP_AUTH, "CHAT_LOGIN_FAILED", {"domain": dname, "reason": e.error_code})
                if e.error_code in (L.TLS_ERROR, L.SERVER_UNAVAILABLE, L.TIMEOUT, L.UNKNOWN):
                    llm.diagnostics.error("chat_auth", e.error_code, str(e), {"domain": dname, "host": domain.config.host}, c.ip)
            except Exception as e:  # noqa: BLE001
                login_error = "Сервис временно недоступен. Попробуйте позже или обратитесь к администратору."
                events.user(c.ip, log_name, _E.OP_AUTH, "CHAT_LOGIN_FAILED", {"domain": dname, "reason": "exception"})
                llm.diagnostics.error("chat_auth", type(e).__name__, str(e), {"domain": dname}, c.ip)
        del password

    if form is not None and action == "logout":
        user = session_current(c.session)
        if user is not None and csrf_verify(c.session, form.get("csrf")):
            events.user(c.ip, user.log_name(), _E.OP_AUTH, "CHAT_LOGOUT_SUCCESS")
            session_logout(c.session)
            csrf_rotate(c.session)
        return RedirectResponse("index.php", 302)

    user = session_current(c.session)
    if user is None:
        return render("chat/login.j2", csrf_token=c.csrf, selected_domain_id=selected_domain_id, error_message=login_error,
                      prefill_login=prefill_login,
                      domains=[{"id": d.id(), "name": d.config.name, "fqdn": d.config.domain} for d in domains])

    allowed = llm.access.allowed_providers(user.group_ids, user.domain_id, user.owner_key())
    extensions = llm.file_processor.supported_extensions()
    formats = ", ".join(e for e in ["pdf", "docx", "odt", "xlsx", "txt", "csv", "md"] if e in extensions).upper()
    if any(e in extensions for e in ("png", "jpg", "jpeg")):
        formats += ", изображения"
    # Микрофон в браузере доступен только в защищённом контексте (HTTPS или localhost) — иначе кнопка объясняет причину.
    insecure = not c.https and not _local_host(request.url.hostname or "") and llm.dictation.enabled()
    models = list(llm.models.allowed_for(allowed).values())
    return render(
        "chat/app.j2", csrf_token=c.csrf, user=user, providers=list(allowed.values()), models=models,
        many_providers=len({m["provider_id"] for m in models}) > 1, accept=",".join("." + e for e in extensions), anonymization_ready=llm.anonymization_settings.is_configured(),
        extensions=extensions, formats=formats, max_upload_mb=php_int(llm.file_settings.get("max_upload_mb")),
        can_skip_anonymization=llm.access.can_skip_anonymization(user.group_ids, user.owner_key()), insecure=insecure,
    )


def _local_host(host: str) -> bool:
    """localhost и петлевые адреса браузер считает защищённым контекстом и по http."""
    if host == "localhost" or host.endswith(".localhost"):
        return True
    try:
        return ipaddress.ip_address(host.strip("[]")).is_loopback
    except ValueError:
        return False


# ======================= api.php =======================
def _error(status: int, message: str, code: str) -> JSONResponse:
    return json_response({"error": message, "code": code}, status)


def _chat_name(value: str, limit: int = 120) -> str:
    """Короткое пользовательское название (чата, проекта)."""
    value = _CONTROL.sub(" ", value).strip(PHP_WS)
    if value == "":
        raise ChatException("invalid_name", "Укажите название.")
    return value[:limit]


class _Uploads:
    """Загруженные файлы во временных файлах llm_tmp (аналог tmp_name PHP); всё оставшееся удаляется после запроса."""

    def __init__(self):
        self.paths: list[str] = []

    def stage(self, f: UploadFile) -> dict:
        directory = paths.llm_tmp()
        directory.mkdir(parents=True, exist_ok=True)
        fd, path = tempfile.mkstemp(prefix="up", dir=str(directory))
        self.paths.append(path)
        size, error = 0, 0
        with os.fdopen(fd, "wb") as out:
            f.file.seek(0)
            while chunk := f.file.read(1024 * 1024):
                size += len(chunk)
                if size > UPLOAD_MAX_BYTES:
                    size, error = 0, 1   # UPLOAD_ERR_INI_SIZE
                    break
                out.write(chunk)
        return {"tmp_name": path, "name": f.filename or "", "size": size, "error": error}

    def many(self, form: FormData, field: str) -> list[dict]:
        """files[] (несколько файлов под одним именем) или одиночное поле files; пустые поля выбора пропускаются."""
        items = form.getlist(field + "[]")
        if not items:
            single = self.one(form, field)
            return [single] if single is not None else []
        if len(items) > ChatService.MAX_UPLOADS:
            raise ChatException("too_many_files", f"Можно приложить не более {ChatService.MAX_UPLOADS} файлов за раз.")
        return [self.stage(f) for f in items if isinstance(f, UploadFile) and f.filename]

    def one(self, form: FormData, field: str) -> dict | None:
        if form.getlist(field + "[]"):
            raise ChatException("multiple_files", "Можно приложить только один файл.")
        f = form.get(field)
        return self.stage(f) if isinstance(f, UploadFile) and f.filename else None

    def cleanup(self) -> None:
        for p in self.paths:
            if os.path.exists(p):
                os.unlink(p)


@router.api_route("/api.php", methods=["GET", "POST"])
async def api(request: Request):
    c = ctx(request)
    user = session_current(c.session)
    if user is None:
        return _error(401, "Сеанс завершён. Войдите снова.", "unauthorized")
    form = None
    if request.method == "POST":
        # Тело больше предела не разбирается (PHP при превышении post_max_size отбрасывал его целиком).
        if php_int(request.headers.get("content-length", "0")) > POST_MAX_BYTES:
            return _error(413, "Файл превышает допустимый размер.", "file_too_large")
        form = await _form(request)
        token = request.headers.get("x-csrf-token", form.get("csrf"))
        if not csrf_verify(c.session, token if isinstance(token, str) else None):
            return _error(403, "Сеанс устарел, обновите страницу.", "csrf")
    # Данные сессии больше не меняются — сохраняем сейчас: долгий запрос к LLM не перезапишет сессию после ответа.
    session_write_close(c)
    return await run_in_threadpool(_api, request, c, user, form)


def _maintain(app, user: ChatUser, task: dict) -> None:
    """Сводка диалога и состояние проекта после ответа. Соединение запроса к этому моменту закрыто — своё."""
    conn = db.connect(app.state.db_path)
    try:
        LlmModule(conn, transport=app.state.transport).chat_service.maintain_context(user, task)
    except Exception as e:  # noqa: BLE001
        log.warning("context maintenance: %s", type(e).__name__)
    finally:
        conn.close()


def _api(request: Request, c: Ctx, user: ChatUser, form: FormData | None):
    llm = module_for(request)
    action = request.query_params.get("action", "")
    route = f"{request.method} {action}"
    owner = user.owner_key()
    conversations, projects, events, chat = llm.conversations, llm.projects, llm.events, llm.chat_service
    log_name, ip = user.log_name(), c.ip
    form = form if form is not None else FormData()
    post_id = php_int(_s(form, "id", "0"))
    q = request.query_params
    uploads = _Uploads()

    def storage() -> dict:
        return llm.storage_quota.status(owner, user.group_ids)

    def style() -> str | None:
        s = _s(form, "style")
        return s if s not in ("", "0") else None   # (string) … ?: null PHP

    try:
        if route == "GET bootstrap":
            prefs = conversations.preferences(None, owner)
            return json_response({
                "user": {"name": log_name, "groups": user.group_names},
                "providers": list(llm.access.allowed_providers(user.group_ids, user.domain_id, owner).values()),
                "conversations": conversations.list_for_owner(owner),
                "projects": projects.list_for_owner(owner),
                "usage": llm.usage.status(owner, user.group_ids),
                "chat_token_limit": chat.chat_budget(0)["token_limit"],
                "storage": storage(),
                "can_skip_anonymization": llm.access.can_skip_anonymization(user.group_ids, owner),
                "dictation": {"enabled": llm.dictation.enabled(), "max_seconds": llm.dictation.max_seconds()},
                "styles": chat.allowed_styles() or [],   # пустой массив PHP — [] в JSON
                "style_default": llm.chat_settings.get("style_default"),
                "prefs": {"style": prefs["style"], "instructions": prefs["personal"]},
            })

        if route == "POST user_prefs":
            return json_response(chat.set_user_preferences(user, ip, style(), _s(form, "instructions")))

        if route == "POST conversation_prefs":
            return json_response(chat.set_conversation_preferences(user, ip, post_id, style(), _s(form, "instructions")))

        if route == "POST dictation_ticket":
            # Голосовой ввод: одноразовый билет на 60 с для WebSocket-шлюза ASR (вход по сессии и CSRF — здесь).
            dictation = llm.dictation
            if not dictation.enabled():
                return _error(403, "Голосовой ввод отключён администратором.", "dictation_disabled")
            if not dictation.asr_ready():
                return _error(503, "Распознавание речи сейчас недоступно. Введите текст вручную.", "asr_unavailable")
            events.user(ip, log_name, _E.OP_CHAT, "DICTATION_START")
            return json_response({"ticket": dictation.issue(owner), "path": "ws/dictation", "max_seconds": dictation.max_seconds()})

        if route == "GET storage":
            return json_response({"storage": storage()})

        if route == "POST bulk_delete_conversations":
            ids = [php_int(v) for v in (form.getlist("ids[]") or form.getlist("ids")) if isinstance(v, str)]
            deleted = sum(1 for i in ids if conversations.delete(i, owner))
            if deleted > 0:
                events.user(ip, log_name, _E.OP_CHAT, "CONVERSATION_BULK_DELETE_SUCCESS", {"count": deleted})
            return json_response({"ok": True, "deleted": deleted, "conversations": conversations.list_for_owner(owner), "storage": storage()})

        if route == "POST delete_all_conversations":
            deleted = conversations.delete_all_for_owner(owner)
            if deleted > 0:
                events.user(ip, log_name, _E.OP_CHAT, "CONVERSATION_BULK_DELETE_SUCCESS", {"count": deleted, "all": True})
            return json_response({"ok": True, "deleted": deleted, "conversations": conversations.list_for_owner(owner), "storage": storage()})

        if route == "GET conversation":
            cid = php_int(q.get("id", "0"))
            conv = conversations.find_owned(cid, owner)
            if conv is None:
                return _error(404, "Диалог не найден.", "not_found")
            return json_response({
                "conversation": {
                    "id": int(conv["id"]), "title": conv["title"],
                    "provider_id": int(conv["provider_id"]) if conv["provider_id"] is not None else None,
                    "project_id": int(conv["project_id"]) if conv["project_id"] is not None else None,
                    "model_id": int(conv["model_id"]) if conv["model_id"] is not None else None,
                    "pinned": bool(conv["pinned"]),
                    **chat.chat_budget(int(conv["tokens_spent"] or 0)),
                    "style": conv["style"],
                    "instructions": compact.unpack(conv["instructions_z"]),
                },
                "messages": conversations.messages(cid, owner),
            })

        if route == "GET search":
            return json_response({"results": conversations.search(owner, q.get("q", ""))})

        if route == "POST send":
            # OCR, обезличивание и ответ модели могут занимать минуты; таймауты шагов заданы в админке.
            cid = php_int(_s(form, "conversation_id", "0"))
            pid = php_int(_s(form, "project_id", "0"))
            mid = php_int(_s(form, "model_id", "0"))
            result = chat.send(user, ip, cid if cid > 0 else None, php_int(_s(form, "provider_id", "0")), _s(form, "text"),
                               uploads.many(form, "files"), pid if pid > 0 else None, _s(form, "skip_anonymization") not in ("", "0"),
                               mid if mid > 0 else None)
            maintenance = result.pop("maintenance", None)
            if maintenance is None:
                return json_response(result)
            # Ответ уходит сразу; сводка диалога и состояние проекта обновляются после — не задерживая его.
            return JSONResponse(result, background=BackgroundTask(_maintain, request.app, user, maintenance))

        if route == "POST conversation_update":
            if conversations.find_owned(post_id, owner) is None:
                return _error(404, "Диалог не найден.", "not_found")
            if _has(form, "pinned"):
                conversations.set_pinned(post_id, owner, _s(form, "pinned") == "1")
            if _has(form, "title"):
                conversations.rename(post_id, owner, _chat_name(_s(form, "title")))
            if _has(form, "project_id"):
                target = php_int(_s(form, "project_id"))
                if target > 0 and projects.find_owned(target, owner) is None:
                    return _error(404, "Проект не найден.", "not_found")
                conversations.move_to_project(post_id, owner, target if target > 0 else None)
            return json_response({"ok": True, "conversations": conversations.list_for_owner(owner)})

        if route == "POST delete_conversation":
            if not conversations.delete(post_id, owner):
                return _error(404, "Диалог не найден.", "not_found")
            events.user(ip, log_name, _E.OP_CHAT, "CONVERSATION_DELETE_SUCCESS", {"conversation_id": post_id})
            return json_response({"ok": True})

        if route == "GET project":
            pid = php_int(q.get("id", "0"))
            project = projects.find_owned(pid, owner)
            if project is None:
                return _error(404, "Проект не найден.", "not_found")
            parts = projects.context_parts(pid, owner)
            settings = llm.chat_settings
            return json_response({
                "project": {**project, "description": parts["description"], "state": parts["state"], "state_updated_at": parts["state_updated_at"]},
                "memory_limit": ChatService.MEMORY_MAX_CHARS,
                "context_tokens": ChatService.project_context_tokens(parts),
                "instructions": projects.instructions(pid, owner),
                "instructions_limit": php_int(settings.get("project_instruction_max_chars")),
                "instructions_max_blocks": php_int(settings.get("project_instructions_max_blocks")),
                "sources": projects.sources(pid, owner),
                "sources_limit": php_int(settings.get("project_sources_max_chars")),
                "sources_max_files": php_int(settings.get("project_sources_max_files")),
                "conversations": [x for x in conversations.list_for_owner(owner) if x["project_id"] == pid],
            })

        if route == "POST project_instruction_add":
            instruction = chat.add_project_instruction(user, ip, php_int(_s(form, "project_id", "0")), _s(form, "title"), _s(form, "text"))
            return json_response({"instruction": instruction})

        if route == "POST project_create":
            pid = projects.create(owner, _chat_name(_s(form, "name"), 80))
            events.user(ip, log_name, _E.OP_CHAT, "PROJECT_CREATE_SUCCESS", {"project_id": pid})
            return json_response({"id": pid, "projects": projects.list_for_owner(owner)})

        if route == "POST project_update":
            if projects.find_owned(post_id, owner) is None:
                return _error(404, "Проект не найден.", "not_found")
            response: dict = {"ok": True}
            if _has(form, "name"):
                projects.rename(post_id, owner, _chat_name(_s(form, "name"), 80))
            if _has(form, "pinned"):
                projects.set_pinned(post_id, owner, _s(form, "pinned") == "1")
            if _has(form, "description"):
                # Описание уходит в модель (слой проекта) — обезличивается.
                response["description"] = chat.set_project_description(user, ip, post_id, _s(form, "description"))
            if _has(form, "instructions"):
                response["instructions"] = chat.set_project_instructions(user, ip, post_id, _s(form, "instructions"))
            if _has(form, "memory"):
                response["memory"] = chat.set_project_memory(user, ip, post_id, _s(form, "memory"))
            if _has(form, "settings"):
                response["settings"] = chat.set_project_settings(user, ip, post_id, _s(form, "settings"))
            response["projects"] = projects.list_for_owner(owner)
            return json_response(response)

        if route == "POST project_delete":
            if not projects.delete(post_id, owner):
                return _error(404, "Проект не найден.", "not_found")
            events.user(ip, log_name, _E.OP_CHAT, "PROJECT_DELETE_SUCCESS", {"project_id": post_id})
            return json_response({"ok": True, "projects": projects.list_for_owner(owner), "conversations": conversations.list_for_owner(owner)})

        if route == "POST project_source_add":
            source = chat.add_project_source(user, ip, php_int(_s(form, "project_id", "0")), _s(form, "title"), _s(form, "text"),
                                             uploads.one(form, "file"))
            return json_response({"source": source})

        if route == "POST project_source_delete":
            if not projects.delete_source(post_id, owner):
                return _error(404, "Источник не найден.", "not_found")
            events.user(ip, log_name, _E.OP_CHAT, "PROJECT_SOURCE_DELETE_SUCCESS", {"source_id": post_id})
            return json_response({"ok": True})

        if route == "GET project_source_text":
            text = projects.source_text(php_int(q.get("id", "0")), owner)
            if text is None:
                return _error(404, "Источник не найден.", "not_found")
            return json_response({"text": text})

        return _error(404, "Неизвестное действие.", "unknown_action")
    except ChatException as e:
        return _error(e.http_status, e.user_message, e.error_code)
    except Exception as e:  # noqa: BLE001
        frame = (traceback.extract_tb(e.__traceback__) or [None])[-1]
        where = f" в {frame.filename}:{frame.lineno}" if frame else ""
        llm.diagnostics.error("php_error", type(e).__name__, f"{e}{where} (chat api {action})", {"user": log_name}, ip)
        return _error(500, "Внутренняя ошибка сервиса. Запрос не выполнен.", "internal")
    finally:
        uploads.cleanup()
