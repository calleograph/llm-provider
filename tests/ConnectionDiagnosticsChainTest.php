<?php

declare(strict_types=1);

namespace App\Tests;

use App\Certificate\CaBundleBuilder;
use App\Certificate\CertificateRepository;
use App\Ldap\ConnectionDiagnostics;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Регрессионный тест на реальный сценарий: сертификат AD подписан
 * Intermediate CA (CORP-IssuingCA), администратор загрузил только Root CA
 * (CORP-RootCA) — цепочка неполная, TLS отклоняется ext-ldap. Диагностика
 * должна явно указать, что Issuer предъявленного сертификата отсутствует
 * среди загруженных, а не просто сказать "TLS certificate valid: Нет"
 * без объяснения причины.
 */
final class ConnectionDiagnosticsChainTest extends TestCase
{
    private function makeRepository(array $subjects): CertificateRepository
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно в этом окружении.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(<<<SQL
            CREATE TABLE certificates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL,
                type TEXT NOT NULL,
                subject TEXT NOT NULL,
                issuer TEXT NOT NULL,
                serial TEXT NOT NULL,
                fingerprint_sha256 TEXT NOT NULL UNIQUE,
                valid_from TEXT NOT NULL,
                valid_to TEXT NOT NULL,
                uploaded_by TEXT NOT NULL,
                uploaded_at TEXT NOT NULL
            )
        SQL);

        $stmt = $pdo->prepare(
            'INSERT INTO certificates (filename, type, subject, issuer, serial, fingerprint_sha256, valid_from, valid_to, uploaded_by, uploaded_at)
             VALUES (:filename, :type, :subject, :issuer, :serial, :fp, :vf, :vt, :by, :at)'
        );
        foreach ($subjects as $i => $subject) {
            $stmt->execute([
                'filename' => 'cert' . $i . '.pem',
                'type' => 'root',
                'subject' => $subject,
                'issuer' => $subject,
                'serial' => (string) $i,
                'fp' => str_pad((string) $i, 64, '0'),
                'vf' => '2024-01-01 00:00:00 UTC',
                'vt' => '2030-01-01 00:00:00 UTC',
                'by' => 'admin',
                'at' => gmdate('c'),
            ]);
        }

        return new CertificateRepository($pdo);
    }

    private function checkIssuer(ConnectionDiagnostics $diagnostics, string $issuer): bool
    {
        $method = new ReflectionMethod(ConnectionDiagnostics::class, 'issuerIsAmongUploadedCertificates');

        return $method->invoke($diagnostics, $issuer);
    }

    public function testIssuerNotFoundWhenOnlyRootCaUploadedButCertSignedByIntermediate(): void
    {
        // Ровно сценарий пользователя: загружен только Root CA, а AD1
        // предъявляет сертификат с Issuer = CORP-IssuingCA (Intermediate).
        $repo = $this->makeRepository(['CN=CORP-RootCA']);
        $caBundleBuilder = new CaBundleBuilder($repo);
        $diagnostics = new ConnectionDiagnostics($caBundleBuilder, $repo);

        $this->assertFalse($this->checkIssuer($diagnostics, 'DC=local, DC=corp, CN=CORP-IssuingCA'));
    }

    public function testIssuerFoundWhenBothRootAndIntermediateUploaded(): void
    {
        $repo = $this->makeRepository(['CN=CORP-RootCA', 'DC=local, DC=corp, CN=CORP-IssuingCA']);
        $caBundleBuilder = new CaBundleBuilder($repo);
        $diagnostics = new ConnectionDiagnostics($caBundleBuilder, $repo);

        $this->assertTrue($this->checkIssuer($diagnostics, 'DC=local, DC=corp, CN=CORP-IssuingCA'));
    }

    public function testReturnsTrueWhenNoCertificateRepositoryProvided(): void
    {
        // Без репозитория (обратная совместимость / вызов без второго аргумента)
        // не должно быть ложной тревоги — конструктор допускает null.
        $repo = $this->makeRepository([]);
        $caBundleBuilder = new CaBundleBuilder($repo);
        $diagnostics = new ConnectionDiagnostics($caBundleBuilder);

        $this->assertTrue($this->checkIssuer($diagnostics, 'CN=anything'));
    }

    /**
     * Регрессия из реального отчёта: Subject загруженного Intermediate CA
     * отформатирован (при разборе PEM-файла) как "DC=local, DC=corp, CN=CORP-IssuingCA",
     * а Issuer того же сертификата, предъявленного сервером и прочитанного
     * через сырой TLS-сокет, ранее склеивался (баг formatDn с multi-valued
     * RDN) как "DC=local+corp, CN=CORP-IssuingCA" — без повторения ключа "DC=" для
     * второго значения. Строковое сравнение не совпадало, хотя это один и
     * тот же сертификат. Нормализация (регистр/порядок/разделители) должна
     * находить совпадение несмотря на разный порядок и разделители компонентов.
     */
    public function testIssuerMatchesDespiteDifferentMultiValuedRdnFormatting(): void
    {
        $repo = $this->makeRepository(['DC=local, DC=corp, CN=CORP-IssuingCA']);
        $caBundleBuilder = new CaBundleBuilder($repo);
        $diagnostics = new ConnectionDiagnostics($caBundleBuilder, $repo);

        // Вариант с "+" вместо ", " для многозначного RDN и другим порядком.
        $this->assertTrue($this->checkIssuer($diagnostics, 'CN=CORP-IssuingCA, DC=corp+DC=local'));
    }

    public function testFormatDnProducesKeyedSegmentsForMultiValuedRdn(): void
    {
        $method = new ReflectionMethod(ConnectionDiagnostics::class, 'formatDn');

        $result = $method->invoke(null, ['DC' => ['local', 'corp'], 'CN' => 'CORP-IssuingCA']);

        $this->assertSame('DC=local, DC=corp, CN=CORP-IssuingCA', $result);
    }
}
