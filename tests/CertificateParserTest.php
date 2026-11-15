<?php

declare(strict_types=1);

namespace App\Tests;

use App\Certificate\CertificateParser;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class CertificateParserTest extends TestCase
{
    private static function generateSelfSignedCaPem(): string
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'x509_extensions' => 'v3_ca',
            'config' => null,
        ];

        $privateKey = openssl_pkey_new($config);
        $csr = openssl_csr_new(['commonName' => 'Test Root CA'], $privateKey, $config);
        $cert = openssl_csr_sign($csr, null, $privateKey, 3650, $config, random_int(1000, 999999));

        openssl_x509_export($cert, $pem, false);
        // Внедряем basicConstraints CA:TRUE вручную, т.к. openssl_csr_sign без extfile
        // не всегда прописывает v3_extensions с basicConstraints в самоподписанном сертификате.
        if (!str_contains(openssl_x509_parse($cert)['extensions']['basicConstraints'] ?? '', 'CA:TRUE')) {
            // Пересобираем через openssl CLI c явным extfile, если PHP API не проставил extension.
            return self::generateViaOpensslCli();
        }

        return $pem;
    }

    private static function generateViaOpensslCli(): string
    {
        $tmpDir = sys_get_temp_dir() . '/adpwd_test_' . bin2hex(random_bytes(6));
        mkdir($tmpDir);
        $keyPath = $tmpDir . '/key.pem';
        $certPath = $tmpDir . '/cert.pem';
        $configPath = $tmpDir . '/req.cnf';

        file_put_contents($configPath, <<<CNF
            [req]
            distinguished_name = dn
            x509_extensions = v3_ca
            prompt = no
            [dn]
            CN = Test Root CA
            [v3_ca]
            basicConstraints = critical,CA:TRUE
            CNF);

        putenv('MSYS_NO_PATHCONV=1');
        $cmd = sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 3650 -nodes -config %s 2>&1',
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg($configPath)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($certPath)) {
            self::fail('Не удалось сгенерировать тестовый сертификат через openssl CLI: ' . implode("\n", $output));
        }

        $pem = file_get_contents($certPath);
        unlink($keyPath);
        unlink($certPath);
        unlink($configPath);
        rmdir($tmpDir);

        return $pem;
    }

    public function testParsesValidCaCertificate(): void
    {
        $pem = self::generateViaOpensslCli();
        $result = CertificateParser::parse($pem);

        $this->assertStringContainsString('Test Root CA', $result['subject']);
        $this->assertNotEmpty($result['fingerprint_sha256']);
        $this->assertSame(64, strlen($result['fingerprint_sha256']));
        $this->assertNotEmpty($result['serial']);
    }

    public function testRejectsPrivateKey(): void
    {
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($privateKey, $pem);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('приватный ключ');
        CertificateParser::parse($pem);
    }

    public function testRejectsGarbageInput(): void
    {
        $this->expectException(RuntimeException::class);
        CertificateParser::parse('this is not a certificate at all');
    }

    public function testRejectsNonCaCertificate(): void
    {
        $tmpDir = sys_get_temp_dir() . '/adpwd_test_' . bin2hex(random_bytes(6));
        mkdir($tmpDir);
        $keyPath = $tmpDir . '/key.pem';
        $certPath = $tmpDir . '/cert.pem';

        putenv('MSYS_NO_PATHCONV=1');
        $cmd = sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 30 -nodes -subj "//CN=leaf.example.com" -addext "basicConstraints=critical,CA:FALSE" 2>&1',
            escapeshellarg($keyPath),
            escapeshellarg($certPath)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($certPath)) {
            self::fail('Не удалось сгенерировать тестовый leaf-сертификат: ' . implode("\n", $output));
        }

        $pem = file_get_contents($certPath);
        unlink($keyPath);
        unlink($certPath);
        rmdir($tmpDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CA-сертификатом');
        CertificateParser::parse($pem);
    }

    /**
     * Регрессия: составной RDN (например DC=local + DC=corp в одном
     * компоненте имени, как у AD-сертификатов) должен форматироваться с
     * повторением ключа для каждого значения ("DC=local, DC=corp"), а не
     * склеиваться в единое некорректное значение ("DC=local+corp").
     */
    public function testFormatNameKeepsKeyForEachMultiValuedRdnComponent(): void
    {
        $method = new ReflectionMethod(CertificateParser::class, 'formatName');

        $result = $method->invoke(null, ['DC' => ['local', 'corp'], 'CN' => 'CORP-IssuingCA']);

        $this->assertSame('DC=local, DC=corp, CN=CORP-IssuingCA', $result);
    }

    public function testFormatNameHandlesSingleValuedComponents(): void
    {
        $method = new ReflectionMethod(CertificateParser::class, 'formatName');

        $result = $method->invoke(null, ['CN' => 'CORP-RootCA']);

        $this->assertSame('CN=CORP-RootCA', $result);
    }
}
