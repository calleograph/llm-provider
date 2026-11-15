<?php

declare(strict_types=1);

/**
 * Аварийный локальный администратор из консоли сервера (как admin-reset.sh «Переговорки»): создаёт его, если нет,
 * или задаёт новый пароль. Пароль случайный, из алфавита без похожих символов, печатается ОДИН раз и нигде не хранится
 * в открытом виде. Через веб недоступно.
 *
 *   docker compose exec php php bin/admin-reset.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor_autoload.php';

use App\Auth\LocalAdminRepository;
use App\Config\Database;
use App\Config\Paths;

Paths::ensureDirectories();
$pdo = Database::connect(Paths::database());
$admins = new LocalAdminRepository($pdo);
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
$password = '';
for ($i = 0; $i < 20; $i++) {
    $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}
if ($admins->exists()) {
    $admins->changePassword($password);
    $action = 'reset';
} else {
    $admins->create('admin', $password);
    @touch(Paths::installedMarker());
    $action = 'created';
}
$pdo->prepare('INSERT INTO audit_log (created_at, actor, ip, action, success, details) VALUES (?, ?, ?, ?, 1, ?)')
    ->execute([gmdate('c'), 'console', '127.0.0.1', 'local_admin_' . $action, '{"source":"console"}']);
echo json_encode(['action' => $action, 'username' => $admins->username(), 'password' => $password], JSON_UNESCAPED_UNICODE), "\n";
