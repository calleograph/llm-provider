<?php

declare(strict_types=1);

namespace App\Llm\Chat;

use PDO;

/**
 * Квота на хранение (в мегабайтах) обезличенного текста пользователя чата —
 * сообщений всех его диалогов и источников всех его проектов. Исходные
 * файлы на диске не хранятся (обрабатываются во временном каталоге и
 * удаляются сразу после обезличивания — см. FileProcessor), поэтому единица
 * учёта — фактический размер сжатых/текстовых колонок в SQLite, а не файлы.
 *
 * Квота работает мягко: превышение не блокирует уже сохранённые данные и
 * не мешает пользователю читать историю — оно только не даёт добавить
 * НОВОЕ содержимое (сообщение, источник), пока место не будет освобождено
 * самим пользователем (раздел «Управление данными» в чате) или снято через
 * увеличение квоты в админке.
 */
final class StorageQuotaService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LimitCategoryRepository $limitCategories,
        private readonly \App\Llm\Domain\DomainGroupRepository $groups,
    ) {
    }

    /** Занятое место в байтах: сообщения всех диалогов + источники всех проектов пользователя. */
    public function usedBytes(string $ownerKey): int
    {
        $messages = $this->pdo->prepare(
            "SELECT COALESCE(SUM(LENGTH(m.content) + LENGTH(m.content_z)), 0) FROM llm_messages m
             JOIN llm_conversations c ON c.id = m.conversation_id WHERE c.owner_key = :k"
        );
        $messages->execute(['k' => $ownerKey]);

        $sources = $this->pdo->prepare(
            "SELECT COALESCE(SUM(LENGTH(s.content_z)), 0) FROM llm_project_sources s
             JOIN llm_projects p ON p.id = s.project_id WHERE p.owner_key = :k"
        );
        $sources->execute(['k' => $ownerKey]);

        return (int) $messages->fetchColumn() + (int) $sources->fetchColumn();
    }

    /**
     * @param int[] $groupIds
     * @return array{limit_mb:?int,source:string}
     */
    public function effectiveLimitMb(string $ownerKey, array $groupIds = []): array
    {
        $stmt = $this->pdo->prepare('SELECT limit_category_id FROM llm_users WHERE owner_key = :k');
        $stmt->execute(['k' => $ownerKey]);
        $categoryId = $stmt->fetchColumn();

        if ($categoryId !== false && $categoryId !== null) {
            $category = $this->limitCategories->find((int) $categoryId);
            if ($category !== null) {
                return ['limit_mb' => $category['storage_mb'], 'source' => 'category:' . $category['name']];
            }
        }

        $groupCategory = $this->mostGenerousGroupStorage($groupIds);
        if ($groupCategory !== null) {
            return ['limit_mb' => $groupCategory['storage_mb'], 'source' => 'group:' . $groupCategory['name']];
        }

        $default = $this->limitCategories->defaultCategory();
        if ($default !== null) {
            return ['limit_mb' => $default['storage_mb'], 'source' => 'default:' . $default['name']];
        }

        return ['limit_mb' => null, 'source' => 'none'];
    }

    /**
     * @param int[] $groupIds
     * @return array{used_bytes:int,limit_bytes:?int,remaining_bytes:?int,percent:?float,source:string}
     */
    public function status(string $ownerKey, array $groupIds = []): array
    {
        $used = $this->usedBytes($ownerKey);
        $limit = $this->effectiveLimitMb($ownerKey, $groupIds);
        $limitBytes = $limit['limit_mb'] !== null ? $limit['limit_mb'] * 1024 * 1024 : null;

        return [
            'used_bytes' => $used,
            'limit_bytes' => $limitBytes,
            'remaining_bytes' => $limitBytes !== null ? max(0, $limitBytes - $used) : null,
            'percent' => $limitBytes !== null && $limitBytes > 0 ? min(100.0, $used / $limitBytes * 100) : null,
            'source' => $limit['source'],
        ];
    }

    /**
     * @param int[] $groupIds
     * @throws ChatException 507, если добавление $additionalBytes превысит квоту
     */
    public function assertCanStore(string $ownerKey, int $additionalBytes, array $groupIds = []): void
    {
        $status = $this->status($ownerKey, $groupIds);
        if ($status['limit_bytes'] === null) {
            return;
        }
        if ($status['used_bytes'] + $additionalBytes > $status['limit_bytes']) {
            throw new ChatException(
                'storage_quota_exceeded',
                sprintf(
                    'Превышена квота хранения: занято %s из %s. Освободите место в разделе «Управление данными» (удалите старые чаты или источники проектов) и повторите отправку.',
                    self::formatBytes($status['used_bytes']),
                    self::formatBytes($status['limit_bytes']),
                ),
                507,
            );
        }
    }

    /** @param int[] $groupIds */
    private function mostGenerousGroupStorage(array $groupIds): ?array
    {
        $best = null;
        foreach ($groupIds as $groupId) {
            $group = $this->groups->find($groupId);
            if ($group === null || $group['limit_category_id'] === null) {
                continue;
            }
            $category = $this->limitCategories->find((int) $group['limit_category_id']);
            if ($category === null) {
                continue;
            }
            // NULL (без ограничения места) — самый щедрый вариант; иначе больше мегабайт — щедрее.
            if ($best === null
                || ($category['storage_mb'] === null && $best['storage_mb'] !== null)
                || ($category['storage_mb'] !== null && $best['storage_mb'] !== null && $category['storage_mb'] > $best['storage_mb'])) {
                $best = $category;
            }
        }

        return $best;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' МБ';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', ' ') . ' КБ';
        }

        return $bytes . ' Б';
    }
}
