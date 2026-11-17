"""Перенос tests/LlmFileProcessorTest.php: локальное извлечение текста — форматы, проверка сигнатур, fail closed и
обязательное удаление временных файлов."""

from __future__ import annotations

import os

import pytest

from kit_anon import PNG, file_settings, make_zip
from llmp.files import FileProcessingException, FileProcessor

NS_W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'


class Env:
    def __init__(self, conn, tmp_path):
        self.tmp_root = tmp_path / "work"
        self.source_dir = tmp_path / "src"
        self.source_dir.mkdir()
        self.settings = file_settings(conn)
        # Инструменты заведомо отсутствуют — проверяем fail closed для сканов/PDF.
        self.settings.save({"pdftotext_path": "/nonexistent/pdftotext", "pdftoppm_path": "/nonexistent/pdftoppm",
                            "tesseract_path": "/nonexistent/tesseract"})
        self.processor = FileProcessor.with_defaults(self.settings, str(self.tmp_root))

    def file(self, name: str, content: bytes | str) -> str:
        path = self.source_dir / name
        path.write_bytes(content.encode() if isinstance(content, str) else content)
        return str(path)

    def zip(self, name: str, entries: dict) -> str:
        return make_zip(self.source_dir / name, entries)

    def process(self, path: str):
        return self.processor.process(path, os.path.basename(path), os.path.getsize(path), False)

    def assert_no_temp_left(self):
        assert (os.listdir(self.tmp_root) if self.tmp_root.exists() else []) == [], "Временные файлы должны удаляться"


@pytest.fixture
def env(conn, tmp_path):
    return Env(conn, tmp_path)


def test_txt_in_windows1251_is_converted(env):
    """TXT в Windows-1251 перекодируется, CRLF нормализуется."""
    result = env.process(env.file("note.txt", "Служебная записка\r\nИванов".encode("cp1251")))
    assert result.text == "Служебная записка\nИванов"
    assert result.extension == "txt"
    env.assert_no_temp_left()


def test_csv(env):
    """CSV с BOM UTF-8."""
    result = env.process(env.file("data.csv", b"\xef\xbb\xbf" + "ФИО;Сумма\nИванов;100\n".encode()))
    assert result.text == "ФИО;Сумма\nИванов;100"


def test_docx_paragraphs_tables_and_headers(env):
    """DOCX: абзацы с табуляцией, таблицы и колонтитулы."""
    path = env.zip("doc.docx", {
        "[Content_Types].xml": '<?xml version="1.0"?><Types/>',
        "word/document.xml": f'<?xml version="1.0"?><w:document {NS_W}><w:body>'
        '<w:p><w:r><w:t>Приказ</w:t></w:r><w:r><w:tab/><w:t>№ 5</w:t></w:r></w:p>'
        '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Иванов</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
        '</w:body></w:document>',
        "word/header1.xml": f'<?xml version="1.0"?><w:hdr {NS_W}><w:p><w:r><w:t>ООО Ромашка</w:t></w:r></w:p></w:hdr>',
    })
    result = env.process(path)
    assert "Приказ\t№ 5" in result.text
    assert "Иванов" in result.text
    assert "ООО Ромашка" in result.text
    env.assert_no_temp_left()


def test_docx_comments_are_checked_and_images_next_to_text_are_blocked_without_ocr(env):
    """Примечания DOCX — тоже содержимое; изображение рядом с текстом без OCR — блокировка."""
    path = env.zip("comments.docx", {
        "word/document.xml": f'<?xml version="1.0"?><w:document {NS_W}><w:body><w:p><w:r><w:t>Текст</w:t></w:r></w:p></w:body></w:document>',
        "word/comments.xml": f'<?xml version="1.0"?><w:comments {NS_W}><w:comment><w:p><w:r><w:t>Позвонить Сидорову</w:t></w:r></w:p></w:comment></w:comments>',
    })
    assert "Позвонить Сидорову" in env.process(path).text, "примечания — тоже содержимое"

    with_image = env.zip("mixed.docx", {
        "word/document.xml": f'<?xml version="1.0"?><w:document {NS_W}><w:body><w:p><w:r><w:t>Есть текст</w:t></w:r></w:p></w:body></w:document>',
        "word/media/image1.png": PNG,
    })
    with pytest.raises(FileProcessingException) as e:
        env.process(with_image)
    assert e.value.error_code == "ocr_required_unavailable"
    env.assert_no_temp_left()


def test_odt_text_notes_annotations_and_headers(env):
    """ODT: заголовки, пробелы text:s, сноски, примечания и колонтитулы styles.xml."""
    t = ('xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
         'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"')
    path = env.zip("doc.odt", {
        "mimetype": "application/vnd.oasis.opendocument.text",
        "content.xml": f'<?xml version="1.0"?><office:document-content {t}><office:body><office:text>'
        '<text:h>Договор</text:h><text:p>Исполнитель<text:s text:c="2"/>Петров<text:note><text:note-body><text:p>сноска: тел. 8 912 000-00-00</text:p></text:note-body></text:note></text:p>'
        '<text:p><office:annotation><text:p>уточнить у Козловой</text:p></office:annotation>Срок</text:p>'
        '</office:text></office:body></office:document-content>',
        "styles.xml": f'<?xml version="1.0"?><office:document-styles {t}><office:master-styles><style:master-page><style:header>'
        '<text:p>ООО Ромашка</text:p></style:header></style:master-page></office:master-styles></office:document-styles>',
    })
    text = env.process(path).text
    for part in ("Договор", "Исполнитель  Петров", "8 912 000-00-00", "уточнить у Козловой", "ООО Ромашка"):
        assert part in text
    env.assert_no_temp_left()


