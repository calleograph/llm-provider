<?php

declare(strict_types=1);

namespace App\Ldap;

/**
 * Исключение LDAP-слоя. Код ошибки — один из констант LdapErrorCode,
 * безопасен для показа пользователю. Технические подробности (message)
 * пишутся только во внутренний лог, никогда не показываются пользователю напрямую.
 */
final class LdapException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $technicalMessage,
    ) {
        parent::__construct($technicalMessage);
    }
}
