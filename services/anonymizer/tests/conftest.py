from __future__ import annotations

import os
from pathlib import Path

# Модели NER (navec + slovnet): кэш вне репозитория; при первом запуске — загрузка колеса natasha с PyPI с проверкой SHA-256.
os.environ.setdefault("PRIVACY_NLP_DIR", os.path.join(os.path.expanduser("~"), ".cache", "llm-provider-nlp"))

from app.privacy import fetch_models as _fm  # noqa: E402

if not _fm.ready(Path(os.environ["PRIVACY_NLP_DIR"])):
    _fm.fetch(Path(os.environ["PRIVACY_NLP_DIR"]), Path(os.environ["PRIVACY_NLP_WHEEL"]) if os.environ.get("PRIVACY_NLP_WHEEL") else None)

import pytest  # noqa: E402

TOKEN = "test-internal-token"
os.environ["INTERNAL_API_TOKEN"] = TOKEN


@pytest.fixture(scope="session")
def client():
    from fastapi.testclient import TestClient

    from app import main

    main.TOKEN = TOKEN
    main._warm()
    assert main._state["ready"], main._state["error"]
    with TestClient(main.app, headers={"X-Internal-Token": TOKEN}) as c:
        yield c


def lab(client, text, profile="basic", participants=(), settings=None):
    """Проверка текста как в лаборатории администратора: один текст, подробный ответ (замены, спорные кандидаты)."""
    r = client.post("/v1/anonymize", json={"texts": [text], "profile": profile, "participants": list(participants), "settings": settings or {}, "detail": True})
    if r.status_code != 200:
        return r
    out = r.json()
    out["text"] = out["texts"][0]
    return out


def lab_file(client, name, data, profile="basic"):
    r = client.post("/v1/extract", params={"name": name}, content=data)
    if r.status_code != 200:
        return r
    ext = r.json()
    out = lab(client, ext["text"], profile)
    out["info"] = ext["info"]
    return out
