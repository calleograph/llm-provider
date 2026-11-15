<?php

declare(strict_types=1);

namespace App\Tests;

use App\Http\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testIgnoresForwardedForFromUntrustedSource(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.99';

        $this->assertSame('203.0.113.5', ClientIp::resolve(['10.0.0.10']));
    }

    public function testTrustsForwardedForFromTrustedExactIp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

        $this->assertSame('198.51.100.7', ClientIp::resolve(['10.0.0.10']));
    }

    public function testTrustsForwardedForFromTrustedCidr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.55';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';

        $this->assertSame('198.51.100.9', ClientIp::resolve(['10.0.0.0/24']));
    }

    public function testFallsBackToRemoteAddrWhenNoForwardedHeader(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.10';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $this->assertSame('10.0.0.10', ClientIp::resolve(['10.0.0.10']));
    }

    public function testTakesFirstAddressInForwardedForChain(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.10, 10.0.0.1';

        $this->assertSame('198.51.100.7', ClientIp::resolve(['10.0.0.10']));
    }
}
