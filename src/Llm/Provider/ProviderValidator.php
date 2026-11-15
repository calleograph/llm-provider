<?php

declare(strict_types=1);

namespace App\Llm\Provider;

use App\Llm\Gateway\AdapterRegistry;

/**
 * Нормализация и проверка формы «Нейросети».
 */
final class ProviderValidator
{
    private const FORBIDDEN_HEADERS = ['host', 'content-length', 'content-type', 'transfer-encoding', 'connection', 'expect', 'accept'];

    /** @return array<string,mixed> */
    public static function normalize(array $post): array
    {
        return [
            'name' => trim((string) ($post['name'] ?? '')),
            'provider_type' => trim((string) ($post['provider_type'] ?? '')),
            'base_url' => rtrim(trim((string) ($post['base_url'] ?? '')), '/'),
            'endpoint' => trim((string) ($post['endpoint'] ?? '')),
            'model' => trim((string) ($post['model'] ?? '')),
            'api_version' => trim((string) ($post['api_version'] ?? '')),
            'routing_provider' => trim((string) ($post['routing_provider'] ?? '')),
            'sort_by_price' => !empty($post['sort_by_price']),
            'max_price_per_mtoken' => trim((string) ($post['max_price_per_mtoken'] ?? '')),
            'allow_fallbacks' => !empty($post['allow_fallbacks']),
            'connect_timeout' => (int) ($post['connect_timeout'] ?? 10),
            'response_timeout' => (int) ($post['response_timeout'] ?? 120),
            'max_tokens' => (int) ($post['max_tokens'] ?? 2048),
            'use_corporate_ca' => !empty($post['use_corporate_ca']),
            'is_enabled' => !empty($post['is_enabled']),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    public static function validate(array $data, AdapterRegistry $registry): array
    {
        $errors = [];
        if ($data['name'] === '' || !mb_check_encoding((string) $data['name'], 'UTF-8') || mb_strlen($data['name']) > 100 || preg_match('/[\x00-\x1F]/', (string) $data['name']) === 1) {
            $errors['name'] = 'Укажите название (до 100 символов).';
        }
        if (!$registry->has((string) $data['provider_type'])) {
            $errors['provider_type'] = 'Выберите тип провайдера.';
        }
        if (filter_var($data['base_url'], FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($data['base_url'], PHP_URL_SCHEME)) !== 'https') {
            $errors['base_url'] = 'Base URL должен быть корректным https:// адресом.';
        } elseif (parse_url($data['base_url'], PHP_URL_USER) !== null || parse_url($data['base_url'], PHP_URL_QUERY) !== null) {
            $errors['base_url'] = 'Base URL не должен содержать логин/пароль или параметры запроса.';
        }
        if (preg_match('#\A/[A-Za-z0-9._~/%:-]*\z#', (string) $data['endpoint']) !== 1) {
            $errors['endpoint'] = 'Endpoint — путь, начинающийся с /, например /chat/completions.';
        }
        if (preg_match('#\A[A-Za-z0-9._:/@+-]{1,200}\z#', (string) $data['model']) !== 1) {
            $errors['model'] = 'Укажите ID модели (латиница, цифры, . _ : / @ + -).';
        }
        if ($data['api_version'] !== '' && preg_match('/\A[A-Za-z0-9._-]{1,50}\z/', (string) $data['api_version']) !== 1) {
            $errors['api_version'] = 'Недопустимая версия API.';
        }
        if ($data['routing_provider'] !== '' && preg_match('#\A[A-Za-z0-9._ /-]{1,80}\z#', (string) $data['routing_provider']) !== 1) {
            $errors['routing_provider'] = 'Название провайдера маршрута: латиница, цифры, пробел, . _ / - (до 80 символов).';
        }
        if ($data['routing_provider'] !== '' && $data['sort_by_price']) {
            $errors['sort_by_price'] = 'Нельзя одновременно зафиксировать провайдера маршрута и включить сортировку по цене.';
        }
        if ($data['max_price_per_mtoken'] !== '') {
            if (preg_match('/\A\d{1,9}(\.\d{1,6})?\z/', (string) $data['max_price_per_mtoken']) !== 1) {
                $errors['max_price_per_mtoken'] = 'Укажите неотрицательное число (₽ за миллион токенов).';
            } elseif (!$data['sort_by_price']) {
                $errors['max_price_per_mtoken'] = 'Потолок цены имеет смысл только вместе с сортировкой по цене.';
            }
        }
        if ($data['connect_timeout'] < 1 || $data['connect_timeout'] > 60) {
            $errors['connect_timeout'] = 'Диапазон 1–60 сек.';
        }
        if ($data['response_timeout'] < 5 || $data['response_timeout'] > 900) {
            $errors['response_timeout'] = 'Диапазон 5–900 сек.';
        }
        if ($data['max_tokens'] < 1 || $data['max_tokens'] > 200000) {
            $errors['max_tokens'] = 'Диапазон 1–200000.';
        }

        return $errors;
    }

    /**
     * Разбор textarea "Имя: значение" по строкам.
     *
     * @return array{0:array<string,string>,1:?string} заголовки и текст ошибки
     */
    public static function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\R/u', trim($raw)) ?: [] as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                return [[], 'Строка ' . ($lineNumber + 1) . ': ожидается формат «Имя: значение».'];
            }
            $name = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $name) !== 1) {
                return [[], 'Строка ' . ($lineNumber + 1) . ': недопустимое имя заголовка.'];
            }
            if (in_array(strtolower($name), self::FORBIDDEN_HEADERS, true)) {
                return [[], 'Заголовок ' . $name . ' задаётся приложением и не может быть переопределён.'];
            }
            if ($value === '' || strlen($value) > 2000 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return [[], 'Строка ' . ($lineNumber + 1) . ': недопустимое значение заголовка.'];
            }
            $headers[$name] = $value;
        }
        if (count($headers) > 20) {
            return [[], 'Не более 20 дополнительных заголовков.'];
        }

        return [$headers, null];
    }
}
