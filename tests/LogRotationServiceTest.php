<?php

declare(strict_types=1);

namespace App\Tests;

use App\Diagnostics\LogRotationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Ротация логов ограничивает размер SQLite-базы (по умолчанию 50 МБ),
 * удаляя старейшие записи журналов при превышении лимита, чтобы логи не
 * росли бесконечно.
 */
final class LogRotationServiceTest extends TestCase
{
    private function makePdo(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно в этом окружении.');
        }

        $pdo = new PDO('sqlite::memory:');
        foreach (['app_log', 'audit_log', 'diagnostic_log'] as $table) {
            $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT)");
        }

        return $pdo;
    }

    public function testDeletesOldestFractionWhenOverLimit(): void
    {
        $pdo = $this->makePdo();
        for ($i = 1; $i <= 100; $i++) {
            $pdo->exec("INSERT INTO app_log (created_at) VALUES ('2026-01-01T00:00:{$i}Z')");
        }

        $rotation = new LogRotationService($pdo);

        $method = new \ReflectionMethod(LogRotationService::class, 'deleteOldestFraction');
        $method->invoke($rotation, 'app_log', 0.2);

        $remaining = (int) $pdo->query('SELECT COUNT(*) FROM app_log')->fetchColumn();
        $this->assertSame(80, $remaining);

        // Удалены именно старейшие (наименьший id), не случайные записи.
        $minId = (int) $pdo->query('SELECT MIN(id) FROM app_log')->fetchColumn();
        $this->assertSame(21, $minId);
    }

    public function testDeleteOldestFractionIsNoOpOnEmptyTable(): void
    {
        $pdo = $this->makePdo();
        $rotation = new LogRotationService($pdo);

        $method = new \ReflectionMethod(LogRotationService::class, 'deleteOldestFraction');
        $method->invoke($rotation, 'app_log', 0.2);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM app_log')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testMaxBytesDefaultsToFiftyMegabytes(): void
    {
        $reflection = new \ReflectionClass(LogRotationService::class);
        $const = $reflection->getConstant('DEFAULT_MAX_BYTES');

        $this->assertSame(50 * 1024 * 1024, $const);
    }
}
