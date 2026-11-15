<?php

declare(strict_types=1);

namespace App\Audit;

use App\Diagnostics\LogRotationService;
use PDO;

/**
 * Журнал операций смены пароля (для обычных пользователей). Никогда не содержит
 * значения старого/нового пароля и не содержит LDAP bind password.
 */
final class AppLogger
{
    private readonly LogRotationService $rotation;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rotation = new LogRotationService($pdo);
    }

    public function log(string $ip, ?string $username, string $operation, string $result): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_log (created_at, ip, username, operation, result)
             VALUES (:created_at, :ip, :username, :operation, :result)'
        );
        $stmt->execute([
            'created_at' => gmdate('c'),
            'ip' => $ip,
            'username' => $username,
            'operation' => $operation,
            'result' => $result,
        ]);

        $this->rotation->maybeRotate();
    }

    public function recent(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM app_log ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private const SORTABLE_COLUMNS = ['created_at', 'ip', 'username', 'operation', 'result'];

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function paginated(int $page, int $perPage, string $sortColumn = 'created_at', string $sortDirection = 'desc'): array
    {
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        if (!in_array($sortColumn, self::SORTABLE_COLUMNS, true)) {
            $sortColumn = 'created_at';
        }
        $sortDirection = strtolower($sortDirection) === 'asc' ? 'ASC' : 'DESC';

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM app_log')->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT * FROM app_log ORDER BY {$sortColumn} {$sortDirection}, id {$sortDirection} LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }
}
