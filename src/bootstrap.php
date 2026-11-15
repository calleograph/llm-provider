<?php

declare(strict_types=1);

namespace App;

use App\Config\Database;
use App\Config\Paths;
use PDO;

require __DIR__ . '/../vendor_autoload.php';

/**
 * Общая инициализация для всех публичных входных точек.
 */
function bootstrap(): PDO
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', Paths::logs() . '/php_errors.log');
    mb_internal_encoding('UTF-8');

    Paths::ensureDirectories();
    installGlobalErrorHandling();

    return Database::connect(Paths::database());
}

/**
 * Последний рубеж на случай ошибки, произошедшей ДО входа в try/catch
 * конкретной точки входа (или в коде, который try/catch не покрывает).
 * PHP-ошибки/непойманные исключения пишутся в var/logs/php_errors.log
 * (никогда не выводятся в браузер — display_errors=0), пользователю
 * показывается нейтральная страница без технических деталей.
 */
function installGlobalErrorHandling(): void
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        error_log(sprintf('[PHP %d] %s in %s:%d', $severity, $message, $file, $line));

        // Не подавляем нормальную обработку PHP (в т.ч. превращение в ErrorException
        // там, где это настроено) — просто гарантируем запись в лог.
        return false;
    });

    set_exception_handler(static function (\Throwable $e): void {
        error_log(sprintf(
            'Uncaught %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<title>Сервис временно недоступен</title></head><body '
            . 'style="font-family:sans-serif;max-width:480px;margin:80px auto;text-align:center;color:#333;">'
            . '<p>Сервис временно недоступен. Попробуйте обновить страницу через минуту '
            . 'или обратитесь к администратору.</p></body></html>';
    });
}

function isHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    // Доверенный reverse proxy сообщает схему через X-Forwarded-Proto —
    // используется только для решения "secure cookie", не для авторизации.
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

    return strtolower((string) $proto) === 'https';
}

function sendSecurityHeaders(): void
{
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}
