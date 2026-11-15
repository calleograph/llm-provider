<?php

declare(strict_types=1);

namespace App\Tests;

use App\Certificate\CaBundleBuilder;
use App\Certificate\CertificateRepository;
use App\Config\AppSettings;
use App\Diagnostics\DiagnosticLogger;
use App\Llm\Anonymization\AnonymizedText;
use App\Llm\Chat\ChatException;
use App\Llm\Chat\ChatUser;
use App\Llm\Domain\DomainGroupRepository;
use App\Llm\Gateway\LlmException;
use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use App\Llm\LlmModule;
use PHPUnit\Framework\TestCase;

/**
 * Сквозная цепочка чата с фейковыми внешними сервисами:
 * пользователь → обезличивание → LLM → история.
 */
final class LlmChatServiceTest extends TestCase
{
    private const RAW = 'Подготовь письмо для Иванов Иван Иванович, паспорт 4510 123456';
    private const ANON = 'Подготовь письмо для [PERSON_1], паспорт [PASSPORT_1]';

    private \PDO $pdo;
    private FakeHttpTransport $transport;
    private LlmModule $module;
    private ChatUser $user;
    private int $providerId;
    private int $forbiddenProviderId;
    private int $groupId;
    private bool $anonymizerDown = false;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно.');
        }
        $this->pdo = LlmTestKit::database();
        $appSettings = new AppSettings($this->pdo);

        $domainId = LlmTestKit::createDomain($this->pdo, 'Corp', 'corp.local');
        $this->providerId = LlmTestKit::createProvider($this->pdo, 'Polza GPT-4o');
        $this->forbiddenProviderId = LlmTestKit::createProvider($this->pdo, 'Anthropic', 'anthropic');
        $groupId = (new DomainGroupRepository($this->pdo))->create($domainId, 'LLM Users', 'CN=LLM-Users,DC=corp,DC=local', true, 'test');
        $this->groupId = $groupId;

        $this->transport = new FakeHttpTransport(function (HttpRequest $r): HttpResponse {
            if (str_contains($r->url, 'anon.corp.local')) {
                if ($this->anonymizerDown) {
                    return new HttpResponse(503, '{}');
                }
                $text = json_decode((string) $r->body, true)['text'];
                $text = str_replace(['Иванов Иван Иванович', '4510 123456'], ['[PERSON_1]', '[PASSPORT_1]'], $text);

                return new HttpResponse(200, json_encode(['anonymized_text' => $text], JSON_UNESCAPED_UNICODE));
            }

            return new HttpResponse(200, json_encode([
                'model' => 'openai/gpt-4o',
                'choices' => [['message' => ['content' => 'Уважаемый [PERSON_1], …'], 'finish_reason' => 'stop']],
            ], JSON_UNESCAPED_UNICODE));
        });

        $this->module = new LlmModule(
            $this->pdo,
            $appSettings,
            new CaBundleBuilder(new CertificateRepository($this->pdo)),
            new DiagnosticLogger($this->pdo),
            null,
            $this->transport,
        );
        $this->module->access()->setGroupProviders($groupId, [$this->providerId]);
        $this->module->anonymizationSettings()->save([
            'profile' => 'generic', 'base_url' => 'https://anon.corp.local', 'endpoint' => '/anonymize', 'use_corporate_ca' => '0',
        ]);

        $this->user = new ChatUser('ivanov', $domainId, 'corp.local', [$groupId], ['LLM Users']);
    }

    private function allStoredText(): string
    {
        $dump = '';
        foreach (['llm_messages', 'llm_conversations', 'app_log', 'audit_log', 'diagnostic_log', 'app_settings'] as $table) {
            $dump .= json_encode($this->pdo->query("SELECT * FROM {$table}")->fetchAll(), JSON_UNESCAPED_UNICODE);
        }

        return $dump;
    }

    public function testOnlyAnonymizedTextLeavesAndIsStored(): void
    {
        $result = $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, self::RAW, []);

        $llmRequests = $this->transport->requestsTo('polza.ai');
        $this->assertCount(1, $llmRequests);
        $body = (string) $llmRequests[0]->body;
        $this->assertStringContainsString('[PERSON_1]', $body);
        $this->assertStringNotContainsString('Иванов', $body);
        $this->assertStringNotContainsString('4510', $body);

        $this->assertSame(self::ANON, $result['user_message']['content']);
        $this->assertSame('Уважаемый [PERSON_1], …', $result['assistant_message']['content']);

        $stored = $this->allStoredText();
        $this->assertStringNotContainsString('Иванов', $stored, 'Исходный текст не должен сохраняться ни в истории, ни в журналах');
        $this->assertStringNotContainsString('4510', $stored);
        $this->assertStringNotContainsString('sk-test-key', $stored, 'API-ключ хранится только зашифрованным');

        $events = array_column($this->pdo->query('SELECT result FROM app_log')->fetchAll(), 'result');
        $joined = implode("\n", $events);
        foreach (['LLM_PROVIDER_SELECT_SUCCESS', 'ANONYMIZATION_SUCCESS', 'LLM_REQUEST_SUCCESS', 'CONVERSATION_CREATE_SUCCESS'] as $event) {
            $this->assertStringContainsString($event, $joined);
        }
    }

    public function testFollowUpSendsAnonymizedHistory(): void
    {
        $first = $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, self::RAW, []);
        $this->module->chatService()->send($this->user, '10.0.0.1', $first['conversation']['id'], $this->providerId, 'Сделай короче', []);

        $llmRequests = $this->transport->requestsTo('polza.ai');
        $messages = json_decode((string) $llmRequests[1]->body, true)['messages'];
        $contents = array_column($messages, 'content');

        $this->assertContains(self::ANON, $contents);
        $this->assertContains('Уважаемый [PERSON_1], …', $contents);
        $this->assertSame('Сделай короче', end($contents));
        $this->assertStringNotContainsString('Иванов', (string) $llmRequests[1]->body);
    }

    public function testAnonymizationFailureBlocksExternalRequest(): void
    {
        $this->anonymizerDown = true;

        try {
            $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, self::RAW, []);
            $this->fail('Ожидалась блокировка');
        } catch (ChatException $e) {
            $this->assertSame('anonymization_failed', $e->errorCode);
        }

        $this->assertSame([], $this->transport->requestsTo('polza.ai'), 'При ошибке обезличивания запрос наружу не отправляется');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM llm_conversations')->fetchColumn());
        $this->assertStringContainsString('ANONYMIZATION_FAILED', $this->allStoredText());
        $this->assertStringNotContainsString('Иванов', $this->allStoredText());
    }

    public function testNotConfiguredAnonymizationBlocksEverything(): void
    {
        $this->module->anonymizationSettings()->save(['base_url' => '']);

        $this->expectException(ChatException::class);
        try {
            $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, 'Привет', []);
        } finally {
            $this->assertSame([], $this->transport->requests);
        }
    }

    public function testTamperedProviderIdIsRejectedBeforeAnyRequest(): void
    {
        try {
            $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->forbiddenProviderId, self::RAW, []);
            $this->fail('Ожидался отказ');
        } catch (ChatException $e) {
            $this->assertSame('access_denied', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
        }
        $this->assertSame([], $this->transport->requests);
    }

    public function testForeignConversationIsNotAccessible(): void
    {
        $first = $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, 'Привет', []);
        $stranger = new ChatUser('petrov', $this->user->domainId, 'corp.local', $this->user->groupIds, []);

        $this->expectException(ChatException::class);
        $this->module->chatService()->send($stranger, '10.0.0.2', $first['conversation']['id'], $this->providerId, 'Покажи историю', []);
    }

    public function testGatewayRechecksPermissions(): void
    {
        $this->expectException(LlmException::class);
        $this->module->gateway()->complete(
            $this->user,
            $this->forbiddenProviderId,
            [['role' => 'user', 'content' => AnonymizedText::fromStorage('x')]],
        );
    }

    public function testSkipAnonymizationDeniedWithoutGroupPermission(): void
    {
        try {
            $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, self::RAW, [], null, true);
            $this->fail('Ожидался отказ без права allow_unanonymized');
        } catch (ChatException $e) {
            $this->assertSame('access_denied', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
        }
        $this->assertSame([], $this->transport->requests, 'При отказе в праве запрос наружу не отправляется');

        $events = array_column($this->pdo->query('SELECT result FROM app_log')->fetchAll(), 'result');
        $this->assertStringContainsString('LLM_UNANONYMIZED_DENIED', implode("\n", $events));
    }

    public function testSkipAnonymizationSendsRawTextWhenPermitted(): void
    {
        (new DomainGroupRepository($this->pdo))->setAllowUnanonymized($this->groupId, true);

        $result = $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, self::RAW, [], null, true);

        // Обезличиватель не вызывался вообще — исходный текст ушёл напрямую в LLM.
        $this->assertSame([], $this->transport->requestsTo('anon.corp.local'));
        $llmRequests = $this->transport->requestsTo('polza.ai');
        $this->assertCount(1, $llmRequests);
        $this->assertStringContainsString('Иванов Иван Иванович', (string) $llmRequests[0]->body);
        $this->assertStringContainsString('4510', (string) $llmRequests[0]->body);

        $this->assertSame(self::RAW, $result['user_message']['content']);
        $this->assertTrue($result['user_message']['unanonymized']);

        // В истории сообщение действительно хранится в открытом виде.
        $stored = (string) $this->pdo->query('SELECT content FROM llm_messages WHERE role = "user"')->fetchColumn();
        $this->assertSame(self::RAW, $stored);
        $unanonymizedFlag = (int) $this->pdo->query('SELECT unanonymized FROM llm_messages WHERE role = "user"')->fetchColumn();
        $this->assertSame(1, $unanonymizedFlag);

        $events = array_column($this->pdo->query('SELECT result FROM app_log')->fetchAll(), 'result');
        $this->assertStringContainsString('LLM_SEND_UNANONYMIZED', implode("\n", $events));
    }

    public function testSkipAnonymizationFalseStillAnonymizesEvenWithPermission(): void
    {
        (new DomainGroupRepository($this->pdo))->setAllowUnanonymized($this->groupId, true);

        $result = $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, self::RAW, [], null, false);

        $this->assertSame(self::ANON, $result['user_message']['content']);
        $this->assertFalse($result['user_message']['unanonymized']);
        $this->assertStringNotContainsString('Иванов', $this->allStoredText());
    }

    public function testSkipAnonymizationPermittedByPersonalGrantWithoutGroupRight(): void
    {
        // Групповое право НЕ выдано — только персональное (llm_users.allow_unanonymized).
        $this->module->usage()->setAllowUnanonymized($this->user->ownerKey(), true);

        $result = $this->module->chatService()->send($this->user, '10.0.0.1', null, $this->providerId, self::RAW, [], null, true);

        $this->assertSame(self::RAW, $result['user_message']['content']);
        $this->assertTrue($result['user_message']['unanonymized']);
        $this->assertStringContainsString('Иванов Иван Иванович', (string) $this->transport->requestsTo('polza.ai')[0]->body);
    }
}
