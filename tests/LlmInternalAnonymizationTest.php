<?php

declare(strict_types=1);

namespace App\Tests;

use App\Certificate\CaBundleBuilder;
use App\Certificate\CertificateRepository;
use App\Config\AppSettings;
use App\Diagnostics\DiagnosticLogger;
use App\Llm\Anonymization\AnonymizationException;
use App\Llm\Chat\ChatException;
use App\Llm\Chat\ChatService;
use App\Llm\Chat\ChatUser;
use App\Llm\Domain\DomainGroupRepository;
use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use App\Llm\LlmModule;
use PHPUnit\Framework\TestCase;

/**
 * Встроенный сервис обезличивания (контейнер anonymizer): совместная обработка
 * сообщения и вложений одним вызовом, строгая проверка ответа, fail closed,
 * маскирование секретов в режиме «без обезличивания».
 */
final class LlmInternalAnonymizationTest extends TestCase
{
    private \PDO $pdo;
    private FakeHttpTransport $transport;
    private LlmModule $module;
    private ChatUser $user;
    private int $providerId;
    /** @var \Closure(array):HttpResponse */
    private \Closure $anonymizer;

    protected function setUp(): void
    {
        putenv('ANONYMIZER_URL=http://anonymizer:8091');
        putenv('INTERNAL_API_TOKEN=test-internal-token');
        $this->pdo = LlmTestKit::database();
        $domainId = LlmTestKit::createDomain($this->pdo, 'Corp', 'corp.local');
        $this->providerId = LlmTestKit::createProvider($this->pdo, 'Polza');
        $groupId = (new DomainGroupRepository($this->pdo))->create($domainId, 'LLM Users', 'CN=LLM-Users,DC=corp,DC=local', true, 'test');

        $this->anonymizer = static function (array $body): HttpResponse {
            $texts = array_map(static fn (string $t): string => str_replace(['Петров Сергей', 'Петрову Сергею', 'S3cr3t-Value-77'], ['Сергей П.', 'Сергею П.', '[секрет удалён]'], $t), $body['texts']);

            return new HttpResponse(200, json_encode(['texts' => $texts, 'stats' => ['person' => 2], 'replaced' => 2, 'warnings' => [], 'verified' => true], JSON_UNESCAPED_UNICODE));
        };
        $this->transport = new FakeHttpTransport(function (HttpRequest $r): HttpResponse {
            if (str_contains($r->url, 'anonymizer:8091')) {
                return str_ends_with($r->url, '/readyz') ? new HttpResponse(200, '{"ready":true}') : ($this->anonymizer)(json_decode((string) $r->body, true));
            }

            return new HttpResponse(200, json_encode(['model' => 'm', 'choices' => [['message' => ['content' => 'Готово, Сергей П.'], 'finish_reason' => 'stop']]], JSON_UNESCAPED_UNICODE));
        });
        $this->module = new LlmModule($this->pdo, new AppSettings($this->pdo), new CaBundleBuilder(new CertificateRepository($this->pdo)), new DiagnosticLogger($this->pdo), null, $this->transport);
        $this->module->access()->setGroupProviders($groupId, [$this->providerId]);
        $this->user = new ChatUser('ivanov', $domainId, 'corp.local', [$groupId], ['LLM Users']);
    }

    protected function tearDown(): void
    {
        putenv('ANONYMIZER_URL');
        putenv('INTERNAL_API_TOKEN');
    }

    /** Вложения в тестах — не из $_FILES: copy вместо move_uploaded_file. */
    private function chat(): ChatService
    {
        $m = $this->module;

        return new ChatService($m->access(), $m->conversations(), $m->anonymizer(), $m->gateway(), $m->fileProcessor(), $m->chatSettings(), $m->events(), $m->projects(), $m->usage(), $m->storageQuota(), $m->models(), null, false);
    }

