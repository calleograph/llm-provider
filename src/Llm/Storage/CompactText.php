<?php

declare(strict_types=1);

namespace App\Llm\Storage;

/**
 * Экономичное хранение текста в SQLite: длинный текст сжимается
 * raw DEFLATE (gzdeflate) — русский текст обычно уменьшается в 3–5 раз.
 * Короткий текст хранится как есть: заголовок сжатия съел бы выигрыш.
 */
final class CompactText
{
    public const THRESHOLD_BYTES = 1024;

    /** @return array{0:string,1:?string} [текстовая колонка, сжатая колонка] */
    public static function encode(string $text): array
    {
        if (strlen($text) < self::THRESHOLD_BYTES || !function_exists('gzdeflate')) {
            return [$text, null];
        }
        $compressed = gzdeflate($text, 6);
        if ($compressed === false || strlen($compressed) >= strlen($text)) {
            return [$text, null];
        }

        return ['', $compressed];
    }

    /** Всегда сжимает (для колонок, где нет текстового варианта). */
    public static function pack(string $text): string
    {
        $compressed = function_exists('gzdeflate') ? gzdeflate($text, 6) : false;

        return $compressed !== false ? 'z' . $compressed : 't' . $text;
    }

    public static function unpack(?string $blob): string
    {
        if ($blob === null || $blob === '') {
            return '';
        }
        if ($blob[0] === 'z') {
            $text = @gzinflate(substr($blob, 1));
            if ($text === false) {
                throw new \RuntimeException('Повреждённые сжатые данные.');
            }

            return $text;
        }

        return substr($blob, 1);
    }

    public static function decode(?string $text, mixed $compressed): string
    {
        if (is_resource($compressed)) {
            $compressed = stream_get_contents($compressed);
        }
        if (is_string($compressed) && $compressed !== '') {
            $inflated = @gzinflate($compressed);
            if ($inflated === false) {
                throw new \RuntimeException('Повреждённые сжатые данные сообщения.');
            }

            return $inflated;
        }

        return (string) $text;
    }
}
