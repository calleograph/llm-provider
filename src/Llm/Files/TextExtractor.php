<?php

declare(strict_types=1);

namespace App\Llm\Files;

/**
 * Извлечение текста из файла одного формата. Новый формат добавляется
 * реализацией интерфейса и регистрацией в FileProcessor::withDefaults().
 */
interface TextExtractor
{
    /** @return string[] расширения в нижнем регистре, без точки */
    public function extensions(): array;

    /** Проверка сигнатуры по первым байтам (защита от подмены расширения). */
    public function matchesSignature(string $head): bool;

    /**
     * @param string $workDir закрытый временный каталог запроса для промежуточных файлов
     * @throws FileProcessingException
     */
    public function extract(string $path, string $workDir): ExtractionResult;
}
