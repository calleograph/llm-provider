<?php

declare(strict_types=1);

namespace App\Llm\Files;

/**
 * Извлечённый из файла ИСХОДНЫЙ текст. Живёт только в памяти текущего
 * запроса до передачи в AnonymizationGateway; имя файла не сохраняется.
 */
final class ExtractedFile
{
    public function __construct(
        public readonly string $extension,
        public readonly string $text,
        public readonly int $size,
        public readonly bool $ocrUsed,
        public readonly ?int $pages,
        public readonly int $ocrPages,
    ) {
    }
}
