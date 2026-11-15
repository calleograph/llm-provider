<?php

declare(strict_types=1);

namespace App\Audit;

use App\Diagnostics\LogRotationService;
use PDO;

/**
 * Отдельный журнал административных действий: вход, изменение настроек,
 * смена активной конфигурации, загрузка/удаление CA, смена пароля локального
 * администратора и т.д. Секретные значения (пароли, ключи) никогда не записываются.
 */
final class AuditLogger
{
    private readonly LogRotationService $rotation;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rotation = new LogRotationService($pdo);
    }

    public function log(string $actor, string $ip, string $action, bool $success, ?string $details = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (created_at, actor, ip, action, details, success)
             VALUES (:created_at, :actor, :ip, :action, :details, :success)'
        );
        $stmt->execute([
            'created_at' => gmdate('c'),
            'actor' => $actor,
            'ip' => $ip,
            'action' => $action,
            'details' => $details,
            'success' => $success ? 1 : 0,
        ]);

        $this->rotation->maybeRotate();
    }

    public function recent(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private const SORTABLE_COLUMNS = ['created_at', 'actor', 'ip', 'action', 'success'];

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

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT * FROM audit_log ORDER BY {$sortColumn} {$sortDirection}, id {$sortDirection} LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }
}
