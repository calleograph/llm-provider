<?php

declare(strict_types=1);

namespace App\Llm\Anonymization;

use App\Config\AppSettings;
use App\Security\SecretBox;

/**
 * Настройки внутреннего API обезличивания. Хранятся в существующем
 * key-value хранилище app_settings: открытые параметры — как есть,
 * токен/пароль — только SecretBox-шифротекстом.
 */
final class AnonymizationSettings
{
    public const AUTH_NONE = 'none';
    public const AUTH_BEARER = 'bearer';
    public const AUTH_HEADER = 'header';
    public const AUTH_BASIC = 'basic';
    public const AUTH_TYPES = [self::AUTH_NONE, self::AUTH_BEARER, self::AUTH_HEADER, self::AUTH_BASIC];

    /** Внутренний обезличиватель документов DocClean (api_text / api_file / api_download). */
    public const PROFILE_DOCCLEAN = 'docclean';
    /** Произвольный JSON API с настраиваемыми именами полей. */
    public const PROFILE_GENERIC = 'generic';
    /** Встроенный Python-сервис обезличивания (контейнер anonymizer, перенос из «Переговорки») — основной режим. */
    public const PROFILE_INTERNAL = 'internal';
    public const PROFILES = [self::PROFILE_INTERNAL, self::PROFILE_DOCCLEAN, self::PROFILE_GENERIC];

    /** Профили встроенного движка и действия по категориям (engine.ACTIONS / CATEGORIES). */
    public const INTERNAL_PROFILES = ['basic' => 'Базовый', 'strict' => 'Строгий', 'custom' => 'Настраиваемый'];
    public const INTERNAL_ACTIONS = ['keep' => 'оставить', 'mask' => 'метка', 'pseudonym' => 'обозначение с номером'];
    public const INTERNAL_CATEGORIES = [
        'person' => 'Люди (ФИО)', 'organization' => 'Организации', 'location' => 'Места', 'address' => 'Адреса', 'phone' => 'Телефоны',
        'email' => 'Почта', 'ip' => 'IP-адреса', 'host' => 'Серверы', 'url' => 'Ссылки', 'login' => 'Учётные записи',
        'id_number' => 'ИНН, СНИЛС, ОГРН, паспорт', 'bank' => 'Карты и счета', 'health' => 'Здоровье', 'marker' => 'Грифы',
        'doc_number' => 'Номера договоров и документов', 'money' => 'Суммы', 'project' => 'Проекты', 'custom' => 'Свой словарь',
    ];

    public const DOCCLEAN_MODES = ['ai_ready', 'full', 'personal_corporate', 'personal'];
    public const DOCCLEAN_GROUPS = ['pdn', 'corporate', 'secrets', 'network', 'other'];

    /** Файл целиком в api_file (обработчики форматов и OCR сервиса). */
    public const FILE_MODE_API = 'api_file';
    /** Локальное извлечение текста + обезличивание текста. */
    public const FILE_MODE_TEXT = 'text';

    private const PREFIX = 'anon_';

    private const DEFAULTS = [
        'profile' => self::PROFILE_INTERNAL,
        'internal_profile' => 'basic',
        'internal_terms' => '',           // строки «термин;категория;замена» — словарь организации (клиенты, проекты, системы)
        'internal_exceptions' => '',      // по одному в строке — никогда не заменять (кроме секретов)
        'internal_domains' => '',         // внутренние домены — адреса и серверы в них заменяются
        'internal_custom_actions' => '',  // JSON {категория: keep|mask|pseudonym} для профиля «Настраиваемый»
        'internal_person_format' => 'short',
        'internal_org_scope' => 'legal',
        'internal_timeout' => '120',
        'docclean_mode' => 'ai_ready',
        'docclean_groups' => '',
        'file_mode' => self::FILE_MODE_API,
        'second_pass' => '1',
        'base_url' => '',
        'endpoint' => '/anonymize',
        'health_endpoint' => '',
        'auth_type' => self::AUTH_NONE,
        'auth_header_name' => 'X-API-Key',
        'auth_username' => '',
        'connect_timeout' => '5',
        'timeout' => '30',
        'request_field' => 'text',
        'response_field' => 'anonymized_text',
        'status_field' => '',
        'status_ok_value' => '',
        'extra_body' => '',
        'use_corporate_ca' => '1',
        'allow_http' => '0',
        'max_chunk_chars' => '20000',
        'last_check_at' => '',
        'last_check_ok' => '',
        'last_check_message' => '',
    ];

    public function __construct(private readonly AppSettings $appSettings)
    {
    }

    public function get(string $name): string
    {
        return (string) $this->appSettings->get(self::PREFIX . $name, self::DEFAULTS[$name] ?? '');
    }

    /** @return array<string,string> все открытые параметры (без секрета) */
    public function all(): array
    {
        $result = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            $result[$name] = $this->get($name);
        }

