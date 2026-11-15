<?php

declare(strict_types=1);

namespace App\Llm\Anonymization;

/**
 * Результат обезличивания файла сервисом: текст, извлечённый из уже
 * обезличенного результата, и безопасные для журнала метаданные.
 */
final class AnonymizedFile
{
    public function __construct(
        public readonly AnonymizedText $text,
        public readonly string $detectedFormat,
        public readonly string $resultFormat,
        public readonly bool $convertedToText,
        public readonly int $totalReplaced,
        public readonly int $residualHigh,
        public readonly bool $secondPass,
    ) {
    }
}
