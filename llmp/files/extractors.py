"""Извлечение текста по форматам: TXT/CSV, DOCX, ODT, XLSX, PDF (pdftotext + OCR страниц-сканов), изображения (OCR).
Новый формат — класс с extensions/matches_signature/extract и регистрация в FileProcessor.with_defaults()."""

from __future__ import annotations

import os
import re
import zipfile
import xml.etree.ElementTree as ET

from defusedxml import DefusedXmlException
from defusedxml.ElementTree import fromstring as safe_fromstring

from .base import ExtractionResult, FileProcessingException, OcrEngine, ProcessRunner, TextNormalizer, php_int, php_trim
from .settings import FileProcessingSettings

NS_W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
NS_TEXT = "urn:oasis:names:tc:opendocument:xmlns:text:1.0"
NS_X = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
NS_REL = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
NS_PKG_REL = "http://schemas.openxmlformats.org/package/2006/relationships"


def _local(el: ET.Element) -> str:
    return el.tag.rsplit("}", 1)[-1] if isinstance(el.tag, str) else ""


def _text(el: ET.Element) -> str:
    return "".join(el.itertext())


def _ext(name: str) -> str:
    """pathinfo(PATHINFO_EXTENSION) PHP: часть имени после последней точки."""
    base = name.rstrip("/").rsplit("/", 1)[-1]
    return base.rsplit(".", 1)[1] if "." in base else ""


class ZipPackage:
    """Безопасное чтение OOXML/ODF-пакета: лимиты числа записей и распакованного размера (zip bomb), XML без
    DOCTYPE/ENTITY (XXE / billion laughs) и без сетевых обращений."""

    MAX_ENTRIES = 10000
    MAX_TOTAL_UNCOMPRESSED = 200 * 1024 * 1024
    MAX_ENTRY_BYTES = 64 * 1024 * 1024

    def __init__(self, path: str):
        try:
            self.zip = zipfile.ZipFile(path)
        except (zipfile.BadZipFile, OSError, ValueError):
            raise FileProcessingException("extraction_failed", "Файл повреждён или не является OOXML-документом.") from None
        infos = self.zip.infolist()
        if len(infos) > self.MAX_ENTRIES:
            self.zip.close()
            raise FileProcessingException("extraction_failed", "Слишком много записей в архиве документа.")
        if sum(i.file_size for i in infos) > self.MAX_TOTAL_UNCOMPRESSED:
            self.zip.close()
            raise FileProcessingException("content_too_large", "Распакованный размер документа превышает лимит.")

    def names(self) -> list[str]:
        return self.zip.namelist()

    def has(self, name: str) -> bool:
        return name in self.zip.NameToInfo

    def read(self, name: str) -> bytes | None:
        info = self.zip.NameToInfo.get(name)
        if info is None:
            return None
        if info.file_size > self.MAX_ENTRY_BYTES:
            raise FileProcessingException("content_too_large", "Часть документа превышает лимит размера.")
        try:
            return self.zip.read(info)
        except (zipfile.BadZipFile, OSError, ValueError, NotImplementedError, EOFError):
            return None

    def xml(self, name: str) -> ET.Element | None:
        data = self.read(name)
        if data is None:
            return None
        low = data.lower()
        if b"<!doctype" in low or b"<!entity" in low:
            raise FileProcessingException("extraction_failed", "XML документа содержит DOCTYPE/ENTITY — отклонён.")
        try:
            return safe_fromstring(data, forbid_dtd=True)
        except (ET.ParseError, DefusedXmlException, ValueError):
            raise FileProcessingException("extraction_failed", "Некорректный XML в документе: " + name) from None

    def close(self) -> None:
        self.zip.close()


class PlainTextExtractor:
    """TXT, CSV и прочие текстовые форматы."""

    def extensions(self) -> list[str]:
        return ["txt", "csv", "tsv", "md", "log"]

    def matches_signature(self, head: bytes) -> bool:
        if head.startswith(b"\xff\xfe") or head.startswith(b"\xfe\xff"):
            return True
        return b"\0" not in head   # двоичный файл, переименованный в .txt/.csv, содержит NUL-байты

    def extract(self, path: str, work_dir: str) -> ExtractionResult:
        try:
            with open(path, "rb") as fh:
                raw = fh.read()
        except OSError:
            raise FileProcessingException("extraction_failed", "Не удалось прочитать файл.") from None
        return ExtractionResult(TextNormalizer.to_utf8(raw))


