<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Paths;

/**
 * Настройка cookie-сессии: HttpOnly, Secure, SameSite=Strict.
 * session_regenerate_id(true) обязателен сразу после успешного входа
 * (защита от session fixation).
 */
final class SessionManager
{
    /**
     * Таймаут бездействия администратора по умолчанию (используется, пока
     * не настроен другой — см. раздел «Настройки» и AppSettings). Поднят с
     * прежних 15 минут до 4 часов по требованию пользователя — слишком
     * короткий idle-таймаут регулярно выбивал администраторов из панели во
     * время обычной работы (чтение журналов, заполнение форм) без признаков
     * реальной угрозы.
     */
    public const DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS = 14400; // 4 часа

    /** Верхняя граница настраиваемого таймаута — 24 часа. */
    public const MAX_ADMIN_IDLE_TIMEOUT_SECONDS = 86400;

    /**
     * Сколько минимум должна жить открытая форма (страница смены пароля,
     * форма входа в админку) без потери CSRF-токена. Задано явно в коде, а
     * не оставлено на усмотрение session.gc_maxlifetime из php.ini сервера:
     * на части хостингов это значение занижено (например 300-600 секунд для
     * экономии диска), из-за чего CSRF-токен в открытой форме "устаревал"
     * быстрее, чем пользователь успевал её заполнить и отправить.
     *
     * Фиксирован под МАКСИМАЛЬНО допустимый настраиваемый idle-таймаут (а не
     * под текущее значение настройки) — иначе снижение таймаута в «Настройках»
     * заставило бы сборщик мусора PHP уничтожать данные сессии раньше, чем
     * отрабатывает сам логический idle-таймаут при следующем повышении.
     */
    private const MIN_SESSION_LIFETIME_SECONDS = self::MAX_ADMIN_IDLE_TIMEOUT_SECONDS + 600;

    public static function start(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Форсируем и время жизни данных сессии на сервере (gc_maxlifetime),
        // и вероятность запуска сборщика мусора — не полагаемся на то, что
        // это уже настроено адекватно в php.ini конкретного хостинга.
        ini_set('session.gc_maxlifetime', (string) self::MIN_SESSION_LIFETIME_SECONDS);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        // Файлы сессий — в собственном каталоге вне /tmp: некоторые хостинги
        // чистят /tmp системными службами по расписанию короче, чем
        // gc_maxlifetime, из-за чего сессия (и CSRF-токен открытой формы)
        // пропадает быстрее, чем настроено здесь.
        Paths::ensureDirectories();
        session_save_path(Paths::sessions());

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('ADPWD_SESSION');
        session_start();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** $idleTimeoutSeconds — из настройки администратора; null — использовать значение по умолчанию. */
    public static function enforceAdminIdleTimeout(?int $idleTimeoutSeconds = null): bool
    {
        $timeout = $idleTimeoutSeconds !== null && $idleTimeoutSeconds > 0
            ? min($idleTimeoutSeconds, self::MAX_ADMIN_IDLE_TIMEOUT_SECONDS)
            : self::DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS;

        $now = time();
        $lastActivity = $_SESSION['admin_last_activity'] ?? null;

        if ($lastActivity !== null && ($now - (int) $lastActivity) > $timeout) {
            self::destroy();

            return false;
        }

        $_SESSION['admin_last_activity'] = $now;

        return true;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            session_destroy();
        }
    }
}
