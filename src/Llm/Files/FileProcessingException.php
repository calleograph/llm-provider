<?php

declare(strict_types=1);

namespace App\Llm\Files;

/**
 * Неудача обработки файла. errorCode безопасен для журнала:
 * no_file | upload_error | too_large | unsupported_type | signature_mismatch |
 * empty_content | content_too_large | ocr_required_unavailable | ocr_failed |
 * tool_unavailable | extraction_failed | storage_error.
 */
final class FileProcessingException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $technicalMessage = '')
    {
        parent::__construct($technicalMessage !== '' ? $technicalMessage : $errorCode);
    }

    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'too_large' => 'Файл превышает допустимый размер.',
            'unsupported_type' => 'Этот тип файла не поддерживается.',
            'signature_mismatch' => 'Содержимое файла не соответствует его расширению.',
            'empty_content' => 'В файле нет текста (пустой файл или не удалось распознать содержимое) — запрос не отправлен.',
            'content_too_large' => 'Текст файла слишком большой для отправки.',
            'ocr_required_unavailable' => 'Файл содержит сканированные страницы, но распознавание текста (OCR) недоступно — запрос не отправлен.',
            'ocr_failed' => 'Не удалось надёжно распознать текст скана — запрос не отправлен.',
            'tool_unavailable' => 'Инструмент обработки этого типа файлов не установлен на сервере.',
            default => 'Не удалось обработать файл — запрос не отправлен.',
        };
    }
}
