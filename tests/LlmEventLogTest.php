<?php

declare(strict_types=1);

namespace App\Tests;

use App\Llm\Domain\ChatAuthenticator;
use App\Llm\LlmEventLog;
use PHPUnit\Framework\TestCase;

final class LlmEventLogTest extends TestCase
{
    public function testContentAndSecretFieldsAreDropped(): void
    {
        $details = LlmEventLog::formatDetails([
            'provider' => 'OpenAI',
            'text' => 'Иванов Иван, паспорт 1234',
            'content' => 'секрет',
            'prompt' => 'x',
            'api_key' => 'sk-123',
            'password' => 'p',
            'token' => 't',
            'file_name' => 'Увольнение Иванова.docx',
            'input_tokens' => 10,
            'text_chars' => 25,
        ]);

        $this->assertSame('provider=OpenAI; input_tokens=10; text_chars=25', $details);
        $this->assertStringNotContainsString('Иванов', $details);
        $this->assertStringNotContainsString('sk-123', $details);
    }

    public function testValuesAreSanitized(): void
    {
        $details = LlmEventLog::formatDetails(['groups' => ['A;B', "C\nD"], 'ok' => true, 'none' => null]);

        $this->assertSame('groups=A,B,C D; ok=1; none=—', $details);
    }

    public function testLoginParsing(): void
    {
        $this->assertSame(['sam' => 'ivanov', 'domain_hint' => null], ChatAuthenticator::parseLogin(' ivanov '));
        $this->assertSame(['sam' => 'ivanov', 'domain_hint' => 'corp'], ChatAuthenticator::parseLogin('CORP\\ivanov'));
        $this->assertSame(['sam' => 'ivanov', 'domain_hint' => 'corp.local'], ChatAuthenticator::parseLogin('ivanov@Corp.Local'));
        $this->assertNull(ChatAuthenticator::parseLogin('iva*nov'));
        $this->assertNull(ChatAuthenticator::parseLogin('a=b,c'));
        $this->assertNull(ChatAuthenticator::parseLogin(''));
    }
}
