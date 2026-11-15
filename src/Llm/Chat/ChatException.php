<?php

declare(strict_types=1);

namespace App\Llm\Chat;

/**
 * Ошибка чата, безопасная для показа пользователю. errorCode — для журнала
 * и клиента, userMessage — готовый текст без технических подробностей.
 */
final class ChatException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $userMessage,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($userMessage);
    }
}
