"""Каталоги приложения. Данные — в APP_VAR_DIR (в контейнере — отдельный том вне кода и образа),
иначе <корень>/var, как у версии 1.x: существующие установки находят БД и мастер-ключ на прежнем месте."""

from __future__ import annotations

import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def var() -> Path:
    env = os.environ.get("APP_VAR_DIR", "")
    return Path(env.rstrip("/")) if env else ROOT / "var"


def data() -> Path:
    return var() / "data"


def certs() -> Path:
    return var() / "certs"


def logs() -> Path:
    return var() / "logs"


def llm_tmp() -> Path:
    return var() / "tmp" / "llm"


def database() -> Path:
    return data() / "app.sqlite"


def secret_key_file() -> Path:
    return data() / "master.key"


def installed_marker() -> Path:
    return data() / ".installed"


def static() -> Path:
    return ROOT / "public" / "assets"


def ensure_directories() -> None:
    for d in (var(), data(), certs(), logs()):
        d.mkdir(mode=0o770, parents=True, exist_ok=True)
    llm_tmp().mkdir(mode=0o700, parents=True, exist_ok=True)
