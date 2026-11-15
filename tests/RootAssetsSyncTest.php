<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Проект поддерживает развёртывание с document root на корне проекта (для
 * хостингов без возможности указать document root на подкаталог public/).
 * В этом случае статические ассеты отдаются напрямую из /assets в корне —
 * это КОПИЯ public/assets/, синхронизируемая bin/sync_root_assets.php.
 * Тест ловит забытую синхронизацию после правки CSS/JS.
 */
final class RootAssetsSyncTest extends TestCase
{
    public function testRootAssetsMatchPublicAssets(): void
    {
        $root = dirname(__DIR__);
        $files = ['css/app.css', 'js/app.js', 'js/chat.js'];

        foreach ($files as $relative) {
            $publicPath = $root . '/public/assets/' . $relative;
            $rootPath = $root . '/assets/' . $relative;

            $this->assertFileExists($publicPath, "Отсутствует канонический файл public/assets/{$relative}");
            $this->assertFileExists($rootPath, "Отсутствует копия в корне assets/{$relative} — запустите bin/sync_root_assets.php");

            $this->assertSame(
                file_get_contents($publicPath),
                file_get_contents($rootPath),
                "assets/{$relative} в корне устарел относительно public/assets/{$relative} — запустите bin/sync_root_assets.php"
            );
        }
    }
}
