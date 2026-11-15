<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Paths;
use RuntimeException;

/**
 * Шифрование значений в состоянии покоя (AEAD, XChaCha20-Poly1305 либо AES-256-GCM),
 * master key берётся из ENV APP_MASTER_KEY либо из файла var/data/master.key
 * (права 0600, вне document root). Используется для любых секретов,
 * которые в будущем потребуется сохранять (сейчас — CSRF/session secret).
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';

    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $envKey = getenv('APP_MASTER_KEY');
        if (is_string($envKey) && $envKey !== '') {
            $decoded = base64_decode($envKey, true);
            if ($decoded !== false && strlen($decoded) === 32) {
                self::$key = $decoded;
                return self::$key;
            }
        }

        $keyFile = Paths::secretKeyFile();
        if (!is_file($keyFile)) {
            Paths::ensureDirectories();
            $generated = random_bytes(32);
            file_put_contents($keyFile, base64_encode($generated), LOCK_EX);
            chmod($keyFile, 0600);
            self::$key = $generated;
            return self::$key;
        }

        $raw = trim((string) file_get_contents($keyFile));
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('Мастер-ключ повреждён.');
        }
        self::$key = $decoded;

        return self::$key;
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Ошибка шифрования.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $key = self::key();
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Ошибка расшифровки.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Ошибка расшифровки.');
        }

        return $plaintext;
    }
}
