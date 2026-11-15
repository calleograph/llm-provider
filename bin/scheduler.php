<?php

declare(strict_types=1);

/**
 * Фоновые задачи (контейнер scheduler, тот же образ, что php): раз в минуту — очередь писем; раз в час —
 * ротация журналов. Пульс — файл scheduler.heartbeat в каталоге данных (healthcheck контейнера).
 * Останавливается по SIGTERM между задачами.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor_autoload.php';

use App\Config\AppSettings;
use App\Config\Database;
use App\Config\Paths;

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
}
$lastHourly = 0;
while (!$stop) {
    try {
        Paths::ensureDirectories();
        $pdo = Database::connect(Paths::database());
        $settings = new AppSettings($pdo);
        $module = new \App\Llm\LlmModule($pdo, $settings, new \App\Certificate\CaBundleBuilder(new \App\Certificate\CertificateRepository($pdo)), new \App\Diagnostics\DiagnosticLogger($pdo));
        $module->mailQueue()->process($module->mailSettings());
        if (time() - $lastHourly >= 3600) {
            (new \App\Diagnostics\LogRotationService($pdo))->rotateIfNeeded();
            $lastHourly = time();
        }
        @file_put_contents(Paths::data() . '/scheduler.heartbeat', (string) time());
        $pdo = null;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'scheduler: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    }
    for ($i = 0; $i < 60 && !$stop; $i++) {
        sleep(1);
    }
}
