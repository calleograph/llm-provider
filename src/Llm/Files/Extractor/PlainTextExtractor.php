<?php

declare(strict_types=1);

namespace App\Llm\Files\Extractor;

use App\Llm\Files\ExtractionResult;
use App\Llm\Files\FileProcessingException;
use App\Llm\Files\TextExtractor;
use App\Llm\Files\TextNormalizer;

/** TXT, CSV и прочие текстовые форматы. */
final class PlainTextExtractor implements TextExtractor
{
    public function extensions(): array
    {
        return ['txt', 'csv', 'tsv', 'md', 'log'];
    }

    public function matchesSignature(string $head): bool
    {
        if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")) {
            return true;
        }

        // Двоичный файл, переименованный в .txt/.csv, содержит NUL-байты.
        return !str_contains($head, "\0");
    }

    public function extract(string $path, string $workDir): ExtractionResult
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new FileProcessingException('extraction_failed', 'Не удалось прочитать файл.');
        }

        return new ExtractionResult(TextNormalizer::toUtf8($raw));
    }
}
