<?php

declare(strict_types=1);

namespace App\Tests;

use App\Audit\UnifiedEventLog;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Единая лента событий объединяет app_log (операции смены пароля), audit_log
 * (административные действия) и diagnostic_log (техническая диагностика) в
 * один хронологический список — нужна для быстрого поиска "что происходило
 * с конкретной учётной записью" без переключения между тремя вкладками.
 */
final class UnifiedEventLogTest extends TestCase
{
    private function makePdo(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно в этом окружении.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE app_log (id INTEGER PRIMARY KEY, created_at TEXT, ip TEXT, username TEXT, operation TEXT, result TEXT)');
        $pdo->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, created_at TEXT, actor TEXT, ip TEXT, action TEXT, details TEXT, success INTEGER)');
        $pdo->exec('CREATE TABLE diagnostic_log (id INTEGER PRIMARY KEY, created_at TEXT, level TEXT, source TEXT, event_code TEXT, message TEXT, context TEXT, ip TEXT)');

        $pdo->exec("INSERT INTO app_log (created_at, ip, username, operation, result) VALUES ('2026-01-01T10:00:00Z', '10.0.0.1', 'ivanov', 'CHANGE_PASSWORD', 'CHANGE_PASSWORD_SUCCESS')");
        $pdo->exec("INSERT INTO audit_log (created_at, actor, ip, action, details, success) VALUES ('2026-01-01T09:00:00Z', 'admin', '10.0.0.2', 'admin_login', 'method=local', 1)");
        $pdo->exec("INSERT INTO diagnostic_log (created_at, level, source, event_code, message, context, ip) VALUES ('2026-01-01T09:30:00Z', 'error', 'ldap_bind', 'invalid_credentials', 'bind failed for ivanov', '{}', '10.0.0.1')");

        return $pdo;
    }

    public function testCombinesAllThreeSourcesOrderedByTime(): void
    {
        $log = new UnifiedEventLog($this->makePdo());
        $result = $log->query();

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['rows']);
        // Отсортировано по убыванию времени: 10:00, 09:30, 09:00.
        $this->assertSame('password_change', $result['rows'][0]['category']);
        $this->assertSame('diagnostic', $result['rows'][1]['category']);
        $this->assertSame('admin_action', $result['rows'][2]['category']);
    }

    public function testFiltersByCategory(): void
    {
        $log = new UnifiedEventLog($this->makePdo());
        $result = $log->query(['category' => 'admin_action']);

        $this->assertSame(1, $result['total']);
        $this->assertSame('admin_action', $result['rows'][0]['category']);
    }

    public function testSearchFindsAcrossAllSources(): void
    {
        $log = new UnifiedEventLog($this->makePdo());

        // "ivanov" встречается и в app_log.username (-> actor), и в
        // diagnostic_log.message (-> summary) — поиск должен найти оба.
        $result = $log->query(['search' => 'ivanov']);

        $this->assertSame(2, $result['total']);
    }

    public function testOutcomeIsDerivedCorrectlyPerCategory(): void
    {
        $log = new UnifiedEventLog($this->makePdo());
        $result = $log->query();

        $byCategory = [];
        foreach ($result['rows'] as $row) {
            $byCategory[$row['category']] = $row['outcome'];
        }

        $this->assertSame('success', $byCategory['password_change']);
        $this->assertSame('success', $byCategory['admin_action']);
        $this->assertSame('failure', $byCategory['diagnostic']);
    }

    public function testPaginationLimitsResults(): void
    {
        $log = new UnifiedEventLog($this->makePdo());
        $result = $log->query(['limit' => 1]);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(3, $result['total']);
    }
}
