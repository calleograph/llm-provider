<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Регрессионный тест на инцидент: LDAP_OPT_X_TLS_NEWCTX не определена в
 * некоторых сборках ext-ldap (обнаружено на PHP 8.4 / Ubuntu 24.04 в
 * продакшене) — необработанное "Undefined constant" внутри
 * LdapClient::connect() приводило к падению PHP-FPM worker'а без ответа
 * клиенту ("Страница недоступна" в браузере, обрыв FastCGI-соединения на
 * уровне nginx, а не аккуратная HTML-страница с ошибкой).
 *
 * Проверяет статически (по исходному коду, не выполняя LDAP-код — ext-ldap
 * недоступен во всех тестовых окружениях), что:
 *  - LDAP_OPT_X_TLS_NEWCTX никогда не используется без defined()-проверки;
 *  - критичные для TLS-проверки константы (CACERTFILE/REQUIRE_CERT/DEMAND)
 *    имеют явную проверку defined() с понятным отказом, а не тихий пропуск —
 *    их отсутствие означает, что ext-ldap собран без поддержки TLS-опций
 *    OpenLDAP, и приложение обязано отказать (fail-closed), а не молча
 *    продолжить без проверки сертификата.
 */
final class LdapClientConstantSafetyTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__) . '/src/Ldap/LdapClient.php';
        $content = file_get_contents($path);
        $this->assertNotFalse($content, 'Не удалось прочитать LdapClient.php');

        return $content;
    }

    public function testNewCtxConstantIsNeverUsedWithoutDefinedGuard(): void
    {
        $source = $this->source();

        // Каждое использование LDAP_OPT_X_TLS_NEWCTX как аргумента ldap_set_option
        // должно находиться внутри блока, защищённого defined('LDAP_OPT_X_TLS_NEWCTX').
        $this->assertStringContainsString("defined('LDAP_OPT_X_TLS_NEWCTX')", $source);

        // Убеждаемся, что вызов ldap_set_option(..., LDAP_OPT_X_TLS_NEWCTX, ...)
        // встречается ровно один раз в самом коде (комментарии не считаются) и
        // находится сразу после defined()-проверки, а не где-то отдельно
        // незащищённым.
        $codeOnly = preg_replace('#//.*#', '', $source);
        $matches = [];
        preg_match_all('/LDAP_OPT_X_TLS_NEWCTX/', $codeOnly, $matches);
        $this->assertCount(2, $matches[0], 'LDAP_OPT_X_TLS_NEWCTX должна упоминаться в коде только внутри защищённого defined()-блока.');
    }

    public function testCriticalTlsConstantsHaveExplicitFailClosedGuard(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("defined('LDAP_OPT_X_TLS_CACERTFILE')", $source);
        $this->assertStringContainsString("defined('LDAP_OPT_X_TLS_REQUIRE_CERT')", $source);
        $this->assertStringContainsString("defined('LDAP_OPT_X_TLS_DEMAND')", $source);

        // Отсутствие этих констант должно приводить к LdapException (fail-closed),
        // а не к молчаливому продолжению без проверки TLS.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!defined\(\'LDAP_OPT_X_TLS_CACERTFILE\'\).*?throw new LdapException/s',
            $source
        );
    }
}
