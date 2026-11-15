<?php

declare(strict_types=1);

namespace App\Llm\Chat;

use App\Security\SessionManager;

/**
 * Состояние входа пользователя чата в общей cookie-сессии приложения
 * (SessionManager: HttpOnly, Secure, SameSite=Strict). Хранится в
 * отдельном ключе $_SESSION['llm_chat'] и не пересекается с AdminSession:
 * выход из чата не завершает сеанс администратора и наоборот.
 */
final class ChatSession
{
    private const KEY = 'llm_chat';
    // Не больше, чем session.gc_maxlifetime в SessionManager (4 ч + 10 мин).
    private const IDLE_TIMEOUT_SECONDS = 14400;
    private const ABSOLUTE_TIMEOUT_SECONDS = 43200;

    public static function login(ChatUser $user): void
    {
        SessionManager::regenerate();
        $now = time();
        $_SESSION[self::KEY] = [
            'username' => $user->username,
            'domain_id' => $user->domainId,
            'domain_fqdn' => $user->domainFqdn,
            'group_ids' => $user->groupIds,
            'group_names' => $user->groupNames,
            'login_at' => $now,
            'last_activity' => $now,
        ];
    }

    public static function current(): ?ChatUser
    {
        $data = $_SESSION[self::KEY] ?? null;
        if (!is_array($data) || empty($data['username'])) {
            return null;
        }

        $now = time();
        if (($now - (int) $data['last_activity']) > self::IDLE_TIMEOUT_SECONDS
            || ($now - (int) $data['login_at']) > self::ABSOLUTE_TIMEOUT_SECONDS) {
            unset($_SESSION[self::KEY]);

            return null;
        }
        $_SESSION[self::KEY]['last_activity'] = $now;

        return new ChatUser(
            username: (string) $data['username'],
            domainId: (int) $data['domain_id'],
            domainFqdn: (string) $data['domain_fqdn'],
            groupIds: array_map('intval', (array) $data['group_ids']),
            groupNames: array_map('strval', (array) $data['group_names']),
        );
    }

    public static function logout(): void
    {
        unset($_SESSION[self::KEY]);
        SessionManager::regenerate();
    }
}
