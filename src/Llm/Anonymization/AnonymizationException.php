<?php

declare(strict_types=1);

namespace App\Llm\Anonymization;

/**
 * Любая неудача обезличивания. Код безопасен для журнала:
 * not_configured | transport_<kind> | http_<status> | invalid_json |
 * missing_field | status_not_ok | empty_result | secret_unavailable.
 * Текст запроса и ответа сервиса в исключение не включается.
 */
final class AnonymizationException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $technicalMessage = '')
    {
        parent::__construct($technicalMessage !== '' ? $technicalMessage : $errorCode);
    }

    /** Текст для пользователя чата: без технических подробностей. */
    public function userMessage(): string
    {
        return match (true) {
            $this->errorCode === 'not_clean'
                => 'Сервис обезличивания не подтвердил полную очистку данных — запрос не отправлен во внешний сервис. Уберите из текста чувствительные сведения или обратитесь к администратору.',
            $this->errorCode === 'api_file_rejected'
                => 'Сервис обезличивания отклонил файл (формат, размер или содержимое) — запрос не отправлен.',
            $this->errorCode === 'api_payload_too_large'
                => 'Текст слишком большой для сервиса обезличивания — запрос не отправлен.',
            str_starts_with($this->errorCode, 'api_ocr_'), $this->errorCode === 'api_pdf_read_failed', $this->errorCode === 'api_pdf_render_no_pages'
                => 'Сервис обезличивания не смог прочитать или распознать файл — запрос не отправлен.',
            default => 'Не удалось обезличить данные — запрос не отправлен во внешний сервис. Повторите позже или обратитесь к администратору.',
        };
    }
}
