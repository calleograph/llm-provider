<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Обёртка над SQLite-хранилищем настроек приложения.
 * База лежит вне document root (var/data/app.sqlite).
 */
final class Database
{
    private static ?PDO $instance = null;

    public static function connect(string $path): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $isNew = !is_file($path);

        try {
            $pdo = new PDO('sqlite:' . $path);
        } catch (PDOException $e) {
            throw new RuntimeException('Не удалось открыть хранилище настроек.');
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        if ($isNew) {
            @chmod($path, 0660);
        }

        self::$instance = $pdo;
        self::migrate($pdo);

        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS ldap_configs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                is_active INTEGER NOT NULL DEFAULT 0,
                ldaps_url TEXT NOT NULL,
                host TEXT NOT NULL,
                port INTEGER NOT NULL DEFAULT 636,
                base_dn TEXT NOT NULL,
                domain TEXT NOT NULL,
                netbios_domain TEXT NOT NULL,
                admin_group_dn TEXT NOT NULL,
                protocol_version INTEGER NOT NULL DEFAULT 3,
                starttls INTEGER NOT NULL DEFAULT 0,
                network_timeout INTEGER NOT NULL DEFAULT 8,
                ca_bundle_file TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS certificates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL,
                type TEXT NOT NULL,
                subject TEXT NOT NULL,
                issuer TEXT NOT NULL,
                serial TEXT NOT NULL,
                fingerprint_sha256 TEXT NOT NULL UNIQUE,
                valid_from TEXT NOT NULL,
                valid_to TEXT NOT NULL,
                uploaded_by TEXT NOT NULL,
                uploaded_at TEXT NOT NULL
            )
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS local_admin (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                username TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS app_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                actor TEXT NOT NULL,
                ip TEXT NOT NULL,
                action TEXT NOT NULL,
                details TEXT,
                success INTEGER NOT NULL
            )
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS app_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                ip TEXT NOT NULL,
                username TEXT,
                operation TEXT NOT NULL,
                result TEXT NOT NULL
            )
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS rate_limit (
                bucket_key TEXT NOT NULL,
                attempted_at INTEGER NOT NULL
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_limit_key ON rate_limit(bucket_key)');

        // Техническая диагностика LDAP/TLS/kpasswd/непредвиденных ошибок —
        // отдельно от app_log (операции пользователей) и audit_log (действия
        // администраторов). Никогда не содержит значения паролей — см.
        // App\Diagnostics\DiagnosticLogger, который формирует записи.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS diagnostic_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                level TEXT NOT NULL,
                source TEXT NOT NULL,
                event_code TEXT NOT NULL,
                message TEXT NOT NULL,
                context TEXT,
                ip TEXT
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_diagnostic_log_created ON diagnostic_log(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_diagnostic_log_source ON diagnostic_log(source)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_diagnostic_log_level ON diagnostic_log(level)');

        // Модуль LLM-чата: расширение ldap_configs и собственные таблицы.
        \App\Llm\LlmSchema::migrate($pdo);
    }
}
