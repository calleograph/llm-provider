<?php

declare(strict_types=1);

namespace App\Config;

use App\Ldap\LdapConnectionConfig;
use PDO;
use RuntimeException;

/**
 * CRUD для именованных конфигураций подключения к AD (раздел «Настройки»/«Подключения»).
 * Ровно одна конфигурация всегда активна; активную удалять запрещено.
 */
final class LdapConfigRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findActive(): ?LdapConnectionConfig
    {
        $stmt = $this->pdo->query('SELECT * FROM ldap_configs WHERE is_active = 1 LIMIT 1');
        $row = $stmt->fetch();

        return $row !== false ? LdapConnectionConfig::fromRow($row) : null;
    }

    public function findById(int $id): ?LdapConnectionConfig
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ldap_configs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? LdapConnectionConfig::fromRow($row) : null;
    }

    /** @return LdapConnectionConfig[] */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM ldap_configs ORDER BY name');
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[] = LdapConnectionConfig::fromRow($row);
        }

        return $result;
    }

    /**
     * @param array{name:string,ldaps_url:string,host:string,port:int,base_dn:string,domain:string,
     *              netbios_domain:string,admin_group_dn:string,protocol_version:int,starttls:bool,
     *              network_timeout:int,ca_bundle_file:?string} $data
     */
    public function create(array $data, bool $makeActive = false): int
    {
        $now = gmdate('c');

        $this->pdo->beginTransaction();
        try {
            if ($makeActive) {
                $this->pdo->exec('UPDATE ldap_configs SET is_active = 0');
            }

            $stmt = $this->pdo->prepare(<<<SQL
                INSERT INTO ldap_configs
                    (name, is_active, ldaps_url, host, port, base_dn, domain, netbios_domain,
                     admin_group_dn, protocol_version, starttls, network_timeout, ca_bundle_file,
                     created_at, updated_at)
                VALUES
                    (:name, :is_active, :ldaps_url, :host, :port, :base_dn, :domain, :netbios_domain,
                     :admin_group_dn, :protocol_version, :starttls, :network_timeout, :ca_bundle_file,
                     :created_at, :updated_at)
            SQL);
            $stmt->execute([
                'name' => $data['name'],
                'is_active' => $makeActive ? 1 : 0,
                'ldaps_url' => $data['ldaps_url'],
                'host' => $data['host'],
                'port' => $data['port'],
                'base_dn' => $data['base_dn'],
                'domain' => $data['domain'],
                'netbios_domain' => $data['netbios_domain'],
                'admin_group_dn' => $data['admin_group_dn'],
                'protocol_version' => $data['protocol_version'],
                'starttls' => $data['starttls'] ? 1 : 0,
                'network_timeout' => $data['network_timeout'],
                'ca_bundle_file' => $data['ca_bundle_file'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(<<<SQL
            UPDATE ldap_configs SET
                name = :name, ldaps_url = :ldaps_url, host = :host, port = :port,
                base_dn = :base_dn, domain = :domain, netbios_domain = :netbios_domain,
                admin_group_dn = :admin_group_dn, protocol_version = :protocol_version,
                starttls = :starttls, network_timeout = :network_timeout,
                ca_bundle_file = :ca_bundle_file, updated_at = :updated_at
            WHERE id = :id
        SQL);
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'ldaps_url' => $data['ldaps_url'],
            'host' => $data['host'],
            'port' => $data['port'],
            'base_dn' => $data['base_dn'],
            'domain' => $data['domain'],
            'netbios_domain' => $data['netbios_domain'],
            'admin_group_dn' => $data['admin_group_dn'],
            'protocol_version' => $data['protocol_version'],
            'starttls' => $data['starttls'] ? 1 : 0,
            'network_timeout' => $data['network_timeout'],
            'ca_bundle_file' => $data['ca_bundle_file'],
            'updated_at' => gmdate('c'),
        ]);
    }

    public function setActive(int $id): void
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException('Конфигурация не найдена.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('UPDATE ldap_configs SET is_active = 0');
            $stmt = $this->pdo->prepare('UPDATE ldap_configs SET is_active = 1, updated_at = :now WHERE id = :id');
            $stmt->execute(['id' => $id, 'now' => gmdate('c')]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $config = $this->findById($id);
        if ($config === null) {
            return;
        }
        if ($config->isActive) {
            throw new RuntimeException('Нельзя удалить активную конфигурацию.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM ldap_configs WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ldap_configs WHERE name = :name AND id != :id');
            $stmt->execute(['name' => $name, 'id' => $excludeId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ldap_configs WHERE name = :name');
            $stmt->execute(['name' => $name]);
        }

        return (int) $stmt->fetchColumn() > 0;
    }
}
