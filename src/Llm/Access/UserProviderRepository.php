<?php

declare(strict_types=1);

namespace App\Llm\Access;

use PDO;

/**
 * Индивидуальный доступ пользователя чата к нейросети (llm_user_providers) —
 * второй тип «субъекта прав» в дополнение к доменным группам
 * (llm_group_providers). Не расширяет круг тех, кто может войти в чат —
 * только список нейросетей, доступных уже вошедшему пользователю.
 */
final class UserProviderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return int[] */
    public function providersFor(string $ownerKey): array
    {
        $stmt = $this->pdo->prepare('SELECT provider_id FROM llm_user_providers WHERE owner_key = :k');
        $stmt->execute(['k' => $ownerKey]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Заменяет индивидуальные права одного пользователя.
     *
     * @param int[] $providerIds
     */
    public function setProviders(string $ownerKey, array $providerIds): void
    {
        $before = $this->providersFor($ownerKey);
        $validIds = array_map('intval', $this->pdo->query('SELECT id FROM llm_providers')->fetchAll(PDO::FETCH_COLUMN));
        $after = array_values(array_intersect(array_unique(array_map('intval', $providerIds)), $validIds));

        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO llm_user_providers (owner_key, provider_id) VALUES (:k, :p)');
        $delete = $this->pdo->prepare('DELETE FROM llm_user_providers WHERE owner_key = :k AND provider_id = :p');
        foreach (array_diff($after, $before) as $providerId) {
            $insert->execute(['k' => $ownerKey, 'p' => $providerId]);
        }
        foreach (array_diff($before, $after) as $providerId) {
            $delete->execute(['k' => $ownerKey, 'p' => $providerId]);
        }
    }

    /** @return array<string,int[]> owner_key => provider_id[] — для отображения в админке. */
    public function matrix(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT owner_key, provider_id FROM llm_user_providers')->fetchAll() as $row) {
            $result[(string) $row['owner_key']][] = (int) $row['provider_id'];
        }

        return $result;
    }
}
