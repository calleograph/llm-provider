<?php

declare(strict_types=1);

namespace App\Llm;

use PDO;

/**
 * Схема модуля LLM-чата. Вызывается из Database::migrate() после основной
 * схемы, поэтому таблица ldap_configs к этому моменту уже существует.
 *
 * Домены НЕ хранятся в отдельной таблице: модуль расширяет существующие
 * подключения ldap_configs (те же LDAPS URL, Base DN, таймауты, общий CA
 * bundle). Колонки ниже добавляются идемпотентно — LdapConfigRepository их
 * не читает и не перезаписывает.
 */
final class LlmSchema
{
    public static function migrate(PDO $pdo): void
    {
        // Участвует ли подключение во входе в LLM-чат (несколько доменов
        // одновременно, независимо от «активной» конфигурации в разделе
        // «Подключения», которая используется для входа администраторов).
        self::addColumn($pdo, 'ldap_configs', 'llm_enabled INTEGER NOT NULL DEFAULT 0');
        // Необязательная read-only сервисная учётка домена: проверка групп
        // из админки и определение членства при входе в чат. Пароль —
        // только в виде SecretBox-шифротекста.
        self::addColumn($pdo, 'ldap_configs', 'service_username TEXT');
        self::addColumn($pdo, 'ldap_configs', 'service_password_encrypted TEXT');

        // Разрешённые субъекты доступа к чату. group_ref — полный DN либо
        // только CN группы (CN разрешается в DN поиском по Base DN домена
        // при входе), либо, для subject_type='user', sAMAccountName
        // отдельного пользователя («группа из одного» — доступ конкретному
        // человеку без создания группы в AD). subject_type ('group'|'user')
        // — явный признак, а не эвристика по формату строки: совпадение CN
        // группы с чьим-то логином иначе могло бы дать ложное трактование.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_domain_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ldap_config_id INTEGER NOT NULL REFERENCES ldap_configs(id) ON DELETE CASCADE,
                display_name TEXT NOT NULL,
                group_ref TEXT NOT NULL,
                is_enabled INTEGER NOT NULL DEFAULT 1,
                created_by TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(ldap_config_id, group_ref)
            )
        SQL);
        self::addColumn($pdo, 'llm_domain_groups', "subject_type TEXT NOT NULL DEFAULT 'group'");
        // Разрешение отправлять сообщения в чат БЕЗ обезличивания (по явной
        // галочке пользователя на конкретном сообщении — см. ChatService::
        // send()). По умолчанию выключено у всех субъектов: это осознанный
        // риск, который включает администратор точечно для доверенных
        // групп/пользователей, а не общее поведение чата.
        self::addColumn($pdo, 'llm_domain_groups', 'allow_unanonymized INTEGER NOT NULL DEFAULT 0');

        // Подключения к внешним LLM API. Ключ и дополнительные заголовки —
        // только SecretBox-шифротекст; api_key_hint — последние 4 символа
        // для опознания ключа администратором (целиком ключ не показывается).
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_providers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                provider_type TEXT NOT NULL,
                base_url TEXT NOT NULL,
                endpoint TEXT NOT NULL,
                api_key_encrypted TEXT,
                api_key_hint TEXT,
                model TEXT NOT NULL,
                api_version TEXT,
                connect_timeout INTEGER NOT NULL DEFAULT 10,
                response_timeout INTEGER NOT NULL DEFAULT 120,
                max_tokens INTEGER NOT NULL DEFAULT 2048,
                extra_headers_encrypted TEXT,
                extra_header_names TEXT,
                use_corporate_ca INTEGER NOT NULL DEFAULT 0,
                is_enabled INTEGER NOT NULL DEFAULT 0,
                last_check_at TEXT,
                last_check_ok INTEGER,
                last_check_message TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        // Жёсткая фиксация провайдера маршрута у мультипровайдерных шлюзов
        // (polza.ai и т.п., тип openai_compatible): подставляется в тело
        // запроса как provider.only — тот же параметр, который такие шлюзы
        // используют для ручного выбора маршрута между несколькими
        // бэкендами одной модели (например DeepInfra вместо Azure/Mistral
        // для одной и той же модели). Для типов openai/anthropic не имеет
        // смысла (это собственные API поставщика, а не шлюз) и в форме не
        // показывается. NULL/'' — маршрут не фиксирован, решает сам шлюз.
        self::addColumn($pdo, 'llm_providers', 'routing_provider TEXT');

        // Альтернатива жёсткой фиксации: автоматический выбор самого
        // дешёвого доступного маршрута у мультипровайдерного шлюза —
        // provider.sort = "price" в запросе (см. ChatCompletionsAdapter).
        // Взаимоисключает routing_provider (либо фиксируем один провайдер,
        // либо даём шлюзу выбрать самый дешёвый) — форма/валидатор не
        // позволяют включить оба одновременно.
        self::addColumn($pdo, 'llm_providers', 'sort_by_price INTEGER NOT NULL DEFAULT 0');
        // Потолок цены (provider.max_price), ₽ за миллион токенов,
        // применяется и к prompt, и к completion — отсекает дорогие
        // маршруты при автовыборе. NULL — без ограничения.
        self::addColumn($pdo, 'llm_providers', 'max_price_per_mtoken REAL');
        // max_price — жёсткий фильтр у Polza: если ни один маршрут не
        // укладывается в потолок цены, шлюз без allow_fallbacks отклоняет
        // запрос целиком (ошибка выглядит как обычный bad_request, а не
        // как "цена не найдена"). allow_fallbacks=1 (по умолчанию) велит
        // шлюзу в этом случае взять любой доступный маршрут вместо отказа.
        self::addColumn($pdo, 'llm_providers', 'allow_fallbacks INTEGER NOT NULL DEFAULT 1');

        // Нейросеть, которая открывается по умолчанию в чате (вместо первой
        // по алфавиту, как без этого флага). Ровно одна строка может быть
        // is_default = 1 (обеспечивается в ProviderRepository); действует
        // только если эта нейросеть доступна конкретному пользователю —
        // иначе используется обычный порядок (см. AccessPolicy::
        // allowedProviders(), сортировка по имени).
        self::addColumn($pdo, 'llm_providers', 'is_default INTEGER NOT NULL DEFAULT 0');

        // Права: какая группа какие нейросети может использовать.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_group_providers (
                group_id INTEGER NOT NULL REFERENCES llm_domain_groups(id) ON DELETE CASCADE,
                provider_id INTEGER NOT NULL REFERENCES llm_providers(id) ON DELETE CASCADE,
                PRIMARY KEY (group_id, provider_id)
            )
        SQL);

        // История диалогов. owner_key = "sam@домен" в нижнем регистре —
        // единственный ключ доступа к диалогу; содержимое сообщений только
        // обезличенное (исходный текст пользователя не сохраняется).
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_key TEXT NOT NULL,
                title TEXT NOT NULL,
                provider_id INTEGER REFERENCES llm_providers(id) ON DELETE SET NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_llm_conversations_owner ON llm_conversations(owner_key, updated_at)');

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL REFERENCES llm_conversations(id) ON DELETE CASCADE,
                role TEXT NOT NULL CHECK (role IN ('user', 'assistant')),
                content TEXT NOT NULL,
                provider_id INTEGER,
                model TEXT,
                attachment_type TEXT,
                attachment_size INTEGER,
                created_at TEXT NOT NULL
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_llm_messages_conversation ON llm_messages(conversation_id, id)');

        // ---- Интерфейс как в ChatGPT: закрепление и проекты ----
        self::addColumn($pdo, 'llm_conversations', 'pinned INTEGER NOT NULL DEFAULT 0');
        self::addColumn($pdo, 'llm_conversations', 'project_id INTEGER');
        // Экономичное хранение: длинные сообщения сжимаются (content = '',
        // content_z = gzdeflate). Короткие остаются текстом — сжатие им не выгодно.
        self::addColumn($pdo, 'llm_messages', 'content_z BLOB');
        self::addColumn($pdo, 'llm_messages', 'tokens INTEGER');
        // Сообщение отправлено с флагом skipAnonymization (см. ChatService::
        // send()) — content хранится в открытом виде, а не обезличенным.
        // Только для role='user' (ответ модели тоже не проходит обратное
        // обезличивание, но это отдельная, всегда действующая особенность,
        // не связанная с этим флагом). Нужно фронтенду, чтобы при повторном
        // открытии диалога показать пользователю явную пометку, а не только
        // в момент отправки.
        self::addColumn($pdo, 'llm_messages', 'unanonymized INTEGER NOT NULL DEFAULT 0');

        // Проекты пользователя: инструкции и источники (только обезличенные,
        // сжатые), которые подмешиваются в контекст чатов проекта.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_key TEXT NOT NULL,
                name TEXT NOT NULL,
                instructions_z BLOB,
                instructions_chars INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_llm_projects_owner ON llm_projects(owner_key, updated_at)');
        self::addColumn($pdo, 'llm_projects', 'pinned INTEGER NOT NULL DEFAULT 0');
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_project_sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL REFERENCES llm_projects(id) ON DELETE CASCADE,
                title TEXT NOT NULL,
                source_type TEXT NOT NULL,
                file_type TEXT,
                content_z BLOB NOT NULL,
                chars INTEGER NOT NULL,
                created_at TEXT NOT NULL
            )
        SQL);

        // Профиль пользователя чата: создаётся при первом входе. Лимит
        // NULL — действует лимит группы/общий (см. UsageLimiter).
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_users (
                owner_key TEXT PRIMARY KEY,
                username TEXT NOT NULL,
                domain_fqdn TEXT NOT NULL,
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                limit_period TEXT,
                limit_tokens INTEGER,
                is_blocked INTEGER NOT NULL DEFAULT 0
            )
        SQL);
        // Персональное разрешение «без обезличивания» — в дополнение к
        // групповому (llm_domain_groups.allow_unanonymized), та же модель
        // объединения прав, что и для доступа к нейросетям
        // (llm_user_providers поверх llm_group_providers). 0 — нет личного
        // разрешения (действует только групповое, если оно есть).
        self::addColumn($pdo, 'llm_users', 'allow_unanonymized INTEGER NOT NULL DEFAULT 0');

        // Расход токенов — одна строка на пользователя в день (агрегат), а не
        // запись на каждый запрос: объём не растёт с числом сообщений.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_usage (
                owner_key TEXT NOT NULL,
                day TEXT NOT NULL,
                tokens INTEGER NOT NULL DEFAULT 0,
                requests INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (owner_key, day)
            )
        SQL);

        // Именованные категории лимитов («Базовый», «Расширенный»...) — вместо
        // свободного ввода периода+числа токенов на каждого пользователя/группу
        // по отдельности. is_default — категория, которая применяется новому
        // пользователю чата, если ему не назначена персональная категория и он
        // не состоит ни в одной группе с собственной категорией. Ровно одна
        // строка может быть is_default = 1 (обеспечивается в LimitCategoryRepository).
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_limit_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                period TEXT NOT NULL,
                tokens INTEGER,
                storage_mb INTEGER,
                is_default INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        // Категория лимита группы (необязательная — NULL означает «действует
        // общий порядок по умолчанию», как и раньше). Пользователь без личной
        // категории получает самый щедрый лимит среди категорий своих групп.
        self::addColumn($pdo, 'llm_domain_groups', 'limit_category_id INTEGER REFERENCES llm_limit_categories(id) ON DELETE SET NULL');

        // Персональная категория лимита пользователя — приоритетнее групповой.
        self::addColumn($pdo, 'llm_users', 'limit_category_id INTEGER REFERENCES llm_limit_categories(id) ON DELETE SET NULL');

        self::seedDefaultLimitCategory($pdo);

        // Модели внутри провайдера (схема по образцу Hermes: provider → models → fallback_providers).
        // Провайдер — подключение (endpoint, тип API, ключ, заголовки, таймауты, маршрутизация Polza);
        // модель — то, что выбирает пользователь: идентификатор у провайдера, окно контекста, длина ответа,
        // температура, приоритет, цепочка резервных моделей. Доступ к модели = доступ к её провайдеру.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_models (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider_id INTEGER NOT NULL REFERENCES llm_providers(id) ON DELETE CASCADE,
                model TEXT NOT NULL,
                display_name TEXT NOT NULL,
                context_window INTEGER NOT NULL DEFAULT 32000,
                max_output_tokens INTEGER NOT NULL DEFAULT 2048,
                temperature REAL,
                supports_streaming INTEGER NOT NULL DEFAULT 1,
                supports_json INTEGER NOT NULL DEFAULT 0,
                priority INTEGER NOT NULL DEFAULT 100,
                is_enabled INTEGER NOT NULL DEFAULT 1,
                is_default INTEGER NOT NULL DEFAULT 0,
                fallback_ids TEXT NOT NULL DEFAULT '[]',
                price_in_per_mtoken REAL,
                price_out_per_mtoken REAL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(provider_id, model)
            )
        SQL);
        // Состояние модели между запросами: счётчики, последняя ошибка, пауза после сбоев (fallback_cooldown Hermes).
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_model_health (
                model_id INTEGER PRIMARY KEY REFERENCES llm_models(id) ON DELETE CASCADE,
                success_count INTEGER NOT NULL DEFAULT 0,
                error_count INTEGER NOT NULL DEFAULT 0,
                empty_count INTEGER NOT NULL DEFAULT 0,
                fallback_count INTEGER NOT NULL DEFAULT 0,
                consecutive_failures INTEGER NOT NULL DEFAULT 0,
                total_ms INTEGER NOT NULL DEFAULT 0,
                last_ms INTEGER,
                tokens_in INTEGER NOT NULL DEFAULT 0,
                tokens_out INTEGER NOT NULL DEFAULT 0,
                cost REAL NOT NULL DEFAULT 0,
                last_success_at TEXT,
                last_error_at TEXT,
                last_error_code TEXT,
                last_error_message TEXT,
                cooldown_until INTEGER NOT NULL DEFAULT 0
            )
        SQL);
        // Журнал проблемных моделей: код, HTTP-статус, техническая причина (без текста запросов и ответов), куда переключились.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_model_incidents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                model_id INTEGER,
                provider_id INTEGER,
                code TEXT NOT NULL,
                http_status INTEGER,
                message TEXT,
                action TEXT NOT NULL,
                fallback_model_id INTEGER
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_llm_model_incidents_at ON llm_model_incidents(created_at)');
        self::addColumn($pdo, 'llm_conversations', 'model_id INTEGER');
        self::seedDefaultModels($pdo);

        // ---- Контекст и экономия токенов (этап 5) ----
        // Сводка ранней части диалога для модели (полная история остаётся пользователю): итеративно обновляется,
        // кэшируется до следующего роста истории. Только обезличенный текст — как и вся история.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_conversation_summaries (
                conversation_id INTEGER PRIMARY KEY REFERENCES llm_conversations(id) ON DELETE CASCADE,
                covered_until_id INTEGER NOT NULL,
                summary_z BLOB NOT NULL,
                chars INTEGER NOT NULL,
                model_id INTEGER,
                updated_at TEXT NOT NULL
            )
        SQL);
        // Состояние проекта: краткое резюме работы, факты и решения, незавершённые задачи (обновляется из сводок диалогов проекта).
        self::addColumn($pdo, 'llm_projects', 'description TEXT');
        self::addColumn($pdo, 'llm_projects', 'state_z BLOB');
        self::addColumn($pdo, 'llm_projects', 'state_updated_at TEXT');
        // Иерархия инструкций (этап 6): модель → проект → диалог; стиль общения пользователя и диалога.
        self::addColumn($pdo, 'llm_models', 'system_hint TEXT');
        self::addColumn($pdo, 'llm_conversations', 'instructions_z BLOB');
        self::addColumn($pdo, 'llm_conversations', 'style TEXT');
        self::addColumn($pdo, 'llm_users', 'style TEXT');
        self::addColumn($pdo, 'llm_users', 'instructions_z BLOB');
        // Сколько токенов контекста ушло с запросом и из чего он состоял (показывается под ответом).
        self::addColumn($pdo, 'llm_messages', 'context_report TEXT');

        // Индивидуальный доступ к нейросети конкретному пользователю чата, в
        // дополнение к правам его групп (llm_group_providers). Не заменяет
        // требование входа: пользователь всё равно обязан состоять хотя бы в
        // одной разрешённой группе домена, чтобы вообще попасть в чат — это
        // право лишь расширяет список доступных ему нейросетей.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS llm_user_providers (
                owner_key TEXT NOT NULL,
                provider_id INTEGER NOT NULL REFERENCES llm_providers(id) ON DELETE CASCADE,
                PRIMARY KEY (owner_key, provider_id)
            )
        SQL);
    }

    private static function addColumn(PDO $pdo, string $table, string $definition): void
    {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
        } catch (\PDOException) {
            // Колонка уже существует — норма при повторном запуске миграции.
        }
    }

    /**
     * Единожды создаёт категорию «Базовый» из уже настроенных общих
     * параметров лимита (app_settings, префикс llm_chat_), чтобы у
     * существующих стендов при обновлении сразу появилась хотя бы одна
     * категория по умолчанию — то есть новые пользователи чата продолжают
     * получать тот же лимит, что и до появления категорий, а не внезапный
     * безлимит. Выполняется только если категорий ещё нет вообще (первый
     * запуск после обновления); на новых стендах без настроенных значений
     * ChatSettings::DEFAULTS даст разумные значения по умолчанию сама.
     */
    private static function seedDefaultLimitCategory(PDO $pdo): void
    {
        $hasCategories = (int) $pdo->query('SELECT COUNT(*) FROM llm_limit_categories')->fetchColumn() > 0;
        if ($hasCategories) {
            return;
        }

        $period = $pdo->query("SELECT value FROM app_settings WHERE key = 'llm_chat_limit_period'")->fetchColumn();
        $tokens = $pdo->query("SELECT value FROM app_settings WHERE key = 'llm_chat_limit_tokens'")->fetchColumn();
        $period = is_string($period) && in_array($period, ['day', 'week', 'month', 'none'], true) ? $period : 'day';
        $tokens = $period === 'none' ? null : max(0, (int) ($tokens !== false ? $tokens : 50000));

        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO llm_limit_categories (name, period, tokens, storage_mb, is_default, created_at, updated_at)
             VALUES (:name, :period, :tokens, 50, 1, :now, :now)'
        );
        $stmt->execute(['name' => 'Базовый', 'period' => $period, 'tokens' => $tokens, 'now' => $now]);
    }

    /**
     * Каждому провайдеру без моделей — модель по умолчанию из его прежних полей (model, max_tokens):
     * обновление со схемы «один провайдер = одна модель» не меняет поведение и права.
     */
    public static function seedDefaultModels(PDO $pdo): void
    {
        $now = gmdate('c');
        $pdo->exec(
            "INSERT INTO llm_models (provider_id, model, display_name, max_output_tokens, is_default, created_at, updated_at)
             SELECT p.id, p.model, p.model, p.max_tokens, 1, '{$now}', '{$now}' FROM llm_providers p
             WHERE NOT EXISTS (SELECT 1 FROM llm_models m WHERE m.provider_id = p.id)"
        );
    }
}
