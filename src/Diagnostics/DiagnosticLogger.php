<?php

declare(strict_types=1);

namespace App\Diagnostics;

use PDO;

/**
 * Технический диагностический журнал: LDAP/TLS/kpasswd ошибки, непредвиденные
 * исключения, результаты «Проверить подключение». Отдельно от app_log
 * (операции пользователей смены пароля) и audit_log (действия администраторов
 * над конфигурацией) — предназначен для диагностики проблем связи с AD.
 *
 * ПРИНЦИПИАЛЬНО: значения паролей (старый/новый/bind password) НИКОГДА не
 * передаются в $context и не должны туда попадать — вызывающий код обязан
 * передавать только безопасные для хранения данные (host, port, errno,
 * LDAP diagnostic message БЕЗ значений паролей, коды ошибок и т.п.).
 * LDAP diagnostic message от AD в норме не содержит пароль (это сообщение
 * об ошибке аутентификации, а не эхо введённых данных), но здесь всё равно
 * дополнительно есть redact() на случай необычных сообщений сторонних систем.
 */
final class DiagnosticLogger
{
    private readonly LogRotationService $rotation;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rotation = new LogRotationService($pdo);
    }

    /**
     * @param array<string,scalar|null> $context
     */
    public function log(string $level, string $source, string $eventCode, string $message, array $context = [], ?string $ip = null): void
    {
        $safeContext = self::redactContext($context);

        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO diagnostic_log (created_at, level, source, event_code, message, context, ip)
            VALUES (:created_at, :level, :source, :event_code, :message, :context, :ip)
        SQL);
        $stmt->execute([
            'created_at' => gmdate('c'),
            'level' => $level,
            'source' => $source,
            'event_code' => $eventCode,
            'message' => self::redactString($message),
            'context' => $safeContext !== [] ? json_encode($safeContext, JSON_UNESCAPED_UNICODE) : null,
            'ip' => $ip,
        ]);

        $this->rotation->maybeRotate();
    }

    public function info(string $source, string $eventCode, string $message, array $context = [], ?string $ip = null): void
    {
        $this->log(DiagnosticLevel::INFO, $source, $eventCode, $message, $context, $ip);
    }

    public function warning(string $source, string $eventCode, string $message, array $context = [], ?string $ip = null): void
    {
        $this->log(DiagnosticLevel::WARNING, $source, $eventCode, $message, $context, $ip);
    }

    public function error(string $source, string $eventCode, string $message, array $context = [], ?string $ip = null): void
    {
        $this->log(DiagnosticLevel::ERROR, $source, $eventCode, $message, $context, $ip);
    }

    private const SORTABLE_COLUMNS = ['created_at', 'level', 'source', 'event_code'];

    /**
     * @param array{level?:string,source?:string,search?:string,since?:string,until?:string,limit?:int,offset?:int,sort?:string,dir?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function query(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['level']) && in_array($filters['level'], DiagnosticLevel::ALL, true)) {
            $where[] = 'level = :level';
            $params['level'] = $filters['level'];
        }
        if (!empty($filters['source']) && in_array($filters['source'], DiagnosticSource::ALL, true)) {
            $where[] = 'source = :source';
            $params['source'] = $filters['source'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(message LIKE :search OR event_code LIKE :search OR context LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['since'])) {
            $where[] = 'created_at >= :since';
            $params['since'] = $filters['since'];
        }
        if (!empty($filters['until'])) {
            $where[] = 'created_at <= :until';
            $params['until'] = $filters['until'];
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM diagnostic_log {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $limit = max(1, min(1000, (int) ($filters['limit'] ?? 200)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $sortColumn = (string) ($filters['sort'] ?? 'created_at');
        if (!in_array($sortColumn, self::SORTABLE_COLUMNS, true)) {
            $sortColumn = 'created_at';
        }
        $sortDirection = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $stmt = $this->pdo->prepare(
            "SELECT * FROM diagnostic_log {$whereSql} ORDER BY {$sortColumn} {$sortDirection}, id {$sortDirection} LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    public function purgeOlderThan(int $days): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM diagnostic_log WHERE created_at < :cutoff');
        $stmt->execute(['cutoff' => gmdate('c', time() - $days * 86400)]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string,scalar|null> $context
     * @return array<string,scalar|null>
     */
    private static function redactContext(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            if (self::looksLikeSecretKey((string) $key)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = is_string($value) ? self::redactString($value) : $value;
        }

        return $redacted;
    }

    private static function looksLikeSecretKey(string $key): bool
    {
        $key = strtolower($key);

        return str_contains($key, 'password') || str_contains($key, 'pwd') || str_contains($key, 'secret')
            || str_contains($key, 'token') || str_contains($key, 'credential');
    }

    /**
     * Защита на случай, если технический текст (например, стек вызовов
     * PHP-исключения из стороннего кода) случайно содержит подстроку вида
     * password=... — сама эта система такого никогда не формирует, но
     * входные данные для error() могут прийти из \Throwable::getMessage()
     * произвольного кода.
     */
    private static function redactString(string $value): string
    {
        return (string) preg_replace(
            '/(password|pwd|secret|token)\s*[=:]\s*\S+/i',
            '$1=[redacted]',
            $value
        );
    }
}
