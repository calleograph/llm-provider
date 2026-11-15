"""Общие части локальной обработки файлов: исключение, структуры результата, нормализация текста, запуск утилит
и OCR. Перенос src/Llm/Files/* PHP-версии: коды ошибок и тексты для пользователя — те же."""

from __future__ import annotations

import math
import os
import re
import selectors
import subprocess
import time
from dataclasses import dataclass
from typing import TYPE_CHECKING, Any

if TYPE_CHECKING:
    from .settings import FileProcessingSettings

_PHP_WS = " \t\n\r\0\x0b"


def php_trim(value: str) -> str:
    """trim() PHP: только пробел, \\t, \\n, \\r, NUL и \\v — не весь Unicode, как str.strip()."""
    return value.strip(_PHP_WS)


def php_int(value: Any) -> int:
    """(int) PHP: число как есть, у строки — ведущее целое, иначе 0."""
    if isinstance(value, bool):
        return int(value)
    if isinstance(value, int):
        return value
    if isinstance(value, float):
        return int(value) if math.isfinite(value) else 0
    if isinstance(value, str):
        m = re.match(r"[ \t\n\r\v\f]*([+-]?[0-9]+)", value)
        return int(m.group(1)) if m else 0
    return 0


class FileProcessingException(RuntimeError):
    """Код безопасен для журнала: no_file | upload_error | too_large | unsupported_type | signature_mismatch |
    empty_content | content_too_large | ocr_required_unavailable | ocr_failed | tool_unavailable |
    extraction_failed | storage_error."""

    def __init__(self, error_code: str, technical_message: str = ""):
        super().__init__(technical_message or error_code)
        self.error_code = error_code

    def user_message(self) -> str:
        return {
            "too_large": "Файл превышает допустимый размер.",
            "unsupported_type": "Этот тип файла не поддерживается.",
            "signature_mismatch": "Содержимое файла не соответствует его расширению.",
            "empty_content": "В файле нет текста (пустой файл или не удалось распознать содержимое) — запрос не отправлен.",
            "content_too_large": "Текст файла слишком большой для отправки.",
            "ocr_required_unavailable": "Файл содержит сканированные страницы, но распознавание текста (OCR) недоступно — запрос не отправлен.",
            "ocr_failed": "Не удалось надёжно распознать текст скана — запрос не отправлен.",
            "tool_unavailable": "Инструмент обработки этого типа файлов не установлен на сервере.",
        }.get(self.error_code, "Не удалось обработать файл — запрос не отправлен.")


@dataclass(frozen=True)
class ExtractionResult:
    text: str
    ocr_used: bool = False
    pages: int | None = None
    ocr_pages: int = 0


@dataclass(frozen=True)
class ExtractedFile:
    """ИСХОДНЫЙ текст файла: живёт только в памяти запроса до AnonymizationGateway; имя файла не сохраняется."""
    extension: str
    text: str
    size: int
    ocr_used: bool
    pages: int | None
    ocr_pages: int


@dataclass(frozen=True)
class StagedFile:
    """Файл, прошедший проверки и лежащий в закрытом временном каталоге запроса (живёт внутри with_staged_file)."""
    path: str
    extension: str
    size: int
    work_dir: str


class TextNormalizer:
    @staticmethod
    def to_utf8(raw: bytes) -> str:
        """BOM UTF-8/UTF-16 учитываются, не-UTF-8 считается Windows-1251 (типичная кодировка корпоративных CSV/TXT)."""
        return TextNormalizer.clean(TextNormalizer.to_utf8_raw(raw))

    @staticmethod
    def to_utf8_raw(raw: bytes) -> str:
        """Только перекодирование, без очистки (сохраняет \\f — разделитель страниц pdftotext)."""
        if raw.startswith(b"\xef\xbb\xbf"):
            try:
                return raw[3:].decode("utf-8")
            except UnicodeDecodeError:
                return ""   # PHP: preg_* с /u на битом UTF-8 даёт пустую строку
        if raw.startswith(b"\xff\xfe"):
            return raw[2:].decode("utf-16-le", errors="replace")
        if raw.startswith(b"\xfe\xff"):
            return raw[2:].decode("utf-16-be", errors="replace")
        try:
            return raw.decode("utf-8")
        except UnicodeDecodeError:
            return raw.decode("cp1251", errors="replace")

    @staticmethod
    def clean(text: str) -> str:
        """Удаляет управляющие символы (кроме \\t, \\n), нормализует переводы строк."""
        text = text.replace("\r\n", "\n").replace("\r", "\n")
        text = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", "", text)
        text = re.sub(r"\n{4,}", "\n\n\n", text)
        return php_trim(text)

    @staticmethod
    def meaningful_length(text: str) -> int:
        return len(re.sub(r"\s+", "", text))


