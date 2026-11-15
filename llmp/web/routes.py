"""Подключение маршрутов: чат (index.php, api.php) и админка (admin/…)."""

from __future__ import annotations

from fastapi import FastAPI


def register(app: FastAPI) -> None:
    from .admin.routes import router as admin_router
    from .chat_routes import router as chat_router

    app.include_router(chat_router)
    app.include_router(admin_router)
