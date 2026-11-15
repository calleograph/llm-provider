<?php

declare(strict_types=1);

namespace App\Llm\Files;

final class TextNormalizer
{
    /**
     * Приводит текст к UTF-8: BOM UTF-8/UTF-16 учитываются, не-UTF-8 текст
     * считается Windows-1251 (типичная кодировка корпоративных CSV/TXT).
     */
    public static function toUtf8(string $raw): string
    {
        return self::clean(self::toUtf8Raw($raw));
    }

    /** Только перекодирование, без очистки (сохраняет \f — разделитель страниц pdftotext). */
    public static function toUtf8Raw(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE")) {
            $raw = (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($raw, "\xFE\xFF")) {
            $raw = (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        } elseif (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }

        return $raw;
    }

    /** Удаляет управляющие символы (кроме \t, \n), нормализует переводы строк. */
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = (string) preg_replace("/\n{4,}/", "\n\n\n", $text);

        return trim($text);
    }

    public static function meaningfulLength(string $text): int
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', $text));
    }
}
