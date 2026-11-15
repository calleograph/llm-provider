<?php

declare(strict_types=1);

namespace App\Tests;

use App\Diagnostics\DiagnosticLevel;
use App\Diagnostics\DiagnosticLogger;
use App\Diagnostics\DiagnosticSource;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Диагностический журнал (LDAP/TLS/kpasswd ошибки) не должен содержать
 * значения паролей — проверяем redaction на уровне контекста и текста
 * сообщения, а также фильтрацию по уровню/источнику/поиску/периоду.
 */
final class DiagnosticLoggerTest extends TestCase
{
    private function makeLogger(): DiagnosticLogger
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно в этом окружении.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(<<<SQL
            CREATE TABLE diagnostic_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                level TEXT NOT NULL,
                source TEXT NOT NULL,
                event_code TEXT NOT NULL,
                message TEXT NOT NULL,
                context TEXT,
                ip TEXT
            )
        SQL);

        return new DiagnosticLogger($pdo);
    }

    public function testContextKeyLookingLikePasswordIsRedacted(): void
    {
        $logger = $this->makeLogger();
        $logger->error(DiagnosticSource::LDAP_BIND, 'test', 'test message', ['old_password' => 'SuperSecret1!', 'username' => 'ivanov']);

        $result = $logger->query();
        $context = json_decode($result['rows'][0]['context'], true);

        $this->assertSame('[redacted]', $context['old_password']);
        $this->assertSame('ivanov', $context['username']);
    }

    public function testMessageContainingPasswordLikeSubstringIsRedacted(): void
    {
        $logger = $this->makeLogger();
        $logger->error(DiagnosticSource::PHP_ERROR, 'test', 'Connection failed, password=hunter2 was rejected');

        $result = $logger->query();
        $this->assertStringNotContainsString('hunter2', $result['rows'][0]['message']);
        $this->assertStringContainsString('[redacted]', $result['rows'][0]['message']);
    }

    public function testFilterByLevel(): void
    {
        $logger = $this->makeLogger();
        $logger->info(DiagnosticSource::CONNECTION_TEST, 'a', 'info message');
        $logger->error(DiagnosticSource::LDAP_BIND, 'b', 'error message');

        $result = $logger->query(['level' => DiagnosticLevel::ERROR]);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('error', $result['rows'][0]['level']);
    }

    public function testFilterBySource(): void
    {
        $logger = $this->makeLogger();
        $logger->info(DiagnosticSource::CONNECTION_TEST, 'a', 'msg1');
        $logger->info(DiagnosticSource::KPASSWD, 'b', 'msg2');

        $result = $logger->query(['source' => DiagnosticSource::KPASSWD]);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(DiagnosticSource::KPASSWD, $result['rows'][0]['source']);
    }

    public function testFilterBySearchText(): void
    {
        $logger = $this->makeLogger();
        $logger->info(DiagnosticSource::CONNECTION_TEST, 'a', 'unique-marker-xyz');
        $logger->info(DiagnosticSource::CONNECTION_TEST, 'b', 'something else');

        $result = $logger->query(['search' => 'unique-marker-xyz']);
        $this->assertCount(1, $result['rows']);
    }

    public function testTotalCountIsIndependentOfLimit(): void
    {
        $logger = $this->makeLogger();
        for ($i = 0; $i < 5; $i++) {
            $logger->info(DiagnosticSource::CONNECTION_TEST, 'a', 'msg' . $i);
        }

        $result = $logger->query(['limit' => 2]);
        $this->assertCount(2, $result['rows']);
        $this->assertSame(5, $result['total']);
    }

    public function testIgnoresInvalidLevelFilter(): void
    {
        $logger = $this->makeLogger();
        $logger->info(DiagnosticSource::CONNECTION_TEST, 'a', 'msg');

        $result = $logger->query(['level' => 'not-a-real-level']);
        $this->assertCount(1, $result['rows']);
    }
}
