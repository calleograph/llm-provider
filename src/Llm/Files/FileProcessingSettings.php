<?php

declare(strict_types=1);

namespace App\Llm\Files;

use App\Config\AppSettings;

/**
 * Параметры локальной обработки файлов (app_settings, префикс files_).
 * Все инструменты локальные: pdftotext/pdftoppm (poppler-utils) и tesseract.
 */
final class FileProcessingSettings
{
    private const PREFIX = 'files_';

    private const DEFAULTS = [
        'max_upload_mb' => '10',
        'max_extracted_chars' => '200000',
        'ocr_enabled' => '1',
        'ocr_languages' => 'rus+eng',
        'ocr_max_pages' => '30',
        'tesseract_path' => '/usr/bin/tesseract',
        'pdftotext_path' => '/usr/bin/pdftotext',
        'pdftoppm_path' => '/usr/bin/pdftoppm',
        'tool_timeout' => '120',
    ];

    public function __construct(private readonly AppSettings $appSettings)
    {
    }

    public function get(string $name): string
    {
        return (string) $this->appSettings->get(self::PREFIX . $name, self::DEFAULTS[$name] ?? '');
    }

    /** @return array<string,string> */
    public function all(): array
    {
        $result = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            $result[$name] = $this->get($name);
        }

        return $result;
    }

    /** @param array<string,string> $values */
    public function save(array $values): void
    {
        foreach ($values as $name => $value) {
            if (array_key_exists($name, self::DEFAULTS)) {
                $this->appSettings->set(self::PREFIX . $name, (string) $value);
            }
        }
    }

    public function maxUploadBytes(): int
    {
        return max(1, (int) $this->get('max_upload_mb')) * 1024 * 1024;
    }

    public function ocrEnabled(): bool
    {
        return $this->get('ocr_enabled') === '1';
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    public static function validate(array $values): array
    {
        $errors = [];
        foreach (['max_upload_mb' => [1, 200], 'max_extracted_chars' => [1000, 5000000], 'ocr_max_pages' => [1, 500], 'tool_timeout' => [5, 1800]] as $field => [$min, $max]) {
            $value = (int) ($values[$field] ?? 0);
            if ($value < $min || $value > $max) {
                $errors[$field] = "Допустимый диапазон: {$min}–{$max}.";
            }
        }
        if (!preg_match('/\A[a-z_]{3,8}(\+[a-z_]{3,8})*\z/', $values['ocr_languages'] ?? '')) {
            $errors['ocr_languages'] = 'Формат: rus+eng.';
        }
        foreach (['tesseract_path', 'pdftotext_path', 'pdftoppm_path'] as $field) {
            if (!preg_match('#\A(/[A-Za-z0-9._-]+)+\z|\A[A-Za-z]:[\\\\/][A-Za-z0-9._\\\\/ -]+\z#', $values[$field] ?? '')) {
                $errors[$field] = 'Укажите абсолютный путь к исполняемому файлу.';
            }
        }

        return $errors;
    }
}
