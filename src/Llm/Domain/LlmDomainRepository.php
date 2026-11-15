<?php

declare(strict_types=1);

namespace App\Llm\Domain;

use App\Ldap\LdapConnectionConfig;
use App\Security\SecretBox;
use PDO;

/**
 * Чтение/запись LLM-параметров доменных подключений. LDAPS-параметры
 * (URL, Base DN, таймаут…) по-прежнему создаются и меняются через
 * существующий LdapConfigRepository — здесь только колонки модуля.
 */
final class LlmDomainRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return LlmDomain[] */
    public function all(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT * FROM ldap_configs ORDER BY name')->fetchAll() as $row) {
            $result[] = self::fromRow($row);
        }

        return $result;
    }

    /** @return LlmDomain[] только подключения, разрешённые для входа в чат */
    public function enabledForChat(): array
    {
        return array_values(array_filter($this->all(), static fn (LlmDomain $d): bool => $d->llmEnabled));
    }

    public function find(int $id): ?LlmDomain
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ldap_configs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? self::fromRow($row) : null;
    }

    /**
     * @param string|null $newServicePassword null — оставить сохранённый
     */
    public function saveLlmSettings(int $id, bool $llmEnabled, string $serviceUsername, ?string $newServicePassword, bool $clearServicePassword): void
    {
        $sets = 'llm_enabled = :enabled, service_username = :username, updated_at = :now';
        $params = [
            'enabled' => $llmEnabled ? 1 : 0,
            'username' => $serviceUsername !== '' ? $serviceUsername : null,
            'now' => gmdate('c'),
            'id' => $id,
        ];
        if ($clearServicePassword || $serviceUsername === '') {
            $sets .= ', service_password_encrypted = NULL';
        } elseif ($newServicePassword !== null && $newServicePassword !== '') {
            $sets .= ', service_password_encrypted = :password';
            $params['password'] = SecretBox::encrypt($newServicePassword);
        }

        $stmt = $this->pdo->prepare("UPDATE ldap_configs SET {$sets} WHERE id = :id");
        $stmt->execute($params);
    }

    public function servicePassword(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT service_password_encrypted FROM ldap_configs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $encrypted = $stmt->fetchColumn();

        return is_string($encrypted) && $encrypted !== '' ? SecretBox::decrypt($encrypted) : null;
    }

    /** @param array<string,mixed> $row */
    private static function fromRow(array $row): LlmDomain
    {
        return new LlmDomain(
            config: LdapConnectionConfig::fromRow($row),
            llmEnabled: (bool) ($row['llm_enabled'] ?? false),
            serviceUsername: isset($row['service_username']) && $row['service_username'] !== '' ? (string) $row['service_username'] : null,
            hasServicePassword: !empty($row['service_password_encrypted']),
        );
    }
}
