"""Маршруты админки (перенос public/admin/index.php и setup.php): admin/, admin/index.php?section=…, admin/setup.php.

Вход (локальный аварийный или доменный администратор), выход, таймаут бездействия, белый список разделов и вызов
`llmp.web.admin.sections.<имя>.handle(page)` (контракт — base.py). По http:// панель работает так же, как по https://:
без перенаправления и предупреждений; кука сессии получает Secure только при HTTPS (core.ContextMiddleware)."""

from __future__ import annotations

import contextlib
import hmac
import importlib
import json
import logging
import traceback

from fastapi import APIRouter, Request
from fastapi.responses import HTMLResponse, RedirectResponse, Response
from markupsafe import Markup

from ... import paths
from ...auth import AdminSession, DomainAdminAuthenticator, LocalAdminRepository
from ...certs import CaBundleBuilder, CertificateRepository
from ...chat.settings import php_int
from ...ldap.client import LdapException
from ...ldap.config import LdapConfigRepository
from ...logs import AuditLogger
from ...module import LlmModule
from ...security import DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS, RateLimiter, Session, csrf_rotate, csrf_verify
from ..core import ctx, render
from .base import FORM_STALE, AdminPage
from .settings_index import SETTINGS_INDEX

router = APIRouter()
log = logging.getLogger("llmp")

SECTION_GROUPS: dict[str, dict[str, str]] = {
    "Обзор": {"status": "Состояние", "services": "Состояние сервисов"},
    "LDAP": {"ldap": "LDAP-подключения", "access": "Доступ к админ-панели"},
    "Доступ к чату": {"chat_access": "Доступ к чату"},
    "Нейросети": {"llm_providers": "Подключения LLM API", "llm_models": "Модели и резервирование", "llm_limits": "Категории лимитов"},
    "Пользователи": {"llm_users": "Пользователи чата", "telemetry": "Аудит и DLP"},
    "Система": {"updates": "Обновление", "anonymization": "Обезличивание", "mail": "Почта и уведомления", "maintenance": "Обслуживание и состояние",
                "log": "Журналы", "system": "Настройки"},
}
SECTION_LABELS = {k: v for group in SECTION_GROUPS.values() for k, v in group.items()}
SECTIONS = ("status", "services", "ldap", "access", "chat_access", "llm_providers", "llm_models", "llm_limits", "llm_users",
            "telemetry", "anonymization", "mail", "log", "system", "maintenance", "updates")


def _settings_index_json() -> Markup:
    entries = [e | {"sectionLabel": SECTION_LABELS[e["section"]]}
               for e in SETTINGS_INDEX if e["section"] in SECTION_LABELS]
    # формат json_encode(JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); «</» экранируется — содержимое внутри <script>
    return Markup(json.dumps(entries, ensure_ascii=False, separators=(",", ":")).replace("</", "<\\/"))


def layout(active: str, title: str, admin: str | None, body: str, flash_success: str | None = None,
           flash_error: str | None = None, status: int = 200) -> HTMLResponse:
    return render("admin/layout.j2", status, active_section=active, page_title=title, admin_username=admin, body=Markup(body),
                  flash_success=flash_success, flash_error=flash_error, section_groups=SECTION_GROUPS,
                  settings_index_json=_settings_index_json())


def _redirect(url: str) -> RedirectResponse:
    return RedirectResponse(url, status_code=302)


def _field(form, name: str, default: str = "") -> str:
    v = form.get(name, default) if form is not None else default
    return v if isinstance(v, str) else default


def _revive(s: Session) -> None:
    """Сессия уничтожена (таймаут, смена пароля) — новая пустая, чтобы токен CSRF формы входа сохранился."""
    if s.destroyed:
        s.destroyed = False
        s.regenerate()


def _retry_after(limiter: RateLimiter, bucket: str) -> int:
    """Задержка без учёта только что записанной попытки — пороги те же, что у RateLimiter.retry_after_seconds."""
    n = limiter.recent_attempt_count(bucket) - 1
    m = limiter.MAX_ATTEMPTS_BEFORE_DELAY
    return 0 if n < m else min(300, 2 ** (n - m + 1))


def _section_body_placeholder() -> str:
    return '<div class="alert alert-warn">Раздел переносится на новую версию и временно недоступен.</div>'


@router.get("/admin")
def admin_no_slash():
    return _redirect("admin/")


