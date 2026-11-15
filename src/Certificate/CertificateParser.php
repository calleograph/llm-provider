<?php

declare(strict_types=1);

namespace App\Certificate;

use RuntimeException;

/**
 * Разбор X.509 сертификата (PEM или DER) средствами OpenSSL перед сохранением.
 * Приватные ключи отклоняются — принимается только сертификат.
 */
final class CertificateParser
{
    /**
     * @return array{pem:string, subject:string, issuer:string, serial:string,
     *               fingerprint_sha256:string, valid_from:string, valid_to:string}
     */
    public static function parse(string $rawContent): array
    {
        if (self::looksLikePrivateKey($rawContent)) {
            throw new RuntimeException('Загружаемый файл похож на приватный ключ. Приватные ключи не принимаются.');
        }

        $pem = self::toPem($rawContent);

        $resource = @openssl_x509_read($pem);
        if ($resource === false) {
            throw new RuntimeException('Файл не распознан как корректный X.509 сертификат.');
        }

        $parsed = openssl_x509_parse($resource);
        if ($parsed === false) {
            throw new RuntimeException('Не удалось разобрать параметры сертификата.');
        }

        $fingerprint = openssl_x509_fingerprint($resource, 'sha256');
        if ($fingerprint === false) {
            throw new RuntimeException('Не удалось вычислить fingerprint сертификата.');
        }

        $isCa = (bool) ($parsed['extensions']['basicConstraints'] ?? '');
        $basicConstraints = (string) ($parsed['extensions']['basicConstraints'] ?? '');
        if (!str_contains($basicConstraints, 'CA:TRUE')) {
            throw new RuntimeException('Сертификат не является CA-сертификатом (basicConstraints CA:TRUE отсутствует).');
        }

        return [
            'pem' => $pem,
            'subject' => self::formatName($parsed['subject'] ?? []),
            'issuer' => self::formatName($parsed['issuer'] ?? []),
            'serial' => (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
            'fingerprint_sha256' => strtoupper(str_replace(':', '', $fingerprint)),
            'valid_from' => self::formatTimestamp((int) $parsed['validFrom_time_t']),
            'valid_to' => self::formatTimestamp((int) $parsed['validTo_time_t']),
        ];
    }

    private static function looksLikePrivateKey(string $content): bool
    {
        return (bool) preg_match('/-----BEGIN (RSA |EC |ENCRYPTED )?PRIVATE KEY-----/', $content);
    }

    private static function toPem(string $content): string
    {
        if (str_contains($content, '-----BEGIN CERTIFICATE-----')) {
            return $content;
        }

        // Предполагаем DER, конвертируем в PEM.
        $base64 = base64_encode($content);
        $chunks = chunk_split($base64, 64, "\n");

        return "-----BEGIN CERTIFICATE-----\n" . $chunks . "-----END CERTIFICATE-----\n";
    }

    private static function formatName(array $nameParts): string
    {
        $segments = [];
        foreach ($nameParts as $key => $value) {
            if (is_array($value)) {
                // Многозначный RDN (например DC=local + DC=example в одном
                // компоненте имени) — каждое значение получает собственный
                // ключ, а не склеивается без него.
                foreach ($value as $v) {
                    $segments[] = $key . '=' . $v;
                }
                continue;
            }
            $segments[] = $key . '=' . $value;
        }

        return implode(', ', $segments);
    }

    private static function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
    }
}
