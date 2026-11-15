<?php

declare(strict_types=1);

/**
 * Если composer install выполнен — используем настоящий vendor/autoload.php
 * (нужен для phpunit и т.п.). Иначе регистрируем минимальный PSR-4 автозагрузчик
 * для namespace App\, чтобы приложение работало и без composer.
 */

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
