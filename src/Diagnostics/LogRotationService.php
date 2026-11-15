<?php

declare(strict_types=1);

namespace App\Diagnostics;

use App\Config\Paths;
use PDO;

/**
 * Ротация журналов: ограничивает суммарный размер SQLite-базы (в которой
 * лежат app_log, audit_log и diagnostic_log вместе с настройками) заданным
 * порогом. При превышении удаляет старейшие записи из каждой из трёх таблиц
 * логов (не трогая ldap_configs/certificates/local_admin/app_settings) и
 * выполняет VACUUM, чтобы место реально освободилось на диске, а не просто
 * пометилось как свободное внутри файла SQLite.
 *
 * Запускается лениво — раз в N записей лога (не на каждой вставке, чтобы не
 * делать дорогой filesize()+VACUUM на каждый запрос), см. maybeRotate().
 */
final class LogRotationService
{
    private const DEFAULT_MAX_BYTES = 50 * 1024 * 1024; // 50 МБ
    private const CHECK_EVERY_N_WRITES = 20;
    private const DELETE_FRACTION = 0.2; // при превышении лимита удаляем 20% старейших записей каждой таблицы

    private static int $writeCounter = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }

    /**
     * Вызывать после каждой записи в любой из трёх таблиц логов. Реальную
     * проверку размера и ротацию выполняет не при каждом вызове, а раз в
     * CHECK_EVERY_N_WRITES вызовов — размер файла проверяется дёшево
     * (filesize), но VACUUM дорогой и не нужен настолько часто.
     */
    public function maybeRotate(): void
    {
        self::$writeCounter++;
        if (self::$writeCounter % self::CHECK_EVERY_N_WRITES !== 0) {
            return;
        }

        $this->rotateIfNeeded();
    }

    public function rotateIfNeeded(): bool
    {
        $dbPath = Paths::database();
        $size = @filesize($dbPath);
        if ($size === false || $size <= $this->maxBytes) {
            return false;
        }

        foreach (['app_log', 'audit_log', 'diagnostic_log'] as $table) {
            $this->deleteOldestFraction($table, self::DELETE_FRACTION);
        }

        // VACUUM возвращает освобождённые страницы файловой системе — без
        // него удалённые строки остаются "дырами" внутри файла .sqlite, и
        // его размер на диске не уменьшится.
        $this->pdo->exec('VACUUM');

        return true;
    }

    private function deleteOldestFraction(string $table, float $fraction): void
    {
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        if ($total === 0) {
            return;
        }

        $toDelete = (int) ceil($total * $fraction);
        if ($toDelete <= 0) {
            return;
        }

        $this->pdo->exec(
            "DELETE FROM {$table} WHERE id IN (SELECT id FROM {$table} ORDER BY id ASC LIMIT {$toDelete})"
        );
    }
}