class ProcessRunner:
    """Запуск локальных утилит без shell: аргументы списком, таймаут и предел объёма вывода."""

    @staticmethod
    def run(command: list[str], timeout_seconds: int, max_output_bytes: int = 32 * 1024 * 1024) -> dict:
        binary = command[0] if command else ""
        if not ProcessRunner.is_executable(binary):
            raise FileProcessingException("tool_unavailable", "Инструмент недоступен: " + binary)
        name = os.path.basename(binary)
        try:
            proc = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    env={"LC_ALL": "C.UTF-8", "OMP_THREAD_LIMIT": "1"}, close_fds=True)
        except OSError:
            raise FileProcessingException("extraction_failed", "Не удалось запустить " + name) from None
        stdout, stderr = bytearray(), bytearray()
        deadline = time.monotonic() + timeout_seconds
        sel = selectors.DefaultSelector()
        sel.register(proc.stdout, selectors.EVENT_READ)
        sel.register(proc.stderr, selectors.EVENT_READ)
        try:
            while sel.get_map():
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    proc.kill()
                    proc.wait()
                    raise FileProcessingException("extraction_failed", f"{name}: превышен таймаут {timeout_seconds} с.")
                for key, _ in sel.select(min(remaining, 1.0)):
                    chunk = os.read(key.fd, 65536)
                    if not chunk:
                        sel.unregister(key.fileobj)
                    elif key.fileobj is proc.stdout:
                        stdout += chunk
                    elif len(stderr) < 8192:
                        stderr += chunk[:8192]
                if len(stdout) > max_output_bytes:
                    proc.kill()
                    proc.wait()
                    raise FileProcessingException("content_too_large", f"{name}: превышен объём вывода.")
            exit_code = proc.wait(timeout=max(1.0, deadline - time.monotonic()))
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.wait()
            raise FileProcessingException("extraction_failed", f"{name}: превышен таймаут {timeout_seconds} с.") from None
        finally:
            sel.close()
            proc.stdout.close()
            proc.stderr.close()
        return {"exit": exit_code, "stdout": bytes(stdout), "stderr": stderr.decode("utf-8", errors="replace")[:1000]}

    @staticmethod
    def is_executable(path: str) -> bool:
        return path != "" and os.path.isfile(path) and os.access(path, os.X_OK)


class OcrEngine:
    """Локальный OCR через tesseract. Изображение не покидает сервер."""

    def __init__(self, settings: "FileProcessingSettings"):
        self.settings = settings

    def is_available(self) -> bool:
        return self.settings.ocr_enabled() and ProcessRunner.is_executable(self.settings.get("tesseract_path"))

    def assert_within_limit(self, images: int) -> None:
        """Тот же предел, что для страниц PDF: тысячи мелких картинок иначе заняли бы воркер на минуты."""
        if images > max(1, php_int(self.settings.get("ocr_max_pages"))):
            raise FileProcessingException("content_too_large", f"Слишком много изображений для OCR: {images}")

    def recognize(self, image_path: str) -> str:
        if not self.is_available():
            raise FileProcessingException("ocr_required_unavailable", "OCR отключён или tesseract не найден.")
        result = ProcessRunner.run(
            [self.settings.get("tesseract_path"), image_path, "stdout", "-l", self.settings.get("ocr_languages")],
            max(5, php_int(self.settings.get("tool_timeout"))),
        )
        if result["exit"] != 0:
            raise FileProcessingException("ocr_failed", f"tesseract exit={result['exit']}: {result['stderr']}")
        return TextNormalizer.to_utf8(result["stdout"])