def test_docx_with_doctype_is_rejected(env):
    """XML с DOCTYPE/ENTITY (XXE) отклоняется."""
    path = env.zip("xxe.docx", {
        "word/document.xml": '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><w:document>&e;</w:document>',
    })
    with pytest.raises(FileProcessingException):
        env.process(path)


def test_docx_with_more_images_than_the_ocr_limit_is_refused_before_ocr(env):
    """Предел ocr_max_pages действует и для картинок DOCX (нужен настоящий tesseract)."""
    if not os.access("/usr/bin/tesseract", os.X_OK):
        pytest.skip("нет tesseract")
    env.settings.save({"tesseract_path": "/usr/bin/tesseract", "ocr_enabled": "1", "ocr_max_pages": "2"})
    env.processor = FileProcessor.with_defaults(env.settings, str(env.tmp_root))
    path = env.zip("many.docx", {
        "word/document.xml": f'<?xml version="1.0"?><w:document {NS_W}><w:body><w:p/></w:body></w:document>',
        "word/media/a.png": PNG, "word/media/b.png": PNG, "word/media/c.png": PNG,
    })
    with pytest.raises(FileProcessingException) as e:
        env.process(path)
    assert e.value.error_code == "content_too_large", "предел ocr_max_pages действует и для картинок DOCX"
    env.assert_no_temp_left()


def test_docx_ocr_limit_without_real_tesseract(env, tmp_path):
    """Тот же предел без настоящего tesseract: исполняемая заглушка, отказ — до запуска OCR."""
    stub = tmp_path / "bin" / "tesseract"
    stub.parent.mkdir()
    stub.write_text("#!/bin/sh\nexit 1\n")
    stub.chmod(0o700)
    env.settings.save({"tesseract_path": str(stub), "ocr_enabled": "1", "ocr_max_pages": "2"})
    env.processor = FileProcessor.with_defaults(env.settings, str(env.tmp_root))
    path = env.zip("many.docx", {
        "word/document.xml": f'<?xml version="1.0"?><w:document {NS_W}><w:body><w:p/></w:body></w:document>',
        "word/media/a.png": PNG, "word/media/b.png": PNG, "word/media/c.png": PNG,
    })
    with pytest.raises(FileProcessingException) as e:
        env.process(path)
    assert e.value.error_code == "content_too_large"
    env.assert_no_temp_left()


def test_scanned_docx_without_ocr_is_blocked(env):
    """DOCX только из изображения без OCR — блокировка."""
    path = env.zip("scan.docx", {
        "word/document.xml": f'<?xml version="1.0"?><w:document {NS_W}><w:body><w:p/></w:body></w:document>',
        "word/media/image1.png": PNG,
    })
    with pytest.raises(FileProcessingException) as e:
        env.process(path)
    assert e.value.error_code == "ocr_required_unavailable"
    env.assert_no_temp_left()


def test_xlsx_sheets_shared_strings_and_columns(env):
    """XLSX: имя листа, общие строки (с разбивкой на runs), inlineStr и пропущенные столбцы."""
    ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    rel = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
    path = env.zip("book.xlsx", {
        "xl/workbook.xml": f'<?xml version="1.0"?><workbook {ns} {rel}><sheets><sheet name="Сотрудники" sheetId="1" r:id="rId1"/></sheets></workbook>',
        "xl/_rels/workbook.xml.rels": '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="x"/></Relationships>',
        "xl/sharedStrings.xml": f'<?xml version="1.0"?><sst {ns}><si><t>ФИО</t></si><si><r><t>Иван</t></r><r><t>ов</t></r></si></sst>',
        "xl/worksheets/sheet1.xml": f'<?xml version="1.0"?><worksheet {ns}><sheetData>'
        '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>Оклад</t></is></c></row>'
        '<row r="2"><c r="A2" t="s"><v>1</v></c><c r="C2"><v>100500</v></c></row>'
        '</sheetData></worksheet>',
    })
    assert env.process(path).text == "Лист: Сотрудники\nФИО\t\tОклад\nИванов\t\t100500"


def test_pdf_without_tools_is_blocked(env):
    """PDF без pdftotext — tool_unavailable."""
    with pytest.raises(FileProcessingException) as e:
        env.process(env.file("doc.pdf", b"%PDF-1.4\n%fake"))
    assert e.value.error_code == "tool_unavailable"
    env.assert_no_temp_left()


