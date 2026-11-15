<?php

declare(strict_types=1);

namespace App\Llm\Gateway;

use PDO;

/**
 * Состояние моделей между запросами (llm_model_health) и журнал проблем (llm_model_incidents).
 * Пишется только безопасное: коды, статусы, длительности, токены, стоимость, техническая причина
 * без текста запросов и ответов.
 */
final class ModelHealthRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    private function ensure(int $modelId): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO llm_model_health (model_id) VALUES (?)')->execute([$modelId]);
    }

    public function recordSuccess(int $modelId, int $ms, ?int $tokensIn, ?int $tokensOut, float $cost): void
    {
        $this->ensure($modelId);
        $this->pdo->prepare(
            'UPDATE llm_model_health SET success_count = success_count + 1, consecutive_failures = 0, cooldown_until = 0, total_ms = total_ms + :ms,
                    last_ms = :ms, tokens_in = tokens_in + :tin, tokens_out = tokens_out + :tout, cost = cost + :cost, last_success_at = :at
             WHERE model_id = :id'
        )->execute(['ms' => $ms, 'tin' => (int) $tokensIn, 'tout' => (int) $tokensOut, 'cost' => $cost, 'at' => gmdate('c'), 'id' => $modelId]);
    }

    /** Ошибка попытки; cooldownSeconds > 0 — модель временно исключается из выбора (кроме случая, когда исключены все). */
    public function recordFailure(int $modelId, string $code, string $adminMessage, int $cooldownSeconds): int
    {
        $this->ensure($modelId);
        $this->pdo->prepare(
            'UPDATE llm_model_health SET error_count = error_count + 1, consecutive_failures = consecutive_failures + 1,
                    empty_count = empty_count + :empty, last_error_at = :at, last_error_code = :code, last_error_message = :msg,
                    cooldown_until = CASE WHEN :cool > 0 THEN MAX(cooldown_until, :until) ELSE cooldown_until END
             WHERE model_id = :id'
        )->execute(['empty' => $code === 'empty_response' ? 1 : 0, 'at' => gmdate('c'), 'code' => $code, 'msg' => mb_substr($adminMessage, 0, 300),
                    'cool' => $cooldownSeconds, 'until' => time() + $cooldownSeconds, 'id' => $modelId]);

        return $this->consecutiveFailures($modelId);
    }

    public function recordFallback(int $fromModelId): void
    {
        $this->ensure($fromModelId);
        $this->pdo->prepare('UPDATE llm_model_health SET fallback_count = fallback_count + 1 WHERE model_id = ?')->execute([$fromModelId]);
    }

    public function consecutiveFailures(int $modelId): int
    {
        $stmt = $this->pdo->prepare('SELECT consecutive_failures FROM llm_model_health WHERE model_id = ?');
        $stmt->execute([$modelId]);

        return (int) $stmt->fetchColumn();
    }

    public function coolingUntil(int $modelId): int
    {
        $stmt = $this->pdo->prepare('SELECT cooldown_until FROM llm_model_health WHERE model_id = ?');
        $stmt->execute([$modelId]);
        $until = (int) $stmt->fetchColumn();

        return $until > time() ? $until : 0;
    }

    public function resetCooldown(int $modelId): void
    {
        $this->pdo->prepare('UPDATE llm_model_health SET cooldown_until = 0, consecutive_failures = 0 WHERE model_id = ?')->execute([$modelId]);
    }

    public function incident(?int $modelId, ?int $providerId, string $code, ?int $httpStatus, string $message, string $action, ?int $fallbackModelId = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO llm_model_incidents (created_at, model_id, provider_id, code, http_status, message, action, fallback_model_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([gmdate('c'), $modelId, $providerId, $code, $httpStatus, mb_substr($message, 0, 300), $action, $fallbackModelId]);
        // Журнал не растёт бесконечно: держим последние 5000 записей.
        if (random_int(1, 50) === 1) {
            $this->pdo->exec('DELETE FROM llm_model_incidents WHERE id <= (SELECT MAX(id) - 5000 FROM llm_model_incidents)');
        }
    }

    /** @return array<int,array<string,mixed>> по id модели */
    public function stats(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM llm_model_health')->fetchAll() as $row) {
            $out[(int) $row['model_id']] = $row;
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function incidents(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, m.display_name AS model_name, f.display_name AS fallback_name, p.name AS provider_name
             FROM llm_model_incidents i LEFT JOIN llm_models m ON m.id = i.model_id LEFT JOIN llm_models f ON f.id = i.fallback_model_id
             LEFT JOIN llm_providers p ON p.id = i.provider_id ORDER BY i.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
