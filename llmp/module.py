"""Сборка сервисов на одно соединение с БД (аналог LlmModule PHP): страницы, API и тесты получают их одинаково.
Объекты создаются при первом обращении и живут в пределах запроса."""

from __future__ import annotations

import sqlite3
from functools import cached_property
from typing import Callable

from . import certs, dictation, domain
from .anon.gateway import AnonymizationGateway
from .anon.settings import AnonymizationSettings
from .chat import (ChatSettings, ConversationRepository, InactivityCleanupService, LimitCategoryRepository,
                   ProjectRepository, StorageQuotaService, UsageLimiter)
from .chat.service import ChatService
from .context.summary import SummaryService
from .files.processor import FileProcessor
from .files.settings import FileProcessingSettings
from .http import HttpTransport, HttpxTransport
from .ldap.config import LdapConfigRepository
from .llm.access import AccessPolicy, UserProviderRepository
from .llm.adapters import AdapterRegistry
from .llm.gateway import LlmGateway
from .llm.health import ModelHealthRepository
from .llm.models import ModelRepository
from .llm.providers import ProviderRepository
from .logs import AppLogger, AuditLogger, DiagnosticLogger, LlmEventLog
from .mail import Alerts, MailQueue, MailSettings
from .settings import AppSettings


class LlmModule:
    def __init__(self, conn: sqlite3.Connection, app_settings: AppSettings | None = None, transport: HttpTransport | None = None,
                 sleeper: Callable[[float], None] | None = None):
        self.conn = conn
        self.app_settings = app_settings or AppSettings(conn)
        self._transport = transport
        self._sleeper = sleeper

    # ---- общее ----
    @cached_property
    def transport(self) -> HttpTransport:
        return self._transport or HttpxTransport()

    @cached_property
    def diagnostics(self) -> DiagnosticLogger:
        return DiagnosticLogger(self.conn)

    @cached_property
    def audit(self) -> AuditLogger:
        return AuditLogger(self.conn)

    @cached_property
    def events(self) -> LlmEventLog:
        return LlmEventLog(AppLogger(self.conn), self.audit)

    @cached_property
    def certificates(self) -> certs.CertificateRepository:
        return certs.CertificateRepository(self.conn)

    @cached_property
    def ca_bundle(self) -> certs.CaBundleBuilder:
        return certs.CaBundleBuilder(self.certificates)

    def ca_bundle_path(self) -> str:
        return self.ca_bundle.current_bundle_path()

    # ---- AD ----
    @cached_property
    def ldap_configs(self) -> LdapConfigRepository:
        return LdapConfigRepository(self.conn)

    @cached_property
    def domains(self) -> domain.LlmDomainRepository:
        return domain.LlmDomainRepository(self.conn)

    @cached_property
    def groups(self) -> domain.DomainGroupRepository:
        return domain.DomainGroupRepository(self.conn)

    @cached_property
    def authenticator(self) -> domain.ChatAuthenticator:
        return domain.ChatAuthenticator(self.groups, self.domains, self.ca_bundle, self.diagnostics)

    # ---- нейросети ----
    @cached_property
    def providers(self) -> ProviderRepository:
        return ProviderRepository(self.conn)

    @cached_property
    def models(self) -> ModelRepository:
        return ModelRepository(self.conn)

    @cached_property
    def model_health(self) -> ModelHealthRepository:
        return ModelHealthRepository(self.conn)

    @cached_property
    def user_providers(self) -> UserProviderRepository:
        return UserProviderRepository(self.conn)

    @cached_property
    def access(self) -> AccessPolicy:
        return AccessPolicy(self.conn, self.user_providers)

    @cached_property
    def adapters(self) -> AdapterRegistry:
        return AdapterRegistry.with_defaults()

    @cached_property
    def gateway(self) -> LlmGateway:
        return LlmGateway(self.providers, self.access, self.adapters, self.transport, self.ca_bundle_path, self.diagnostics,
                          self.models, self.model_health, self._sleeper, self.alerts.notify)

    # ---- обезличивание и файлы ----
    @cached_property
    def anonymization_settings(self) -> AnonymizationSettings:
        return AnonymizationSettings(self.app_settings)

    @cached_property
    def anonymizer(self) -> AnonymizationGateway:
        return AnonymizationGateway(self.anonymization_settings, self.transport, self.diagnostics)

    @cached_property
    def file_settings(self) -> FileProcessingSettings:
        return FileProcessingSettings(self.app_settings)

    @cached_property
    def file_processor(self) -> FileProcessor:
        return FileProcessor.with_defaults(self.file_settings)

    # ---- чат ----
    @cached_property
    def chat_settings(self) -> ChatSettings:
        return ChatSettings(self.app_settings)

    @cached_property
    def conversations(self) -> ConversationRepository:
        return ConversationRepository(self.conn)

    @cached_property
    def projects(self) -> ProjectRepository:
        return ProjectRepository(self.conn)

    @cached_property
    def limit_categories(self) -> LimitCategoryRepository:
        return LimitCategoryRepository(self.conn)

    @cached_property
    def usage(self) -> UsageLimiter:
        return UsageLimiter(self.conn, self.chat_settings, self.limit_categories)

    @cached_property
    def storage_quota(self) -> StorageQuotaService:
        return StorageQuotaService(self.conn, self.limit_categories)

    @cached_property
    def inactivity_cleanup(self) -> InactivityCleanupService:
        return InactivityCleanupService(self.conn)

    @cached_property
    def summaries(self) -> SummaryService:
        return SummaryService(self.conn, self.gateway)

    @cached_property
    def chat_service(self) -> ChatService:
        return ChatService(self.access, self.conversations, self.anonymizer, self.gateway, self.file_processor, self.chat_settings,
                           self.events, self.projects, self.usage, self.storage_quota, self.models, self.summaries)

    @cached_property
    def dictation(self) -> dictation.DictationTicket:
        return dictation.DictationTicket(self.chat_settings)

    # ---- почта ----
    @cached_property
    def mail_settings(self) -> MailSettings:
        return MailSettings(self.app_settings)

    @cached_property
    def mail_queue(self) -> MailQueue:
        return MailQueue(self.conn)

    @cached_property
    def alerts(self) -> Alerts:
        return Alerts(self.mail_settings, self.mail_queue, self.app_settings)
