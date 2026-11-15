<?php

declare(strict_types=1);

namespace App\Llm\Files;

/**
 * Загруженный файл, прошедший проверку расширения, размера и сигнатуры и
 * сохранённый в закрытый временный каталог запроса. Живёт только внутри
 * FileProcessor::withStagedFile() — каталог удаляется при выходе.
 */
final class StagedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $extension,
        public readonly int $size,
        public readonly string $workDir,
    ) {
    }
}
