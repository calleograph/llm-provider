"""Точка входа Uvicorn: `uvicorn llmp.web.main:app`."""

from .app import create_app

app = create_app()
