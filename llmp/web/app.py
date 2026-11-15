"""Приложение FastAPI. Адреса прежние (index.php, api.php, admin/…, healthz.php): браузерный код и закладки
работают без изменений. Код и каталог данных по HTTP не отдаются ни при какой настройке веб-сервера — наружу
смотрят только маршруты приложения и каталог статики public/assets."""

from __future__ import annotations

import os
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.staticfiles import StaticFiles

from .. import db, paths
from .core import ContextMiddleware, ctx, json_response, plain


def create_app(db_path: Path | str | None = None) -> FastAPI:
    app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
    app.state.db_path = str(db_path or paths.database())
    app.state.transport = None   # HTTP-транспорт внешних сервисов; тесты подставляют FakeHttpTransport
    if app.state.db_path != ":memory:":
        paths.ensure_directories()
    conn = db.connect(app.state.db_path, migrate_schema=True)   # миграции — один раз при старте процесса
    conn.close()

    app.add_middleware(ContextMiddleware)
    app.mount("/assets", StaticFiles(directory=str(paths.static())), name="assets")

    @app.get("/healthz.php")
    @app.get("/healthz")
    def healthz(request: Request):
        """Только с localhost или через доверенный прокси — снаружи не нужен."""
        c = ctx(request)
        remote = request.client.host if request.client else ""
        if remote not in ("127.0.0.1", "::1") and c.ip == remote:
            return plain("forbidden", 403)
        checks = {"python": True, "database_reachable": True, "data_writable": os.access(paths.data(), os.W_OK)}
        try:
            c.conn.execute("SELECT 1")
        except Exception:  # noqa: BLE001
            checks["database_reachable"] = False
        ok = all(checks.values())
        return json_response({"status": "ok" if ok else "degraded", "checks": checks}, 200 if ok else 503)

    from . import routes  # noqa: E402  — маршруты чата и админки
    routes.register(app)
    return app
