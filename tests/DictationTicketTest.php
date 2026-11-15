<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\AppSettings;
use App\Llm\Chat\ChatSettings;
use App\Llm\Dictation\DictationTicket;
use PHPUnit\Framework\TestCase;

/** Билет голосового ввода: формат и подпись совпадают с services/asr/app/ws_gateway.py::verify_ticket. */
final class DictationTicketTest extends TestCase
{
    private ChatSettings $settings;

    protected function setUp(): void
    {
        putenv('DICTATION_SECRET=' . str_repeat('s', 48));
        putenv('ASR_ENABLED=1');
        $this->settings = new ChatSettings(new AppSettings(LlmTestKit::database()));
    }

    protected function tearDown(): void
    {
        putenv('DICTATION_SECRET');
        putenv('ASR_ENABLED');
    }

    private static function b64d(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public function testTicketIsSignedShortLivedAndUnique(): void
    {
        $tickets = new DictationTicket($this->settings);
        $this->assertTrue($tickets->enabled());
        $t = $tickets->issue('ivanov@corp.local', 1_000_000);
        [$body, $sig] = explode('.', $t);
        $this->assertSame(hash_hmac('sha256', $body, str_repeat('s', 48), true), self::b64d($sig));
        $data = json_decode(self::b64d($body), true);
        $this->assertSame('ivanov@corp.local', $data['u']);
        $this->assertSame(1_000_000 + DictationTicket::TTL, $data['exp']);
        $this->assertSame(120, $data['ms']);
        $this->assertSame(4, $data['mc']);
        $this->assertSame(32, strlen($data['n']));
        $this->assertNotSame($t, $tickets->issue('ivanov@corp.local', 1_000_000), 'одноразовый номер в каждом билете свой');
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $t, 'base64url без заполнения — годится для адреса');
    }

    public function testDisabledByAdminOrWithoutAsr(): void
    {
        $this->settings->save(['dictation_enabled' => '0']);
        $this->assertFalse((new DictationTicket($this->settings))->enabled());
        $this->settings->save(['dictation_enabled' => '1', 'dictation_max_seconds' => '9999']);
        $this->assertSame(600, (new DictationTicket($this->settings))->maxSeconds());
        putenv('ASR_ENABLED=0');
        $this->assertFalse((new DictationTicket($this->settings))->enabled());
    }
}
