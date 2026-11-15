<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\AppSettings;
use App\Ldap\AdLinkStatusCache;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Публичный маркер связи с AD не должен делать реальное сетевое подключение
 * при каждом обращении анонимного посетителя — кэш ограничивает частоту
 * реальных проверок независимо от количества и источника запросов.
 */
final class AdLinkStatusCacheTest extends TestCase
{
    private function makeAppSettings(): AppSettings
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно в этом окружении.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        return new AppSettings($pdo);
    }

    public function testEmptyCacheReturnsNull(): void
    {
        $cache = new AdLinkStatusCache($this->makeAppSettings());
        $this->assertNull($cache->get());
    }

    public function testCachedValueIsReturnedBeforeExpiry(): void
    {
        $cache = new AdLinkStatusCache($this->makeAppSettings());
        $cache->set(true);

        $result = $cache->get();
        $this->assertNotNull($result);
        $this->assertTrue($result['ok']);
    }

    public function testCachedFalseValueIsReturnedAsFalse(): void
    {
        $cache = new AdLinkStatusCache($this->makeAppSettings());
        $cache->set(false);

        $result = $cache->get();
        $this->assertNotNull($result);
        $this->assertFalse($result['ok']);
    }

    public function testMalformedCacheValueIsTreatedAsEmpty(): void
    {
        $appSettings = $this->makeAppSettings();
        $appSettings->set('ad_link_status_cache', 'not-json-at-all');

        $cache = new AdLinkStatusCache($appSettings);
        $this->assertNull($cache->get());
    }

    /**
     * Успешный результат живёт дольше (60 сек) — реальная сеть/AD не флапают
     * за секунды, а держать положительный маркер дольше значит реже дёргать
     * контроллер домена лишними подключениями.
     */
    public function testSuccessfulResultSurvivesTenSeconds(): void
    {
        $appSettings = $this->makeAppSettings();
        $appSettings->set('ad_link_status_cache', json_encode(['ok' => true, 'checked_at' => time() - 10]));

        $cache = new AdLinkStatusCache($appSettings);
        $result = $cache->get();

        $this->assertNotNull($result);
        $this->assertTrue($result['ok']);
    }

    /**
     * Регрессия на реальный отчёт: единичный кратковременный сбой не должен
     * держать маркер красным для всех посетителей на 15+ секунд, пока связь
     * уже восстановилась — отрицательный результат кэшируется всего 5 секунд.
     */
    public function testFailedResultExpiresAfterFiveSeconds(): void
    {
        $appSettings = $this->makeAppSettings();
        $appSettings->set('ad_link_status_cache', json_encode(['ok' => false, 'checked_at' => time() - 10]));

        $cache = new AdLinkStatusCache($appSettings);
        $this->assertNull($cache->get());
    }

    public function testFailedResultIsStillReturnedWithinFiveSeconds(): void
    {
        $appSettings = $this->makeAppSettings();
        $appSettings->set('ad_link_status_cache', json_encode(['ok' => false, 'checked_at' => time() - 2]));

        $cache = new AdLinkStatusCache($appSettings);
        $result = $cache->get();

        $this->assertNotNull($result);
        $this->assertFalse($result['ok']);
    }
}
