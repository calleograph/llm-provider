<?php

declare(strict_types=1);

namespace App\Llm\Anonymization;

/**
 * Результат совместного обезличивания нескольких текстов одной сессией замен
 * (сообщение + вложения + инструкции): один и тот же человек получает одно
 * обозначение во всех частях. stats — число замен по категориям (без значений).
 */
final class AnonymizationBatch
{
    /**
     * @param list<AnonymizedText> $texts в том же порядке, что вход
     * @param array<string,int> $stats
     * @param list<array<string,mixed>> $replacements только для лаборатории админки (исходные значения!) — не журналировать
     */
    public function __construct(
        public readonly array $texts,
        public readonly int $replaced = 0,
        public readonly array $stats = [],
        public readonly array $warnings = [],
        public readonly int $durationMs = 0,
        public readonly array $replacements = [],
        public readonly array $candidates = [],
    ) {
    }
}