def test_image_without_ocr_is_blocked(env):
    """Изображение без OCR — отказ."""
    with pytest.raises(FileProcessingException):
        env.process(env.file("scan.png", PNG))


def test_signature_mismatch_is_rejected(env):
    """Содержимое не соответствует расширению — signature_mismatch."""
    with pytest.raises(FileProcessingException) as e:
        env.process(env.file("fake.docx", "просто текст"))
    assert e.value.error_code == "signature_mismatch"
    env.assert_no_temp_left()


def test_binary_disguised_as_txt_is_rejected(env):
    """Двоичный файл под видом .txt (NUL-байты) — отказ."""
    with pytest.raises(FileProcessingException):
        env.process(env.file("bin.txt", b"MZ\0\0\x90\0binary"))


def test_unsupported_extension_and_limits(env):
    """Неизвестное расширение и превышение размера."""
    with pytest.raises(FileProcessingException) as e:
        env.process(env.file("script.exe", "x"))
    assert e.value.error_code == "unsupported_type"

    env.settings.save({"max_upload_mb": "1"})
    with pytest.raises(FileProcessingException) as e:
        env.processor.process(env.file("big.txt", "x"), "big.txt", 2 * 1024 * 1024, False)
    assert e.value.error_code == "too_large"


def test_empty_content_is_blocked(env):
    """Файл из одних пробелов — отказ."""
    with pytest.raises(FileProcessingException):
        env.process(env.file("empty.txt", " \n\t "))


def test_file_settings_validate_limits_and_languages():
    """Проверяются пределы и языки OCR; пути к инструментам зафиксированы в образе и формой не задаются."""
    from llmp.files import FileProcessingSettings
    ok = {"max_upload_mb": "10", "max_extracted_chars": "200000", "ocr_max_pages": "30", "tool_timeout": "120", "ocr_languages": "rus+eng"}
    assert FileProcessingSettings.validate(ok) == {}
    errors = FileProcessingSettings.validate({**ok, "ocr_languages": "RU", "max_upload_mb": "0"})
    assert set(errors) == {"ocr_languages", "max_upload_mb"}


def _stub(tmp_path, name: str, script: str) -> str:
    path = tmp_path / "bin" / name
    path.parent.mkdir(exist_ok=True)
    path.write_text("#!/bin/sh\n" + script)
    path.chmod(0o700)
    return str(path)


def test_pdf_text_layer_and_scanned_page_via_stub_tools(env, tmp_path):
    """PDF через заглушку pdftotext: текстовый слой извлекается; страница-скан без OCR — блокировка (без poppler на хосте)."""
    long_page = "Договор поставки с Ивановым на сумму сто тысяч рублей"
    env.settings.save({"pdftotext_path": _stub(tmp_path, "pdftotext", f"printf '{long_page}\\f'\n")})
    result = env.process(env.file("doc.pdf", b"%PDF-1.4\n%fake"))
    assert result.text == long_page and result.pages == 1 and not result.ocr_used

    env.settings.save({"pdftotext_path": _stub(tmp_path, "pdftotext", f"printf '{long_page}\\f\\f'\n")})
    with pytest.raises(FileProcessingException) as e:
        env.process(env.file("doc.pdf", b"%PDF-1.4\n%fake"))
    assert e.value.error_code == "ocr_required_unavailable"
    env.assert_no_temp_left()


def test_process_runner_timeout_and_exit_code(tmp_path):
    """Утилита запускается без shell; зависшая — снимается по таймауту."""
    from llmp.files import ProcessRunner
    assert ProcessRunner.run([_stub(tmp_path, "ok", "echo hi; exit 3\n"), "x"], 5) == {"exit": 3, "stdout": b"hi\n", "stderr": ""}
    with pytest.raises(FileProcessingException) as e:
        ProcessRunner.run([_stub(tmp_path, "slow", "exec sleep 10\n")], 1)
    assert e.value.error_code == "extraction_failed"


def test_old_word_doc_goes_through_catdoc_and_fails_closed_without_it(env, tmp_path):
    """.doc (Word 97–2003): текст — через catdoc (здесь исполняемая заглушка); без catdoc — отказ; не OLE2 — отказ по сигнатуре."""
    ole = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" + b"\0" * 504
    with pytest.raises(FileProcessingException) as e:
        env.process(env.file("old.doc", ole))
    assert e.value.error_code == "tool_unavailable"
    stub = tmp_path / "bin" / "catdoc"
    stub.parent.mkdir()
    stub.write_text("#!/bin/sh\nprintf 'Договор поставки\\r\\nИванов Иван\\n'\n")
    stub.chmod(0o700)
    env.settings.save({"catdoc_path": str(stub)})
    env.processor = FileProcessor.with_defaults(env.settings, str(env.tmp_root))
    result = env.process(env.file("old2.doc", ole))
    assert (result.extension, result.text) == ("doc", "Договор поставки\nИванов Иван")
    with pytest.raises(FileProcessingException) as e:
        env.process(env.file("fake.doc", "просто текст"))
    assert e.value.error_code == "signature_mismatch"
    env.assert_no_temp_left()
