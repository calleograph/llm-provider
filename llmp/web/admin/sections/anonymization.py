"""Раздел «Обезличивание»: состояние встроенного сервиса, настройки его движка, локальная обработка файлов и
лаборатория «что уйдёт в нейросеть». Режим обезличивания один — выбирать и подключать нечего."""

from __future__ import annotations

import json
import os
import re
import shutil
import tempfile

from ....anon import settings as s
from ....anon.gateway import AnonymizationException, AnonymizationGateway
from ....files.base import FileProcessingException, ProcessRunner, php_trim
from ....files.settings import FileProcessingSettings
from ....llm.providers import php_empty
from ..base import FORM_STALE, AdminPage
from .llm_models import num

ACTIONS = ("save_anonymization", "check_anonymization", "save_file_processing", "lab_anonymization")
_INTERNAL_FIELDS = ("internal_profile", "internal_person_format", "internal_org_scope", "internal_timeout", "internal_terms",
                    "internal_exceptions", "internal_domains")
_FILE_FIELDS = ("max_upload_mb", "max_extracted_chars", "ocr_languages", "ocr_max_pages", "tool_timeout")


def _lines(text: str) -> int:
    """count(array_filter(explode("\\n", …))): пустые строки и «0» не считаются."""
    return len([x for x in text.split("\n") if x not in ("", "0")])


def _ocr_languages(file_settings: FileProcessingSettings) -> list[str] | None:
    """Языки, установленные для tesseract (`tesseract --list-langs`); None — tesseract недоступен или не ответил."""
    try:
        result = ProcessRunner.run([file_settings.get("tesseract_path"), "--list-langs"], 5, 64 * 1024)
    except FileProcessingException:
        return None
    if result["exit"] != 0:
        return None
    # первая строка — заголовок «List of available languages … :», дальше по языку в строке; osd — служебный
    lines = result["stdout"].decode("utf-8", "replace").splitlines()[1:]
    return [x.strip() for x in lines if re.fullmatch(r"[a-z_]{3,8}", x.strip()) and x.strip() != "osd"]


def _lab(page: AdminPage, lab_input: dict) -> tuple[dict | None, str | None]:
    """Тот же путь, что у сообщения в чате (извлечение файла → обезличивание → проверка). В журнал — объём и число замен."""
    m = page.module
    lab_input["text"] = page.post("lab_text")
    lab_input["profile"] = page.post("lab_profile") if page.post("lab_profile") in s.INTERNAL_PROFILES else ""
    source, fmt = lab_input["text"], "текст"
    try:
        upload = page.file("lab_file")
        if upload is not None:
            fd, tmp = tempfile.mkstemp(prefix="lab_")
            try:
                with os.fdopen(fd, "wb") as fh:
                    shutil.copyfileobj(upload.file, fh)
                extracted = m.file_processor.process(tmp, upload.filename, os.path.getsize(tmp))
            finally:
                if os.path.exists(tmp):
                    os.unlink(tmp)
            source = extracted.text
            fmt = extracted.extension.upper() + (", OCR" if extracted.ocr_used else "")
        if php_trim(source) == "":
            raise ValueError("Вставьте текст или выберите файл.")
        if len(source) > 300000:
            raise ValueError("Текст длиннее 300 000 знаков — разбейте его на части.")
        batch = m.anonymizer.anonymize_many([source], "admin_lab", True, lab_input["profile"] or None)
        m.events.admin(page.admin, page.ip, "anonymization_lab", True, {"chars": len(source), "format": fmt, "replaced": batch.replaced})
        return {"source": source, "text": batch.texts[0].value, "batch": batch, "format": fmt}, None
    except FileProcessingException as e:
        return None, e.user_message()
    except AnonymizationException as e:
        m.events.admin(page.admin, page.ip, "anonymization_lab", False, {"chars": len(source), "reason": e.error_code})
        return None, AnonymizationGateway.describe(e) + (" " + str(e) if e.error_code == "not_clean" else "")
    except ValueError as e:
        return None, str(e)


