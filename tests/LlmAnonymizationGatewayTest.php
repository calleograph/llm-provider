<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\AppSettings;
use App\Llm\Anonymization\AnonymizationException;
use App\Llm\Anonymization\AnonymizationGateway;
use App\Llm\Anonymization\AnonymizationSettings;
use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use App\Llm\Http\HttpTransportException;
use PHPUnit\Framework\TestCase;

/**
 * Anonymization Gateway работает по принципу fail closed: любой
 * неоднозначный ответ сервиса — исключение, а не «пропустить как есть».
 */
final class LlmAnonymizationGatewayTest extends TestCase
{
    private AnonymizationSettings $settings;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно.');
        }
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->settings = new AnonymizationSettings(new AppSettings($pdo));
    }

    private function configure(array $overrides = []): void
    {
        $this->settings->save($overrides + [
            'profile' => 'generic',
            'base_url' => 'https://anon.corp.local',
            'endpoint' => '/anonymize',
            'request_field' => 'text',
            'response_field' => 'anonymized_text',
            'use_corporate_ca' => '0',
        ]);
    }

    private function gateway(FakeHttpTransport $transport): AnonymizationGateway
    {
        return new AnonymizationGateway($this->settings, $transport, static fn (): string => '/nonexistent/ca.pem');
    }

    private static function echoService(): FakeHttpTransport
    {
        return new FakeHttpTransport(static function (HttpRequest $r): HttpResponse {
            $body = json_decode((string) $r->body, true);

            return new HttpResponse(200, json_encode(['anonymized_text' => str_replace('Иванов', '[PERSON_1]', $body['text'])], JSON_UNESCAPED_UNICODE));
        });
    }

    public function testNotConfiguredFailsClosed(): void
    {
        $transport = self::echoService();
        try {
            $this->gateway($transport)->anonymize('Иванов', 'message');
            $this->fail('Ожидалось исключение');
        } catch (AnonymizationException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }
        $this->assertSame([], $transport->requests);
    }

    public function testSuccessReturnsAnonymizedText(): void
    {
        $this->configure();
        $transport = self::echoService();

        $result = $this->gateway($transport)->anonymize('Позвонить Иванов завтра', 'message');

        $this->assertSame('Позвонить [PERSON_1] завтра', $result->value);
        $this->assertSame('https://anon.corp.local/anonymize', $transport->requests[0]->url);
        $this->assertSame(['text' => 'Позвонить Иванов завтра'], json_decode((string) $transport->requests[0]->body, true));
    }

    /** @return iterable<string,array{0:\Closure,1:string}> */
    public static function failingResponses(): iterable
    {
        yield 'HTTP 500' => [static fn (): HttpResponse => new HttpResponse(500, '{"anonymized_text":"x"}'), 'http_500'];
        yield 'не JSON' => [static fn (): HttpResponse => new HttpResponse(200, '<html>'), 'invalid_json'];
        yield 'нет поля' => [static fn (): HttpResponse => new HttpResponse(200, '{"result":"x"}'), 'missing_field'];
        yield 'поле не строка' => [static fn (): HttpResponse => new HttpResponse(200, '{"anonymized_text":["x"]}'), 'missing_field'];
        yield 'пустой результат' => [static fn (): HttpResponse => new HttpResponse(200, '{"anonymized_text":"  "}'), 'empty_result'];
        yield 'таймаут' => [static function (): HttpResponse { throw new HttpTransportException('timeout', 'timeout'); }, 'transport_timeout'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingResponses')]
    public function testFailuresThrow(\Closure $handler, string $expectedCode): void
    {
        $this->configure();
        try {
            $this->gateway(new FakeHttpTransport($handler))->anonymize('Иванов Иван', 'message');
            $this->fail('Ожидалось исключение');
        } catch (AnonymizationException $e) {
            $this->assertSame($expectedCode, $e->errorCode);
        }
    }

    public function testStatusFieldMustMatch(): void
    {
        $this->configure(['status_field' => 'status', 'status_ok_value' => 'ok']);
        $transport = new FakeHttpTransport(static fn (): HttpResponse => new HttpResponse(200, '{"status":"error","anonymized_text":"x"}'));

        $this->expectException(AnonymizationException::class);
        $this->gateway($transport)->anonymize('Иванов', 'message');
    }

    public function testNestedFieldsAndExtraBody(): void
    {
        $this->configure(['request_field' => 'input.text', 'response_field' => 'result.text', 'extra_body' => '{"lang":"ru"}']);
        $transport = new FakeHttpTransport(static fn (): HttpResponse => new HttpResponse(200, '{"result":{"text":"[ORG]"}}'));

        $this->assertSame('[ORG]', $this->gateway($transport)->anonymize('ООО Ромашка', 'message')->value);
        $this->assertSame(['lang' => 'ru', 'input' => ['text' => 'ООО Ромашка']], json_decode((string) $transport->requests[0]->body, true));
    }

    public function testLongTextIsSentInChunksAndAnyChunkFailureFailsAll(): void
    {
        $this->configure(['max_chunk_chars' => '500']);
        $text = str_repeat("Абзац с данными Иванов.\n", 100);
        $calls = 0;
        $transport = new FakeHttpTransport(static function (HttpRequest $r) use (&$calls): HttpResponse {
            $calls++;
            if ($calls === 3) {
                return new HttpResponse(503, '{}');
            }
            $body = json_decode((string) $r->body, true);

            return new HttpResponse(200, json_encode(['anonymized_text' => $body['text']], JSON_UNESCAPED_UNICODE));
        });

        $this->expectException(AnonymizationException::class);
        $this->gateway($transport)->anonymize($text, 'file');
    }

    public function testChunkSplitKeepsAllText(): void
    {
        $text = str_repeat("Строка номер один с текстом.\n", 50) . str_repeat('x', 1300);
        $chunks = AnonymizationGateway::splitIntoChunks($text, 500);

        $this->assertSame($text, implode('', $chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(500, mb_strlen($chunk));
        }
    }

    public function testBearerSecretIsSentButStoredEncrypted(): void
    {
        $this->configure(['auth_type' => AnonymizationSettings::AUTH_BEARER]);
        $this->settings->setSecret('anon-secret-token');
        $transport = self::echoService();

        $this->gateway($transport)->anonymize('Иванов', 'message');

        $this->assertSame('Bearer anon-secret-token', $transport->requests[0]->headers['Authorization']);
        $this->assertTrue($this->settings->hasSecret());
    }

    public function testCorporateCaIsUsedWhenEnabled(): void
    {
        $this->configure(['use_corporate_ca' => '1']);
        $transport = self::echoService();
        $gateway = new AnonymizationGateway($this->settings, $transport, static fn (): string => '/var/certs/ca-bundle.pem');

        $gateway->anonymize('Иванов', 'message');

        $this->assertSame('/var/certs/ca-bundle.pem', $transport->requests[0]->caBundlePath);
    }
}
