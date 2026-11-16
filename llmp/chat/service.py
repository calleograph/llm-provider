"""Оркестратор отправки сообщения (порт src/Llm/Chat/ChatService.php):

  права (AccessPolicy) → [файл: локальное извлечение/OCR] →
  AnonymizationGateway (встроенный сервис, fail closed) → LLM Gateway → сохранение ТОЛЬКО обезличенных реплик → ответ пользователю.

Исходный текст пользователя и текст файла существуют лишь в памяти запроса и нигде не сохраняются и не журналируются.
При ошибке любого шага до LLM запрос наружу не отправляется.

Исключение — явный флаг skip_anonymization в send(): пользователь с правом allow_unanonymized подтверждает, что в
сообщении нет конфиденциальных данных. Текст и файлы не проходят обезличивание (только маскирование секретов, файл —
только локальное извлечение), уходят только в выбранную модель и хранятся открыто; событие LLM_SEND_UNANONYMIZED.

Вложение — словарь {tmp_name, name, size, error} (как $_FILES PHP; error 0 — без ошибки)."""

from __future__ import annotations

import json
import math
import re
import time

from ..anon.gateway import AnonymizationException, AnonymizationGateway
from ..anon.text import AnonymizedText
from ..context import builder as context_builder
from ..context.builder import ContextOverflowException
from ..context.summary import PREFIX as SUMMARY_PREFIX, SummaryService
from ..files.base import FileProcessingException
from ..files.extractors import _ext
from ..files.processor import FileProcessor
from ..llm.access import AccessPolicy
from ..llm.errors import LlmException
from ..llm.gateway import LlmGateway
from ..llm.models import ModelRepository
from ..logs import LlmEventLog
from ..prompt import STYLES
from .conversations import ConversationRepository
from .core import ChatException, ChatUser
from .limits import StorageQuotaService, UsageLimiter
from .projects import SOURCES_SHARES, ProjectRepository
from .settings import ChatSettings, php_int
from .telemetry import Telemetry, elapsed_ms

PHP_WS = " \t\n\r\0\x0b"
UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE = 0, 1, 2
_CONTROL = re.compile(r"[\x00-\x1F\x7F]+")
_E = LlmEventLog


class _File:
    """Вложение в пределах запроса: raw — исходный текст (только до обезличивания), content — итог."""

    def __init__(self, extension: str, size: int, raw: str | None = None, content: AnonymizedText | None = None):
        self.extension, self.size, self.raw, self.content = extension, size, raw, content


