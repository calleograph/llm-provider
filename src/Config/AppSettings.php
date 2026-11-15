<?php

declare(strict_types=1);

namespace App\Config;

use PDO;

/**
 * Простое key-value хранилище прочих настроек приложения (например список
 * доверенных reverse proxy) в таблице app_settings.
 */
final class AppSettings
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM app_settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_settings (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    /** @return string[] */
    public function trustedProxies(): array
    {
        $raw = $this->get('trusted_proxies', '');

        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }

    public function setTrustedProxies(array $proxies): void
    {
        $this->set('trusted_proxies', implode(',', $proxies));
    }
}
