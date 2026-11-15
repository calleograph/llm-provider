<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Определение реального IP клиента за reverse proxy.
 * X-Forwarded-For доверяем ТОЛЬКО если непосредственный REMOTE_ADDR входит
 * в список доверенных прокси из конфигурации (app_settings.trusted_proxies).
 */
final class ClientIp
{
    /** @param string[] $trustedProxies CIDR или точные IP */
    public static function resolve(array $trustedProxies): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!self::isTrusted($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }

        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($forwardedFor === null) {
            return $remoteAddr;
        }

        $parts = array_map('trim', explode(',', $forwardedFor));
        $clientCandidate = $parts[0] ?? $remoteAddr;

        return filter_var($clientCandidate, FILTER_VALIDATE_IP) !== false ? $clientCandidate : $remoteAddr;
    }

    /** @param string[] $trustedProxies */
    private static function isTrusted(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $proxy) {
            $proxy = trim($proxy);
            if ($proxy === '') {
                continue;
            }
            if (str_contains($proxy, '/')) {
                if (self::ipInCidr($ip, $proxy)) {
                    return true;
                }
            } elseif ($proxy === $ip) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $maskBits] = explode('/', $cidr, 2);
        $maskBits = (int) $maskBits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($maskBits, 8);
        $bits = $maskBits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $bits)) & 0xFF);

        return (substr($ipBin, $bytes, 1) & $mask) === (substr($subnetBin, $bytes, 1) & $mask);
    }
}
