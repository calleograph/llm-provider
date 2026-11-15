<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\AppSettings;
use App\Llm\Anonymization\AnonymizationException;
use App\Llm\Anonymization\AnonymizationGateway;
use App\Llm\Anonymization\AnonymizationSettings;
use App\Llm\Files\FileProcessingSettings;
use App\Llm\Files\FileProcessor;
use App\Llm\Files\StagedFile;
use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

/**
 * Контракт DocClean (docs/API_GUIDE.md обезличивателя): api_text, api_file,
 * api_download, openapi. Текст принимается только при ok=true и
 * verification.clean=true.
 */
final class LlmDocCleanGatewayTest extends TestCase
{
    private AnonymizationSettings $settings;
    private FileProcessor $processor;
    private string $tmp;
    private bool $clean = true;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно.');
        }
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $app = new AppSettings($pdo);
        $this->settings = new AnonymizationSettings($app);
        $this->settings->save(['profile' => 'docclean', 'base_url' => 'http://10.0.0.5:8103', 'allow_http' => '1', 'use_corporate_ca' => '0', 'docclean_mode' => 'ai_ready']);
        $this->settings->setSecret('ak_test');
        $this->tmp = sys_get_temp_dir() . '/dc-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0700, true);
        $this->processor = FileProcessor::withDefaults(new FileProcessingSettings($app), $this->tmp . '/work');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            is_file($f) ? unlink($f) : @rmdir($f);
        }
        @rmdir($this->tmp);
    }

    private function service(): FakeHttpTransport
    {
        return new FakeHttpTransport(function (HttpRequest $r): HttpResponse {
            parse_str((string) parse_url($r->url, PHP_URL_QUERY), $q);
            if (($r->headers['Authorization'] ?? '') !== 'Bearer ak_test' && $q['action'] !== 'openapi') {
                return new HttpResponse(401, '{"ok":false,"code":"UNAUTHORIZED","error":"x"}');
            }
            switch ($q['action']) {
                case 'openapi':
                    return new HttpResponse(200, json_encode(['openapi' => '3.0.3', 'paths' => ['/index.php?action=api_text' => []]]));
                case 'api_text':
                    $body = json_decode((string) $r->body, true);
                    return new HttpResponse(200, json_encode([
                        'ok' => true,
                        'text' => str_replace('Иванов', '«ФИО»', $body['text']),
                        'report' => ['total_replaced' => 1, 'rows' => []],
                        'verification' => ['clean' => $this->clean, 'leaked_values_count' => $this->clean ? 0 : 1],
                    ], JSON_UNESCAPED_UNICODE));
                case 'api_file':
                    return new HttpResponse(200, json_encode([
                        'ok' => true,
                        'file' => ['detected_format' => 'png', 'result_format' => 'txt', 'converted_to_text' => true],
                        'download_token' => 'tok_1234567890',
                        'download_url' => '?action=api_download&token=tok_1234567890',
                        'report' => ['total_replaced' => 3],
                        'verification' => ['clean' => $this->clean, 'leaked_values_count' => 0],
                        'residualRiskScan' => ['total' => 0, 'high' => 0],
                    ]));
                case 'api_download':
                    return $q['token'] === 'tok_1234567890'
                        ? new HttpResponse(200, 'Скан: «ФИО» получил премию')
                        : new HttpResponse(404, '{"ok":false,"code":"DOWNLOAD_NOT_FOUND"}');
            }

            return new HttpResponse(404, '{}');
        });
    }

    private function gateway(FakeHttpTransport $t): AnonymizationGateway
    {
        return new AnonymizationGateway($this->settings, $t, static fn (): string => '/none');
    }

    public function testApiTextRequestAndResponse(): void
    {
        $t = $this->service();
        $this->settings->save(['docclean_groups' => 'pdn,secrets']);

        $this->assertSame('Письмо «ФИО»', $this->gateway($t)->anonymize('Письмо Иванов', 'message')->value);

        $this->assertSame('http://10.0.0.5:8103/index.php?action=api_text', $t->requests[0]->url);
        $this->assertSame(['text' => 'Письмо Иванов', 'mode' => 'ai_ready', 'groups' => ['pdn', 'secrets']], json_decode((string) $t->requests[0]->body, true));
    }

    public function testNotCleanVerificationBlocks(): void
    {
        $this->clean = false;
        try {
            $this->gateway($this->service())->anonymize('Письмо Иванов', 'message');
            $this->fail('Ожидалась блокировка');
        } catch (AnonymizationException $e) {
            $this->assertSame('not_clean', $e->errorCode);
        }
    }

    public function testServiceErrorCodeIsMapped(): void
    {
        $this->settings->setSecret('wrong');
        try {
            $this->gateway($this->service())->anonymize('x Иванов', 'message');
            $this->fail('Ожидалась ошибка');
        } catch (AnonymizationException $e) {
            $this->assertSame('api_unauthorized', $e->errorCode);
        }
    }

    public function testFileGoesThroughApiFileAndDownload(): void
    {
        $t = $this->service();
        $source = $this->tmp . '/Увольнение Иванова.png';
        file_put_contents($source, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32));

        $result = $this->processor->withStagedFile($source, basename($source), (int) filesize($source), false,
            fn (StagedFile $f) => $this->gateway($t)->anonymizeFile($f, $this->processor));

        $this->assertSame('Скан: «ФИО» получил премию', $result->text->value);
        $this->assertTrue($result->convertedToText);
        $this->assertSame(3, $result->totalReplaced);

        $upload = $t->requestsTo('api_file')[0];
        $this->assertSame('document.png', $upload->body['document']->getPostFilename(), 'Исходное имя файла сервису не передаётся');
        $this->assertSame('ai_ready', $upload->body['mode']);
        $this->assertCount(1, $t->requestsTo('api_download'));
        $this->assertCount(1, $t->requestsTo('api_text'), 'Повторная проверка извлечённого текста');
        $this->assertSame([], glob($this->tmp . '/work/*') ?: [], 'Временные файлы удалены');
    }

    public function testFileNotCleanIsBlockedBeforeDownload(): void
    {
        $this->clean = false;
        $t = $this->service();
        $source = $this->tmp . '/a.txt';
        file_put_contents($source, 'Иванов');

        try {
            $this->processor->withStagedFile($source, 'a.txt', 6, false, fn (StagedFile $f) => $this->gateway($t)->anonymizeFile($f, $this->processor));
            $this->fail('Ожидалась блокировка');
        } catch (AnonymizationException $e) {
            $this->assertSame('not_clean', $e->errorCode);
        }
        $this->assertSame([], $t->requestsTo('api_download'));
    }

    public function testConnectionCheckUsesOpenApi(): void
    {
        $t = $this->service();
        $result = $this->gateway($t)->testConnection();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringContainsString('action=openapi', $t->requests[0]->url);

        $placeholder = new FakeHttpTransport(static fn (): HttpResponse => new HttpResponse(200, '<h1>Project 8103</h1>'));
        $this->assertFalse($this->gateway($placeholder)->testConnection()['ok']);
    }
}
