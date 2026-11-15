<?php

declare(strict_types=1);

/**
 * Единый индекс ГЛОБАЛЬНЫХ настроек админки (одна запись на всё
 * приложение — «Настройки», «API обезличивания», общие параметры чата,
 * вход администратора) для строкового кода над полем (как в Moodle) и
 * для глобального поиска в шапке. НЕ включает поля динамических записей
 * (нейросети, LDAP-подключения, категории лимитов) — у них нет
 * постоянного id вне контекста конкретной записи, их ищут по
 * имени/списком внутри своего раздела.
 *
 * code — короткое латинское кодовое слово (UPPER_SNAKE_CASE), anchor —
 * id элемента на странице раздела (используется и как HTML id атрибут,
 * и как #hash при переходе из поиска).
 *
 * @return array<int,array{section:string,label:string,anchor:string,code:string}>
 */
return [
    // system.php — «Настройки»
    ['section' => 'system', 'label' => 'Таймаут бездействия администратора', 'anchor' => 'admin_idle_timeout_minutes', 'code' => 'ADMIN_IDLE_TIMEOUT'],
    ['section' => 'system', 'label' => 'Доверенные reverse proxy', 'anchor' => 'trusted_proxies', 'code' => 'TRUSTED_PROXIES'],
    ['section' => 'system', 'label' => 'Восстановить конфигурацию из архива', 'anchor' => 'archive', 'code' => 'CONFIG_RESTORE'],

    // access.php — «Доступ к админ-панели»
    ['section' => 'access', 'label' => 'DN группы администраторов', 'anchor' => 'admin_group_dn', 'code' => 'ADMIN_GROUP_DN'],

    // anonymization.php — «API обезличивания»
    ['section' => 'anonymization', 'label' => 'Тип API обезличивания', 'anchor' => 'profile', 'code' => 'ANON_PROFILE'],
    ['section' => 'anonymization', 'label' => 'URL сервиса обезличивания', 'anchor' => 'base_url', 'code' => 'ANON_BASE_URL'],
    ['section' => 'anonymization', 'label' => 'API-ключ обезличивания', 'anchor' => 'anon_secret', 'code' => 'ANON_SECRET'],
    ['section' => 'anonymization', 'label' => 'Режим обезличивания DocClean', 'anchor' => 'docclean_mode', 'code' => 'ANON_MODE'],
    ['section' => 'anonymization', 'label' => 'Обработка файлов DocClean', 'anchor' => 'file_mode', 'code' => 'ANON_FILE_MODE'],
    ['section' => 'anonymization', 'label' => 'Повторная проверка текста файла', 'anchor' => 'second_pass', 'code' => 'ANON_SECOND_PASS'],
    ['section' => 'anonymization', 'label' => 'Endpoint обезличивания', 'anchor' => 'endpoint', 'code' => 'ANON_ENDPOINT'],
    ['section' => 'anonymization', 'label' => 'Health endpoint обезличивания', 'anchor' => 'health_endpoint', 'code' => 'ANON_HEALTH_ENDPOINT'],
    ['section' => 'anonymization', 'label' => 'Тип авторизации обезличивания', 'anchor' => 'auth_type', 'code' => 'ANON_AUTH_TYPE'],
    ['section' => 'anonymization', 'label' => 'Имя заголовка авторизации', 'anchor' => 'auth_header_name', 'code' => 'ANON_AUTH_HEADER'],
    ['section' => 'anonymization', 'label' => 'Логин Basic-авторизации', 'anchor' => 'auth_username', 'code' => 'ANON_AUTH_USER'],
    ['section' => 'anonymization', 'label' => 'Поле запроса с текстом', 'anchor' => 'request_field', 'code' => 'ANON_REQUEST_FIELD'],
    ['section' => 'anonymization', 'label' => 'Поле ответа с результатом', 'anchor' => 'response_field', 'code' => 'ANON_RESPONSE_FIELD'],
    ['section' => 'anonymization', 'label' => 'Поле статуса ответа', 'anchor' => 'status_field', 'code' => 'ANON_STATUS_FIELD'],
    ['section' => 'anonymization', 'label' => 'Значение успешного статуса', 'anchor' => 'status_ok_value', 'code' => 'ANON_STATUS_OK'],
    ['section' => 'anonymization', 'label' => 'Дополнительные поля запроса', 'anchor' => 'extra_body', 'code' => 'ANON_EXTRA_BODY'],
    ['section' => 'anonymization', 'label' => 'Таймаут соединения обезличивания', 'anchor' => 'connect_timeout', 'code' => 'ANON_CONNECT_TIMEOUT'],
    ['section' => 'anonymization', 'label' => 'Таймаут ответа обезличивания', 'anchor' => 'timeout', 'code' => 'ANON_TIMEOUT'],
    ['section' => 'anonymization', 'label' => 'Макс. размер фрагмента текста', 'anchor' => 'max_chunk_chars', 'code' => 'ANON_MAX_CHUNK'],
    ['section' => 'anonymization', 'label' => 'Проверять TLS по корпоративному CA', 'anchor' => 'anon_use_corporate_ca', 'code' => 'ANON_CORPORATE_CA'],
    ['section' => 'anonymization', 'label' => 'Разрешить http:// для обезличивания', 'anchor' => 'allow_http', 'code' => 'ANON_ALLOW_HTTP'],
    ['section' => 'anonymization', 'label' => 'Макс. размер файла', 'anchor' => 'max_upload_mb', 'code' => 'FILE_MAX_UPLOAD_MB'],
    ['section' => 'anonymization', 'label' => 'Макс. извлекаемый текст', 'anchor' => 'max_extracted_chars', 'code' => 'FILE_MAX_EXTRACTED_CHARS'],
    ['section' => 'anonymization', 'label' => 'Макс. страниц OCR', 'anchor' => 'ocr_max_pages', 'code' => 'FILE_OCR_MAX_PAGES'],
    ['section' => 'anonymization', 'label' => 'Таймаут инструментов обработки файлов', 'anchor' => 'tool_timeout', 'code' => 'FILE_TOOL_TIMEOUT'],
    ['section' => 'anonymization', 'label' => 'Путь к pdftotext', 'anchor' => 'pdftotext_path', 'code' => 'FILE_PDFTOTEXT_PATH'],
    ['section' => 'anonymization', 'label' => 'Путь к pdftoppm', 'anchor' => 'pdftoppm_path', 'code' => 'FILE_PDFTOPPM_PATH'],
    ['section' => 'anonymization', 'label' => 'Путь к tesseract', 'anchor' => 'tesseract_path', 'code' => 'FILE_TESSERACT_PATH'],
    ['section' => 'anonymization', 'label' => 'Языки OCR', 'anchor' => 'ocr_languages', 'code' => 'FILE_OCR_LANGUAGES'],
    ['section' => 'anonymization', 'label' => 'Локальный OCR для сканов', 'anchor' => 'ocr_enabled', 'code' => 'FILE_OCR_ENABLED'],

    // llm_providers.php — «Подключения LLM API» (общие параметры чата)
    ['section' => 'llm_providers', 'label' => 'Системная инструкция для всех нейросетей', 'anchor' => 'system_prompt', 'code' => 'CHAT_SYSTEM_PROMPT'],
    ['section' => 'llm_providers', 'label' => 'Лимит истории, передаваемой в модель', 'anchor' => 'history_max_chars', 'code' => 'CHAT_HISTORY_MAX_CHARS'],
    ['section' => 'llm_providers', 'label' => 'Максимальная длина сообщения', 'anchor' => 'max_message_chars', 'code' => 'CHAT_MAX_MESSAGE_CHARS'],

    // llm_users.php — «Пользователи чата» (блок «Хранение»)
    ['section' => 'llm_users', 'label' => 'Хранить неиспользуемые чаты, дней', 'anchor' => 'retention_days', 'code' => 'CHAT_RETENTION_DAYS'],
    ['section' => 'llm_users', 'label' => 'Объём источников проекта', 'anchor' => 'project_sources_max_chars', 'code' => 'PROJECT_SOURCES_MAX_CHARS'],
    ['section' => 'llm_users', 'label' => 'Макс. источников в проекте', 'anchor' => 'project_sources_max_files', 'code' => 'PROJECT_SOURCES_MAX_FILES'],
    ['section' => 'llm_users', 'label' => 'Макс. блоков инструкций в проекте', 'anchor' => 'project_instructions_max_blocks', 'code' => 'PROJECT_INSTRUCTIONS_MAX_BLOCKS'],
    ['section' => 'llm_users', 'label' => 'Макс. длина одного блока инструкций', 'anchor' => 'project_instruction_max_chars', 'code' => 'PROJECT_INSTRUCTION_MAX_CHARS'],
    ['section' => 'llm_users', 'label' => 'Зачищать содержимое чатов при неактивности, дней', 'anchor' => 'inactivity_content_purge_days', 'code' => 'CHAT_INACTIVITY_PURGE_DAYS'],
];