def _ocr_images(package: ZipPackage, ocr: OcrEngine | None, images: list[str], text: str, work_dir: str, kind: str) -> ExtractionResult:
    """Встроенные изображения — тоже содержимое: OCR, а без него документ блокируется целиком."""
    if not images:
        return ExtractionResult(text)
    if ocr is None or not ocr.is_available():
        raise FileProcessingException("ocr_required_unavailable", f"{kind.upper()} содержит изображения, OCR недоступен — содержимое не проверить.")
    ocr.assert_within_limit(len(images))
    ocr_texts = []
    for index, image in enumerate(images):
        data = package.read(image)
        if data is None:
            continue
        tmp = f"{work_dir}/{kind}-image-{index}.{_ext(image).lower()}"
        with open(tmp, "wb") as fh:
            fh.write(data)
        try:
            ocr_texts.append(ocr.recognize(tmp))
        finally:
            try:
                os.unlink(tmp)
            except OSError:
                pass
    return ExtractionResult(TextNormalizer.clean("\n\n".join([text, *ocr_texts])), True, None, len(ocr_texts))


class DocxExtractor:
    """DOCX: основной документ, колонтитулы, сноски и примечания; встроенные изображения — через OCR."""

    def __init__(self, ocr: OcrEngine | None = None):
        self.ocr = ocr

    def extensions(self) -> list[str]:
        return ["docx"]

    def matches_signature(self, head: bytes) -> bool:
        return head.startswith(b"PK\x03\x04")

    def extract(self, path: str, work_dir: str) -> ExtractionResult:
        package = ZipPackage(path)
        try:
            if not package.has("word/document.xml"):
                raise FileProcessingException("extraction_failed", "В архиве нет word/document.xml.")
            parts = ["word/document.xml"] + [n for n in package.names()
                                             if re.fullmatch(r"word/(?:header[0-9]*|footer[0-9]*|footnotes|endnotes|comments)\.xml", n)]
            sections = []
            for part in parts:
                root = package.xml(part)
                if root is not None:
                    text = self._paragraphs_text(root)
                    if php_trim(text) != "":
                        sections.append(text)
            text = TextNormalizer.clean("\n\n".join(sections))
            images = [n for n in package.names() if re.fullmatch(r"word/media/[^/]+\.(?:png|jpe?g|tiff?|bmp)", n, re.I)]
            return _ocr_images(package, self.ocr, images, text, work_dir, "docx")
        finally:
            package.close()

    @staticmethod
    def _paragraphs_text(root: ET.Element) -> str:
        lines: list[str] = []
        p_tag = f"{{{NS_W}}}p"

        def walk(el: ET.Element) -> None:
            # Только абзацы верхнего уровня: текст вложенных (надписи) войдёт в них.
            if el.tag == p_tag:
                line = ""
                for node in el.iter():
                    if node is el or not isinstance(node.tag, str) or not node.tag.startswith(f"{{{NS_W}}}"):
                        continue
                    name = _local(node)
                    if name == "t":
                        line += _text(node)
                    elif name == "tab":
                        line += "\t"
                    elif name in ("br", "cr"):
                        line += "\n"
                lines.append(line)
                return
            for child in el:
                walk(child)

        walk(root)
        return "\n".join(lines)


class OdtExtractor:
    """ODT: content.xml (сноски text:note и примечания office:annotation — внутри абзацев) и styles.xml (колонтитулы);
    изображения — через OCR, как DOCX."""

    def __init__(self, ocr: OcrEngine | None = None):
        self.ocr = ocr

    def extensions(self) -> list[str]:
        return ["odt"]

    def matches_signature(self, head: bytes) -> bool:
        return head.startswith(b"PK\x03\x04") and b"application/vnd.oasis.opendocument.text" in head

    def extract(self, path: str, work_dir: str) -> ExtractionResult:
        package = ZipPackage(path)
        try:
            if not package.has("content.xml"):
                raise FileProcessingException("extraction_failed", "В архиве нет content.xml.")
            sections = []
            for part in ("content.xml", "styles.xml"):
                root = package.xml(part) if package.has(part) else None
                if root is not None:
                    text = self._paragraphs_text(root)
                    if php_trim(text) != "":
                        sections.append(text)
            text = TextNormalizer.clean("\n\n".join(sections))
            images = [n for n in package.names() if re.fullmatch(r"Pictures/[^/]+\.(?:png|jpe?g|tiff?|bmp)", n, re.I)]
            return _ocr_images(package, self.ocr, images, text, work_dir, "odt")
        finally:
            package.close()

    @classmethod
    def _paragraphs_text(cls, root: ET.Element) -> str:
        p_tag, h_tag = f"{{{NS_TEXT}}}p", f"{{{NS_TEXT}}}h"
        lines: list[str] = []

        def walk(el: ET.Element, inside_p: bool) -> None:
            # //text:p[not(ancestor::text:p)] | //text:h[not(ancestor::text:p)] в порядке документа
            if el.tag in (p_tag, h_tag) and not inside_p:
                lines.append(cls._node_text(el))
            for child in el:
                walk(child, inside_p or el.tag == p_tag)

        walk(root, False)
        return "\n".join(lines)

    @classmethod
    def _node_text(cls, node: ET.Element) -> str:
        out = node.text or ""
        for child in node:
            name = _local(child)
            if name == "tab":
                out += "\t"
            elif name == "line-break":
                out += "\n"
            elif name == "s":
                out += " " * max(1, php_int(child.get(f"{{{NS_TEXT}}}c", "")))
            elif name in ("p", "h"):
                out += "\n" + cls._node_text(child)
            elif name in ("annotation", "note"):
                out += cls._node_text(child) + "\n"
            else:
                out += cls._node_text(child)
            out += child.tail or ""
        return out