class ChatService:
    MAX_UPLOADS = 5   # разом не больше 5 файлов — защита от чрезмерно долгой обработки одного запроса

    def __init__(self, access_policy: AccessPolicy, conversations: ConversationRepository, anonymizer: AnonymizationGateway,
                 llm_gateway: LlmGateway, file_processor: FileProcessor, settings: ChatSettings, events: LlmEventLog,
                 projects: ProjectRepository, usage: UsageLimiter, storage_quota: StorageQuotaService, models: ModelRepository,
                 summaries: SummaryService | None = None, is_http_upload: bool = True):
        """is_http_upload=False — только в тестах: исходный файл копируется, а не переносится."""
        self.access_policy = access_policy
        self.conversations = conversations
        self.anonymizer = anonymizer
        self.llm_gateway = llm_gateway
        self.file_processor = file_processor
        self.settings = settings
        self.events = events
        self.projects = projects
        self.usage = usage
        self.storage_quota = storage_quota
        self.models = models
        self.summaries = summaries
        self.is_http_upload = is_http_upload
        self.telemetry = Telemetry(conversations.conn)

    def send(self, user: ChatUser, ip: str, conversation_id: int | None, provider_id: int, text: str, uploads: list[dict],
             project_id: int | None = None, skip_anonymization: bool = False, model_id: int | None = None) -> dict:
        if len(uploads) > self.MAX_UPLOADS:
            raise ChatException("too_many_files", f"Можно приложить не более {self.MAX_UPLOADS} файлов за раз.")
        log = user.log_name()
        owner = user.owner_key()

        if skip_anonymization and not self.access_policy.can_skip_anonymization(user.group_ids, owner):
            self.events.user(ip, log, _E.OP_CHAT, "LLM_UNANONYMIZED_DENIED")
            raise ChatException("access_denied", "Отправка без обезличивания вам не разрешена.", 403)

        # 0. Блокировка и лимит токенов пользователя на период.
        try:
            self.usage.assert_can_send(owner, user.group_ids)
        except ChatException as e:
            self.events.user(ip, log, _E.OP_CHAT, "LLM_LIMIT_EXCEEDED" if e.error_code == "limit_exceeded" else "LLM_USER_BLOCKED")
            raise

        # 1. Права — на backend, по данным БД на момент запроса.
        allowed = self.access_policy.allowed_providers(user.group_ids, user.domain_id, owner)
        if provider_id not in allowed:
            self.events.user(ip, log, _E.OP_CHAT, "LLM_ACCESS_DENIED", {"provider_id": provider_id})
            raise ChatException("access_denied", "Выбранная нейросеть вам недоступна.", 403)
        provider_name = allowed[provider_id]["name"]
        # Модель: явно выбранная (должна принадлежать провайдеру) или модель провайдера по умолчанию.
        allowed_models = self.models.allowed_for(allowed)
        if model_id is None:
            model_id = self.models.default_for(provider_id)
        if model_id is None or model_id not in allowed_models or allowed_models[model_id]["provider_id"] != provider_id:
            self.events.user(ip, log, _E.OP_CHAT, "LLM_ACCESS_DENIED", {"provider_id": provider_id, "model_id": model_id})
            raise ChatException("access_denied", "Выбранная модель вам недоступна.", 403)

        conversation = None
        if conversation_id is not None:
            conversation = self.conversations.find_owned(conversation_id, owner)
            if conversation is None:
                raise ChatException("not_found", "Диалог не найден.", 404)
            project_id = int(conversation["project_id"]) if conversation["project_id"] is not None else None
        elif project_id is not None and self.projects.find_owned(project_id, owner) is None:
            raise ChatException("not_found", "Проект не найден.", 404)

        text = text.strip(PHP_WS)
        max_chars = max(100, php_int(self.settings.get("max_message_chars")))
        if len(text) > max_chars:
            raise ChatException("message_too_long", f"Сообщение длиннее {max_chars} символов.")
        if text == "" and not uploads:
            raise ChatException("empty", "Введите сообщение или приложите файл.")

        # 2–3. Обезличивание обязательно для всего, что уйдёт наружу, кроме явного skip_anonymization. Сбой файла N —
        # ничего не уходит наружу и не пишется в историю.
        # Локальное извлечение всех вложений, затем текст и вложения — ОДНИМ вызовом (одна сессия замен). Без
        # обезличивания — только маскирование секретов, тоже одним вызовом.
        label = "extracted_unanonymized" if skip_anonymization else "extracted"
        files = [self._extract_upload(user, ip, u, label) for u in uploads]
        parts = [text, *[f.raw for f in files]]
        batch = self._mask_secrets(user, ip, parts) if skip_anonymization else \
            self._anonymize_parts(user, ip, parts, "message" if not files else "message_files")
        text_content = batch[0]
        for i, f in enumerate(files):
            f.content, f.raw = batch[i + 1], None
        del parts, batch
        text = ""
        file_contents = [f.content.with_label(f"[Вложение: {f.extension.upper()}]") for f in files]
        user_content = AnonymizedText.join([text_content, *file_contents])
        if user_content.is_empty():
            raise ChatException("empty", "После обработки не осталось текста для отправки.")
        # Грубая проверка квоты (вопрос с вложениями + ответ сопоставимого объёма) — до дорогого обращения к LLM.
        self.storage_quota.assert_can_store(owner, len(user_content.value.encode()) * 2, user.group_ids)
        if skip_anonymization:
            self.events.user(ip, log, _E.OP_CHAT, "LLM_SEND_UNANONYMIZED", {
                "provider": provider_name, "provider_id": provider_id, "conversation_id": conversation["id"] if conversation else "new",
                "text_chars": len(user_content.value), "attachments": len(files),
            })

        # 4. Контекст по окну выбранной модели — только обезличенные данные.
        plan = self._build_context(user, conversation, project_id, allowed_models[model_id], user_content.value)
        history = [{"role": m["role"], "content": AnonymizedText.from_storage(m["content"])} for m in plan["messages"]]

        if conversation is None or int(conversation["provider_id"] or 0) != provider_id:
            self.events.user(ip, log, _E.OP_CHAT, "LLM_PROVIDER_SELECT_SUCCESS", {
                "provider": provider_name, "provider_id": provider_id, "conversation_id": conversation["id"] if conversation else "new",
            })

        # Лимит расхода на один чат: каждое сообщение заново отправляет историю, длинный чат дорожает — до обращения к LLM.
        self._assert_chat_budget(conversation, plan["report"]["total"])

        # 5. LLM Gateway. Контекст бронируется в лимите до вызова (параллельные вкладки видят бронь), после ответа бронь
        # заменяется фактическим расходом, при отказе возвращается.
        reserved = plan["report"]["total"]
        self.usage.record(owner, reserved, 0)
        started = time.monotonic()
        # телеметрия: что именно ушло наружу — независимо от последующего удаления диалога пользователем
        outbound = lambda **kw: self.telemetry.record(  # noqa: E731
            user, "chat", text=user_content.value, conversation_id=conversation["id"] if conversation else None, project_id=project_id,
            unanonymized=skip_anonymization, attachment_types=", ".join(f.extension for f in files) or None, attachment_count=len(files),
            duration_ms=elapsed_ms(started), **kw)
        try:
            # без обезличивания — только выбранная модель: резервные цепочки не применяются
            result = self.llm_gateway.complete_with_failover(
                user, model_id, history, plan["system"], None,
                {model_id: allowed_models[model_id]} if skip_anonymization else allowed_models,
                self.settings.get("fallback_auto") == "1", 540, plan["report"]["total"])
        except BaseException as e:
            self.usage.record(owner, -reserved, 0)
            if not isinstance(e, LlmException):
                raise
            self.events.user(ip, log, _E.OP_CHAT, "LLM_REQUEST_FAILED", {
                "provider": provider_name, "provider_id": provider_id, "model_id": model_id, "reason": e.error_code,
                "http_status": e.http_status, "last_reason": e.provider_error_type,
            })
            outbound(provider_id=provider_id, provider_name=provider_name, model_id=model_id,
                     model_name=allowed_models[model_id]["display_name"], outcome="error:" + e.error_code)
            raise ChatException("llm_" + e.error_code, e.user_message(), 403 if e.error_code == "access_denied" else 502) from None

        # Расход: данные провайдера (вся история + контекст проекта), иначе оценка.
        if result.input_tokens is not None or result.output_tokens is not None:
            charged = int(result.input_tokens or 0) + int(result.output_tokens or 0)
        else:
            charged = plan["report"]["total"] + UsageLimiter.estimate_tokens(len(result.text))
        self.usage.record(owner, charged - reserved)
        answered = result.model_id or model_id
        outbound_id = outbound(provider_id=result.provider_id or provider_id, provider_name=result.provider_name or provider_name,
                               model_id=answered, model_name=allowed_models.get(answered, {}).get("display_name") or result.model,
                               fallback_from=result.fallback_from, input_tokens=result.input_tokens, output_tokens=result.output_tokens,
                               tokens=charged)

        if result.fallback_from is not None:
            self.events.user(ip, log, _E.OP_CHAT, "LLM_FALLBACK_USED", {
                "requested_model_id": model_id, "answered_model_id": result.model_id, "answered_provider": result.provider_name,
                "attempts": result.attempts,
            })
        self.events.user(ip, log, _E.OP_CHAT, "LLM_REQUEST_SUCCESS", {
            "provider": result.provider_name or provider_name, "provider_id": result.provider_id or provider_id, "model": result.model,
            "input_tokens": result.input_tokens, "output_tokens": result.output_tokens, "usage_charged": charged,
            "project_id": project_id, "duration_ms": result.duration_ms,
        })

        # 6. Сохранение: только обезличенный вопрос и ответ. Несколько вложений — одной строкой через запятую и суммой.
        attachment_type = ", ".join(f.extension for f in files) if files else None
        attachment_size = sum(f.size for f in files) if files else None
        report = {**plan["report"], "input_tokens": int(result.input_tokens or 0), "output_tokens": int(result.output_tokens or 0)}

        def save() -> dict:
            conv = conversation
            if conv is None:
                title = self._title_from(user_content.value)
                cid = self.conversations.create(owner, title, provider_id, project_id)
                conv = {"id": cid, "title": title, "created": True}
            else:
                self.conversations.touch(int(conv["id"]), provider_id)
            cid = int(conv["id"])
            user_message_id = self.conversations.add_message(cid, "user", user_content.value, provider_id, None, attachment_type,
                                                             attachment_size, None, skip_anonymization)
            # ответ на сообщение без обезличивания обычно цитирует те же данные — помечается так же
            assistant_message_id = self.conversations.add_message(cid, "assistant", result.text, result.provider_id or provider_id,
                                                                  result.model, None, None, charged, skip_anonymization)
            self.conversations.set_context_report(assistant_message_id, {**report, "fallback_from": result.fallback_from,
                                                                         "truncated": result.truncated()})
            self.conversations.set_model(cid, model_id)
            self.conversations.add_spent(cid, charged)
            if project_id is not None:
                self.projects.touch(project_id)
            return {
                "conversation": {"id": cid, "title": str(conv["title"]), "provider_id": provider_id, "project_id": project_id,
                                 "pinned": bool(conv.get("pinned") or False), **self.chat_budget(int(conv.get("tokens_spent") or 0) + charged)},
                "created": bool(conv.get("created")),
                "user_message_id": user_message_id,
                "assistant_message_id": assistant_message_id,
            }

        saved = self.conversations.transactional(save)
        if saved["created"]:
            self.telemetry.attach_conversation(outbound_id, saved["conversation"]["id"])
            self.events.user(ip, log, _E.OP_CHAT, "CONVERSATION_CREATE_SUCCESS", {"conversation_id": saved["conversation"]["id"], "provider": provider_name})

        return {
            "usage": self.usage.status(owner, user.group_ids),
            "storage": self.storage_quota.status(owner, user.group_ids),
            "conversation": saved["conversation"],
            "user_message": {
                "id": saved["user_message_id"], "role": "user", "content": user_content.value,
                "attachment_type": attachment_type, "attachment_size": attachment_size, "unanonymized": skip_anonymization,
            },
            "assistant_message": {
                "id": saved["assistant_message_id"], "role": "assistant", "content": result.text,
                "model": result.model, "provider": result.provider_name or provider_name,
                # ответила резервная модель — пользователь видит это явно
                "fallback_from": result.fallback_from, "truncated": result.truncated(),
                "context_report": report,
            },
            # Обслуживание контекста (сводка, состояние проекта) — вызывающий выполняет его, уже отдав ответ.
            "maintenance": {"conversation_id": saved["conversation"]["id"], "model_id": model_id, "project_id": project_id}
            if plan["needsSummary"] else None,
        }

    # ---- вложения ----
    @staticmethod
    def _upload_meta(upload: dict) -> tuple[str, dict]:
        extension = _ext(str(upload["name"])).lower()
        return extension, {"type": extension or "unknown", "size": int(upload["size"])}

    @staticmethod
    def _check_upload_error(upload: dict) -> None:
        error = int(upload.get("error") or 0)
        if error in (UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE):
            raise FileProcessingException("too_large", "Превышен upload_max_filesize.")
        if error != UPLOAD_ERR_OK:
            raise FileProcessingException("upload_error", f"Код ошибки загрузки {error}")

    def _handle_upload(self, user: ChatUser, ip: str, upload: dict) -> _File:
        """Источник проекта: локальное извлечение, затем обезличивание текста файла."""
        file = self._extract_upload(user, ip, upload, "extracted")
        file.content, file.raw = self._anonymize(user, ip, file.raw, "file"), None
        return file

    def _extract_upload(self, user: ChatUser, ip: str, upload: dict, result_label: str) -> _File:
        """Локальное извлечение текста вложения без внешних сервисов; исходный текст — только в памяти запроса."""
        extension, fields = self._upload_meta(upload)
        try:
            self._check_upload_error(upload)
            file = self.file_processor.process(str(upload["tmp_name"]), str(upload["name"]), int(upload["size"]), self.is_http_upload)
            self.events.user(ip, user.log_name(), _E.OP_FILE, "FILE_UPLOAD_SUCCESS", {
                **fields, "result": result_label, "ocr": file.ocr_used, "pages": file.pages, "ocr_pages": file.ocr_pages,
                "text_chars": len(file.text),
            })
        except FileProcessingException as e:
            self.events.user(ip, user.log_name(), _E.OP_FILE, "FILE_UPLOAD_FAILED", {**fields, "reason": e.error_code})
            raise ChatException("file_" + e.error_code, e.user_message(), 422) from None
        return _File(extension, int(upload["size"]), raw=file.text)

    # ---- обезличивание ----
    def _anonymize_parts(self, user: ChatUser, ip: str, parts: list[str], purpose: str) -> list[AnonymizedText]:
        """Совместное обезличивание частей одного запроса (сообщение + вложения)."""
        try:
            batch = self.anonymizer.anonymize_many(parts, purpose)
        except AnonymizationException as e:
            self.events.user(ip, user.log_name(), _E.OP_ANONYMIZATION, "ANONYMIZATION_FAILED",
                             {"purpose": purpose, "reason": e.error_code, "parts": len(parts)})
            raise ChatException("anonymization_failed", e.user_message(), 503) from None
        self.events.user(ip, user.log_name(), _E.OP_ANONYMIZATION, "ANONYMIZATION_SUCCESS", {
            "purpose": purpose, "parts": len(parts), "text_chars": sum(len(p) for p in parts),
            "replaced": batch.replaced, "categories": ",".join(f"{k}:{v}" for k, v in batch.stats.items()), "duration_ms": batch.duration_ms,
        })
        return batch.texts

    def _mask_secrets(self, user: ChatUser, ip: str, parts: list[str]) -> list[AnonymizedText]:
        try:
            batch = self.anonymizer.mask_secrets(parts, "unanonymized")
        except AnonymizationException as e:
            self.events.user(ip, user.log_name(), _E.OP_ANONYMIZATION, "SECRET_MASK_FAILED", {"reason": e.error_code})
            raise ChatException("anonymization_failed", "Не удалось проверить сообщение на пароли и ключи — запрос не отправлен.", 503) from None
        if batch.replaced > 0:
            self.events.user(ip, user.log_name(), _E.OP_ANONYMIZATION, "SECRET_MASKED", {"replaced": batch.replaced})
        return batch.texts

    def _project_title(self, user: ChatUser, ip: str, title: str, default: str) -> str:
        """Заголовок блока или источника проекта уходит в инструкцию модели при каждом запросе — обезличивается так же,
        как текст. Пустой — заголовок по умолчанию."""
        title = _CONTROL.sub(" ", title).strip(PHP_WS)
        if title == "":
            return default
        title = self._anonymize(user, ip, title[:120], "project_title").value.strip(PHP_WS)
        return title[:160] if title != "" else default

    def _anonymize(self, user: ChatUser, ip: str, text: str, purpose: str) -> AnonymizedText:
        if text.strip(PHP_WS) == "":
            return AnonymizedText.from_storage("")
        try:
            result = self.anonymizer.anonymize(text, purpose)
        except AnonymizationException as e:
            self.events.user(ip, user.log_name(), _E.OP_ANONYMIZATION, "ANONYMIZATION_FAILED", {"purpose": purpose, "reason": e.error_code})
            raise ChatException("anonymization_failed", e.user_message(), 503) from None
        self.events.user(ip, user.log_name(), _E.OP_ANONYMIZATION, "ANONYMIZATION_SUCCESS",
                         {"purpose": purpose, "text_chars": len(text), "result_chars": len(result.value)})
        return result

    # ---- проекты и настройки ответа ----
    def _assert_project(self, user: ChatUser, project_id: int) -> None:
        if self.projects.find_owned(project_id, user.owner_key()) is None:
            raise ChatException("not_found", "Проект не найден.", 404)

    def set_project_instructions(self, user: ChatUser, ip: str, project_id: int, instructions: str) -> str:
        """Устаревшее единое поле инструкций проекта: обезличивается и хранится только обезличенным."""
        self._assert_project(user, project_id)
        instructions = instructions.strip(PHP_WS)
        if len(instructions) > 8000:
            raise ChatException("too_long", "Инструкции проекта — не длиннее 8000 символов.")
        anonymized = self._anonymize(user, ip, instructions, "project_instructions")
        self.projects.set_instructions(project_id, user.owner_key(), anonymized)
        self.events.user(ip, user.log_name(), _E.OP_CHAT, "PROJECT_INSTRUCTIONS_UPDATE_SUCCESS",
                         {"project_id": project_id, "text_chars": len(anonymized.value)})
        return anonymized.value

    def set_user_preferences(self, user: ChatUser, ip: str, style: str | None, instructions: str) -> dict:
        """Стиль и личные постоянные инструкции (слой 6): инструкции обезличиваются, пользователь видит итог."""
        style = self._check_style(style)
        anon = self._anonymize_instruction(user, ip, instructions, "user_instructions")
        self.usage.touch_profile(user)
        self.conversations.set_user_preferences(user.owner_key(), style, anon)
        self.events.user(ip, user.log_name(), _E.OP_CHAT, "USER_PREFERENCES_UPDATE", {"style": style or "default", "instructions_chars": len(anon)})
        return {"style": style, "instructions": anon}

    def set_conversation_preferences(self, user: ChatUser, ip: str, conversation_id: int, style: str | None, instructions: str) -> dict:
        if self.conversations.find_owned(conversation_id, user.owner_key()) is None:
            raise ChatException("not_found", "Диалог не найден.", 404)
        style = self._check_style(style)
        anon = self._anonymize_instruction(user, ip, instructions, "dialog_instructions")
        self.conversations.set_conversation_preferences(conversation_id, user.owner_key(), style, anon)
        self.events.user(ip, user.log_name(), _E.OP_CHAT, "CONVERSATION_PREFERENCES_UPDATE",
                         {"conversation_id": conversation_id, "style": style or "default", "instructions_chars": len(anon)})
        return {"style": style, "instructions": anon}

    def set_project_description(self, user: ChatUser, ip: str, project_id: int, description: str) -> str:
        self._assert_project(user, project_id)
        anon = self._anonymize_instruction(user, ip, description[:1000], "project_description")
        self.projects.set_description(project_id, anon)
        return anon

    MEMORY_MAX_CHARS = 4000   # память проекта — короткие факты и решения

    def set_project_memory(self, user: ChatUser, ip: str, project_id: int, memory: str) -> str:
        """Правка памяти проекта пользователем: текст уходит в модель — обезличивается; пустой — очистить."""
        self._assert_project(user, project_id)
        memory = memory.strip(PHP_WS)
        if len(memory) > self.MEMORY_MAX_CHARS:
            raise ChatException("too_long", f"Память проекта — не длиннее {self.MEMORY_MAX_CHARS} символов.")
        anon = self._anonymize(user, ip, memory, "project_memory")
        self.projects.set_memory(project_id, user.owner_key(), anon)
        self.events.user(ip, user.log_name(), _E.OP_CHAT, "PROJECT_MEMORY_UPDATE", {"project_id": project_id, "text_chars": len(anon.value)})
        return anon.value

    def set_project_settings(self, user: ChatUser, ip: str, project_id: int, raw: str) -> dict:
        """Настройки проекта (JSON от клиента): модель — из доступных пользователю, стиль — из разрешённых."""
        self._assert_project(user, project_id)
        try:
            data = json.loads(raw)
        except ValueError:
            data = None
        if not isinstance(data, dict):
            raise ChatException("invalid_settings", "Неверный формат настроек проекта.", 422)
        model_id = php_int(str(data.get("model_id") or 0))
        if model_id and model_id not in self.models.allowed_for(self.access_policy.allowed_providers(user.group_ids, user.domain_id, user.owner_key())):
            raise ChatException("access_denied", "Выбранная модель вам недоступна.", 403)
        share = php_int(str(data.get("sources_share") or 40))
        settings = {"model_id": model_id, "style": self._check_style(str(data.get("style") or "")) or "",
                    "use_memory": bool(data.get("use_memory", True)), "use_sources": bool(data.get("use_sources", True)),
                    "sources_share": share if share in SOURCES_SHARES else 40}
        self.projects.set_settings(project_id, user.owner_key(), settings)
        self.events.user(ip, user.log_name(), _E.OP_CHAT, "PROJECT_SETTINGS_UPDATE", {"project_id": project_id, **settings})
        return settings

    @staticmethod
    def project_layer(parts: dict) -> str:
        """Слой 5 промпта: описание и инструкции проекта (из ProjectRepository.context_parts)."""
        return ((("Проект: " + parts["description"] + "\n") if parts["description"] != "" else "") + parts["instructions"]).strip(PHP_WS)

    @staticmethod
    def project_context_tokens(parts: dict) -> dict:
        """Оценка токенов, которые проект добавляет к каждому запросу (без учёта настроек-выключателей)."""
        return {"instructions": context_builder.tokens(ChatService.project_layer(parts)), "memory": context_builder.tokens(parts["state"]),
                "sources": sum(context_builder.tokens(x["text"]) for x in parts["sources"])}

    def _project_memory_on(self, owner: str, project_id: int) -> bool:
        project = self.projects.find_owned(project_id, owner)
        return project is not None and project["settings"]["use_memory"]

    def allowed_styles(self) -> dict[str, str]:
        """Разрешённые администратором стили: ключ → название."""
        return {k: STYLES[k][0] for k in self.settings.get("styles_allowed").split(",") if k and k in STYLES}

    def _check_style(self, style: str | None) -> str | None:
        if not style:
            return None
        if style not in self.allowed_styles():
            raise ChatException("style_denied", "Этот стиль ответа недоступен.", 422)
        return style

    def _anonymize_instruction(self, user: ChatUser, ip: str, text: str, purpose: str) -> str:
        text = text.strip(PHP_WS)
        if len(text) > 4000:
            raise ChatException("too_long", "Инструкция — не длиннее 4000 символов.")
        return "" if text == "" else self._anonymize(user, ip, text, purpose).value

    def add_project_instruction(self, user: ChatUser, ip: str, project_id: int, title: str, text: str) -> dict:
        """Именованный блок инструкций проекта — короткое правило поведения модели. Хранится в llm_project_sources
        (source_type = 'instruction') со своими лимитами: число блоков и длина одного блока."""
        self._assert_project(user, project_id)
        text = text.strip(PHP_WS)
        if text == "":
            raise ChatException("empty", "Введите текст инструкции.")
        max_chars = max(100, php_int(self.settings.get("project_instruction_max_chars")))
        if len(text) > max_chars:
            raise ChatException("too_long", f"Инструкция длиннее {max_chars} символов — сократите или разбейте на несколько блоков.")
        max_blocks = max(1, php_int(self.settings.get("project_instructions_max_blocks")))
        if self.projects.instruction_count(project_id) >= max_blocks:
            raise ChatException("instructions_limit", f"В проекте уже максимум блоков инструкций ({max_blocks}). Удалите ненужный, чтобы добавить новый.", 422)

        anonymized = self._anonymize(user, ip, text, "project_instructions")
        if anonymized.is_empty():
            raise ChatException("empty", "После обработки не осталось текста для сохранения.")
        self.storage_quota.assert_can_store(user.owner_key(), len(anonymized.value.encode()), user.group_ids)

        title = self._project_title(user, ip, title, f"Инструкция {self.projects.instruction_count(project_id) + 1}")
        sid = self.projects.add_source(project_id, title, ProjectRepository.TYPE_INSTRUCTION, None, anonymized)
        self.events.user(ip, user.log_name(), _E.OP_CHAT, "PROJECT_INSTRUCTION_ADD_SUCCESS",
                         {"project_id": project_id, "source_id": sid, "text_chars": len(anonymized.value)})
        return {"id": sid, "title": title, "chars": len(anonymized.value)}

    def add_project_source(self, user: ChatUser, ip: str, project_id: int, title: str, text: str, upload: dict | None) -> dict:
        """Источник проекта: файл (та же цепочка, что для вложений чата) или вставленный текст; хранится обезличенным."""
        self._assert_project(user, project_id)
        self.usage.assert_can_send(user.owner_key(), user.group_ids)

        # До обработки файла — иначе впустую тратится время на файл, который будет отклонён по лимиту.
        max_files = max(1, php_int(self.settings.get("project_sources_max_files")))
        if self.projects.source_count(project_id) >= max_files:
            raise ChatException("sources_limit", f"В проекте уже максимум источников ({max_files}). Удалите ненужный, чтобы добавить новый.", 422)

        if upload is not None:
            file = self._handle_upload(user, ip, upload)
            content, stype, file_type = file.content, "file", file.extension
            # Исходное имя файла может содержать ПДн — по умолчанию не сохраняется.
            default_title = f"Документ {file.extension.upper()} · {max(1, math.ceil(file.size / 1024))} КБ"
        else:
            if text.strip(PHP_WS) == "":
                raise ChatException("empty", "Вставьте текст источника или приложите файл.")
            content, stype, file_type = self._anonymize(user, ip, text, "project_source"), "text", None
            default_title = f"Текст · {len(content.value)} симв."
        text = ""

        limit = max(1000, php_int(self.settings.get("project_sources_max_chars")))
        if self.projects.total_source_chars(project_id) + len(content.value) > limit:
            raise ChatException("sources_limit", f"Превышен суммарный объём источников проекта ({limit} символов). Удалите ненужные источники.", 422)
        self.storage_quota.assert_can_store(user.owner_key(), len(content.value.encode()), user.group_ids)

        title = self._project_title(user, ip, title, default_title)
        sid = self.projects.add_source(project_id, title, stype, file_type, content)
        self.events.user(ip, user.log_name(), _E.OP_CHAT, "PROJECT_SOURCE_ADD_SUCCESS",
                         {"project_id": project_id, "source_id": sid, "type": file_type or "text", "text_chars": len(content.value)})
        return {"id": sid, "title": title, "source_type": stype, "file_type": file_type, "chars": len(content.value)}

    # ---- контекст ----
    def _build_context(self, user: ChatUser, conversation: dict | None, project_id: int | None, model: dict, current: str) -> dict:
        owner = user.owner_key()
        project = self.projects.context_parts(project_id, owner) if project_id is not None else None
        history = self.conversations.messages(int(conversation["id"]), owner) if conversation is not None else []
        summary = self.summaries.get(int(conversation["id"])) if conversation is not None and self.summaries is not None else None
        prefs = self.conversations.preferences(int(conversation["id"]) if conversation is not None else None, owner)
        allowed_styles = [s for s in self.settings.get("styles_allowed").split(",") if s]
        ps = project["settings"] if project is not None else {}
        # Стиль: чат → проект → пользователь → администратор.
        style = (conversation or {}).get("style") or ps.get("style") or prefs["style"] or self.settings.get("style_default")
        layers = {
            "admin": self.settings.get("system_prompt").strip(PHP_WS),
            "style": style if style in allowed_styles else self.settings.get("style_default"),
            "model": str(model.get("system_hint") or "").strip(PHP_WS),
            "project": self.project_layer(project) if project is not None else "",
            "dialog": prefs["dialog"] or "",
            "personal": prefs["personal"] or "",
        }
        try:
            return context_builder.build(
                int(model["context_window"]), int(model["max_output_tokens"]), layers, project["sources"] if ps.get("use_sources") else [],
                [{"id": int(m["id"]), "role": str(m["role"]), "content": str(m["content"]), "unanonymized": bool(m.get("unanonymized"))} for m in history],
                current,
                {"text": SUMMARY_PREFIX + "\n\n" + summary["text"], "covered_until_id": summary["covered_until_id"]} if summary is not None else None,
                project["state"] if ps.get("use_memory") else None,
                math.ceil(max(1000, php_int(self.settings.get("history_max_chars"))) / context_builder.CHARS_PER_TOKEN),
                int(ps.get("sources_share") or 40) / 100)
        except ContextOverflowException as e:
            raise ChatException(
                "context_overflow",
                f"Сообщение вместе с инструкциями не помещается в контекст выбранной модели (нужно ~{e.required} токенов, доступно {e.budget}). "
                "Сократите сообщение или выберите модель с бо́льшим окном.", 422) from None

    def chat_budget(self, spent: int) -> dict:
        """Расход токенов чата и лимит (0 — без ограничения) — для интерфейса."""
        return {"tokens_spent": int(spent), "token_limit": max(0, php_int(self.settings.get("chat_token_limit")))}

    def _assert_chat_budget(self, conversation: dict | None, needed: int) -> None:
        """Чат, в котором расход вместе со следующим запросом превысит лимит, закрыт для новых сообщений: нужен новый чат."""
        limit = self.chat_budget(0)["token_limit"]
        spent = int(conversation.get("tokens_spent") or 0) if conversation else 0
        if limit and spent + needed > limit:
            raise ChatException(
                "chat_limit",
                f"Этот чат израсходовал лимит токенов ({spent:,} из {limit:,}): каждое сообщение заново отправляет модели всю историю, "
                "и дальше разговор стоил бы слишком дорого. Начните новый чат — для новой темы так и лучше.".replace(",", " "), 429)

    def maintain_context(self, user: ChatUser, task: dict) -> None:
        """Обслуживание контекста после ответа (ответ уже отдан): сводка ранней части диалога и состояние проекта.
        Сбой не влияет на пользователя; токены сводок списываются с его лимита."""
        if self.summaries is None:
            return
        owner = user.owner_key()
        allowed_models = self.models.allowed_for(self.access_policy.allowed_providers(user.group_ids, user.domain_id, owner))
        summary_model = php_int(self.settings.get("summary_model_id"))
        model_id = summary_model if summary_model in allowed_models else task["model_id"]
        if model_id not in allowed_models:
            return
        history = [{"id": int(m["id"]), "role": str(m["role"]), "content": str(m["content"]), "unanonymized": bool(m.get("unanonymized"))}
                   for m in self.conversations.messages(task["conversation_id"], owner)]
        auto = self.settings.get("fallback_auto") == "1"
        self.summaries.spent_tokens = 0
        step = lambda kind, call: self._outbound_step(user, task, kind, allowed_models[model_id], call)  # noqa: E731
        if step("summary", lambda: self.summaries.update(user, task["conversation_id"], history, model_id, allowed_models, 6, auto)) \
                and task["project_id"] is not None and self._project_memory_on(owner, task["project_id"]):
            summary = self.summaries.get(task["conversation_id"])
            if summary is not None:
                step("project_state", lambda: self.summaries.update_project_state(user, task["project_id"], summary["text"], model_id,
                                                                                 allowed_models, auto))
        if self.summaries.spent_tokens > 0:
            self.usage.record(owner, self.summaries.spent_tokens)
            self.conversations.add_spent(task["conversation_id"], self.summaries.spent_tokens)   # сводки тоже расходуют токены чата

    def _outbound_step(self, user: ChatUser, task: dict, kind: str, model: dict, call) -> bool:
        """Шаг обслуживания контекста с записью в телеметрию; обращение к LLM состоялось, если выросли потраченные токены.
        shortcut: неудачные обращения сводок и ответ резервной модели здесь не видны — нужна запись внутри SummaryService._ask."""
        before, started = self.summaries.spent_tokens, time.monotonic()
        done = call()
        if self.summaries.spent_tokens > before:
            self.telemetry.record(user, kind, provider_id=model["provider_id"], provider_name=model["provider_name"], model_id=model["id"],
                                  model_name=model["display_name"], conversation_id=task["conversation_id"], project_id=task["project_id"],
                                  tokens=self.summaries.spent_tokens - before, duration_ms=elapsed_ms(started), outcome="ok" if done else "empty")
        return done

    @staticmethod
    def _title_from(anonymized_text: str) -> str:
        line = re.sub(r"\s+", " ", re.sub(r"\A\[Вложение: [^\]]+\]\s*", "", anonymized_text)).strip(PHP_WS)
        if line == "":
            return "Новый диалог"
        return line[:60] + "…" if len(line) > 60 else line
