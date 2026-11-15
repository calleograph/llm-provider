<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\Paths;
use App\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Регрессия: CSRF-токен в открытой форме "устаревал" быстрее ожидаемого —
 * причина в том, что время жизни сессии на сервере (session.gc_maxlifetime)
 * не было зафиксировано в коде и зависело от настроек конкретного хостинга
 * (иногда занижено), а сами файлы сессий могли лежать в системном /tmp,
 * который на части хостингов чистится внешними службами быстрее, чем
 * настроено в PHP. Эти тесты проверяют, что оба параметра зафиксированы
 * явно и с достаточным запасом (не менее 10 минут, требуемых пользователем).
 */
final class SessionManagerTest extends TestCase
{
    public function testMinSessionLifetimeIsAtLeastTenMinutes(): void
    {
        $reflection = new ReflectionClass(SessionManager::class);
        $lifetime = $reflection->getConstant('MIN_SESSION_LIFETIME_SECONDS');

        $this->assertGreaterThanOrEqual(600, $lifetime, 'Время жизни сессии должно быть не меньше 10 минут.');
    }

    /**
     * Регрессия: idle-таймаут администратора в 15 минут был слишком коротким
     * и регулярно выбивал администраторов из панели во время обычной работы.
     * По требованию пользователя поднят до не менее 4 часов.
     */
    public function testAdminIdleTimeoutIsAtLeastFourHours(): void
    {
        $this->assertGreaterThanOrEqual(4 * 3600, SessionManager::DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS, 'Idle-таймаут администратора должен быть не меньше 4 часов.');
    }

    /**
     * Время жизни сессии на сервере (gc_maxlifetime) не должно быть короче
     * максимально допустимого настраиваемого idle-таймаута администратора —
     * иначе сборщик мусора PHP мог бы уничтожить сессию раньше, чем сработает
     * сам таймаут бездействия, при повышении настройки администратором.
     */
    public function testMinSessionLifetimeIsNotShorterThanAdminIdleTimeout(): void
    {
        $reflection = new ReflectionClass(SessionManager::class);
        $lifetime = $reflection->getConstant('MIN_SESSION_LIFETIME_SECONDS');

        $this->assertGreaterThanOrEqual(SessionManager::MAX_ADMIN_IDLE_TIMEOUT_SECONDS, $lifetime);
    }

    public function testSessionsDirectoryIsOutsideSystemTemp(): void
    {
        $sessionsPath = Paths::sessions();

        $this->assertStringStartsWith(Paths::var() . '/', $sessionsPath);
        $this->assertStringNotContainsString(sys_get_temp_dir(), $sessionsPath);
    }

    public function testSessionsDirectoryIsUnderVarNotDocumentRoot(): void
    {
        $sessionsPath = Paths::sessions();
        $publicPath = Paths::root() . '/public';

        $this->assertStringNotContainsString($publicPath, $sessionsPath);
    }
}
