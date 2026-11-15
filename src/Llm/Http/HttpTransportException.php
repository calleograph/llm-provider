<?php

declare(strict_types=1);

namespace App\Llm\Http;

/**
 * Ошибка транспорта. $kind — безопасный для журнала код:
 * timeout | connect | dns | tls | too_large | scheme | other.
 */
final class HttpTransportException extends \RuntimeException
{
    public function __construct(
        public readonly string $kind,
        string $technicalMessage,
    ) {
        parent::__construct($technicalMessage);
    }
}
