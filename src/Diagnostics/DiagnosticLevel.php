<?php

declare(strict_types=1);

namespace App\Diagnostics;

/**
 * Уровень записи технического диагностического журнала.
 */
final class DiagnosticLevel
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    public const ALL = [self::INFO, self::WARNING, self::ERROR];
}
