<?php

declare(strict_types=1);

namespace App\Audit;

use PDO;

/**
 * Единая хронологическая лента событий: объединяет три раздельных журнала
 * (app_log — операции смены пароля, audit_log — административные действия,
 * diagnostic_log — техническая диагностика LDAP/TLS/kpasswd) в один список,
 * отсортированный по времени, с общим набором полей для отображения.
 *
 * Не заменяет отдельные журналы (каждый по-прежнему доступен отдельно со
 * своими специфичными полями и экспортом), а даёт обзорную картину "что
 * вообще происходило" и позволяет искать по конкретному логину/учётной
 * записи сразу во всех трёх источниках одним запросом.
 */
final class UnifiedEventLog
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{search?:string,category?:string,since?:string,until?:string,limit?:int,offset?:int} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function query(array $filters = []): array
    {
        $whereClauses = [];
        $params = [];

        if (!empty($filters['search'])) {
            $whereClauses[] = '(actor LIKE :search OR summary LIKE :search OR details LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['since'])) {
            $whereClauses[] = 'created_at >= :since';
            $params['since'] = $filters['since'];
        }
        if (!empty($filters['until'])) {
            $whereClauses[] = 'created_at <= :until';
            $params['until'] = $filters['until'];
        }

        $category = $filters['category'] ?? '';
        $validCategories = [
            'password_change', 'llm_auth', 'llm_chat', 'llm_anonymization', 'llm_file', 'admin_action', 'diagnostic',
        ];
        $categoryFilter = in_array($category, $validCategories, true) ? $category : null;

        // app_log содержит действия пользователей LLM-чата (operation = LLM_*).
        // Разбито по точному значению operation (LlmEventLog::OP_*), а не по
        // общему префиксу LLM_ — так фильтр «Журналов» различает вход в чат,
        // саму переписку, обезличивание и работу с файлами по отдельности.
        // Ветка 'password_change' — историческая категория для событий,
        // записанных до перехода на LLM-чат (в новых установках пуста).
        $llmOperations = [
            'llm_auth' => 'LLM_AUTH', 'llm_chat' => 'LLM_CHAT',
            'llm_anonymization' => 'LLM_ANONYMIZATION', 'llm_file' => 'LLM_FILE',
        ];
        $subqueries = [];
        if ($categoryFilter === null || $categoryFilter === 'password_change') {
            $subqueries[] = "SELECT id, created_at, 'password_change' AS category, username AS actor, ip,
                             operation AS event_type, result AS summary, NULL AS details,
                             CASE WHEN result LIKE '%SUCCESS%' OR result LIKE 'success%' THEN 'success'
                                  WHEN result LIKE '%REQUIRED%' THEN 'warning'
                                  ELSE 'failure' END AS outcome
                             FROM app_log WHERE operation NOT LIKE 'LLM\\_%' ESCAPE '\\'";
        }
        foreach ($llmOperations as $categoryKey => $operation) {
            if ($categoryFilter === null || $categoryFilter === $categoryKey) {
                $params['op_' . $categoryKey] = $operation;
                $subqueries[] = "SELECT id, created_at, '{$categoryKey}' AS category, username AS actor, ip,
                                 operation AS event_type, result AS summary, NULL AS details,
                                 CASE WHEN result LIKE '%SUCCESS%' THEN 'success' ELSE 'failure' END AS outcome
                                 FROM app_log WHERE operation = :op_{$categoryKey}";
            }
        }
        if ($categoryFilter === null || $categoryFilter === 'admin_action') {
            $subqueries[] = "SELECT id, created_at, 'admin_action' AS category, actor, ip,
                             action AS event_type, action AS summary, details,
                             CASE WHEN success = 1 THEN 'success' ELSE 'failure' END AS outcome
                             FROM audit_log";
        }
        if ($categoryFilter === null || $categoryFilter === 'diagnostic') {
            $subqueries[] = "SELECT id, created_at, 'diagnostic' AS category, NULL AS actor, ip,
                             source AS event_type, message AS summary, context AS details,
                             CASE WHEN level = 'error' THEN 'failure'
                                  WHEN level = 'warning' THEN 'warning'
                                  ELSE 'success' END AS outcome
                             FROM diagnostic_log";
        }

        $unionSql = implode(' UNION ALL ', $subqueries);
        $whereSql = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM ({$unionSql}) AS combined {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $stmt = $this->pdo->prepare(
            "SELECT * FROM ({$unionSql}) AS combined {$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }
}
