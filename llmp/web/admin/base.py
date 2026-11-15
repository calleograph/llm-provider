"""Контракт разделов админки (перенос public/admin/sections/*.php).

Диспетчер (llmp/web/admin/routes.py) проверяет вход, разбирает форму и вызывает
`llmp.web.admin.sections.<имя>.handle(page: AdminPage) -> str | Response`:
  * str — HTML тела раздела; диспетчер оборачивает его в общий макет (шапка, меню, поиск, flash-сообщения);
  * Response — готовый ответ (перенаправление, выгрузка файла).
Раздел сам проверяет CSRF у своих POST-действий (page.csrf_ok()) и пишет page.flash_success / page.flash_error,
как PHP-раздел писал $flashSuccess / $flashError. Шаблон раздела — templates/admin/sections/<имя>.j2."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from fastapi import Request
from starlette.datastructures import FormData, UploadFile

from ...module import LlmModule
from ..core import Ctx, templates

FORM_STALE = "Форма устарела, обновите страницу и попробуйте снова."


@dataclass
class AdminPage:
    request: Request
    ctx: Ctx
    module: LlmModule
    section: str
    method: str                      # GET | POST
    action: str                      # ?action=…
    form: FormData | None            # разобранная форма POST (None для GET)
    admin: str                       # имя вошедшего администратора
    admin_method: str                # local | domain
    flash_success: str | None = None
    flash_error: str | None = None
    page_title: str = ""
    extra: dict[str, Any] = field(default_factory=dict)

    # ---- форма ----
    def post(self, name: str, default: str = "") -> str:
        if self.form is None:
            return default
        v = self.form.get(name, default)
        return v if isinstance(v, str) else default

    def post_list(self, name: str) -> list[str]:
        """Поля вида name[] (и name) — как массивы PHP."""
        if self.form is None:
            return []
        return [v for v in self.form.getlist(name + "[]") + self.form.getlist(name) if isinstance(v, str)]

    def file(self, name: str) -> UploadFile | None:
        if self.form is None:
            return None
        v = self.form.get(name)
        return v if isinstance(v, UploadFile) and v.filename else None

    def query(self, name: str, default: str = "") -> str:
        return self.request.query_params.get(name, default)

    def is_post(self, *actions: str) -> bool:
        return self.method == "POST" and (not actions or self.action in actions)

    def csrf_ok(self) -> bool:
        """POST-поле csrf (или GET csrf для выгрузок, как в PHP)."""
        from ...security import csrf_verify
        token = self.post("csrf") if self.form is not None else self.query("csrf")
        return csrf_verify(self.ctx.session, token or self.query("csrf"))

    @property
    def ip(self) -> str:
        return self.ctx.ip

    @property
    def csrf(self) -> str:
        return self.ctx.csrf

    def render(self, template: str, **context: Any) -> str:
        """Шаблон раздела с общими переменными: page, csrf, flash, admin."""
        return templates.get_template(template).render(page=self, csrf=self.csrf, **context)
