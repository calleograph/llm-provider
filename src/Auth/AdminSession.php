<?php

declare(strict_types=1);

namespace App\Auth;

use App\Security\SessionManager;

/**
 * Работа с состоянием авторизации администратора в сессии.
 * Метод входа ('local' или 'domain') хранится для отображения в интерфейсе
 * и для того, чтобы ограничить смену пароля локального администратора этим методом входа.
 */
final class AdminSession
{
    public static function login(string $username, string $method): void
    {
        SessionManager::regenerate();
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_method'] = $method;
        $_SESSION['admin_last_activity'] = time();
    }

    public static function isLoggedIn(?int $idleTimeoutSeconds = null): bool
    {
        if (empty($_SESSION['admin_username'])) {
            return false;
        }

        return SessionManager::enforceAdminIdleTimeout($idleTimeoutSeconds);
    }

    public static function username(): ?string
    {
        return $_SESSION['admin_username'] ?? null;
    }

    public static function method(): ?string
    {
        return $_SESSION['admin_method'] ?? null;
    }

    public static function logout(): void
    {
        SessionManager::destroy();
    }
}
