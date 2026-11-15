"""Общее для всех страниц: соединение с БД и сессия на запрос, заголовки безопасности, шаблоны, адрес клиента.

Каждый запрос получает Ctx (request.state.ctx): conn, session, settings, ip. Сессия сохраняется после ответа;
кука ADPWD_SESSION получает Secure только при HTTPS — по http://IP:порт вход тоже работает."""

from __future__ import annotations

import hashlib
import logging
import sqlite3
from contextvars import ContextVar
from dataclasses import dataclass, field
from html import escape
from pathlib import Path

from fastapi import Request
from fastapi.responses import HTMLResponse, JSONResponse, Response
from jinja2 import Environment, FileSystemLoader, select_autoescape
from markupsafe import Markup
from starlette.middleware.base import BaseHTTPMiddleware

from .. import db, paths
from ..module import LlmModule
from ..security import COOKIE, Session, SessionStore, client_ip, csrf_token, is_https
from ..settings import DEFAULT_THEME, THEMES, AppSettings

log = logging.getLogger("llmp")

SECURITY_HEADERS = {
    "Content-Security-Policy": "default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
    "X-Frame-Options": "DENY",
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "no-referrer",
    "Cache-Control": "no-store, no-cache, must-revalidate",
    "Pragma": "no-cache",
}


# настройки запроса — для data-site-theme на <html> любой страницы; читаются при отрисовке, после сохранения формы
_settings: ContextVar[AppSettings | None] = ContextVar("settings", default=None)


def site_theme() -> str:
    s = _settings.get()
    return s.site_theme() if s is not None else DEFAULT_THEME


@dataclass
class Ctx:
    conn: sqlite3.Connection
    session: Session
    settings: AppSettings
    ip: str
    https: bool
    extra: dict = field(default_factory=dict)

    @property
    def csrf(self) -> str:
        return csrf_token(self.session)


def ctx(request: Request) -> Ctx:
    return request.state.ctx


class ContextMiddleware(BaseHTTPMiddleware):
    """Соединение с БД, сессия и заголовки безопасности. Статика (/assets) обслуживается без БД."""

    async def dispatch(self, request: Request, call_next):
        if request.url.path.startswith("/assets/"):
            return await call_next(request)
        conn = db.connect(request.app.state.db_path)
        try:
            settings = AppSettings(conn)
            _settings.set(settings)
            store = SessionStore(conn)
            session = store.load(request.cookies.get(COOKIE))
            remote = request.client.host if request.client else None
            request.state.ctx = Ctx(
                conn=conn,
                session=session,
                settings=settings,
                ip=client_ip(remote, request.headers.get("x-forwarded-for"), settings.trusted_proxies()),
                https=is_https(request.url.scheme, request.headers.get("x-forwarded-proto")),
            )
            response = await call_next(request)
            c = request.state.ctx
            sid = c.extra["sid"] if "sid" in c.extra else store.save(session)   # уже сохранена session_write_close()
            if sid is None:
                if request.cookies.get(COOKIE):
                    response.delete_cookie(COOKIE, path="/")
            elif sid != request.cookies.get(COOKIE):
                response.set_cookie(COOKIE, sid, path="/", httponly=True, samesite="strict", secure=request.state.ctx.https)
            for k, v in SECURITY_HEADERS.items():
                response.headers.setdefault(k, v)
            return response
        finally:
            conn.close()


def session_write_close(c: Ctx) -> None:
    """Сохранить сессию сейчас и больше не трогать (session_write_close PHP): долгий запрос к LLM не перезапишет
    после ответа то, что за это время изменил другой запрос (выход, смена CSRF)."""
    c.extra["sid"] = SessionStore(c.conn).save(c.session)


def module_for(request: Request, conn: sqlite3.Connection | None = None) -> LlmModule:
    """Сервисы на соединение запроса; app.state.transport — подмена HTTP-транспорта в тестах."""
    return LlmModule(conn or ctx(request).conn, transport=request.app.state.transport)


# ---- шаблоны ----
def asset(path: str) -> str:
    """Версия статики в адресе (?v=…): после обновления браузер не держит прежние стили и скрипты."""
    rel = path.lstrip("./").lstrip("/")
    while rel.startswith("../"):
        rel = rel[3:]
    f = paths.ROOT / "public" / rel
    if f.is_file():
        st = f.stat()
        # формат версии PHP: md5(filemtime . filesize), первые 10 знаков
        return path + "?v=" + hashlib.md5(f"{int(st.st_mtime)}{st.st_size}".encode()).hexdigest()[:10]
    return path


def setting_code(code: str) -> Markup:
    return Markup('<span class="field-code">') + escape(code) + Markup("</span>")


templates = Environment(
    loader=FileSystemLoader(str(Path(__file__).resolve().parent / "templates")),
    autoescape=select_autoescape(["html", "j2"]),
    trim_blocks=True,
    lstrip_blocks=True,
)
templates.globals.update(asset=asset, setting_code=setting_code, themes=THEMES, site_theme=site_theme)


def render(name: str, status: int = 200, **context) -> HTMLResponse:
    return HTMLResponse(templates.get_template(name).render(**context), status_code=status)


def json_response(data: dict, status: int = 200) -> JSONResponse:
    return JSONResponse(data, status_code=status)


def plain(text: str, status: int = 200) -> Response:
    return Response(text, status_code=status, media_type="text/plain; charset=utf-8")
