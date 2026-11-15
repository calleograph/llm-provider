<?php

declare(strict_types=1);

namespace App\Tests;

use App\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsGeneratedAndPersisted(): void
    {
        $token1 = Csrf::token();
        $token2 = Csrf::token();

        $this->assertSame($token1, $token2);
        $this->assertGreaterThanOrEqual(32, strlen($token1));
    }

    public function testVerifyAcceptsMatchingToken(): void
    {
        $token = Csrf::token();
        $this->assertTrue(Csrf::verify($token));
    }

    public function testVerifyRejectsWrongToken(): void
    {
        Csrf::token();
        $this->assertFalse(Csrf::verify('wrong-token'));
    }

    public function testVerifyRejectsNullToken(): void
    {
        Csrf::token();
        $this->assertFalse(Csrf::verify(null));
    }

    public function testVerifyRejectsWhenNoTokenInSession(): void
    {
        $this->assertFalse(Csrf::verify('anything'));
    }

    public function testRotateChangesToken(): void
    {
        $token1 = Csrf::token();
        Csrf::rotate();
        $token2 = Csrf::token();

        $this->assertNotSame($token1, $token2);
        $this->assertFalse(Csrf::verify($token1));
    }
}
