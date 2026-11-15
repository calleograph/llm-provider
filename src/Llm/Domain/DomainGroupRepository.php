<?php

declare(strict_types=1);

namespace App\Llm\Domain;

use PDO;

/**
 * Разрешённые субъекты доступа к LLM-чату (llm_domain_groups): доменные
 * группы (subject_type='group', group_ref — полный DN или CN) либо
 * отдельные пользователи домена (subject_type='user', group_ref —
 * sAMAccountName, «группа из одного» без создания группы в AD).
 */
final class DomainGroupRepository
{
    public const SUBJECT_GROUP = 'group';
    public const SUBJECT_USER = 'user';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> с именем домена */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT g.*, c.name AS domain_name, c.domain AS domain_fqdn
             FROM llm_domain_groups g JOIN ldap_configs c ON c.id = g.ldap_config_id
             ORDER BY c.name, g.display_name'
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function forDomain(int $domainId, bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM llm_domain_groups WHERE ldap_config_id = :id' . ($enabledOnly ? ' AND is_enabled = 1' : '') . ' ORDER BY display_name';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $domainId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM llm_domain_groups WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function refExists(int $domainId, string $groupRef, ?int $excludeId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM llm_domain_groups WHERE ldap_config_id = :d AND lower(group_ref) = lower(:r) AND id != :id');
        $stmt->execute(['d' => $domainId, 'r' => $groupRef, 'id' => $excludeId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(int $domainId, string $displayName, string $groupRef, bool $enabled, string $actor, string $subjectType = self::SUBJECT_GROUP): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO llm_domain_groups (ldap_config_id, display_name, group_ref, is_enabled, created_by, created_at, updated_at, subject_type)
             VALUES (:d, :n, :r, :e, :by, :now, :now, :st)'
        );
        $stmt->execute(['d' => $domainId, 'n' => $displayName, 'r' => $groupRef, 'e' => $enabled ? 1 : 0, 'by' => $actor, 'now' => $now, 'st' => $subjectType]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $displayName, string $groupRef, bool $enabled): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE llm_domain_groups SET display_name = :n, group_ref = :r, is_enabled = :e, updated_at = :now WHERE id = :id'
        );
        $stmt->execute(['n' => $displayName, 'r' => $groupRef, 'e' => $enabled ? 1 : 0, 'now' => gmdate('c'), 'id' => $id]);
    }

    /** NULL — группа без собственной категории лимита (см. UsageLimiter). */
    public function setLimitCategory(int $id, ?int $categoryId): void
    {
        $stmt = $this->pdo->prepare('UPDATE llm_domain_groups SET limit_category_id = :c, updated_at = :now WHERE id = :id');
        $stmt->execute(['c' => $categoryId, 'now' => gmdate('c'), 'id' => $id]);
    }

    public function setAllowUnanonymized(int $id, bool $allowed): void
    {
        $stmt = $this->pdo->prepare('UPDATE llm_domain_groups SET allow_unanonymized = :a, updated_at = :now WHERE id = :id');
        $stmt->execute(['a' => $allowed ? 1 : 0, 'now' => gmdate('c'), 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM llm_domain_groups WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Полный DN или только CN: DN распознаётся по наличию "=".
     */
    /**
     * @return string|null текст ошибки
     */
    public static function validateDisplayName(string $displayName): ?string
    {
        if ($displayName === '') {
            return 'укажите отображаемое имя';
        }
        if (!mb_check_encoding($displayName, 'UTF-8') || mb_strlen($displayName) > 200 || preg_match('/[\x00-\x1F]/', $displayName) === 1) {
            return 'недопустимое отображаемое имя';
        }

        return null;
    }

    public static function isDistinguishedName(string $groupRef): bool
    {
        return str_contains($groupRef, '=');
    }

    /**
     * @return string|null текст ошибки
     */
    public static function validateSamAccountName(string $samAccountName): ?string
    {
        if ($samAccountName === '') {
            return 'Укажите доменное имя пользователя (sAMAccountName).';
        }
        if (preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $samAccountName) !== 1) {
            return 'Доменное имя пользователя — латиница, цифры, . _ - (до 64 символов), без домена и без @.';
        }

        return null;
    }

    /**
     * @return string|null текст ошибки
     */
    public static function validateRef(string $groupRef): ?string
    {
        if ($groupRef === '') {
            return 'Укажите CN или DN группы.';
        }
        if (!mb_check_encoding($groupRef, 'UTF-8') || mb_strlen($groupRef) > 512 || preg_match('/[\x00-\x1F]/', $groupRef) === 1) {
            return 'Недопустимое значение CN/DN.';
        }
        if (self::isDistinguishedName($groupRef) && preg_match('/\A[A-Za-z]+=.+/u', $groupRef) !== 1) {
            return 'DN должен иметь вид CN=Группа,OU=...,DC=example,DC=local.';
        }

        return null;
    }
}
