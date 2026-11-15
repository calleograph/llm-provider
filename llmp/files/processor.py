"""Локальная цепочка обработки загруженного файла: временный закрытый каталог → проверка расширения и сигнатуры →
локальное извлечение текста (+ OCR) → удаление временных файлов при любом исходе.

Результат — ИСХОДНЫЙ текст; дальше он обязан пройти AnonymizationGateway. Сам файл никуда не передаётся."""

from __future__ import annotations

import os
import re
import secrets
import shutil
import time
from typing import Callable, TypeVar

from .. import paths
from .base import ExtractedFile, ExtractionResult, FileProcessingException, OcrEngine, StagedFile, TextNormalizer, php_int
from .extractors import DocxExtractor, ImageOcrExtractor, OdtExtractor, PdfExtractor, PlainTextExtractor, XlsxExtractor, _ext
from .settings import FileProcessingSettings

T = TypeVar("T")
STALE_SECONDS = 3600


class FileProcessor:
    def __init__(self, settings: FileProcessingSettings, tmp_root: str):
        self.settings = settings
        self.tmp_root = str(tmp_root)
        self.by_extension: dict[str, object] = {}

    @classmethod
    def with_defaults(cls, settings: FileProcessingSettings, tmp_root: str | None = None) -> "FileProcessor":
        ocr = OcrEngine(settings)
        processor = cls(settings, tmp_root or str(paths.llm_tmp()))
        for extractor in (PlainTextExtractor(), DocxExtractor(ocr), OdtExtractor(ocr), XlsxExtractor(),
                          PdfExtractor(settings, ocr), ImageOcrExtractor(ocr)):
            processor.register(extractor)
        return processor

    def register(self, extractor) -> None:
        for extension in extractor.extensions():
            self.by_extension[extension.lower()] = extractor

    def supported_extensions(self) -> list[str]:
        return list(self.by_extension)

    def process(self, source_path: str, original_name: str, size: int, is_http_upload: bool = True) -> ExtractedFile:
        """is_http_upload=True — исходный файл временный (загрузка) и переносится, иначе копируется."""
        def handler(file: StagedFile) -> ExtractedFile:
            r = self.extract_text(file.path, file.extension, file.work_dir)
            return ExtractedFile(file.extension, r.text, file.size, r.ocr_used, r.pages, r.ocr_pages)

        return self.with_staged_file(source_path, original_name, size, is_http_upload, handler)

    def with_staged_file(self, source_path: str, original_name: str, size: int, is_http_upload: bool,
                         handler: Callable[[StagedFile], T]) -> T:
        """Принимает файл во временный каталог (с проверками) и передаёт обработчику. Каталог со всем содержимым
        удаляется при любом исходе."""
        extension = _ext(original_name).lower()
        if extension not in self.by_extension:
            raise FileProcessingException("unsupported_type", "Расширение не поддерживается: " + extension)
        if size <= 0:
            raise FileProcessingException("empty_content", "Пустой файл.")
        if size > self.settings.max_upload_bytes():
            raise FileProcessingException("too_large", "Размер файла превышает лимит.")

        self._cleanup_stale()
        work_dir = self._create_work_dir()
        stored = f"{work_dir}/input.{extension}"
        try:
            try:
                if is_http_upload:
                    shutil.move(source_path, stored)
                else:
                    shutil.copyfile(source_path, stored)
            except OSError:
                raise FileProcessingException("storage_error", "Не удалось сохранить временный файл.") from None
            os.chmod(stored, 0o600)
            with open(stored, "rb") as fh:
                head = fh.read(2048)
            if not self.by_extension[extension].matches_signature(head):
                raise FileProcessingException("signature_mismatch", f"Сигнатура файла не соответствует расширению {extension}.")
            return handler(StagedFile(stored, extension, size, work_dir))
        finally:
            shutil.rmtree(work_dir, ignore_errors=True)

    def extract_text(self, path: str, extension: str, work_dir: str) -> ExtractionResult:
        """Извлечение с нормализацией и лимитами. Пустой результат — блокировка."""
        extension = extension.lower()
        if extension not in self.by_extension:
            raise FileProcessingException("unsupported_type", "Нет обработчика для формата: " + extension)
        result = self.by_extension[extension].extract(path, work_dir)
        text = TextNormalizer.clean(result.text)
        if TextNormalizer.meaningful_length(text) == 0:
            raise FileProcessingException("empty_content", "Текст не извлечён.")
        if len(text) > max(1000, php_int(self.settings.get("max_extracted_chars"))):
            raise FileProcessingException("content_too_large", "Извлечённый текст превышает лимит.")
        return ExtractionResult(text, result.ocr_used, result.pages, result.ocr_pages)

    def _create_work_dir(self) -> str:
        try:
            os.makedirs(self.tmp_root, mode=0o700, exist_ok=True)
        except OSError:
            raise FileProcessingException("storage_error", "Каталог временных файлов недоступен.") from None
        work_dir = f"{self.tmp_root}/{secrets.token_hex(16)}"
        try:
            os.mkdir(work_dir, 0o700)
        except OSError:
            raise FileProcessingException("storage_error", "Не удалось создать временный каталог.") from None
        return work_dir

    def _cleanup_stale(self) -> None:
        """Страховка после аварийного завершения процесса посреди обработки: забытые каталоги старше часа."""
        try:
            entries = list(os.scandir(self.tmp_root))
        except OSError:
            return
        for entry in entries:
            try:
                if entry.is_dir(follow_symlinks=False) and re.fullmatch(r"[0-9a-f]{32}", entry.name) \
                        and time.time() - entry.stat(follow_symlinks=False).st_mtime > STALE_SECONDS:
                    shutil.rmtree(entry.path, ignore_errors=True)
            except OSError:
                continue
