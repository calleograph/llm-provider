<?php

declare(strict_types=1);

namespace App\Tests;

use App\Certificate\CaBundleBuilder;
use App\Certificate\CertificateRepository;
use App\Config\AppSettings;
use App\Diagnostics\DiagnosticLogger;
use App\Llm\Chat\ChatException;
use App\Llm\Chat\ChatUser;
use App\Llm\Domain\DomainGroupRepository;
use App\Llm\Gateway\FailoverPolicy;
use App\Llm\Gateway\LlmException;
use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use App\Llm\Http\HttpTransportException;
use App\Llm\LlmModule;
use PHPUnit\Framework\TestCase;

/**
 * Резервирование моделей на контролируемых заглушках LLM: каждая «модель» — свой адрес провайдера,
 * сценарий ответа задаётся очередью. Проверяется: что считается ошибкой генерации, порядок переключения,
 * учёт прав, паузы, Retry-After, отсутствие бесконечных повторов, ничего не сохраняется при общем отказе.
 */
final class LlmFailoverTest extends TestCase
{
    private \PDO $pdo;
    private LlmModule $module;
    private FakeHttpTransport $transport;
    private ChatUser $user;
    /** @var array<string,list<HttpResponse|\Throwable>> сценарий по хосту провайдера */
    private array $script = [];
    /** @var list<float> */
    private array $sleeps = [];
    private int $primaryProvider;
    private int $backupProvider;
    private int $forbiddenProvider;
    private int $groupId;

    protected function setUp(): void
    {
        putenv('ANONYMIZER_URL');
        $this->pdo = LlmTestKit::database();
        $settings = new AppSettings($this->pdo);
        $domainId = LlmTestKit::createDomain($this->pdo, 'Corp', 'corp.local');
        $this->primaryProvider = $this->provider('primary');
        $this->backupProvider = $this->provider('backup');
        $this->forbiddenProvider = $this->provider('forbidden');
        $this->groupId = (new DomainGroupRepository($this->pdo))->create($domainId, 'LLM', 'CN=LLM,DC=corp,DC=local', true, 't');

        $this->transport = new FakeHttpTransport(function (HttpRequest $r): HttpResponse {
            if (str_contains($r->url, 'anon.corp.local')) {
                return new HttpResponse(200, json_encode(['anonymized_text' => json_decode((string) $r->body, true)['text']], JSON_UNESCAPED_UNICODE));
            }
            $host = (string) parse_url($r->url, PHP_URL_HOST);
            $this->script[$host] ??= [];
            $next = array_shift($this->script[$host]) ?? self::ok('ответ ' . $host);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });
        $this->module = new LlmModule($this->pdo, $settings, new CaBundleBuilder(new CertificateRepository($this->pdo)), new DiagnosticLogger($this->pdo), null, $this->transport);
        $this->module->setSleeper(function (float $s): void { $this->sleeps[] = $s; });
        $this->module->access()->setGroupProviders($this->groupId, [$this->primaryProvider, $this->backupProvider]);
        $this->module->anonymizationSettings()->save(['profile' => 'generic', 'base_url' => 'https://anon.corp.local', 'endpoint' => '/anonymize', 'use_corporate_ca' => '0']);
        $this->user = new ChatUser('ivanov', $domainId, 'corp.local', [$this->groupId], ['LLM']);
        // Цепочка: primary → forbidden (нет прав) → backup; автоматическое резервирование выключено — только явная цепочка.
        $this->module->chatSettings()->save(['fallback_auto' => '0']);
        $this->setChain($this->primaryProvider, [$this->forbiddenProvider, $this->backupProvider]);
    }

    private function provider(string $host): int
    {
        $id = LlmTestKit::createProvider($this->pdo, ucfirst($host));
        $this->pdo->prepare('UPDATE llm_providers SET base_url = ? WHERE id = ?')->execute(['https://' . $host . '.example.com/v1', $id]);

        return $id;
    }

    /** @param list<int> $providerIds */
    private function setChain(int $providerId, array $providerIds): void
    {
        $models = $this->module->models();
        $ids = array_map(fn (int $p): int => (int) $models->defaultFor($p), $providerIds);
        $this->pdo->prepare('UPDATE llm_models SET fallback_ids = ? WHERE id = ?')->execute([json_encode($ids), $models->defaultFor($providerId)]);
    }

    private static function ok(string $text, string $finish = 'stop'): HttpResponse
    {
        return new HttpResponse(200, json_encode(['model' => 'm', 'choices' => [['message' => ['content' => $text], 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]], JSON_UNESCAPED_UNICODE));
    }

    private function send(): array
    {
        return $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->primaryProvider, 'Привет', []);
    }

    private function callsTo(string $host): int
    {
        return count($this->transport->requestsTo($host . '.example.com'));
    }

