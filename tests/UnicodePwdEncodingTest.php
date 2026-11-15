<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Проверяет кодирование значения unicodePwd: пароль должен быть заключён
 * в двойные кавычки и преобразован в UTF-16LE перед отправкой в LDAP modify,
 * как того требует Active Directory для операции смены пароля через LDAP.
 */
final class UnicodePwdEncodingTest extends TestCase
{
    private function encode(string $password): string
    {
        $quoted = '"' . $password . '"';

        return mb_convert_encoding($quoted, 'UTF-16LE', 'UTF-8');
    }

    public function testAsciiPasswordIsQuotedAndUtf16LittleEndian(): void
    {
        $encoded = $this->encode('Passw0rd!');

        // UTF-16LE: каждый ASCII символ занимает 2 байта, младший байт первый.
        $expected = '"Passw0rd!"';
        $expectedBytes = '';
        foreach (mb_str_split($expected, 1, 'UTF-8') as $char) {
            $codepoint = mb_ord($char, 'UTF-8');
            $expectedBytes .= chr($codepoint & 0xFF) . chr(($codepoint >> 8) & 0xFF);
        }

        $this->assertSame($expectedBytes, $encoded);
    }

    public function testEncodedLengthMatchesQuotedUtf16Length(): void
    {
        $password = 'Test1234';
        $encoded = $this->encode($password);

        // Кавычки (2 символа) + длина пароля, каждый символ = 2 байта в UTF-16LE.
        $expectedLength = (mb_strlen($password, 'UTF-8') + 2) * 2;
        $this->assertSame($expectedLength, strlen($encoded));
    }

    public function testFirstBytesAreQuoteCharacterInUtf16Le(): void
    {
        $encoded = $this->encode('x');
        // '"' = U+0022 -> в UTF-16LE это байты 0x22 0x00
        $this->assertSame("\x22\x00", substr($encoded, 0, 2));
    }

    public function testCyrillicPasswordRoundTrips(): void
    {
        $password = 'Пароль123!';
        $encoded = $this->encode($password);
        $decoded = mb_convert_encoding($encoded, 'UTF-8', 'UTF-16LE');

        $this->assertSame('"' . $password . '"', $decoded);
    }
}
