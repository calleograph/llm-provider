<?php

declare(strict_types=1);

namespace App\Llm\Files;

final class ExtractionResult
{
    public function __construct(
        public readonly string $text,
        public readonly bool $ocrUsed = false,
        public readonly ?int $pages = null,
        public readonly int $ocrPages = 0,
    ) {
    }
}
