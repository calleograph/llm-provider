<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;
use RuntimeException;

/**
 * Единственный локальный аварийный администратор приложения.
 * Создание второго локального администратора невозможно — таблица local_admin
 * имеет ровно одну строку (id = 1, CHECK constraint).
 */
final class LocalAdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function exists(): bool
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM local_admin')->fetchColumn();

        return $count > 0;
    }

    public function get(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM local_admin WHERE id = 1')->fetch();

        return $row !== false ? $row : null;
    }

    public function create(string $username, string $password): void
    {
        if ($this->exists()) {
            throw new RuntimeException('Локальный администратор уже создан.');
        }

        $hash = self::hashPassword($password);
        $stmt = $this->pdo->prepare(
            'INSERT INTO local_admin (id, username, password_hash, updated_at) VALUES (1, :username, :hash, :now)'
        );
        $stmt->execute(['username' => $username, 'hash' => $hash, 'now' => gmdate('c')]);
    }

    public function verifyPassword(string $username, string $password): bool
    {
        $admin = $this->get();
        if ($admin === null) {
            return false;
        }

        if (!hash_equals($admin['username'], $username)) {
            return false;
        }

        return password_verify($password, $admin['password_hash']);
    }

    public function changePassword(string $newPassword): void
    {
        if (!$this->exists()) {
            throw new RuntimeException('Локальный администратор не создан.');
        }

        $hash = self::hashPassword($newPassword);
        $stmt = $this->pdo->prepare('UPDATE local_admin SET password_hash = :hash, updated_at = :now WHERE id = 1');
        $stmt->execute(['hash' => $hash, 'now' => gmdate('c')]);
    }

    public function username(): ?string
    {
        $admin = $this->get();

        return $admin['username'] ?? null;
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_hash($password, $algo);
    }

    public static function generateStrongPassword(int $length = 28): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^&*-_=+';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
