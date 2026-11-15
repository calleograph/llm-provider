"""Общие фикстуры: каталог данных во временной папке, БД со схемой, мастер-ключ для SecretBox."""

from __future__ import annotations

import base64
import sqlite3

import pytest

from llmp import db, secretbox


@pytest.fixture(autouse=True)
def var_dir(tmp_path, monkeypatch):
    monkeypatch.setenv("APP_VAR_DIR", str(tmp_path / "var"))
    monkeypatch.setenv("APP_MASTER_KEY", base64.b64encode(b"k" * 32).decode())
    secretbox.reset_cache()
    yield tmp_path / "var"
    secretbox.reset_cache()


@pytest.fixture
def conn() -> sqlite3.Connection:
    c = db.connect(":memory:", migrate_schema=True)
    yield c
    c.close()
