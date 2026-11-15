<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Минимальный рендерер шаблонов (обычный PHP include в изолированной области видимости).
 */
final class View
{
    public static function render(string $templatePath, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require $templatePath;

        return (string) ob_get_clean();
    }

    /**
     * Адрес статики с версией (время изменения файла): после обновления браузер сразу берёт новые стили и скрипты,
     * несмотря на долгий кэш (expires 7d).
     */
    public static function asset(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/public/' . ltrim((string) preg_replace('#^(\.\./)+#', '', $path), '/');

        return self::e($path . (is_file($file) ? '?v=' . substr(md5((string) filemtime($file) . filesize($file)), 0, 10) : ''));
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Короткий код настройки рядом с её заголовком (как в Moodle) — тот
     * же код, что и в записи templates/admin/settings_index.php для этого
     * поля, чтобы найденное поиском совпадало с тем, что видно в форме.
     */
    public static function settingCode(string $code): string
    {
        return '<span class="field-code">' . self::e($code) . '</span>';
    }
}
