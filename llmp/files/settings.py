"""Параметры локальной обработки файлов (app_settings, префикс files_). Инструменты локальные: pdftotext/pdftoppm
(poppler-utils) и tesseract. Пути к ним зафиксированы в образе (/usr/bin) и в админке не редактируются: строки
files_*_path старых установок читаются, но не сохраняются из формы."""

from __future__ import annotations

import re

from ..settings import AppSettings
from .base import php_int

PREFIX = "files_"
DEFAULTS = {
    "max_upload_mb": "10",
    "max_extracted_chars": "200000",
    "ocr_enabled": "1",
    "ocr_languages": "rus+eng",
    "ocr_max_pages": "30",
    "tesseract_path": "/usr/bin/tesseract",
    "pdftotext_path": "/usr/bin/pdftotext",
    "pdftoppm_path": "/usr/bin/pdftoppm",
    "tool_timeout": "120",
}
_RANGES = {"max_upload_mb": (1, 200), "max_extracted_chars": (1000, 5000000), "ocr_max_pages": (1, 500), "tool_timeout": (5, 1800)}


class FileProcessingSettings:
    def __init__(self, app_settings: AppSettings):
        self.app_settings = app_settings

    def get(self, name: str) -> str:
        return str(self.app_settings.get(PREFIX + name, DEFAULTS.get(name, "")))

    def all(self) -> dict[str, str]:
        return {k: self.get(k) for k in DEFAULTS}

    def save(self, values: dict[str, str]) -> None:
        for name, value in values.items():
            if name in DEFAULTS:
                self.app_settings.set(PREFIX + name, str(value))

    def max_upload_bytes(self) -> int:
        return max(1, php_int(self.get("max_upload_mb"))) * 1024 * 1024

    def ocr_enabled(self) -> bool:
        return self.get("ocr_enabled") == "1"

    @staticmethod
    def validate(values: dict[str, str]) -> dict[str, str]:
        errors: dict[str, str] = {}
        for field, (lo, hi) in _RANGES.items():
            if not lo <= php_int(values.get(field, 0)) <= hi:
                errors[field] = f"Допустимый диапазон: {lo}–{hi}."
        if not re.fullmatch(r"[a-z_]{3,8}(?:\+[a-z_]{3,8})*", str(values.get("ocr_languages", ""))):
            errors["ocr_languages"] = "Формат: rus+eng."
        return errors
