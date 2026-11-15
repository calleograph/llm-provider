<?php

declare(strict_types=1);

/**
 * Healthcheck контейнера php-fpm (CLI): БД открывается и отвечает, каталоги данных доступны на запись.
 * Код 0 — здоров. Текст ошибки не содержит секретов.
 */

require __DIR__ . '/../vendor_autoload.php';

use App\Config\Database;
use App\Config\Paths;

try {
    Paths::ensureDirectories();
    $pdo = Database::connect(Paths::database());
    $pdo->query('SELECT 1')->fetchColumn();
    foreach ([Paths::data(), Paths::sessions(), Paths::logs()] as $dir) {
        if (!is_writable($dir)) {
            throw new RuntimeException('Каталог недоступен на запись: ' . basename($dir));
        }
    }
    echo "ok\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'unhealthy: ' . $e->getMessage() . "\n");
    exit(1);
}
