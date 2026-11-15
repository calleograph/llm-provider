<?php

declare(strict_types=1);

namespace App\Llm\Files\Extractor;

use App\Llm\Files\ExtractionResult;
use App\Llm\Files\OcrEngine;
use App\Llm\Files\TextExtractor;

/**
 * Изображения (фото/скан документа) — только локальный OCR. Само
 * изображение во внешний API не передаётся.
 */
final class ImageOcrExtractor implements TextExtractor
{
    public function __construct(private readonly OcrEngine $ocr)
    {
    }

    public function extensions(): array
    {
        return ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp'];
    }

    public function matchesSignature(string $head): bool
    {
        return str_starts_with($head, "\x89PNG")
            || str_starts_with($head, "\xFF\xD8\xFF")
            || str_starts_with($head, "II*\0")
            || str_starts_with($head, "MM\0*")
            || str_starts_with($head, 'BM');
    }

    public function extract(string $path, string $workDir): ExtractionResult
    {
        return new ExtractionResult($this->ocr->recognize($path), true, 1, 1);
    }
}
