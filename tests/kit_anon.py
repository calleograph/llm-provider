"""Помощники тестов обезличивания и обработки файлов: настройки поверх БД со схемой, сборка ZIP-пакетов."""

from __future__ import annotations

import json
import zipfile

from llmp.anon.settings import AnonymizationSettings
from llmp.files.settings import FileProcessingSettings
from llmp.http import HttpResponse
from llmp.settings import AppSettings

ANON_HOST = "anonymizer:8091"

PNG = b"\x89PNG\r\n\x1a\n" + b"\0" * 32


def anon_settings(conn) -> AnonymizationSettings:
    return AnonymizationSettings(AppSettings(conn))


def file_settings(conn) -> FileProcessingSettings:
    return FileProcessingSettings(AppSettings(conn))


def make_zip(path, entries: dict[str, str | bytes]) -> str:
    """ODT: несжатый mimetype первым — по нему узнаётся формат."""
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        for name, content in entries.items():
            z.writestr(name, content, compress_type=zipfile.ZIP_STORED if name == "mimetype" else zipfile.ZIP_DEFLATED)
    return str(path)


def dumps(data) -> str:
    return json.dumps(data, ensure_ascii=False)


def use_service(monkeypatch) -> None:
    """Окружение встроенного сервиса обезличивания, как в контейнере app."""
    monkeypatch.setenv("ANONYMIZER_URL", f"http://{ANON_HOST}")
    monkeypatch.setenv("INTERNAL_API_TOKEN", "test-internal-token")


def service_reply(request, replace=lambda t: t) -> HttpResponse:
    """Ответ /v1/anonymize (контракт встроенного сервиса) с заменой replace в каждом тексте; /readyz — готов."""
    if request.url.endswith("/readyz"):
        return HttpResponse(200, '{"ready":true}')
    return HttpResponse(200, dumps({"texts": [replace(t) for t in json.loads(request.body)["texts"]], "stats": {}, "replaced": 0,
                                    "warnings": [], "verified": True}))
