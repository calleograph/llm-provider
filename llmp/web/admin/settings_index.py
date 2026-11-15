"""Индекс глобальных настроек админки (перенос templates/admin/settings_index.php): строковый код над полем и поиск
в шапке. Поля динамических записей (нейросети, LDAP-подключения, категории лимитов) сюда не входят — у них нет
постоянного id вне конкретной записи. anchor — id элемента на странице раздела и #hash при переходе из поиска."""

from __future__ import annotations

_RAW = [
    # system — «Настройки»
    ("system", "Тема по умолчанию для всех", "site_theme", "SITE_THEME"),
    ("system", "Таймаут бездействия администратора", "admin_idle_timeout_minutes", "ADMIN_IDLE_TIMEOUT"),
    ("system", "Доверенные reverse proxy", "trusted_proxies", "TRUSTED_PROXIES"),
    ("system", "Восстановить конфигурацию из архива", "archive", "CONFIG_RESTORE"),
    # access — «Доступ к админ-панели»
    ("access", "DN группы администраторов", "admin_group_dn", "ADMIN_GROUP_DN"),
    # anonymization — «Обезличивание» (встроенный сервис: только движок и обработка файлов)
    ("anonymization", "Профиль обезличивания", "internal_profile", "ANON_INTERNAL_PROFILE"),
    ("anonymization", "Словарь организации", "internal_terms", "ANON_TERMS"),
    ("anonymization", "Макс. размер файла", "max_upload_mb", "FILE_MAX_UPLOAD_MB"),
    ("anonymization", "Макс. извлекаемый текст", "max_extracted_chars", "FILE_MAX_EXTRACTED_CHARS"),
    ("anonymization", "Макс. страниц OCR", "ocr_max_pages", "FILE_OCR_MAX_PAGES"),
    ("anonymization", "Таймаут инструментов обработки файлов", "tool_timeout", "FILE_TOOL_TIMEOUT"),
    ("anonymization", "Языки OCR", "ocr_languages", "FILE_OCR_LANGUAGES"),
    ("anonymization", "Локальный OCR для сканов", "ocr_enabled", "FILE_OCR_ENABLED"),
    # llm_providers — «Подключения LLM API» (общие параметры чата)
    ("llm_providers", "Системная инструкция для всех нейросетей", "system_prompt", "CHAT_SYSTEM_PROMPT"),
    ("llm_providers", "Лимит истории, передаваемой в модель", "history_max_chars", "CHAT_HISTORY_MAX_CHARS"),
    ("llm_providers", "Максимальная длина сообщения", "max_message_chars", "CHAT_MAX_MESSAGE_CHARS"),
    # llm_users — «Пользователи чата» (блок «Хранение»)
    ("llm_users", "Хранить неиспользуемые чаты, дней", "retention_days", "CHAT_RETENTION_DAYS"),
    ("llm_users", "Объём источников проекта", "project_sources_max_chars", "PROJECT_SOURCES_MAX_CHARS"),
    ("llm_users", "Макс. источников в проекте", "project_sources_max_files", "PROJECT_SOURCES_MAX_FILES"),
    ("llm_users", "Макс. блоков инструкций в проекте", "project_instructions_max_blocks", "PROJECT_INSTRUCTIONS_MAX_BLOCKS"),
    ("llm_users", "Макс. длина одного блока инструкций", "project_instruction_max_chars", "PROJECT_INSTRUCTION_MAX_CHARS"),
    ("llm_users", "Зачищать содержимое чатов при неактивности, дней", "inactivity_content_purge_days", "CHAT_INACTIVITY_PURGE_DAYS"),
    # telemetry — «Аудит и DLP», вкладка «Правила и хранение»
    ("telemetry", "Хранить журнал аудита (телеметрию), дней", "telemetry_retention_days", "TELEMETRY_RETENTION_DAYS"),
]
_TABS = {"telemetry_retention_days": "&tab=rules"}   # поле не на первой вкладке раздела

SETTINGS_INDEX: list[dict[str, str]] = [{"section": s, "label": label, "anchor": a, "code": c, "url": f"index.php?section={s}" + _TABS.get(a, "")}
                                        for s, label, a, c in _RAW]