class XlsxExtractor:
    """XLSX: листы в порядке книги, строки — переводом строки, ячейки — табуляцией с сохранением позиций столбцов.
    Формулы не вычисляются: берётся сохранённое значение."""

    MAX_COLUMNS = 16384

    def extensions(self) -> list[str]:
        return ["xlsx"]

    def matches_signature(self, head: bytes) -> bool:
        return head.startswith(b"PK\x03\x04")

    def extract(self, path: str, work_dir: str) -> ExtractionResult:
        package = ZipPackage(path)
        try:
            if not package.has("xl/workbook.xml"):
                raise FileProcessingException("extraction_failed", "В архиве нет xl/workbook.xml.")
            shared = self._shared_strings(package)
            blocks = []
            for name, sheet_path in self._sheets(package).items():
                root = package.xml(sheet_path)
                if root is None:
                    continue
                rows = self._sheet_rows(root, shared)
                if rows:
                    blocks.append("Лист: " + name + "\n" + "\n".join(rows))
            return ExtractionResult(TextNormalizer.clean("\n\n".join(blocks)), False, len(blocks))
        finally:
            package.close()

    @staticmethod
    def _shared_strings(package: ZipPackage) -> list[str]:
        root = package.xml("xl/sharedStrings.xml")
        if root is None:
            return []
        t_tag, rph_tag = f"{{{NS_X}}}t", f"{{{NS_X}}}rPh"

        def collect(el: ET.Element) -> str:   # .//x:t[not(ancestor::x:rPh)]
            out = ""
            for child in el:
                if child.tag == rph_tag:
                    continue
                out += _text(child) if child.tag == t_tag else collect(child)
            return out

        return [collect(si) for si in root.iter(f"{{{NS_X}}}si")]

    @staticmethod
    def _sheets(package: ZipPackage) -> dict[str, str]:
        targets: dict[str, str] = {}
        rels = package.xml("xl/_rels/workbook.xml.rels")
        if rels is not None:
            for rel in rels.iter(f"{{{NS_PKG_REL}}}Relationship"):
                target = rel.get("Target", "")
                targets[rel.get("Id", "")] = target.lstrip("/") if target.startswith("/") else "xl/" + target
        result: dict[str, str] = {}
        workbook = package.xml("xl/workbook.xml")
        if workbook is not None:
            for sheets in workbook.iter(f"{{{NS_X}}}sheets"):
                for sheet in sheets.findall(f"{{{NS_X}}}sheet"):
                    rid = sheet.get(f"{{{NS_REL}}}id", "")
                    if rid in targets and re.fullmatch(r"xl/worksheets/[^/]+\.xml", targets[rid]):
                        result[sheet.get("name", "")] = targets[rid]
        if not result:
            # Нестандартный пакет без связей — листы по именам файлов.
            names = [n for n in package.names() if re.fullmatch(r"xl/worksheets/sheet[0-9]+\.xml", n)]
            names.sort(key=lambda n: int(re.search(r"[0-9]+", n.rsplit("/", 1)[-1]).group()))
            for i, name in enumerate(names):
                result[f"Лист{i + 1}"] = name
        return result

    @classmethod
    def _sheet_rows(cls, root: ET.Element, shared: list[str]) -> list[str]:
        rows = []
        for sheet_data in root.iter(f"{{{NS_X}}}sheetData"):
            for row in sheet_data.findall(f"{{{NS_X}}}row"):
                cells: dict[int, str] = {}
                next_column = 0
                for cell in row.findall(f"{{{NS_X}}}c"):
                    column = cls._column_index(cell.get("r", ""))
                    if column is None or column < next_column:
                        column = next_column
                    if column >= cls.MAX_COLUMNS:
                        break
                    cells[column] = cls._cell_value(cell, shared)
                    next_column = column + 1
                cells = {k: v for k, v in cells.items() if v != ""}
                if not cells:
                    continue
                rows.append("\t".join(cells.get(i, "").replace("\t", " ").replace("\n", " ") for i in range(max(cells) + 1)))
        return rows

    @staticmethod
    def _cell_value(cell: ET.Element, shared: list[str]) -> str:
        cell_type = cell.get("t", "")
        if cell_type == "inlineStr":
            return "".join(_text(t) for is_ in cell.findall(f"{{{NS_X}}}is") for t in is_.iter(f"{{{NS_X}}}t") if t is not is_)
        v = cell.find(f"{{{NS_X}}}v")
        raw = _text(v) if v is not None else ""
        if cell_type == "s":
            i = php_int(raw)
            return shared[i] if 0 <= i < len(shared) else ""
        if cell_type == "b":
            return "TRUE" if raw == "1" else "FALSE"
        return raw

    @staticmethod
    def _column_index(reference: str) -> int | None:
        m = re.fullmatch(r"([A-Z]{1,3})[0-9]+", reference)
        if not m:
            return None
        index = 0
        for char in m.group(1):
            index = index * 26 + (ord(char) - 64)
        return index - 1


