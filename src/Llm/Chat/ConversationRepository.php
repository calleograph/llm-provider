<?php

declare(strict_types=1);

namespace App\Llm\Chat;

use App\Llm\Storage\CompactText;
use PDO;

/**
 * История диалогов. Каждый метод чтения/изменения фильтрует по owner_key —
 * пользователь физически не может получить или изменить чужой диалог, даже
 * подставив чужой id. Содержимое сообщений в норме только обезличенное;
 * исключение — сообщения, отправленные с явным флагом skipAnonymization в
 * ChatService::send() (право allow_unanonymized), они хранятся в открытом
 * виде. Длинные сообщения хранятся сжатыми (CompactText) в обоих случаях.
 */
final class ConversationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Порядок как в ChatGPT: последние использованные сверху.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForOwner(string $ownerKey, int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, title, provider_id, project_id, pinned, created_at, updated_at FROM llm_conversations
             WHERE owner_key = :owner ORDER BY updated_at DESC, id DESC LIMIT {$limit}"
        );
        $stmt->execute(['owner' => $ownerKey]);

        return array_map(self::castConversation(...), $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function findOwned(int $id, string $ownerKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM llm_conversations WHERE id = :id AND owner_key = :owner');
        $stmt->execute(['id' => $id, 'owner' => $ownerKey]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function create(string $ownerKey, string $title, int $providerId, ?int $projectId = null): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO llm_conversations (owner_key, title, provider_id, project_id, created_at, updated_at)
             VALUES (:owner, :title, :p, :project, :now, :now)'
        );
        $stmt->execute(['owner' => $ownerKey, 'title' => $title, 'p' => $providerId, 'project' => $projectId, 'now' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function messages(int $conversationId, string $ownerKey): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.role, m.content, m.content_z, m.provider_id, m.model, m.attachment_type, m.attachment_size, m.unanonymized, m.context_report, m.created_at
             FROM llm_messages m JOIN llm_conversations c ON c.id = m.conversation_id
             WHERE m.conversation_id = :id AND c.owner_key = :owner ORDER BY m.id'
        );
        $stmt->execute(['id' => $conversationId, 'owner' => $ownerKey]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['content'] = CompactText::decode($row['content'], $row['content_z']);
            $row['unanonymized'] = (bool) $row['unanonymized'];
            unset($row['content_z']);
            $result[] = $row;
        }

        return $result;
    }

    public function addMessage(int $conversationId, string $role, string $content, ?int $providerId, ?string $model, ?string $attachmentType, ?int $attachmentSize, ?int $tokens = null, bool $unanonymized = false): int
    {
        [$text, $compressed] = CompactText::encode($content);
        $stmt = $this->pdo->prepare(
            'INSERT INTO llm_messages (conversation_id, role, content, content_z, provider_id, model, attachment_type, attachment_size, tokens, unanonymized, created_at)
             VALUES (:c, :role, :content, :z, :p, :model, :at, :as, :tokens, :unanon, :now)'
        );
        $stmt->bindValue('c', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue('role', $role);
        $stmt->bindValue('content', $text);
        $stmt->bindValue('z', $compressed, $compressed === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue('p', $providerId, $providerId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('model', $model);
        $stmt->bindValue('at', $attachmentType);
        $stmt->bindValue('as', $attachmentSize, $attachmentSize === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('tokens', $tokens, $tokens === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('unanon', $unanonymized ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue('now', gmdate('c'));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function touch(int $conversationId, int $providerId): void
    {
        $stmt = $this->pdo->prepare('UPDATE llm_conversations SET provider_id = :p, updated_at = :now WHERE id = :id');
        $stmt->execute(['p' => $providerId, 'now' => gmdate('c'), 'id' => $conversationId]);
    }

    /** Модель, выбранная в диалоге (llm_models.id) — чат восстанавливает выбор при открытии. */
    public function setModel(int $conversationId, int $modelId): void
    {
        $this->pdo->prepare('UPDATE llm_conversations SET model_id = :m WHERE id = :id')->execute(['m' => $modelId, 'id' => $conversationId]);
    }

    /** @param array<string,int> $report */
    public function setContextReport(int $messageId, array $report): void
    {
        $this->pdo->prepare('UPDATE llm_messages SET context_report = ? WHERE id = ?')->execute([json_encode($report), $messageId]);
    }

    /**
     * Стиль и инструкции: диалога (llm_conversations) и личные (llm_users). Тексты хранятся только обезличенными.
     *
     * @return array{style:?string,dialog:string,personal:string}
     */
    public function preferences(?int $conversationId, string $ownerKey): array
    {
        $user = $this->pdo->prepare('SELECT style, instructions_z FROM llm_users WHERE owner_key = ?');
        $user->execute([$ownerKey]);
        $u = $user->fetch() ?: [];
        $c = [];
        if ($conversationId !== null) {
            $conv = $this->pdo->prepare('SELECT style, instructions_z FROM llm_conversations WHERE id = ? AND owner_key = ?');
            $conv->execute([$conversationId, $ownerKey]);
            $c = $conv->fetch() ?: [];
        }
        $text = static fn (mixed $z): string => CompactText::unpack(is_resource($z) ? (string) stream_get_contents($z) : (is_string($z) ? $z : null));

        return [
            'style' => ($c['style'] ?? null) ?: (($u['style'] ?? null) ?: null),
            'dialog' => $text($c['instructions_z'] ?? null),
            'personal' => $text($u['instructions_z'] ?? null),
        ];
    }

    public function setUserPreferences(string $ownerKey, ?string $style, string $anonymizedInstructions): void
    {
        $this->pdo->prepare('UPDATE llm_users SET style = ?, instructions_z = ? WHERE owner_key = ?')
            ->execute([$style, $anonymizedInstructions !== '' ? CompactText::pack($anonymizedInstructions) : null, $ownerKey]);
    }

    public function setConversationPreferences(int $id, string $ownerKey, ?string $style, ?string $anonymizedInstructions): bool
    {
        return $this->updateOwned($id, $ownerKey, 'style = :s, instructions_z = :z', ['s' => $style, 'z' => $anonymizedInstructions !== null && $anonymizedInstructions !== '' ? CompactText::pack($anonymizedInstructions) : null]);
    }

    public function setPinned(int $id, string $ownerKey, bool $pinned): bool
    {
        return $this->updateOwned($id, $ownerKey, 'pinned = :v', ['v' => $pinned ? 1 : 0]);
    }

    public function rename(int $id, string $ownerKey, string $title): bool
    {
        return $this->updateOwned($id, $ownerKey, 'title = :v', ['v' => $title]);
    }

    /** $projectId уже проверен вызывающим кодом на принадлежность владельцу. */
    public function moveToProject(int $id, string $ownerKey, ?int $projectId): bool
    {
        return $this->updateOwned($id, $ownerKey, 'project_id = :v', ['v' => $projectId]);
    }

    public function delete(int $id, string $ownerKey): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM llm_conversations WHERE id = :id AND owner_key = :owner');
        $stmt->execute(['id' => $id, 'owner' => $ownerKey]);

        return $stmt->rowCount() > 0;
    }

    /** Вся история пользователя, включая закреплённые чаты. @return int число удалённых диалогов */
    public function deleteAllForOwner(string $ownerKey): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM llm_conversations WHERE owner_key = :owner');
        $stmt->execute(['owner' => $ownerKey]);

        return $stmt->rowCount();
    }

    /**
     * Поиск по заголовкам и тексту сообщений своих диалогов. Сообщения
     * хранятся обезличенными (и частично сжатыми), поэтому сравнение идёт
     * в PHP по уже распакованному тексту. Запрос пользователя нигде не
     * сохраняется и не журналируется.
     *
     * @return array<int,array<string,mixed>> диалоги с полем snippet
     */
    public function search(string $ownerKey, string $query, int $limit = 30): array
    {
        $needle = mb_strtolower(trim($query));
        if (mb_strlen($needle) < 2) {
            return [];
        }

        $found = [];
        foreach ($this->listForOwner($ownerKey, 1000) as $conversation) {
            if (str_contains(mb_strtolower((string) $conversation['title']), $needle)) {
                $found[$conversation['id']] = $conversation + ['snippet' => null];
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT m.conversation_id, m.content, m.content_z FROM llm_messages m
             JOIN llm_conversations c ON c.id = m.conversation_id
             WHERE c.owner_key = :owner ORDER BY c.updated_at DESC, m.id DESC LIMIT 5000'
        );
        $stmt->execute(['owner' => $ownerKey]);
        $byId = [];
        foreach ($this->listForOwner($ownerKey, 1000) as $conversation) {
            $byId[$conversation['id']] = $conversation;
        }
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['conversation_id'];
            if (isset($found[$id]) && $found[$id]['snippet'] !== null) {
                continue;
            }
            $content = CompactText::decode($row['content'], $row['content_z']);
            $position = mb_stripos($content, $needle);
            if ($position === false || !isset($byId[$id])) {
                continue;
            }
            $start = max(0, $position - 40);
            $snippet = ($start > 0 ? '…' : '') . trim((string) preg_replace('/\s+/u', ' ', mb_substr($content, $start, 120))) . '…';
            $found[$id] = $byId[$id] + ['snippet' => $snippet];
        }

        uasort($found, static fn (array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

        return array_slice(array_values($found), 0, $limit);
    }

    /** Удаляет диалоги, не использовавшиеся дольше $days дней (срок хранения). */
    public function deleteOlderThan(string $ownerKey, int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare('DELETE FROM llm_conversations WHERE owner_key = :owner AND pinned = 0 AND updated_at < :cutoff');
        $stmt->execute(['owner' => $ownerKey, 'cutoff' => gmdate('c', time() - $days * 86400)]);

        return $stmt->rowCount();
    }

    public function transactional(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $params */
    private function updateOwned(int $id, string $ownerKey, string $set, array $params): bool
    {
        $stmt = $this->pdo->prepare("UPDATE llm_conversations SET {$set} WHERE id = :id AND owner_key = :owner");
        $stmt->execute($params + ['id' => $id, 'owner' => $ownerKey]);

        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $row */
    private static function castConversation(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'provider_id' => $row['provider_id'] !== null ? (int) $row['provider_id'] : null,
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'pinned' => (bool) $row['pinned'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
