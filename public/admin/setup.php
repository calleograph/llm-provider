<?php

declare(strict_types=1);

namespace App;

use App\Audit\AuditLogger;
use App\Auth\AdminSession;
use App\Auth\LocalAdminRepository;
use App\Config\AppSettings;
use App\Http\ClientIp;
use App\Http\View;
use App\Security\Csrf;
use App\Security\RateLimiter;
use App\Security\SessionManager;

require __DIR__ . '/../../src/bootstrap.php';

$pdo = bootstrap();
SessionManager::start(isHttps());
sendSecurityHeaders();

$settings = new AppSettings($pdo);
$clientIp = ClientIp::resolve($settings->trustedProxies());
$admins = new LocalAdminRepository($pdo);
$audit = new AuditLogger($pdo);
$limiter = new RateLimiter($pdo);

if ($admins->exists()) {
    header('Location: index.php');
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirm'] ?? '');
    $bucket = 'admin_setup:' . $clientIp;

    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $error = 'Форма устарела. Обновите страницу и попробуйте снова.';
    } elseif ($limiter->retryAfterSeconds($bucket) > 0) {
        $error = 'Слишком много попыток. Повторите позже.';
    } elseif (mb_strlen($password) < 14) {
        $error = 'Пароль должен содержать не менее 14 символов.';
    } elseif (!hash_equals($password, $confirmation)) {
        $error = 'Пароль и подтверждение не совпадают.';
    } else {
        $limiter->recordAttempt($bucket);
        try {
            $admins->create('admin', $password);
            $limiter->resetOnSuccess($bucket);
            $audit->log('admin', $clientIp, 'local_admin_bootstrap', true);
            AdminSession::login('admin', 'local');
            Csrf::rotate();
            header('Location: index.php?section=status&first_setup=1');
            exit;
        } catch (\Throwable) {
            $audit->log('system', $clientIp, 'local_admin_bootstrap', false);
            $error = 'Не удалось завершить первичную настройку. Проверьте права записи в var/data.';
        }
    }
    $password = str_repeat("\0", strlen($password));
    $confirmation = str_repeat("\0", strlen($confirmation));
    unset($password, $confirmation);
}

echo View::render(__DIR__ . '/../../templates/admin/setup.php', [
    'csrfToken' => Csrf::token(),
    'errorMessage' => $error,
]);
