<?php

declare(strict_types=1);

namespace App\Llm\Access;

use App\Llm\Chat\ChatUser;
use App\Llm\Gateway\LlmException;
use PDO;

/**
 * Права «доменная группа → доступные нейросети» (llm_group_providers).
 *
 * Итоговый набор нейросетей пользователя — объединение прав всех его
 * разрешённых групп. Проверка выполняется только на backend по данным БД
 * на момент запроса: отключение группы/нейросети или снятие права
 * действует сразу, а подмена provider_id в запросе ничего не даёт.
 */
final class AccessPolicy
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?UserProviderRepository $userProviders = null,
    ) {
    }

    /**
     * @param int[] $groupIds группы, членство в которых подтверждено при входе
     * @return array<int,array{id:int,name:string,model:string,provider_type:string,is_default:bool}>
     */
    public function allowedProviders(array $groupIds, int $domainId, ?string $ownerKey = null): array
    {
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        $result = [];

        if ($groupIds !== []) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT p.id, p.name, p.model, p.provider_type, p.is_default
                 FROM llm_group_providers gp
                 JOIN llm_domain_groups g ON g.id = gp.group_id
                 JOIN ldap_configs c ON c.id = g.ldap_config_id
                 JOIN llm_providers p ON p.id = gp.provider_id
                 WHERE gp.group_id IN ({$placeholders})
                   AND g.is_enabled = 1
                   AND g.ldap_config_id = ?
                   AND c.llm_enabled = 1
                   AND p.is_enabled = 1
                 ORDER BY p.name"
            );
            $stmt->execute([...$groupIds, $domainId]);

            foreach ($stmt->fetchAll() as $row) {
                $result[(int) $row['id']] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'model' => (string) $row['model'],
                    'provider_type' => (string) $row['provider_type'],
                    'is_default' => (bool) $row['is_default'],
                ];
            }
        }

        // Индивидуальный доступ расширяет набор нейросетей поверх групповых
        // прав — не заменяет требование членства хотя бы в одной разрешённой
        // группе домена для самого входа в чат (это проверяется отдельно, в
        // ChatAuthenticator, до вызова этого метода).
        if ($ownerKey !== null && $this->userProviders !== null) {
            $individualIds = $this->userProviders->providersFor($ownerKey);
            if ($individualIds !== []) {
                $placeholders = implode(',', array_fill(0, count($individualIds), '?'));
                $stmt = $this->pdo->prepare(
                    "SELECT id, name, model, provider_type, is_default FROM llm_providers WHERE id IN ({$placeholders}) AND is_enabled = 1"
                );
                $stmt->execute($individualIds);
                foreach ($stmt->fetchAll() as $row) {
                    $result[(int) $row['id']] = [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'model' => (string) $row['model'],
                        'provider_type' => (string) $row['provider_type'],
                        'is_default' => (bool) $row['is_default'],
                    ];
                }
            }
        }

        uasort($result, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $result;
    }

    public function isAllowed(ChatUser $user, int $providerId): bool
    {
        return isset($this->allowedProviders($user->groupIds, $user->domainId, $user->ownerKey())[$providerId]);
    }

    /**
     * Разрешено ли пользователю отправлять сообщения без обезличивания
     * (см. ChatService::send()). Достаточно, чтобы хотя бы одна из его
     * разрешённых групп имела эту галочку ЛИБО было выставлено личное
     * разрешение (llm_users.allow_unanonymized, в дополнение к групповому
     * — та же модель объединения прав, что и allowedProviders()/
     * llm_user_providers поверх llm_group_providers).
     *
     * @param int[] $groupIds
     */
    public function canSkipAnonymization(array $groupIds, ?string $ownerKey = null): bool
    {
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        if ($groupIds !== []) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM llm_domain_groups
                 WHERE id IN ({$placeholders}) AND is_enabled = 1 AND allow_unanonymized = 1"
            );
            $stmt->execute($groupIds);
            if ((int) $stmt->fetchColumn() > 0) {
                return true;
            }
        }

        if ($ownerKey !== null) {
            $stmt = $this->pdo->prepare('SELECT allow_unanonymized FROM llm_users WHERE owner_key = :k');
            $stmt->execute(['k' => $ownerKey]);
            if ((int) $stmt->fetchColumn() === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws LlmException access_denied
     */
    public function assertAllowed(ChatUser $user, int $providerId): void
    {
        if (!$this->isAllowed($user, $providerId)) {
            throw new LlmException('access_denied', 'Нейросеть недоступна пользователю.');
        }
    }

    /** @return array<int,int[]> group_id => provider_id[] */
    public function matrix(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT group_id, provider_id FROM llm_group_providers')->fetchAll() as $row) {
            $result[(int) $row['group_id']][] = (int) $row['provider_id'];
        }

        return $result;
    }

    /** @return int[] */
    public function providersForGroup(int $groupId): array
    {
        return $this->matrix()[$groupId] ?? [];
    }

    /**
     * Заменяет права одной группы.
     *
     * @param int[] $providerIds
     * @return array{added:int[],removed:int[]}
     */
    public function setGroupProviders(int $groupId, array $providerIds): array
    {
        $before = $this->providersForGroup($groupId);
        $after = array_values(array_unique(array_filter(array_map('intval', $providerIds), static fn (int $id): bool => $id > 0)));

        $validIds = array_map('intval', $this->pdo->query('SELECT id FROM llm_providers')->fetchAll(PDO::FETCH_COLUMN));
        $after = array_values(array_intersect($after, $validIds));

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO llm_group_providers (group_id, provider_id) VALUES (:g, :p)');
        $delete = $this->pdo->prepare('DELETE FROM llm_group_providers WHERE group_id = :g AND provider_id = :p');
        foreach ($added as $providerId) {
            $insert->execute(['g' => $groupId, 'p' => $providerId]);
        }
        foreach ($removed as $providerId) {
            $delete->execute(['g' => $groupId, 'p' => $providerId]);
        }

        return ['added' => $added, 'removed' => $removed];
    }
}
