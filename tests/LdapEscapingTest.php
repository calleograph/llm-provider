<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Проверяет, что фильтр LDAP формируется через ldap_escape(..., LDAP_ESCAPE_FILTER)
 * и специальные символы, которые могли бы использоваться для LDAP injection,
 * корректно экранируются (не остаются "как есть" в результирующей строке).
 */
final class LdapEscapingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('ldap')) {
            $this->markTestSkipped('Расширение ext-ldap недоступно в этом окружении.');
        }
    }

    public function testWildcardIsEscaped(): void
    {
        $escaped = ldap_escape('*', '', LDAP_ESCAPE_FILTER);
        $this->assertSame('\\2a', $escaped);
        $this->assertStringNotContainsString('*', $escaped);
    }

    public function testParenthesesAreEscaped(): void
    {
        $malicious = 'admin)(|(objectClass=*';
        $escaped = ldap_escape($malicious, '', LDAP_ESCAPE_FILTER);

        $this->assertStringNotContainsString('(', $escaped);
        $this->assertStringNotContainsString(')', $escaped);
    }

    public function testBackslashIsEscaped(): void
    {
        $escaped = ldap_escape('a\\b', '', LDAP_ESCAPE_FILTER);
        $this->assertSame('a\\5cb', $escaped);
    }

    public function testNullByteIsEscaped(): void
    {
        $escaped = ldap_escape("a\0b", '', LDAP_ESCAPE_FILTER);
        $this->assertStringNotContainsString("\0", $escaped);
    }

    public function testFilterInjectionAttemptCannotBreakOutOfFilter(): void
    {
        // Классическая попытка LDAP injection: закрыть текущий фильтр и добавить свой.
        $injection = '*)(uid=*))(|(uid=*';
        $escaped = ldap_escape($injection, '', LDAP_ESCAPE_FILTER);
        $filter = sprintf('(&(objectClass=user)(sAMAccountName=%s))', $escaped);

        // Итоговая строка должна содержать ровно 4 непарные скобки от шаблона фильтра,
        // а не дополнительные скобки, "вырвавшиеся" из экранированного значения.
        $openCount = substr_count($filter, '(');
        $closeCount = substr_count($filter, ')');
        $this->assertSame($openCount, $closeCount);
        $this->assertSame(3, $openCount);
    }
}