        return $result;
    }

    /**
     * @param array<string,string> $values только ключи из DEFAULTS
     */
    public function save(array $values): void
    {
        foreach ($values as $name => $value) {
            if (array_key_exists($name, self::DEFAULTS)) {
                $this->appSettings->set(self::PREFIX . $name, (string) $value);
            }
        }
    }

    public function isConfigured(): bool
    {
        if ($this->isInternal()) {
            return self::internalUrl() !== '' && self::internalToken() !== '';
        }

        return $this->get('base_url') !== '' && ($this->isDocClean() || $this->get('endpoint') !== '');
    }

    public function isInternal(): bool
    {
        return $this->get('profile') === self::PROFILE_INTERNAL;
    }

    /** Адрес сервиса во внутренней сети Compose — из окружения, не из БД (в браузер не попадает). */
    public static function internalUrl(): string
    {
        return rtrim((string) getenv('ANONYMIZER_URL'), '/');
    }

    public static function internalToken(): string
    {
        return (string) getenv('INTERNAL_API_TOKEN');
    }

    /**
     * Настройки движка для запроса к сервису (тот же набор полей, что AnonymizerSettings «Переговорки»).
     *
     * @param array<string,string> $draft непроверенный черновик из лаборатории админки (поверх сохранённых)
     * @return array<string,mixed>
     */
    public function internalEngineSettings(array $draft = []): array
    {
        $v = static fn (string $k) => array_key_exists($k, $draft) ? (string) $draft[$k] : null;
        $get = fn (string $k): string => $v($k) ?? $this->get($k);
        $lines = static fn (string $text): array => array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), static fn ($x) => $x !== ''));
        $terms = [];
        foreach ($lines($get('internal_terms')) as $line) {
            $parts = array_map('trim', explode(';', $line));
            if ($parts[0] !== '') {
                $terms[] = ['term' => mb_substr($parts[0], 0, 200), 'category' => $parts[1] ?? 'custom', 'replacement' => mb_substr($parts[2] ?? '', 0, 100)];
            }
        }
        $actions = json_decode($get('internal_custom_actions'), true);

        return [
            'terms' => $terms,
            'exceptions' => $lines($get('internal_exceptions')),
            'internal_domains' => $lines($get('internal_domains')),
            'custom_actions' => is_array($actions) ? $actions : [],
            'custom_person_format' => $get('internal_person_format'),
            'custom_org_scope' => $get('internal_org_scope'),
        ];
    }

    public function isDocClean(): bool
    {
        return $this->get('profile') === self::PROFILE_DOCCLEAN;
    }

    /** Файлы целиком обрабатывает сервис (только профиль DocClean). */
    public function usesFileApi(): bool
    {
        return $this->isDocClean() && $this->get('file_mode') === self::FILE_MODE_API;
    }

    /** @return string[] */
    public function docCleanGroups(): array
    {
        return array_values(array_intersect(self::DOCCLEAN_GROUPS, explode(',', $this->get('docclean_groups'))));
    }

    /**
     * Адрес действия DocClean: единая точка входа index.php?action=...
     * Base URL может указывать на каталог приложения или сразу на index.php.
     *
     * @param array<string,string> $query
     */
    public function docCleanUrl(string $action, array $query = []): string
    {
        $base = rtrim($this->get('base_url'), '/');
        if (!str_ends_with(strtolower($base), '.php')) {
            $base .= '/index.php';
        }

        return $base . '?' . http_build_query(['action' => $action] + $query);
    }

    public function hasSecret(): bool
    {
        return (string) $this->appSettings->get(self::PREFIX . 'secret_encrypted', '') !== '';
    }

    public function secret(): ?string
    {
        $encrypted = (string) $this->appSettings->get(self::PREFIX . 'secret_encrypted', '');

        return $encrypted === '' ? null : SecretBox::decrypt($encrypted);
    }

    public function setSecret(string $secret): void
    {
        $this->appSettings->set(self::PREFIX . 'secret_encrypted', SecretBox::encrypt($secret));
    }

    public function clearSecret(): void
    {
        $this->appSettings->set(self::PREFIX . 'secret_encrypted', '');
    }

    public function recordCheck(bool $ok, string $message): void
    {
        $this->save([
            'last_check_at' => gmdate('c'),
            'last_check_ok' => $ok ? '1' : '0',
            'last_check_message' => mb_substr($message, 0, 300),
        ]);
    }

    public function url(string $endpoint): string
    {
        return rtrim($this->get('base_url'), '/') . '/' . ltrim($endpoint, '/');
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string> поле => ошибка
     */
    public static function validate(array $values): array
    {
        $errors = [];
        $profile = $values['profile'] ?? self::PROFILE_INTERNAL;
        if (!in_array($profile, self::PROFILES, true)) {
            $errors['profile'] = 'Недопустимый тип API.';
        }
        if ($profile === self::PROFILE_INTERNAL) {
            return $errors + self::validateInternal($values);
        }
        if ($profile === self::PROFILE_DOCCLEAN) {
            if (!in_array($values['docclean_mode'] ?? '', self::DOCCLEAN_MODES, true)) {
                $errors['docclean_mode'] = 'Недопустимый режим.';
            }
            if (!in_array($values['file_mode'] ?? '', [self::FILE_MODE_API, self::FILE_MODE_TEXT], true)) {
                $errors['file_mode'] = 'Недопустимый способ обработки файлов.';
            }
            // DocClean всегда авторизуется Bearer-ключом.
            $values['auth_type'] = self::AUTH_BEARER;
            $values['endpoint'] = '/index.php';
            $values['request_field'] = 'text';
            $values['response_field'] = 'text';
            $values['health_endpoint'] = '';
            $values['status_field'] = '';
            $values['extra_body'] = '';
        }
        $baseUrl = $values['base_url'] ?? '';
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            $errors['base_url'] = 'Укажите корректный URL сервиса, например https://anon.corp.local';
        } elseif ($scheme !== 'https' && !($scheme === 'http' && ($values['allow_http'] ?? '0') === '1')) {
            $errors['base_url'] = 'Разрешён только https://. HTTP допустим лишь при явном разрешении ниже.';
        }
        foreach (['endpoint', 'health_endpoint'] as $field) {
            $value = $values[$field] ?? '';
            if (($field === 'endpoint' || $value !== '') && !preg_match('#\A/[A-Za-z0-9._~/%-]*(\?[A-Za-z0-9._~=&%-]*)?\z#', $value)) {
                $errors[$field] = 'Путь должен начинаться с / и не содержать пробелов.';
            }
        }
        if (!in_array($values['auth_type'] ?? '', self::AUTH_TYPES, true)) {
            $errors['auth_type'] = 'Недопустимый тип авторизации.';
        }
        if (($values['auth_type'] ?? '') === self::AUTH_HEADER && !preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $values['auth_header_name'] ?? '')) {
            $errors['auth_header_name'] = 'Имя заголовка: латиница, цифры и дефис.';
        }
        foreach (['connect_timeout' => [1, 60], 'timeout' => [1, 600], 'max_chunk_chars' => [500, 1000000]] as $field => [$min, $max]) {
            $value = (int) ($values[$field] ?? 0);
            if ($value < $min || $value > $max) {
                $errors[$field] = "Допустимый диапазон: {$min}–{$max}.";
            }
        }
        foreach (['request_field', 'response_field', 'status_field'] as $field) {
            $value = $values[$field] ?? '';
            if (($field !== 'status_field' || $value !== '') && !preg_match('/\A[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*\z/', $value)) {
                $errors[$field] = 'Имя поля JSON: латиница/цифры/_, вложенность через точку.';
            }
        }
        $extra = trim($values['extra_body'] ?? '');
        if ($extra !== '') {
            $decoded = json_decode($extra, true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                $errors['extra_body'] = 'Ожидается JSON-объект, например {"lang":"ru"}.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    public static function validateInternal(array $values): array
    {
        $errors = [];
        if (!array_key_exists($values['internal_profile'] ?? '', self::INTERNAL_PROFILES)) {
            $errors['internal_profile'] = 'Профиль: базовый, строгий или настраиваемый.';
        }
        if (!in_array($values['internal_person_format'] ?? 'short', ['short', 'role'], true)) {
            $errors['internal_person_format'] = 'Недопустимый формат имён.';
        }
        if (!in_array($values['internal_org_scope'] ?? 'legal', ['legal', 'all'], true)) {
            $errors['internal_org_scope'] = 'Недопустимая область организаций.';
        }
        $actions = trim($values['internal_custom_actions'] ?? '');
        if ($actions !== '') {
            $decoded = json_decode($actions, true);
            if (!is_array($decoded) || array_diff_key($decoded, self::INTERNAL_CATEGORIES) !== [] || array_diff($decoded, array_keys(self::INTERNAL_ACTIONS)) !== []) {
                $errors['internal_custom_actions'] = 'Неизвестная категория или действие.';
            }
        }
        $timeout = (int) ($values['internal_timeout'] ?? 120);
        if ($timeout < 5 || $timeout > 600) {
            $errors['internal_timeout'] = 'Допустимый диапазон: 5–600.';
        }
        foreach (['internal_terms' => 5000, 'internal_exceptions' => 5000, 'internal_domains' => 500] as $field => $maxLines) {
            $text = (string) ($values[$field] ?? '');
            if (count(preg_split('/\R/u', $text) ?: []) > $maxLines || mb_strlen($text) > 500000) {
                $errors[$field] = 'Не больше ' . $maxLines . ' строк.';
            }
        }

        return $errors;
    }
}
