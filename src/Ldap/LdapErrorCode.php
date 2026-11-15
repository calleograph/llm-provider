<?php

declare(strict_types=1);

namespace App\Ldap;

/**
 * Каталог безопасных для пользователя кодов ошибок AD-операций.
 * Тексты (RU) формируются в Http-слое, здесь только машинные коды.
 */
final class LdapErrorCode
{
    public const INVALID_CREDENTIALS = 'invalid_credentials';
    public const ACCOUNT_LOCKED = 'account_locked';
    public const ACCOUNT_DISABLED = 'account_disabled';
    public const PASSWORD_EXPIRED = 'password_expired';

    /**
     * data 773 (ERROR_PASSWORD_MUST_CHANGE) — учётной записи выставлен флаг
     * "User must change password at next logon" (pwdLastSet=0). Это НЕ ошибка
     * неверного пароля: bind отклонён штатно по причине истёкшего/временного
     * пароля, а не неверных учётных данных.
     */
    public const PASSWORD_MUST_CHANGE = 'password_must_change';

    /**
     * errno=49, diagnostic содержит "data 52f" (ERROR_ACCOUNT_RESTRICTION) —
     * bind отклонён из-за ограничения на сам вход (не неверный пароль).
     * Наиболее частая причина в контексте LDAP simple bind — учётная запись
     * состоит в защищённой группе AD "Protected Users" (форсирует
     * Kerberos-only аутентификацию с Windows Server 2012 R2+ и отклоняет
     * simple bind даже по LDAPS), но код не гарантированно означает именно
     * это — могут быть и другие ограничения входа (время входа, разрешённые
     * рабочие станции, LogonWorkstation).
     */
    public const ACCOUNT_RESTRICTION = 'account_restriction';

    public const USER_NOT_FOUND = 'user_not_found';
    public const SERVER_UNAVAILABLE = 'server_unavailable';
    public const TLS_ERROR = 'tls_error';
    public const INSUFFICIENT_ACCESS = 'insufficient_access';
    public const TIMEOUT = 'timeout';
    public const UNKNOWN = 'unknown';
}
