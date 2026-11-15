<?php

declare(strict_types=1);

namespace App\Llm\Chat;

use App\Llm\Domain\DomainGroupRepository;
use PDO;

/**
 * Профили пользователей чата и лимит контекста (токенов) на период.
 *
 *  - профиль (llm_users) создаётся при первом входе, дальше обновляется
 *    только время последнего входа;
 *  - расход хранится агрегатом «пользователь × день» (llm_usage): одна
 *    строка в день, сколько бы запросов ни было;
 *  - лимит определяется по приоритету: персональная категория лимита
 *    (llm_users.limit_category_id) → самая щедрая из категорий групп
 *    пользователя (llm_domain_groups.limit_category_id) → категория по
 *    умолчанию (LimitCategoryRepository::defaultCategory) → «без лимита»,
 *    если категорий вообще не заведено; период — день, неделя (с
 *    понедельника) или месяц.
 *
 * Токены считаются по данным провайдера (input + output — то есть вся
 * переданная история и контекст проекта), а если провайдер их не вернул —
 * оценкой «символы / 3».
 */
final class UsageLimiter
{
    public const PERIOD_NONE = 'none';
    public const PERIODS = ['day', 'week', 'month', self::PERIOD_NONE];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ChatSettings $settings,
        private readonly LimitCategoryRepository $limitCategories,
        private readonly DomainGroupRepository $groups,
        private readonly ?\DateTimeZone $timezone = null,
    ) {
    }

    public function touchProfile(ChatUser $user): void
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at) VALUES (:k, :u, :d, :now, :now)
             ON CONFLICT(owner_key) DO UPDATE SET last_seen_at = excluded.last_seen_at'
        );
        $stmt->execute(['k' => $user->ownerKey(), 'u' => $user->username, 'd' => $user->domainFqdn, 'now' => $now]);
    }

    public function isBlocked(string $ownerKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT is_blocked FROM llm_users WHERE owner_key = :k');
        $stmt->execute(['k' => $ownerKey]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * @param int[] $groupIds группы пользователя (для резолва лимита по
     *                        группе, если у него нет персонального лимита
     *                        или персональной категории); пусто — только
     *                        персональный лимит/категория/значение по умолчанию.
     * @return array{period:string,tokens:?int,individual:bool,source:string}
     */
    public function effectiveLimit(string $ownerKey, array $groupIds = []): array
    {
        $stmt = $this->pdo->prepare('SELECT limit_period, limit_tokens, limit_category_id FROM llm_users WHERE owner_key = :k');
        $stmt->execute(['k' => $ownerKey]);
        $row = $stmt->fetch();

        // 1. Персональный лимит вручную (период+токены) — высший приоритет,
        //    сохранён для обратной совместимости с уже настроенными записями.
        if ($row !== false && $row['limit_period'] !== null && in_array($row['limit_period'], self::PERIODS, true)) {
            return [
                'period' => (string) $row['limit_period'],
                'tokens' => $row['limit_period'] === self::PERIOD_NONE ? null : max(0, (int) $row['limit_tokens']),
                'individual' => true,
                'source' => 'manual',
            ];
        }

        // 2. Персональная категория лимита.
        if ($row !== false && $row['limit_category_id'] !== null) {
            $category = $this->limitCategories->find((int) $row['limit_category_id']);
            if ($category !== null) {
                return [
                    'period' => $category['period'], 'tokens' => $category['tokens'],
                    'individual' => true, 'source' => 'category:' . $category['name'],
                ];
            }
        }

        // 3. Самая щедрая категория среди групп пользователя (без лимита —
        //    самый щедрый вариант, иначе наибольшее число токенов в периоде).
        $groupCategory = $this->mostGenerousGroupCategory($groupIds);
        if ($groupCategory !== null) {
            return [
                'period' => $groupCategory['period'], 'tokens' => $groupCategory['tokens'],
                'individual' => false, 'source' => 'group:' . $groupCategory['name'],
            ];
        }

        // 4. Категория по умолчанию.
        $default = $this->limitCategories->defaultCategory();
        if ($default !== null) {
            return [
                'period' => $default['period'], 'tokens' => $default['tokens'],
                'individual' => false, 'source' => 'default:' . $default['name'],
            ];
        }

        // 5. Категорий вообще не заведено — прежнее поведение через общие
        //    настройки чата (обратная совместимость с уже развёрнутыми стендами).
        $period = $this->settings->get('limit_period');
        $period = in_array($period, self::PERIODS, true) ? $period : self::PERIOD_NONE;

        return [
            'period' => $period,
            'tokens' => $period === self::PERIOD_NONE ? null : max(0, (int) $this->settings->get('limit_tokens')),
            'individual' => false,
            'source' => 'settings',
        ];
    }

    /** @param int[] $groupIds */
    private function mostGenerousGroupCategory(array $groupIds): ?array
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
            if ($best === null || self::isMoreGenerous($category, $best)) {
                $best = $category;
            }
        }

        return $best;
    }

    /** «Без лимита» — самый щедрый вариант; иначе больше токенов в периоде — щедрее. */
    private static function isMoreGenerous(array $candidate, array $current): bool
    {
        if ($candidate['period'] === self::PERIOD_NONE) {
            return $current['period'] !== self::PERIOD_NONE;
        }
        if ($current['period'] === self::PERIOD_NONE) {
            return false;
        }

        return (int) $candidate['tokens'] > (int) $current['tokens'];
    }

    /**
     * @param int[] $groupIds
     * @return array{period:string,used:int,limit:?int,remaining:?int,resets_at:?string,individual:bool,source:string}
     */
    public function status(string $ownerKey, array $groupIds = []): array
    {
        $limit = $this->effectiveLimit($ownerKey, $groupIds);
        $period = $limit['period'] === self::PERIOD_NONE ? 'month' : $limit['period'];
        [$start, $reset] = $this->periodBounds($period);
        $used = $this->usedSince($ownerKey, $start);

        return [
            'period' => $limit['period'],
            'used' => $used,
            'limit' => $limit['tokens'],
            'remaining' => $limit['tokens'] !== null ? max(0, $limit['tokens'] - $used) : null,
            'resets_at' => $limit['tokens'] !== null ? $reset : null,
            'individual' => $limit['individual'],
            'source' => $limit['source'],
        ];
    }

    /**
     * @param int[] $groupIds
     * @throws ChatException 429 при исчерпанном лимите, 403 для заблокированного
     */
    public function assertCanSend(string $ownerKey, array $groupIds = []): void
    {
        if ($this->isBlocked($ownerKey)) {
            throw new ChatException('user_blocked', 'Доступ к чату для вашей учётной записи приостановлен администратором.', 403);
        }
        $status = $this->status($ownerKey, $groupIds);
        if ($status['limit'] !== null && $status['used'] >= $status['limit']) {
            $label = ['day' => 'день', 'week' => 'неделю', 'month' => 'месяц'][$status['period']] ?? $status['period'];
            throw new ChatException(
                'limit_exceeded',
                sprintf('Исчерпан лимит на %s: %s из %s токенов. Лимит обновится %s.', $label, self::fmt($status['used']), self::fmt($status['limit']), self::formatDate((string) $status['resets_at'])),
                429,
            );
        }
    }

    public function record(string $ownerKey, int $tokens): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO llm_usage (owner_key, day, tokens, requests) VALUES (:k, :d, :t, 1)
             ON CONFLICT(owner_key, day) DO UPDATE SET tokens = tokens + excluded.tokens, requests = requests + 1'
        );
        $stmt->execute(['k' => $ownerKey, 'd' => $this->now()->format('Y-m-d'), 't' => max(0, $tokens)]);
    }

    public static function estimateTokens(int $chars): int
    {
        return (int) ceil($chars / 3);
    }

    /** @return array{0:string,1:string} [начало периода Y-m-d, дата сброса Y-m-d] */
    public function periodBounds(string $period): array
    {
        $now = $this->now();
        $start = match ($period) {
            'day' => $now,
            'week' => $now->modify('monday this week'),
            default => $now->modify('first day of this month'),
        };
        $reset = match ($period) {
            'day' => $now->modify('+1 day'),
            'week' => $now->modify('monday next week'),
            default => $now->modify('first day of next month'),
        };

        return [$start->format('Y-m-d'), $reset->format('Y-m-d')];
    }

    public function usedSince(string $ownerKey, string $startDay): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(tokens), 0) FROM llm_usage WHERE owner_key = :k AND day >= :d');
        $stmt->execute(['k' => $ownerKey, 'd' => $startDay]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Для админки: профили с расходом за текущий период каждого. Групповой
     * лимит здесь не пересчитывается (членство в группах известно только на
     * момент реального входа через LDAP, а не хранится в профиле) — статус
     * отражает персональный лимит/категорию или категорию по умолчанию, как
     * если бы у пользователя не было групп с собственной категорией лимита.
     * При реальной отправке сообщения (ChatService) групповой лимит уже
     * учитывается — там group_ids берутся из активной сессии входа.
     */
    public function allProfiles(): array
    {
        $rows = $this->pdo->query('SELECT * FROM llm_users ORDER BY last_seen_at DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['status'] = $this->status((string) $row['owner_key']);
            $row['requests_30d'] = $this->requestsSince((string) $row['owner_key'], $this->now()->modify('-30 days')->format('Y-m-d'));
        }

        return $rows;
    }

    public function setIndividualLimit(string $ownerKey, ?string $period, ?int $tokens): void
    {
        $stmt = $this->pdo->prepare('UPDATE llm_users SET limit_period = :p, limit_tokens = :t, limit_category_id = NULL WHERE owner_key = :k');
        $stmt->execute(['p' => $period, 't' => $period === null || $period === self::PERIOD_NONE ? null : $tokens, 'k' => $ownerKey]);
        if ($stmt->rowCount() === 0) {
            // Профиль обычно уже существует (создаётся при первом входе, до
            // того как администратор может назначить лимит через админку),
            // но без явного upsert назначение лимита превратилось бы в
            // тихий no-op, если профиль почему-то ещё не создан.
            [$username, $domainFqdn] = self::splitOwnerKey($ownerKey);
            $this->pdo->prepare(
                'INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at, limit_period, limit_tokens)
                 VALUES (:k, :u, :d, :now, :now, :p, :t)
                 ON CONFLICT(owner_key) DO UPDATE SET limit_period = excluded.limit_period, limit_tokens = excluded.limit_tokens, limit_category_id = NULL'
            )->execute([
                'k' => $ownerKey, 'u' => $username, 'd' => $domainFqdn, 'now' => gmdate('c'),
                'p' => $period, 't' => $period === null || $period === self::PERIOD_NONE ? null : $tokens,
            ]);
        }
    }

    /** NULL — снять персональную категорию (пользователь вернётся к групповой/умолчанию). */
    public function setLimitCategory(string $ownerKey, ?int $categoryId): void
    {
        $stmt = $this->pdo->prepare('UPDATE llm_users SET limit_category_id = :c, limit_period = NULL, limit_tokens = NULL WHERE owner_key = :k');
        $stmt->execute(['c' => $categoryId, 'k' => $ownerKey]);
        if ($stmt->rowCount() === 0) {
            [$username, $domainFqdn] = self::splitOwnerKey($ownerKey);
            $this->pdo->prepare(
                'INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at, limit_category_id)
                 VALUES (:k, :u, :d, :now, :now, :c)
                 ON CONFLICT(owner_key) DO UPDATE SET limit_category_id = excluded.limit_category_id, limit_period = NULL, limit_tokens = NULL'
            )->execute(['k' => $ownerKey, 'u' => $username, 'd' => $domainFqdn, 'now' => gmdate('c'), 'c' => $categoryId]);
        }
    }

    /** @return array{0:string,1:string} [username, domain_fqdn] из "user@domain". */
    private static function splitOwnerKey(string $ownerKey): array
    {
        $parts = explode('@', $ownerKey, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    public function setBlocked(string $ownerKey, bool $blocked): void
    {
        $this->pdo->prepare('UPDATE llm_users SET is_blocked = :b WHERE owner_key = :k')->execute(['b' => $blocked ? 1 : 0, 'k' => $ownerKey]);
    }

    /** Персональное разрешение «без обезличивания» — в дополнение к групповому (см. AccessPolicy::canSkipAnonymization()). */
    public function setAllowUnanonymized(string $ownerKey, bool $allowed): void
    {
        $stmt = $this->pdo->prepare('UPDATE llm_users SET allow_unanonymized = :a WHERE owner_key = :k');
        $stmt->execute(['a' => $allowed ? 1 : 0, 'k' => $ownerKey]);
        if ($stmt->rowCount() === 0) {
            [$username, $domainFqdn] = self::splitOwnerKey($ownerKey);
            $this->pdo->prepare(
                'INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at, allow_unanonymized)
                 VALUES (:k, :u, :d, :now, :now, :a)
                 ON CONFLICT(owner_key) DO UPDATE SET allow_unanonymized = excluded.allow_unanonymized'
            )->execute(['k' => $ownerKey, 'u' => $username, 'd' => $domainFqdn, 'now' => gmdate('c'), 'a' => $allowed ? 1 : 0]);
        }
    }

    /** Агрегаты старше года не нужны ни для одного периода лимита. */
    public function purgeOldUsage(): void
    {
        $this->pdo->prepare('DELETE FROM llm_usage WHERE day < :d')->execute(['d' => $this->now()->modify('-400 days')->format('Y-m-d')]);
    }

    private function requestsSince(string $ownerKey, string $day): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(requests), 0) FROM llm_usage WHERE owner_key = :k AND day >= :d');
        $stmt->execute(['k' => $ownerKey, 'd' => $day]);

        return (int) $stmt->fetchColumn();
    }

    private function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', $this->timezone ?? new \DateTimeZone(date_default_timezone_get())))->setTime(0, 0);
    }

    private static function fmt(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    private static function formatDate(string $ymd): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $ymd);

        return $date !== false ? $date->format('d.m.Y') : $ymd;
    }
}
