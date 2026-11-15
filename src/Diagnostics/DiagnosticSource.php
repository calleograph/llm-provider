<?php

declare(strict_types=1);

namespace App\Diagnostics;

/**
 * Источник диагностической записи — для фильтрации в UI журнала.
 */
final class DiagnosticSource
{
    public const LDAP_BIND = 'ldap_bind';
    public const LDAP_SEARCH = 'ldap_search';
    public const LDAP_MODIFY = 'ldap_modify';
    public const LDAP_CONNECT = 'ldap_connect';
    public const TLS = 'tls';
    public const KPASSWD = 'kpasswd';
    public const CONNECTION_TEST = 'connection_test';
    public const CERTIFICATE = 'certificate';
    public const PHP_ERROR = 'php_error';
    public const AD_LINK_STATUS = 'ad_link_status';
    public const KERBEROS_GROUP_SYNC = 'kerberos_group_sync';
    public const CHAT_AUTH = 'chat_auth';
    public const ANONYMIZATION = 'anonymization';
    public const LLM_GATEWAY = 'llm_gateway';
    public const FILE_PROCESSING = 'file_processing';

    public const ALL = [
        self::LDAP_BIND,
        self::LDAP_SEARCH,
        self::LDAP_MODIFY,
        self::LDAP_CONNECT,
        self::TLS,
        self::KPASSWD,
        self::CONNECTION_TEST,
        self::CERTIFICATE,
        self::PHP_ERROR,
        self::AD_LINK_STATUS,
        self::KERBEROS_GROUP_SYNC,
        self::CHAT_AUTH,
        self::ANONYMIZATION,
        self::LLM_GATEWAY,
        self::FILE_PROCESSING,
    ];
}
