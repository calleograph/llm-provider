<?php

declare(strict_types=1);

namespace App\Llm\Chat;

use PDO;

/**
 * Автоочистка содержимого у пользователей, не заходивших в чат дольше
 * порога неактивности (по умолчанию 3 месяца) — экономия места на диске.
 *
 * Удаляется только СОДЕРЖИМОЕ: текст сообщений (llm_messages.content/
 * content_z) и текст источников проектов (llm_project_sources.content_z,
 * chars обнуляется). Сами диалоги и проекты как сущности (заголовки,
 * список чатов, даты) остаются — пользователь при следующем входе видит
 * структуру своей истории, но не её содержимое.
 *
 * Запускается лениво (как и другие фоновые задачи модуля — см.
 * UsageLimiter::purgeOldUsage) со случайной вероятностью при входе любого
 * пользователя, а не привязана к конкретному владельцу очищаемых данных
 * (тот, чьи данные чистятся, по определению давно не заходит).
 */
final class InactivityCleanupService
{
    private const DEFAULT_THRESHOLD_DAYS = 90;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{owners:int,messages:int,sources:int} сколько пользователей/сообщений/источников затронуто
     */
    public function cleanupInactive(int $thresholdDays = self::DEFAULT_THRESHOLD_DAYS): array
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(1, $thresholdDays) . ' days')
            ->format('c');

        $owners = $this->pdo->prepare(
            'SELECT owner_key FROM llm_users WHERE last_seen_at < :cutoff'
        );
        $owners->execute(['cutoff' => $cutoff]);
        $ownerKeys = $owners->fetchAll(PDO::FETCH_COLUMN);

        if ($ownerKeys === []) {
            return ['owners' => 0, 'messages' => 0, 'sources' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($ownerKeys), '?'));

        // Только сообщения, ещё содержащие текст (LENGTH(...) > 0) — не
        // трогаем уже очищенные строки повторно на каждый запуск.
        $messages = $this->pdo->prepare(
            "UPDATE llm_messages SET content = '', content_z = NULL
             WHERE conversation_id IN (SELECT id FROM llm_conversations WHERE owner_key IN ({$placeholders}))
               AND (LENGTH(content) > 0 OR content_z IS NOT NULL)"
        );
        $messages->execute($ownerKeys);
        $messagesCleared = $messages->rowCount();

        $sources = $this->pdo->prepare(
            "UPDATE llm_project_sources SET content_z = X'', chars = 0
             WHERE project_id IN (SELECT id FROM llm_projects WHERE owner_key IN ({$placeholders}))
               AND chars > 0"
        );
        $sources->execute($ownerKeys);
        $sourcesCleared = $sources->rowCount();

        return ['owners' => count($ownerKeys), 'messages' => $messagesCleared, 'sources' => $sourcesCleared];
    }
}
