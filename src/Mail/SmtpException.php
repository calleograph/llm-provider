<?php

declare(strict_types=1);

namespace App\Mail;

/** Сбой на конкретном этапе SMTP; code_ — класс ошибки (SmtpClient::classify, RETRYABLE). Текст без секретов. */
final class SmtpException extends \RuntimeException
{
    public function __construct(public readonly string $stage, public readonly string $code_, string $message)
    {
        parent::__construct($message);
    }
}
