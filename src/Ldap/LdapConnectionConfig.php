<?php

declare(strict_types=1);

namespace App\Ldap;

/**
 * Неизменяемый снимок параметров подключения к одному AD-серверу.
 * Соответствует одной строке таблицы ldap_configs.
 */
final class LdapConnectionConfig
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $isActive,
        public readonly string $ldapsUrl,
        public readonly string $host,
        public readonly int $port,
        public readonly string $baseDn,
        public readonly string $domain,
        public readonly string $netbiosDomain,
        public readonly string $adminGroupDn,
        public readonly int $protocolVersion,
        public readonly bool $startTls,
        public readonly int $networkTimeout,
        public readonly ?string $caBundleFile,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            isActive: (bool) $row['is_active'],
            ldapsUrl: (string) $row['ldaps_url'],
            host: (string) $row['host'],
            port: (int) $row['port'],
            baseDn: (string) $row['base_dn'],
            domain: (string) $row['domain'],
            netbiosDomain: (string) $row['netbios_domain'],
            adminGroupDn: (string) $row['admin_group_dn'],
            protocolVersion: (int) $row['protocol_version'],
            startTls: (bool) $row['starttls'],
            networkTimeout: (int) $row['network_timeout'],
            caBundleFile: $row['ca_bundle_file'] !== null ? (string) $row['ca_bundle_file'] : null,
        );
    }
}
