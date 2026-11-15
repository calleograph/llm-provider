<?php

declare(strict_types=1);

require __DIR__ . '/../vendor_autoload.php';

// vendor_autoload.php регистрирует PSR-4 только для App\ (src/) — этот
// проект не полагается на composer install. Тестовые вспомогательные
// классы, не оканчивающиеся на *Test (например FakeHttpTransport,
// LlmTestKit), не подхватываются ни этим автозагрузчиком, ни сканером
// тестовых классов PHPUnit — регистрируем для них отдельный минимальный
// автозагрузчик по тому же принципу.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