    private function txtUpload(string $text): array
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $text);

        return ['tmp_name' => $path, 'name' => 'note.txt', 'size' => strlen($text), 'error' => UPLOAD_ERR_OK];
    }

    public function testInternalIsTheDefaultProfileAndIsConfiguredFromEnvironment(): void
    {
        $settings = $this->module->anonymizationSettings();
        $this->assertTrue($settings->isInternal());
        $this->assertTrue($settings->isConfigured());
        putenv('ANONYMIZER_URL');
        $this->assertFalse($settings->isConfigured());
    }

    public function testMessageAndAttachmentGoInOneCallWithOneSessionOfReplacements(): void
    {
        $result = $this->chat()->send($this->user, '10.0.0.1', null, $this->providerId, 'Напиши Петрову Сергею', [$this->txtUpload('Отчёт подготовил Петров Сергей.')]);

        $anonCalls = $this->transport->requestsTo('/v1/anonymize');
        $this->assertCount(1, $anonCalls, 'сообщение и вложение — одним вызовом');
        $body = json_decode((string) $anonCalls[0]->body, true);
        $this->assertSame(['Напиши Петрову Сергею', 'Отчёт подготовил Петров Сергей.'], $body['texts']);
        $this->assertSame('basic', $body['profile']);
        $this->assertSame('test-internal-token', $anonCalls[0]->headers['X-Internal-Token']);

        $llm = (string) $this->transport->requestsTo('polza.ai')[0]->body;
        $this->assertStringNotContainsString('Петров', $llm);
        $this->assertStringContainsString('Сергею П.', $llm);
        $this->assertStringContainsString('Сергей П.', $llm);
        $this->assertStringNotContainsString('Петров', $result['user_message']['content']);

        $log = json_encode($this->pdo->query('SELECT * FROM app_log')->fetchAll(), JSON_UNESCAPED_UNICODE) . json_encode($this->pdo->query('SELECT * FROM diagnostic_log')->fetchAll(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Петров', $log);
        $this->assertStringContainsString('person:2', $log, 'в журнале — категории и число замен');
    }

    /** @return iterable<string,array{0:HttpResponse}> */
    public static function badResponses(): iterable
    {
        yield 'verification failed' => [new HttpResponse(422, '{"detail":{"code":"verification_failed","message":"leak"}}')];
        yield 'models not ready' => [new HttpResponse(503, '{"detail":"nlp_unavailable"}')];
        yield 'engine error' => [new HttpResponse(500, '{"detail":"anonymizer_failed"}')];
        yield 'not verified' => [new HttpResponse(200, '{"texts":["x"],"verified":false}')];
        yield 'wrong count' => [new HttpResponse(200, '{"texts":[],"verified":true}')];
        yield 'empty result' => [new HttpResponse(200, '{"texts":["   "],"verified":true}')];
        yield 'not json' => [new HttpResponse(200, '<html>')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badResponses')]
    public function testAnyFailureBlocksTheRequestToTheLlm(HttpResponse $response): void
    {
        $this->anonymizer = static fn (array $b): HttpResponse => $response;
        try {
            $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, 'Напиши Петрову Сергею', []);
            $this->fail('ожидался отказ');
        } catch (ChatException $e) {
            $this->assertSame('anonymization_failed', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }
        $this->assertCount(0, $this->transport->requestsTo('polza.ai'), 'при сбое обезличивания в LLM не уходит ничего');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM llm_messages')->fetchColumn());
    }

    public function testVerificationFailureMapsToNotClean(): void
    {
        $this->anonymizer = static fn (array $b): HttpResponse => new HttpResponse(422, '{"detail":{"code":"verification_failed","message":"leak"}}');
        $this->expectException(AnonymizationException::class);
        try {
            $this->module->anonymizer()->anonymizeMany(['a'], 'check');
        } catch (AnonymizationException $e) {
            $this->assertSame('not_clean', $e->errorCode);
            throw $e;
        }
    }

    public function testUnanonymizedSendStillMasksSecrets(): void
    {
        $this->pdo->exec("INSERT INTO llm_users (owner_key, username, domain_fqdn, first_seen_at, last_seen_at, allow_unanonymized) VALUES ('" . $this->user->ownerKey() . "', 'ivanov', 'corp.local', '2026-01-01', '2026-01-01', 1)");
        $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, 'Петров Сергей, пароль S3cr3t-Value-77', [], null, true);

        $call = json_decode((string) $this->transport->requestsTo('/v1/anonymize')[0]->body, true);
        $this->assertSame('custom', $call['profile']);
        $this->assertSame('keep', $call['settings']['custom_actions']['person'], 'без обезличивания — категории остаются, только секреты');
        $llm = (string) $this->transport->requestsTo('polza.ai')[0]->body;
        $this->assertStringNotContainsString('S3cr3t-Value-77', $llm);
    }

    public function testEngineSettingsAreBuiltFromAdminFields(): void
    {
        $settings = $this->module->anonymizationSettings();
        $settings->save(['internal_terms' => "Альбатрос;project;\nCRM;project;Система учёта", 'internal_exceptions' => "Ромашка\n", 'internal_domains' => "corp.example.local", 'internal_custom_actions' => '{"location":"pseudonym"}']);
        $engine = $settings->internalEngineSettings();
        $this->assertSame([['term' => 'Альбатрос', 'category' => 'project', 'replacement' => ''], ['term' => 'CRM', 'category' => 'project', 'replacement' => 'Система учёта']], $engine['terms']);
        $this->assertSame(['Ромашка'], $engine['exceptions']);
        $this->assertSame(['location' => 'pseudonym'], $engine['custom_actions']);
        $this->assertSame([], $settings::validateInternal(['internal_profile' => 'custom', 'internal_custom_actions' => '{"person":"mask"}']));
        $this->assertArrayHasKey('internal_custom_actions', $settings::validateInternal(['internal_profile' => 'basic', 'internal_custom_actions' => '{"secret":"keep"}']), 'секреты нельзя оставить');
    }
}
