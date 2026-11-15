<?php

declare(strict_types=1);

namespace App\Llm\Chat;

use App\Llm\Anonymization\AnonymizedText;
use App\Llm\Storage\CompactText;
use PDO;

/**
 * Проекты пользователя (как в ChatGPT): название, инструкции и источники.
 * Инструкции и источники уходят в модель как контекст, поэтому хранятся
 * только обезличенными (AnonymizedText) и сжатыми. Название проекта
 * используется только в интерфейсе и в модель не передаётся.
 * Все методы фильтруют по owner_key.
 */
final class ProjectRepository
{
    /** source_type для именованных блоков инструкций (правила поведения — без нумерации в контексте). */
    public const TYPE_INSTRUCTION = 'instruction';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int,array{id:int,name:string,updated_at:string,sources:int,pinned:bool}> */
    public function listForOwner(string $ownerKey): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.name, p.updated_at, p.pinned, (SELECT COUNT(*) FROM llm_project_sources s WHERE s.project_id = p.id) AS sources
             FROM llm_projects p WHERE p.owner_key = :owner ORDER BY p.pinned DESC, p.updated_at DESC, p.id DESC'
        );
        $stmt->execute(['owner' => $ownerKey]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'updated_at' => (string) $r['updated_at'],
            'sources' => (int) $r['sources'], 'pinned' => (bool) $r['pinned'],
        ], $stmt->fetchAll());
    }

    /** @return array{id:int,name:string,instructions:string,instructions_chars:int}|null */
    public function findOwned(int $id, string $ownerKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM llm_projects WHERE id = :id AND owner_key = :owner');
        $stmt->execute(['id' => $id, 'owner' => $ownerKey]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'instructions' => CompactText::unpack(self::blob($row['instructions_z'])),
            'instructions_chars' => (int) $row['instructions_chars'],
        ];
    }

    public function create(string $ownerKey, string $name): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare('INSERT INTO llm_projects (owner_key, name, created_at, updated_at) VALUES (:o, :n, :now, :now)');
        $stmt->execute(['o' => $ownerKey, 'n' => $name, 'now' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $ownerKey, string $name): bool
    {
        $stmt = $this->pdo->prepare('UPDATE llm_projects SET name = :n, updated_at = :now WHERE id = :id AND owner_key = :o');
        $stmt->execute(['n' => $name, 'now' => gmdate('c'), 'id' => $id, 'o' => $ownerKey]);

        return $stmt->rowCount() > 0;
    }

    public function setPinned(int $id, string $ownerKey, bool $pinned): bool
    {
        $stmt = $this->pdo->prepare('UPDATE llm_projects SET pinned = :p, updated_at = :now WHERE id = :id AND owner_key = :o');
        $stmt->execute(['p' => $pinned ? 1 : 0, 'now' => gmdate('c'), 'id' => $id, 'o' => $ownerKey]);

        return $stmt->rowCount() > 0;
    }

    public function setInstructions(int $id, string $ownerKey, AnonymizedText $instructions): bool
    {
        $stmt = $this->pdo->prepare('UPDATE llm_projects SET instructions_z = :z, instructions_chars = :c, updated_at = :now WHERE id = :id AND owner_key = :o');
        $stmt->bindValue('z', $instructions->isEmpty() ? null : CompactText::pack($instructions->value), $instructions->isEmpty() ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue('c', mb_strlen($instructions->value), PDO::PARAM_INT);
        $stmt->bindValue('now', gmdate('c'));
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->bindValue('o', $ownerKey);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Переносит устаревшее одиночное поле инструкций (llm_projects.
     * instructions_z) в первый именованный блок «Основные инструкции»,
     * один раз на проект — вызывается при открытии проекта, до чтения
     * instructions()/contextFor(). Идемпотентна: если старое поле уже
     * пусто (перенесено или никогда не заполнялось), ничего не делает.
     */
    public function migrateLegacyInstructions(int $projectId, string $ownerKey): void
    {
        $stmt = $this->pdo->prepare('SELECT instructions_z FROM llm_projects WHERE id = :id AND owner_key = :o');
        $stmt->execute(['id' => $projectId, 'o' => $ownerKey]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }
        $legacy = CompactText::unpack(self::blob($row['instructions_z']));
        if (trim($legacy) === '') {
            return;
        }

        $this->addSource($projectId, 'Основные инструкции', self::TYPE_INSTRUCTION, null, AnonymizedText::fromStorage($legacy));
        $this->setInstructions($projectId, $ownerKey, AnonymizedText::fromStorage(''));
    }

    /** Чаты проекта остаются, но выходят из проекта. */
    public function delete(int $id, string $ownerKey): bool
    {
        $this->pdo->prepare('UPDATE llm_conversations SET project_id = NULL WHERE project_id = :id AND owner_key = :o')->execute(['id' => $id, 'o' => $ownerKey]);
        $stmt = $this->pdo->prepare('DELETE FROM llm_projects WHERE id = :id AND owner_key = :o');
        $stmt->execute(['id' => $id, 'o' => $ownerKey]);

        return $stmt->rowCount() > 0;
    }

    public function touch(int $id): void
    {
        $this->pdo->prepare('UPDATE llm_projects SET updated_at = :now WHERE id = :id')->execute(['now' => gmdate('c'), 'id' => $id]);
    }

    /** $projectId уже проверен на принадлежность владельцу. */
    public function addSource(int $projectId, string $title, string $type, ?string $fileType, AnonymizedText $content): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO llm_project_sources (project_id, title, source_type, file_type, content_z, chars, created_at)
             VALUES (:p, :t, :type, :ft, :z, :c, :now)'
        );
        $stmt->bindValue('p', $projectId, PDO::PARAM_INT);
        $stmt->bindValue('t', $title);
        $stmt->bindValue('type', $type);
        $stmt->bindValue('ft', $fileType);
        $stmt->bindValue('z', CompactText::pack($content->value), PDO::PARAM_LOB);
        $stmt->bindValue('c', mb_strlen($content->value), PDO::PARAM_INT);
        $stmt->bindValue('now', gmdate('c'));
        $stmt->execute();
        $this->touch($projectId);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array{id:int,title:string,source_type:string,file_type:?string,chars:int,created_at:string}> */
    public function sources(int $projectId, string $ownerKey): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.title, s.source_type, s.file_type, s.chars, s.created_at FROM llm_project_sources s
             JOIN llm_projects p ON p.id = s.project_id
             WHERE s.project_id = :id AND p.owner_key = :o AND s.source_type != :instr ORDER BY s.id"
        );
        $stmt->execute(['id' => $projectId, 'o' => $ownerKey, 'instr' => self::TYPE_INSTRUCTION]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'title' => (string) $r['title'], 'source_type' => (string) $r['source_type'],
            'file_type' => $r['file_type'] !== null ? (string) $r['file_type'] : null, 'chars' => (int) $r['chars'], 'created_at' => (string) $r['created_at'],
        ], $stmt->fetchAll());
    }

    /** Именованные блоки инструкций (без нумерации в контексте — правила поведения, не документы). */
    public function instructions(int $projectId, string $ownerKey): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title, s.chars, s.created_at FROM llm_project_sources s
             JOIN llm_projects p ON p.id = s.project_id
             WHERE s.project_id = :id AND p.owner_key = :o AND s.source_type = :instr ORDER BY s.id'
        );
        $stmt->execute(['id' => $projectId, 'o' => $ownerKey, 'instr' => self::TYPE_INSTRUCTION]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'title' => (string) $r['title'], 'chars' => (int) $r['chars'], 'created_at' => (string) $r['created_at'],
        ], $stmt->fetchAll());
    }

    public function totalSourceChars(int $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(chars), 0) FROM llm_project_sources WHERE project_id = :id AND source_type != :instr');
        $stmt->execute(['id' => $projectId, 'instr' => self::TYPE_INSTRUCTION]);

        return (int) $stmt->fetchColumn();
    }

    public function sourceCount(int $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM llm_project_sources WHERE project_id = :id AND source_type != :instr');
        $stmt->execute(['id' => $projectId, 'instr' => self::TYPE_INSTRUCTION]);

        return (int) $stmt->fetchColumn();
    }

    public function totalInstructionChars(int $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(chars), 0) FROM llm_project_sources WHERE project_id = :id AND source_type = :instr');
        $stmt->execute(['id' => $projectId, 'instr' => self::TYPE_INSTRUCTION]);

        return (int) $stmt->fetchColumn();
    }

    public function instructionCount(int $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM llm_project_sources WHERE project_id = :id AND source_type = :instr');
        $stmt->execute(['id' => $projectId, 'instr' => self::TYPE_INSTRUCTION]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteSource(int $sourceId, string $ownerKey): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM llm_project_sources WHERE id = :id AND project_id IN (SELECT id FROM llm_projects WHERE owner_key = :o)'
        );
        $stmt->execute(['id' => $sourceId, 'o' => $ownerKey]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Полный обезличенный текст одного источника — по запросу пользователя
     * (просмотр «что реально сохранилось после обезличивания»), не
     * тянется вместе со списком source() ради экономии на каждом
     * открытии проекта.
     */
    public function sourceText(int $sourceId, string $ownerKey): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.content_z FROM llm_project_sources s
             JOIN llm_projects p ON p.id = s.project_id WHERE s.id = :id AND p.owner_key = :o'
        );
        $stmt->execute(['id' => $sourceId, 'o' => $ownerKey]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return CompactText::unpack(self::blob($row['content_z']));
    }

    /**
     * Контекст проекта для модели: именованные блоки инструкций (без
     * нумерации — это правила поведения, не документы) + источники
     * (пронумерованные, это документы/выдержки). Всё уже обезличено
     * при сохранении. Устаревшее одиночное поле llm_projects.
     * instructions_z переносится в первый именованный блок здесь же
     * (migrateLegacyInstructions() идемпотентна) — так оно никогда не
     * теряется молча, даже если отправка сообщения происходит без
     * предварительного открытия проекта через GET project (например
     * прямой вызов project_update с instructions, минуя UI).
     */
    public function contextFor(int $projectId, string $ownerKey): ?AnonymizedText
    {
        $project = $this->findOwned($projectId, $ownerKey);
        if ($project === null) {
            return null;
        }
        $this->migrateLegacyInstructions($projectId, $ownerKey);

        $parts = [];
        $stmt = $this->pdo->prepare('SELECT title, source_type, content_z FROM llm_project_sources WHERE project_id = :id ORDER BY id');
        $stmt->execute(['id' => $projectId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            if ($row['source_type'] !== self::TYPE_INSTRUCTION) {
                continue;
            }
            $parts[] = '[Инструкция: ' . $row['title'] . "]\n" . CompactText::unpack(self::blob($row['content_z']));
        }

        $number = 0;
        foreach ($rows as $row) {
            if ($row['source_type'] === self::TYPE_INSTRUCTION) {
                continue;
            }
            $number++;
            $parts[] = '[Источник ' . $number . ': ' . $row['title'] . "]\n" . CompactText::unpack(self::blob($row['content_z']));
        }

        return $parts === [] ? null : AnonymizedText::fromStorage(implode("\n\n", $parts));
    }

    private static function blob(mixed $value): ?string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) ? $value : null;
    }
}
