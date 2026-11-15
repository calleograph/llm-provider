<?php

declare(strict_types=1);

namespace App\Llm\Context;

/** Инструкции и текущее сообщение сами по себе не помещаются в окно модели — запрос не отправляется. */
final class ContextOverflowException extends \RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $budget)
    {
        parent::__construct("Требуется ~{$required} токенов, доступно {$budget}.");
    }
}