class PdfExtractor:
    """PDF: текстовый слой через pdftotext; страницы без текста растеризуются pdftoppm и распознаются tesseract.
    Есть сканы, а OCR недоступен — документ блокируется (fail closed), частичный текст не отправляется."""

    SCANNED_PAGE_THRESHOLD = 20   # меньше непробельных символов — страница считается сканом

    def __init__(self, settings: FileProcessingSettings, ocr: OcrEngine):
        self.settings = settings
        self.ocr = ocr

    def extensions(self) -> list[str]:
        return ["pdf"]

    def matches_signature(self, head: bytes) -> bool:
        return b"%PDF-" in head[:1024]

    def extract(self, path: str, work_dir: str) -> ExtractionResult:
        pdftotext = self.settings.get("pdftotext_path")
        if not ProcessRunner.is_executable(pdftotext):
            raise FileProcessingException("tool_unavailable", "pdftotext не найден: " + pdftotext)
        timeout = max(5, php_int(self.settings.get("tool_timeout")))
        result = ProcessRunner.run([pdftotext, "-layout", "-enc", "UTF-8", path, "-"], timeout)
        if result["exit"] != 0:
            # Зашифрованный/повреждённый PDF.
            raise FileProcessingException("extraction_failed", f"pdftotext exit={result['exit']}: {result['stderr']}")
        pages = TextNormalizer.to_utf8_raw(result["stdout"]).split("\f")
        if len(pages) > 1 and php_trim(pages[-1]) == "":
            pages.pop()
        scanned = [i for i, page in enumerate(pages) if TextNormalizer.meaningful_length(page) < self.SCANNED_PAGE_THRESHOLD]
        ocr_pages = 0
        if scanned:
            # Даже если текст есть, но страница «короткая», нужен OCR — иначе содержимое скана потеряется.
            pdftoppm = self.settings.get("pdftoppm_path")
            if not self.ocr.is_available() or not ProcessRunner.is_executable(pdftoppm):
                raise FileProcessingException("ocr_required_unavailable", "PDF содержит страницы без текста, OCR недоступен.")
            if len(scanned) > max(1, php_int(self.settings.get("ocr_max_pages"))):
                raise FileProcessingException("content_too_large", f"Слишком много страниц для OCR: {len(scanned)}")
            for index in scanned:
                page_number = str(index + 1)
                prefix = f"{work_dir}/pdf-page-{page_number}"
                render = ProcessRunner.run(
                    [pdftoppm, "-r", "300", "-gray", "-f", page_number, "-l", page_number, "-png", "-singlefile", path, prefix], timeout)
                image = prefix + ".png"
                try:
                    if render["exit"] != 0 or not os.path.isfile(image):
                        raise FileProcessingException("ocr_failed", f"pdftoppm exit={render['exit']} для страницы {page_number}")
                    # Текст из слоя (если был) сохраняем, OCR добавляем.
                    pages[index] = php_trim(pages[index] + "\n" + self.ocr.recognize(image))
                    ocr_pages += 1
                finally:
                    try:
                        os.unlink(image)
                    except OSError:
                        pass
        return ExtractionResult(TextNormalizer.clean("\n\n".join(pages)), ocr_pages > 0, len(pages), ocr_pages)


class ImageOcrExtractor:
    """Изображения (фото/скан документа) — только локальный OCR; во внешний API изображение не передаётся."""

    def __init__(self, ocr: OcrEngine):
        self.ocr = ocr

    def extensions(self) -> list[str]:
        return ["png", "jpg", "jpeg", "tif", "tiff", "bmp"]

    def matches_signature(self, head: bytes) -> bool:
        return head.startswith((b"\x89PNG", b"\xff\xd8\xff", b"II*\0", b"MM\0*", b"BM"))

    def extract(self, path: str, work_dir: str) -> ExtractionResult:
        return ExtractionResult(self.ocr.recognize(path), True, 1, 1)