@router.api_route("/admin/", methods=["GET", "POST"])
@router.api_route("/admin/index.php", methods=["GET", "POST"])
async def admin_index(request: Request):
    c = ctx(request)
    s = c.session
    admins = LocalAdminRepository(c.conn)
    audit = AuditLogger(c.conn)
    action = request.query_params.get("action", "").strip().lower()
    form = await request.form() if request.method == "POST" else None

    # До создания локального аварийного администратора доступна только форма первого запуска.
    if not admins.exists():
        return _redirect("setup.php")

    login_error: str | None = None
    login_retry: int | None = None
    if request.method == "POST" and action == "login":
        method = "local" if _field(form, "method", "domain") == "local" else "domain"
        username = _field(form, "username").strip(" \t\n\r\0\x0b")
        password = _field(form, "password")
        bucket = "admin_login:" + c.ip
        limiter = RateLimiter(c.conn)
        if not csrf_verify(s, _field(form, "csrf") or None):
            login_error = FORM_STALE
        elif username == "" or password == "":
            login_error = "Введите логин и пароль."
        else:
            # попытка записывается до проверки: параллельные запросы не проскочат мимо счётчика
            limiter.record_attempt(bucket)
            retry = _retry_after(limiter, bucket)
            if retry > 0:
                login_error, login_retry = "Слишком много попыток входа. Повторите позже.", retry
            else:
                ok = False
                try:
                    if method == "local":
                        ok = admins.verify_password(username, password)
                    else:
                        config = LdapConfigRepository(c.conn).find_active()
                        if config is not None:
                            ok = DomainAdminAuthenticator(config, CaBundleBuilder(CertificateRepository(c.conn))).authenticate(username, password)
                except (LdapException, RuntimeError, OSError):
                    ok = False
                if ok:
                    limiter.reset_on_success(bucket)
                    AdminSession.login(s, username, method, admins.credential_stamp() if method == "local" else None)
                    audit.log(username, c.ip, "admin_login", True, "method=" + method)
                    csrf_rotate(s)
                    return _redirect("index.php")
                login_error = "Неверный логин или пароль."
                audit.log(username, c.ip, "admin_login", False, "method=" + method)

    if action == "logout":
        user = AdminSession.username(s)
        if user is not None:
            audit.log(user, c.ip, "admin_logout", True)
        AdminSession.logout(s)
        return _redirect("index.php")

    minutes = php_int(c.settings.get("admin_idle_timeout_minutes", str(DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS // 60)))
    if not AdminSession.is_logged_in(s, minutes * 60 if minutes > 0 else None, admins.credential_stamp()):
        _revive(s)
        # без активного подключения AD доменный вход невозможен — по умолчанию предлагается локальный администратор
        default_method = "domain" if LdapConfigRepository(c.conn).find_active() else "local"
        return render("admin/login.j2", csrf=c.csrf, error_message=login_error, retry_after=login_retry,
                      default_method=(form.get("method") if form is not None and form.get("method") in ("local", "domain") else default_method))

    section = request.query_params.get("section", "status")
    if section not in SECTIONS:
        section = "status"
    module = LlmModule(c.conn, c.settings)
    admin = AdminSession.username(s) or ""
    page = AdminPage(request=request, ctx=c, module=module, section=section, method=request.method, action=action,
                     form=form, admin=admin, admin_method=AdminSession.method(s) or "")
    try:
        name = f"{__package__}.sections.{section}"
        try:
            mod = importlib.import_module(name)
        except ModuleNotFoundError as exc:
            if exc.name != name:
                raise
            mod = None   # раздел ещё не перенесён
        result = mod.handle(page) if mod is not None else _section_body_placeholder()
    except Exception as exc:  # noqa: BLE001
        # Ошибка раздела — не голая 500-я: причина в диагностический журнал, администратору понятное сообщение.
        log.exception("admin section %s failed", section)
        frame = traceback.extract_tb(exc.__traceback__)[-1]
        module.diagnostics.error("php_error", type(exc).__name__, f"{exc} в {frame.filename}:{frame.lineno} (раздел {section})",
                                 {"admin": admin}, c.ip)
        return layout(section, "Ошибка", admin, "", None,
                      "Произошла непредвиденная ошибка при обработке раздела. Подробности записаны в диагностический журнал (раздел «Журнал»).",
                      status=500)
    if isinstance(result, Response):
        return result
    return layout(section, page.page_title or SECTION_LABELS[section], admin, result, page.flash_success, page.flash_error)


@router.api_route("/admin/setup.php", methods=["GET", "POST"])
async def admin_setup(request: Request):
    c = ctx(request)
    admins = LocalAdminRepository(c.conn)
    if admins.exists():
        return _redirect("index.php")
    error: str | None = None
    if request.method == "POST":
        form = await request.form()
        password, confirmation = _field(form, "password"), _field(form, "password_confirm")
        bucket = "admin_setup:" + c.ip
        limiter = RateLimiter(c.conn)
        audit = AuditLogger(c.conn)
        if not csrf_verify(c.session, _field(form, "csrf") or None):
            error = "Форма устарела. Обновите страницу и попробуйте снова."
        elif limiter.retry_after_seconds(bucket) > 0:
            error = "Слишком много попыток. Повторите позже."
        elif len(password) < 14:
            error = "Пароль должен содержать не менее 14 символов."
        elif not hmac.compare_digest(password.encode(), confirmation.encode()):
            error = "Пароль и подтверждение не совпадают."
        else:
            limiter.record_attempt(bucket)
            try:
                admins.create("admin", password)   # id = 1 (CHECK) — параллельный второй запрос получит ошибку
                with contextlib.suppress(OSError):   # маркер установки — не повод отменять созданную учётку
                    paths.installed_marker().touch()
                limiter.reset_on_success(bucket)
                audit.log("admin", c.ip, "local_admin_bootstrap", True)
                AdminSession.login(c.session, "admin", "local", admins.credential_stamp())
                csrf_rotate(c.session)
                return _redirect("index.php?section=status&first_setup=1")
            except Exception:  # noqa: BLE001
                audit.log("system", c.ip, "local_admin_bootstrap", False)
                error = "Не удалось завершить первичную настройку. Проверьте права записи в var/data."
    return render("admin/setup.j2", csrf=c.csrf, error_message=error)