    public function testEmptyContentWithHttp200IsAGenerationErrorAndBackupAnswers(): void
    {
        $this->script['primary.example.com'] = [self::ok(''), self::ok("   \n")];
        $result = $this->send();

        $this->assertSame('ответ backup.example.com', $result['assistant_message']['content']);
        $this->assertSame('Backup', $result['assistant_message']['provider']);
        $this->assertSame('openai/gpt-4o', $result['assistant_message']['fallback_from'], 'пользователь видит, что ответила резервная модель');
        $this->assertSame(2, $this->callsTo('primary'), 'пустой ответ: один повтор, затем резерв');
        $this->assertSame(0, $this->callsTo('forbidden'), 'модель без прав пользователя в цепочке пропускается');
        $stats = $this->module->modelHealth()->stats();
        $primaryModel = (int) $this->module->models()->defaultFor($this->primaryProvider);
        $this->assertSame(2, (int) $stats[$primaryModel]['empty_count']);
        $this->assertSame(1, (int) $stats[$primaryModel]['fallback_count']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM llm_messages WHERE role = 'assistant' AND content = ''")->fetchColumn() === 0 ? 1 : 0, 'пустой ответ не сохраняется');
    }

    public function testRateLimitHonoursRetryAfterThenFallsBackAndCoolsDown(): void
    {
        $this->script['primary.example.com'] = [
            new HttpResponse(429, '{"error":{"message":"slow down"}}', 0, ['retry-after' => '2']),
            new HttpResponse(429, '{"error":{"message":"slow down"}}', 0, ['retry-after' => '2']),
            new HttpResponse(429, '{"error":{"message":"slow down"}}', 0, ['retry-after' => '120']),
        ];
        $result = $this->send();
        $this->assertSame('Backup', $result['assistant_message']['provider']);
        $this->assertSame([2.0, 2.0], $this->sleeps, 'Retry-After учтён; длинное ожидание не держит запрос пользователя');
        $primaryModel = (int) $this->module->models()->defaultFor($this->primaryProvider);
        $this->assertGreaterThan(time() + 60, $this->module->modelHealth()->coolingUntil($primaryModel), 'модель на паузе по Retry-After');

        // Следующий запрос: модель на паузе уходит в конец цепочки — сразу отвечает резервная.
        $this->transport->requests = [];
        $again = $this->send();
        $this->assertSame('Backup', $again['assistant_message']['provider']);
        $this->assertSame(0, $this->callsTo('primary'));
    }

    /** @return iterable<string,array{0:list<HttpResponse|\Throwable>,1:string}> */
    public static function transientFailures(): iterable
    {
        yield '5xx' => [[new HttpResponse(502, 'bad gateway'), new HttpResponse(500, '{}'), new HttpResponse(503, '{}')], 'server_error'];
        yield 'timeout' => [[new HttpTransportException('timeout', 'curl errno=28')], 'transport_timeout'];
        yield 'connection dropped' => [[new HttpTransportException('closed', 'curl errno=56'), new HttpTransportException('closed', 'curl errno=18')], 'transport_closed'];
        yield 'malformed body' => [[new HttpResponse(200, '<html>oops'), new HttpResponse(200, '{"choices":null}')], 'invalid_response'];
        yield 'bad request' => [[new HttpResponse(400, '{"error":{"message":"weird"}}')], 'bad_request'];
    }

    /** @param list<HttpResponse|\Throwable> $script */
    #[\PHPUnit\Framework\Attributes\DataProvider('transientFailures')]
    public function testTransientFailuresSwitchToBackup(array $script, string $code): void
    {
        $this->script['primary.example.com'] = $script;
        $result = $this->send();
        $this->assertSame('Backup', $result['assistant_message']['provider']);
        $row = $this->pdo->query("SELECT code, action FROM llm_model_incidents WHERE action = 'fallback'")->fetch();
        $this->assertSame($code, $row['code']);
        $this->assertLessThanOrEqual(FailoverPolicy::MAX_RETRIES_PER_MODEL + 1, $this->callsTo('primary'));
    }

    public function testAllModelsDownGivesClearErrorAndSavesNothing(): void
    {
        $this->script['primary.example.com'] = array_fill(0, 10, new HttpResponse(503, '{}'));
        $this->script['backup.example.com'] = array_fill(0, 10, self::ok(''));
        try {
            $this->send();
            $this->fail('ожидался отказ');
        } catch (ChatException $e) {
            $this->assertSame('llm_all_failed', $e->errorCode);
            $this->assertStringContainsString('не отвечают', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM llm_messages')->fetchColumn());
        $this->assertLessThanOrEqual(FailoverPolicy::MAX_ATTEMPTS_TOTAL, count($this->transport->requestsTo('example.com')), 'число попыток ограничено');
        $this->assertGreaterThanOrEqual(2, (int) $this->pdo->query('SELECT COUNT(*) FROM llm_model_incidents')->fetchColumn(), 'журнал проблемных моделей');
        $this->assertSame(0, (int) $this->pdo->query("SELECT tokens FROM llm_usage")->fetchColumn(), 'отказ не тарифицируется');
    }

    public function testNoBackupConfiguredReturnsTheRealReason(): void
    {
        $this->setChain($this->primaryProvider, []);
        $this->script['primary.example.com'] = [self::ok(''), self::ok('')];
        try {
            $this->send();
            $this->fail('ожидался отказ');
        } catch (ChatException $e) {
            $this->assertSame('llm_empty_response', $e->errorCode);
        }
    }

    public function testContextOverflowGoesOnlyToALargerModel(): void
    {
        $models = $this->module->models();
        $this->pdo->prepare('UPDATE llm_models SET context_window = 8000 WHERE id = ?')->execute([$models->defaultFor($this->primaryProvider)]);
        $this->pdo->prepare('UPDATE llm_models SET context_window = 8000 WHERE id = ?')->execute([$models->defaultFor($this->backupProvider)]);
        $bigId = $models->create(['provider_id' => $this->backupProvider, 'model' => 'big-128k', 'display_name' => 'Большая', 'context_window' => 128000,
            'max_output_tokens' => 2048, 'is_enabled' => 1, 'fallback_ids' => []]);
        $this->pdo->prepare('UPDATE llm_models SET fallback_ids = ? WHERE id = ?')->execute([json_encode([$models->defaultFor($this->backupProvider), $bigId]), $models->defaultFor($this->primaryProvider)]);
        $this->script['primary.example.com'] = [new HttpResponse(400, '{"error":{"message":"This model\'s maximum context length is 8192 tokens"}}')];

        $result = $this->send();
        $body = json_decode((string) $this->transport->requestsTo('backup.example.com')[0]->body, true);
        $this->assertSame('big-128k', $body['model'], 'модель с тем же окном пропущена, выбрана с бо́льшим');
        $this->assertSame('Backup', $result['assistant_message']['provider']);
    }

    public function testUnsupportedParameterIsRetriedWithoutIt(): void
    {
        $this->pdo->prepare('UPDATE llm_models SET temperature = 0.3 WHERE id = ?')->execute([$this->module->models()->defaultFor($this->primaryProvider)]);
        $this->script['primary.example.com'] = [new HttpResponse(400, '{"error":{"message":"Unsupported parameter: temperature"}}')];
        $result = $this->send();
        $this->assertSame('Primary', $result['assistant_message']['provider']);
        $calls = $this->transport->requestsTo('primary.example.com');
        $this->assertSame(0.3, json_decode((string) $calls[0]->body, true)['temperature']);
        $this->assertArrayNotHasKey('temperature', json_decode((string) $calls[1]->body, true));
    }

    public function testAuthAndAccessErrorsAreClassifiedSeparately(): void
    {
        $this->assertFalse(FailoverPolicy::decide(new LlmException('access_denied'), 0, 1)['fallback'], 'нет прав — не сбой модели');
        $this->assertSame(300, FailoverPolicy::decide(new LlmException('auth_failed'), 0, 1)['cooldown']);
        $this->assertTrue(FailoverPolicy::decide(new LlmException('context_overflow'), 0, 1)['needsLargerContext']);
        $this->assertSame(240, FailoverPolicy::decide(new LlmException('rate_limited', '', 429), 5, 3)['cooldown'], 'нарастающая пауза 60·2ⁿ⁻¹');
        $this->assertSame(FailoverPolicy::COOLDOWN_CAP_SECONDS, FailoverPolicy::decide(new LlmException('rate_limited', '', 429), 5, 40)['cooldown']);
        $this->assertSame('billing', LlmException::fromHttpStatus(429, ['error' => ['code' => 'insufficient_quota', 'message' => 'x']], '')->errorCode);
        $this->assertSame('overloaded', LlmException::fromHttpStatus(529, null, 'overloaded')->errorCode);
        $this->assertSame(30, (new HttpResponse(429, '', 0, ['retry-after' => '30']))->retryAfter());
        $this->assertSame(10, (new HttpResponse(429, '', 0, ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', 1_000_010)]))->retryAfter(1_000_000));
    }

    public function testTruncatedAnswerIsAcceptedAndFlagged(): void
    {
        $this->script['primary.example.com'] = [self::ok('Начало ответа…', 'length')];
        $result = $this->send();
        $this->assertSame('Primary', $result['assistant_message']['provider']);
        $this->assertTrue($result['assistant_message']['truncated']);
        $this->assertNull($result['assistant_message']['fallback_from']);
    }

    public function testExplicitModelMustBelongToAllowedProvider(): void
    {
        $foreign = (int) $this->module->models()->defaultFor($this->forbiddenProvider);
        $this->expectException(ChatException::class);
        $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->primaryProvider, 'Привет', [], null, false, $foreign);
    }
}
