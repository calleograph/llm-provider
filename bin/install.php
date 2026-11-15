<?php

declare(strict_types=1);

namespace App;

use App\Config\Database;
use App\Config\Paths;

require __DIR__ . '/../vendor_autoload.php';

/**
 * Первоначальная установка: создаёт var/{data,certs,logs} вне document root,
 * SQLite-базу со схемой и master key для шифрования. Параметры AD и пароль
 * локального аварийного администратора задаются в /admin/ через браузер.
 *
 * Запуск: php bin/install.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Установка доступна только из командной строки.' . PHP_EOL);
}

echo "=== Установка корпоративного AI-чата ===\n\n";

Paths::ensureDirectories();
echo "[OK] Каталоги var/data, var/certs, var/logs созданы.\n";

if (is_file(Paths::installedMarker())) {
    echo "\nПриложение уже было установлено ранее (найден маркер " . Paths::installedMarker() . ").\n";
    echo "Повторная установка не выполняется, чтобы не потерять существующие данные.\n";
    echo "Если требуется переустановка — удалите этот файл и var/data/app.sqlite вручную, осознавая последствия.\n";
    exit(0);
}

$pdo = Database::connect(Paths::database());
echo "[OK] SQLite база данных создана: " . Paths::database() . "\n";

// Master key для шифрования секретов создаётся автоматически при первом
// обращении к SecretBox::key(), но инициализируем его явно на этапе установки.
\App\Security\SecretBox::key();
echo "[OK] Мастер-ключ создан: " . Paths::secretKeyFile() . " (права 0600)\n";

file_put_contents(Paths::installedMarker(), gmdate('c') . "\n", LOCK_EX);
chmod(Paths::installedMarker(), 0600);

echo "\n=== Установка завершена ===\n\n";
echo "Откройте /admin/ из защищённой администраторской сети и задайте пароль локального аварийного администратора.\n";
echo "Затем загрузите CA, создайте конфигурацию AD и настройте нейросети/обезличивание в веб-интерфейсе.\n";
echo "Чат для пользователей: /\n";
