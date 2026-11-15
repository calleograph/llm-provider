<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\AppSettings;
use App\Security\SecretBox;

/**
 * Настройки SMTP (app_settings, префикс mail_). Пароль хранится только зашифрованным (SecretBox) и в форму
 * не возвращается: пустое поле при сохранении оставляет прежний пароль, смена сервера или порта его сбрасывает
 * (как в ArkLock — чтобы пароль не ушёл на другой сервер).
 */
final class MailSettings
{
    private const PREFIX = 'mail_';
    public const SECURITY = ['starttls' => 'STARTTLS (порт 587)', 'tls' => 'TLS сразу (порт 465)', 'none' => 'Без шифрования (только внутренняя сеть)'];
    public const AUTH = ['auto' => 'Автоматически (по возможностям сервера)', 'plain' => 'PLAIN', 'login' => 'LOGIN', 'none' => 'Без авторизации (relay по адресу)'];
    private const DEFAULTS = [
        'enabled' => '0', 'host' => '', 'port' => '587', 'security' => 'starttls', 'auth' => 'auto', 'username' => '',
        'from_address' => '', 'from_name' => 'LLM-провайдер', 'timeout' => '15', 'verify_cert' => '1', 'helo' => '',
        'alert_recipients' => '', 'alerts_enabled' => '1',
    ];

    public function __construct(private readonly AppSettings $settings)
    {
    }

    public function get(string $name): string
    {
        return (string) $this->settings->get(self::PREFIX . $name, self::DEFAULTS[$name] ?? '');
    }

    /** @return array<string,string> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            $out[$k] = $this->get($k);
        }

        return $out;
    }

    /** @param array<string,string> $values */
    public function save(array $values, ?string $newPassword): void
    {
        $serverChanged = ($values['host'] ?? $this->get('host')) !== $this->get('host') || ($values['port'] ?? $this->get('port')) !== $this->get('port');
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS)) {
                $this->settings->set(self::PREFIX . $k, (string) $v);
            }
        }
        if ($newPassword !== null && $newPassword !== '') {
            $this->settings->set(self::PREFIX . 'password_enc', SecretBox::encrypt($newPassword));
        } elseif ($serverChanged) {
            $this->settings->set(self::PREFIX . 'password_enc', '');
        }
    }

    public function hasPassword(): bool
    {
        return (string) $this->settings->get(self::PREFIX . 'password_enc', '') !== '';
    }

    public function password(): string
    {
        $enc = (string) $this->settings->get(self::PREFIX . 'password_enc', '');

        return $enc === '' ? '' : SecretBox::decrypt($enc);
    }

    public function isConfigured(): bool
    {
        return $this->get('enabled') === '1' && $this->get('host') !== '' && filter_var($this->get('from_address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return list<string> */
    public function alertRecipients(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $this->get('alert_recipients')) ?: []), static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
    }

    /**
     * @param array<string,string> $v
     * @return array<string,string>
     */
    public static function validate(array $v): array
    {
        $e = [];
        if (($v['enabled'] ?? '0') === '1' && !preg_match('/\A[A-Za-z0-9.-]{1,253}\z/', $v['host'] ?? '')) {
            $e['host'] = 'Имя или адрес сервера: латиница, цифры, точки и дефисы.';
        }
        $port = (int) ($v['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $e['port'] = 'Порт: 1–65535.';
        }
        if (!array_key_exists($v['security'] ?? '', self::SECURITY)) {
            $e['security'] = 'Недопустимый режим шифрования.';
        }
        if (!array_key_exists($v['auth'] ?? '', self::AUTH)) {
            $e['auth'] = 'Недопустимый способ авторизации.';
        }
        if (($v['enabled'] ?? '0') === '1' && filter_var($v['from_address'] ?? '', FILTER_VALIDATE_EMAIL) === false) {
            $e['from_address'] = 'Укажите адрес отправителя.';
        }
        if (preg_match('/[\r\n]/', ($v['from_name'] ?? '') . ($v['username'] ?? '') . ($v['helo'] ?? '')) === 1) {
            $e['from_name'] = 'Переводы строк недопустимы.';
        }
        $t = (int) ($v['timeout'] ?? 0);
        if ($t < 3 || $t > 120) {
            $e['timeout'] = 'Таймаут: 3–120 секунд.';
        }

        return $e;
    }
}
