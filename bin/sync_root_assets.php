<?php

declare(strict_types=1);

/**
 * Синхронизирует /assets (корень проекта, для развёртывания с document root
 * в корне) с каноничным источником public/assets. Запускать после любого
 * изменения файлов в public/assets/.
 *
 * Использование: php bin/sync_root_assets.php
 */

$root = dirname(__DIR__);
$source = $root . '/public/assets';
$target = $root . '/assets';

function copyRecursive(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }

    foreach (scandir($source) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $sourcePath = $source . '/' . $entry;
        $targetPath = $target . '/' . $entry;

        if (is_dir($sourcePath)) {
            copyRecursive($sourcePath, $targetPath);
        } else {
            copy($sourcePath, $targetPath);
            echo "  {$targetPath}\n";
        }
    }
}

echo "Синхронизация {$source} -> {$target}\n";
copyRecursive($source, $target);
echo "Готово.\n";
