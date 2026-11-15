"""Помощник тестов разделов админки: AdminPage без HTTP-сервера (Request из scope, сессия, FormData)."""

from __future__ import annotations

import io
import sqlite3

from starlette.datastructures import FormData, Headers, UploadFile
from starlette.requests import Request

from llmp.module import LlmModule
from llmp.security import Session, csrf_token
from llmp.settings import AppSettings
from llmp.web.admin.base import AdminPage
from llmp.web.core import Ctx

ADMIN = "admin"
IP = "10.0.0.1"


def session() -> Session:
    s = Session("s" * 64)
    csrf_token(s)
    return s


def upload(name: str, content: bytes) -> UploadFile:
    return UploadFile(io.BytesIO(content), size=len(content), filename=name, headers=Headers({"content-type": "application/octet-stream"}))


def page(conn: sqlite3.Connection, section: str, action: str = "", form: dict | list | None = None, *, sess: Session | None = None,
         csrf: bool = True, method: str | None = None, query: str = "") -> AdminPage:
    """form — dict или список пар (для повторяющихся ключей); csrf=True подставляет верный токен."""
    sess = sess if sess is not None else session()
    qs = f"section={section}" + (f"&action={action}" if action else "") + (f"&{query}" if query else "")
    scope = {"type": "http", "method": method or ("POST" if form is not None else "GET"), "path": "/admin/index.php",
             "query_string": qs.encode(), "headers": [], "client": (IP, 1234), "scheme": "http", "server": ("test", 80)}
    request = Request(scope)
    data = None
    if form is not None:
        items = list(form.items()) if isinstance(form, dict) else list(form)
        if csrf:
            items.append(("csrf", sess["csrf_token"]))
        data = FormData(items)
    settings = AppSettings(conn)
    ctx = Ctx(conn=conn, session=sess, settings=settings, ip=IP, https=False)
    return AdminPage(request=request, ctx=ctx, module=LlmModule(conn, settings), section=section, method=scope["method"],
                     action=action, form=data, admin=ADMIN, admin_method="local")


def audit(conn: sqlite3.Connection) -> list[dict]:
    return [dict(r) for r in conn.execute("SELECT * FROM audit_log ORDER BY id")]