def handle(page: AdminPage) -> str:
    m = page.module
    settings, file_settings, events = m.anonymization_settings, m.file_settings, m.events
    admin, ip = page.admin, page.ip
    field_errors: dict[str, str] = {}
    form_values: dict | None = None
    file_errors: dict[str, str] = {}
    file_form_values: dict | None = None
    check_result = lab_result = lab_error = None
    lab_input = {"text": "", "profile": ""}

    if page.is_post(*ACTIONS):
        if not page.csrf_ok():
            page.flash_error = FORM_STALE
        elif page.action == "save_anonymization":
            values = {f: php_trim(page.post(f).replace("\r\n", "\n")) for f in _INTERNAL_FIELDS}
            actions = {}
            for key, act in page.form.multi_items():
                cat = key[len("internal_action["):-1] if key.startswith("internal_action[") and key.endswith("]") else None
                if cat in s.INTERNAL_CATEGORIES and isinstance(act, str) and act in s.INTERNAL_ACTIONS:
                    actions[cat] = act
            values["internal_custom_actions"] = json.dumps(actions, separators=(",", ":")) if actions else ""
            field_errors = s.AnonymizationSettings.validate(values)
            if field_errors:
                page.flash_error = "Проверьте выделенные поля формы."
                form_values = {**settings.all(), **values}
            else:
                settings.save(values)
                # В журнал — только какие параметры менялись и объёмы словарей, без самих терминов.
                events.admin(admin, ip, "anonymization_settings_update", True, {
                    "engine_profile": values["internal_profile"],
                    "terms": _lines(values["internal_terms"]), "exceptions": _lines(values["internal_exceptions"]),
                    "domains": _lines(values["internal_domains"]),
                })
                page.flash_success = "Настройки встроенного обезличивания сохранены."
        elif page.action == "lab_anonymization":
            lab_result, lab_error = _lab(page, lab_input)
        elif page.action == "check_anonymization":
            check_result = m.anonymizer.test_connection()
            events.admin(admin, ip, "anonymization_check", check_result["ok"], {"duration_ms": check_result["duration_ms"]})
        else:   # save_file_processing
            values = {f: php_trim(page.post(f)) for f in _FILE_FIELDS}
            values["ocr_enabled"] = "0" if php_empty(page.post("ocr_enabled")) else "1"
            file_errors = FileProcessingSettings.validate(values)
            if file_errors:
                page.flash_error = "Проверьте выделенные поля обработки файлов."
                file_form_values = values
            else:
                file_settings.save(values)
                events.admin(admin, ip, "file_processing_settings_update", True, {
                    "max_upload_mb": values["max_upload_mb"], "ocr_enabled": values["ocr_enabled"], "ocr_languages": values["ocr_languages"],
                })
                page.flash_success = "Параметры обработки файлов сохранены."

    current = form_values if form_values is not None else settings.all()
    try:
        custom_actions = json.loads(current.get("internal_custom_actions", ""))
    except ValueError:
        custom_actions = {}
    last_check_at = settings.get("last_check_at")
    tools = {
        "pdftotext": ProcessRunner.is_executable(file_settings.get("pdftotext_path")),
        "pdftoppm": ProcessRunner.is_executable(file_settings.get("pdftoppm_path")),
        "tesseract": ProcessRunner.is_executable(file_settings.get("tesseract_path")),
    }
    try:
        shutil.rmtree(m.file_processor._create_work_dir())
        tmp_ok = True
    except (FileProcessingException, OSError):
        tmp_ok = False
    lab = None
    if lab_result is not None:
        b = lab_result["batch"]
        cat = lambda c, secret_label: s.INTERNAL_CATEGORIES.get(c, secret_label if c == "secret" else c)  # noqa: E731
        lab = {
            **lab_result, "chars": num(len(lab_result["source"])),
            "stats": " · ".join(f"{cat(k, 'Секреты')} — {v}" for k, v in b.stats.items()),
            "replacements": [{**r, "category_label": cat(str(r.get("category") or ""), "Пароли, токены, ключи")}
                             for r in b.replacements[:300] if isinstance(r, dict)],
            "candidates": ", ".join(f"{c.get('original') or ''} ({c.get('reason') or ''})" for c in b.candidates[:50] if isinstance(c, dict)),
        }

    page.page_title = "Обезличивание"
    return page.render(
        "admin/sections/anonymization.j2",
        s=s, settings=settings, current=current, files=file_form_values if file_form_values is not None else file_settings.all(),
        field_errors=field_errors, file_errors=file_errors, check_result=check_result,
        last_check_at=last_check_at, last_check_ok=settings.get("last_check_ok") == "1", tools=tools, tmp_ok=tmp_ok,
        ocr_langs=_ocr_languages(file_settings), service_error=m.anonymizer.ready_error(),
        custom_actions=custom_actions if isinstance(custom_actions, dict) else {},
        lab_input=lab_input, lab=lab, lab_error=lab_error,
        lab_accept=",".join("." + e for e in m.file_processor.supported_extensions()),
    )
