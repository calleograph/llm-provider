<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Централизованные пути к каталогам приложения вне document root.
 */
final class Paths
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function var(): string
    {
        return self::root() . '/var';
    }

    public static function data(): string
    {
        return self::var() . '/data';
    }

    public static function certs(): string
    {
        return self::var() . '/certs';
    }

    public static function logs(): string
    {
        return self::var() . '/logs';
    }

    /**
     * Собственный каталог для файлов сессий PHP — вне системного /tmp.
     * На части хостингов /tmp периодически очищается системными службами
     * (tmpwatch/systemd-tmpfiles) по расписанию короче, чем
     * session.gc_maxlifetime, из-за чего сессии (а вместе с ними CSRF-токен
     * открытой формы) пропадают быстрее ожидаемого. Собственный каталог не
     * подвержен таким внешним политикам очистки.
     */
    public static function sessions(): string
    {
        return self::var() . '/sessions';
    }

    /**
     * Временные файлы LLM-чата (загруженные документы, страницы для OCR).
     * Каждый файл удаляется сразу после извлечения текста; каталог закрыт
     * правами 0700 и лежит вне document root.
     */
    public static function llmTmp(): string
    {
        return self::var() . '/tmp/llm';
    }

    public static function database(): string
    {
        return self::data() . '/app.sqlite';
    }

    public static function secretKeyFile(): string
    {
        return self::data() . '/master.key';
    }

    public static function installedMarker(): string
    {
        return self::data() . '/.installed';
    }

    public static function ensureDirectories(): void
    {
        foreach ([self::var(), self::data(), self::certs(), self::logs(), self::sessions()] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
        }
        if (!is_dir(self::llmTmp())) {
            @mkdir(self::llmTmp(), 0700, true);
        }
    }
}
