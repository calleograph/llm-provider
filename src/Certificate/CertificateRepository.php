<?php

declare(strict_types=1);

namespace App\Certificate;

use App\Config\Paths;
use PDO;
use RuntimeException;

/**
 * Хранение доверенных CA/Intermediate сертификатов. Файлы сертификатов лежат
 * в var/certs (вне document root), метаданные — в SQLite.
 */
final class CertificateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{pem:string,subject:string,issuer:string,serial:string,
     *              fingerprint_sha256:string,valid_from:string,valid_to:string} $parsed
     */
    public function store(array $parsed, string $type, string $uploadedBy): int
    {
        $existing = $this->pdo->prepare('SELECT COUNT(*) FROM certificates WHERE fingerprint_sha256 = :fp');
        $existing->execute(['fp' => $parsed['fingerprint_sha256']]);
        if ((int) $existing->fetchColumn() > 0) {
            throw new RuntimeException('Такой сертификат уже загружен (совпадает fingerprint).');
        }

        Paths::ensureDirectories();
        $filename = 'cert_' . bin2hex(random_bytes(8)) . '.pem';
        $path = Paths::certs() . '/' . $filename;
        if (file_put_contents($path, $parsed['pem'], LOCK_EX) === false) {
            throw new RuntimeException('Не удалось сохранить файл сертификата.');
        }
        chmod($path, 0640);

        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO certificates
                (filename, type, subject, issuer, serial, fingerprint_sha256, valid_from, valid_to, uploaded_by, uploaded_at)
            VALUES
                (:filename, :type, :subject, :issuer, :serial, :fp, :valid_from, :valid_to, :uploaded_by, :uploaded_at)
        SQL);
        $stmt->execute([
            'filename' => $filename,
            'type' => $type,
            'subject' => $parsed['subject'],
            'issuer' => $parsed['issuer'],
            'serial' => $parsed['serial'],
            'fp' => $parsed['fingerprint_sha256'],
            'valid_from' => $parsed['valid_from'],
            'valid_to' => $parsed['valid_to'],
            'uploaded_by' => $uploadedBy,
            'uploaded_at' => gmdate('c'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM certificates ORDER BY uploaded_at DESC')->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM certificates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function delete(int $id): void
    {
        $cert = $this->findById($id);
        if ($cert === null) {
            return;
        }

        $path = Paths::certs() . '/' . $cert['filename'];
        if (is_file($path)) {
            unlink($path);
        }

        $stmt = $this->pdo->prepare('DELETE FROM certificates WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function readPem(array $certRow): string
    {
        $path = Paths::certs() . '/' . $certRow['filename'];
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Не удалось прочитать файл сертификата.');
        }

        return $content;
    }
}
