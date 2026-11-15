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
    public const PROFILES = [self::PROFILE_DOCCLEAN, self::PROFILE_GENERIC];

    public const DOCCLEAN_MODES = ['ai_ready', 'full', 'personal_corporate', 'personal'];
    public const DOCCLEAN_GROUPS = ['pdn', 'corporate', 'secrets', 'network', 'other'];

    /** Файл целиком в api_file (обработчики форматов и OCR сервиса). */
    public const FILE_MODE_API = 'api_file';
    /** Локальное извлечение текста + обезличивание текста. */
    public const FILE_MODE_TEXT = 'text';

    private const PREFIX = 'anon_';

    private const DEFAULTS = [
        'profile' => self::PROFILE_DOCCLEAN,
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
        return $this->get('base_url') !== '' && ($this->isDocClean() || $this->get('endpoint') !== '');
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
        $profile = $values['profile'] ?? self::PROFILE_DOCCLEAN;
        if (!in_array($profile, self::PROFILES, true)) {
            $errors['profile'] = 'Недопустимый тип API.';
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
}
