<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

/**
 * Простой rate limiting по ключу (например ip+"pwd_change" или ip+"admin_login").
 * После серии неудачных попыток — временная задержка (экспоненциальная), не вечная блокировка.
 */
final class RateLimiter
{
    private const WINDOW_SECONDS = 900; // окно учёта попыток — 15 минут
    private const MAX_ATTEMPTS_BEFORE_DELAY = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recordAttempt(string $bucketKey): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO rate_limit (bucket_key, attempted_at) VALUES (:key, :ts)');
        $stmt->execute(['key' => $bucketKey, 'ts' => time()]);

        $this->cleanup($bucketKey);
    }

    /**
     * Возвращает количество секунд, на которое нужно задержать следующую попытку (0, если задержка не требуется).
     */
    public function retryAfterSeconds(string $bucketKey): int
    {
        $count = $this->recentAttemptCount($bucketKey);
        if ($count < self::MAX_ATTEMPTS_BEFORE_DELAY) {
            return 0;
        }

        $excess = $count - self::MAX_ATTEMPTS_BEFORE_DELAY + 1;
        $delay = min(300, (int) (pow(2, $excess)));

        return $delay;
    }

    public function recentAttemptCount(string $bucketKey): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM rate_limit WHERE bucket_key = :key AND attempted_at > :since'
        );
        $stmt->execute(['key' => $bucketKey, 'since' => time() - self::WINDOW_SECONDS]);

        return (int) $stmt->fetchColumn();
    }

    public function resetOnSuccess(string $bucketKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limit WHERE bucket_key = :key');
        $stmt->execute(['key' => $bucketKey]);
    }

    private function cleanup(string $bucketKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limit WHERE bucket_key = :key AND attempted_at <= :since');
        $stmt->execute(['key' => $bucketKey, 'since' => time() - self::WINDOW_SECONDS]);
    }
}
