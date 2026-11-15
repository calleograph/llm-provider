"""Помощник тестов разделов админки (нейросети, обезличивание, почта): AdminPage без HTTP-сервера."""

from __future__ import annotations

from urllib.parse import urlencode

from starlette.datastructures import FormData
from starlette.requests import Request

from llmp.module import LlmModule
from llmp.security import Session
from llmp.settings import AppSettings
from llmp.web.admin.base import AdminPage
from llmp.web.core import Ctx


class AdminBench:
    def __init__(self, conn, transport=None):
        self.conn = conn
        self.session = Session(None)
        self.ctx = Ctx(conn=conn, session=self.session, settings=AppSettings(conn), ip="10.0.0.5", https=False)
        self.transport = transport

    def page(self, section: str, action: str = "", form: dict | list | None = None, csrf: bool = True,
             query: dict | None = None) -> AdminPage:
        """form None — GET; иначе POST с полями (список пар — для повторяющихся имён)."""
        q = {"section": section, **({"action": action} if action else {}), **(query or {})}
        scope = {"type": "http", "method": "POST" if form is not None else "GET", "path": "/admin/index.php",
                 "query_string": urlencode(q).encode(), "headers": []}
        items = list(form.items()) if isinstance(form, dict) else list(form or [])
        if form is not None and csrf:
            items.append(("csrf", self.ctx.csrf))
        return AdminPage(request=Request(scope), ctx=self.ctx, module=LlmModule(self.conn, transport=self.transport),
                         section=section, method=scope["method"], action=action,
                         form=FormData(items) if form is not None else None, admin="admin", admin_method="local")

    def run(self, module, action: str = "", form=None, csrf: bool = True, query: dict | None = None) -> tuple[AdminPage, str]:
        page = self.page(module.__name__.rsplit(".", 1)[-1], action, form, csrf, query)
        return page, module.handle(page)

    def audit(self) -> list[dict]:
        return [dict(r) for r in self.conn.execute("SELECT action, success, details FROM audit_log ORDER BY id")]
