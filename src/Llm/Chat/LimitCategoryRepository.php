<?php

declare(strict_types=1);

namespace App\Llm\Chat;

use PDO;

/**
 * Именованные категории лимитов токенов («Базовый», «Расширенный»...).
 * Ровно одна категория может быть отмечена как категория по умолчанию —
 * она применяется пользователю чата, у которого нет ни персональной
 * категории, ни категории через группу.
 */
final class LimitCategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int,array{id:int,name:string,period:string,tokens:?int,storage_mb:?int,is_default:bool}> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM llm_limit_categories ORDER BY is_default DESC, name')->fetchAll();

        return array_map(self::hydrate(...), $rows);
    }

    /** @return array{id:int,name:string,period:string,tokens:?int,storage_mb:?int,is_default:bool}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM llm_limit_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? self::hydrate($row) : null;
    }

    /** Категория по умолчанию для новых пользователей чата. */
    public function defaultCategory(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM llm_limit_categories WHERE is_default = 1 LIMIT 1')->fetch();

        return $row !== false ? self::hydrate($row) : null;
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM llm_limit_categories WHERE name = :name AND id != :id');
        $stmt->execute(['name' => $name, 'id' => $excludeId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** $storageMb — NULL/0 означает «без ограничения места». */
    public function create(string $name, string $period, ?int $tokens, ?int $storageMb, bool $isDefault): int
    {
        $now = gmdate('c');
        if ($isDefault) {
            $this->clearDefault();
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO llm_limit_categories (name, period, tokens, storage_mb, is_default, created_at, updated_at)
             VALUES (:name, :period, :tokens, :storage_mb, :is_default, :now, :now)'
        );
        $stmt->execute([
            'name' => $name, 'period' => $period,
            'tokens' => $period === UsageLimiter::PERIOD_NONE ? null : $tokens,
            'storage_mb' => $storageMb !== null && $storageMb > 0 ? $storageMb : null,
            'is_default' => $isDefault ? 1 : 0, 'now' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $period, ?int $tokens, ?int $storageMb, bool $isDefault): void
    {
        if ($isDefault) {
            $this->clearDefault();
        }
        $stmt = $this->pdo->prepare(
            'UPDATE llm_limit_categories SET name = :name, period = :period, tokens = :tokens, storage_mb = :storage_mb, is_default = :is_default, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name, 'period' => $period,
            'tokens' => $period === UsageLimiter::PERIOD_NONE ? null : $tokens,
            'storage_mb' => $storageMb !== null && $storageMb > 0 ? $storageMb : null,
            'is_default' => $isDefault ? 1 : 0, 'now' => gmdate('c'), 'id' => $id,
        ]);
    }

    /**
     * Категорию по умолчанию удалить нельзя вообще — сначала нужно назначить
     * другую категорию по умолчанию. Иначе новые пользователи чата (и те, у
     * кого не осталось ни персональной, ни групповой категории) остались бы
     * совсем без лимита — именно то, чего категории должны были избежать.
     */
    public function delete(int $id): bool
    {
        $category = $this->find($id);
        if ($category === null) {
            return false;
        }
        if ($category['is_default']) {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM llm_limit_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);

        // Категория была единственным лимитом группы/пользователя — они
        // возвращаются к "нет персональной/групповой категории", что
        // приводит к категории по умолчанию.
        return true;
    }

    private function clearDefault(): void
    {
        $this->pdo->exec('UPDATE llm_limit_categories SET is_default = 0');
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'period' => (string) $row['period'],
            'tokens' => $row['tokens'] !== null ? (int) $row['tokens'] : null,
            'storage_mb' => $row['storage_mb'] !== null ? (int) $row['storage_mb'] : null,
            'is_default' => (bool) $row['is_default'],
        ];
    }
}
