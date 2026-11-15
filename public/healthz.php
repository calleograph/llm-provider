<?php

declare(strict_types=1);

namespace App;

use App\Config\AppSettings;
use App\Http\ClientIp;

require __DIR__ . '/../src/bootstrap.php';

/**
 * Health-check route, доступный только с localhost или доверенных reverse proxy.
 * Не требует аутентификации, поэтому не должен быть публично доступен извне.
 */
$pdo = bootstrap();
sendSecurityHeaders();
$appSettings = new AppSettings($pdo);
$trustedProxies = $appSettings->trustedProxies();

$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalhost = in_array($remoteAddr, ['127.0.0.1', '::1'], true);
$viaTrustedProxy = ClientIp::resolve($trustedProxies) !== $remoteAddr;

if (!$isLocalhost && !$viaTrustedProxy) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$checks = [
    'php_version' => PHP_VERSION,
    'ext_ldap' => extension_loaded('ldap'),
    'ext_openssl' => extension_loaded('openssl'),
    'ext_pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'ext_mbstring' => extension_loaded('mbstring'),
    'database_reachable' => true,
];

try {
    $pdo->query('SELECT 1');
} catch (\Throwable) {
    $checks['database_reachable'] = false;
}

$healthy = !in_array(false, $checks, true);

http_response_code($healthy ? 200 : 503);
echo json_encode(['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
