<?php

declare(strict_types=1);

namespace App\Tests;

use App\Certificate\CaBundleBuilder;
use App\Certificate\CertificateRepository;
use App\Config\AppSettings;
use App\Diagnostics\DiagnosticLogger;
use App\Llm\Anonymization\AnonymizedText;
use App\Llm\Chat\ChatException;
use App\Llm\Chat\ChatSettings;
use App\Llm\Chat\ChatUser;
use App\Llm\Chat\LimitCategoryRepository;
use App\Llm\Chat\UsageLimiter;
use App\Llm\Domain\DomainGroupRepository;
use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use App\Llm\LlmModule;
use App\Llm\Storage\CompactText;
use PHPUnit\Framework\TestCase;

/**
 * Интерфейс «как в ChatGPT» на backend: закрепление, поиск, проекты с
 * источниками, лимиты токенов на пользователя и экономичное хранение.
 */
final class LlmProjectsAndLimitsTest extends TestCase
{
    private \PDO $pdo;
    private FakeHttpTransport $transport;
    private LlmModule $module;
    private ChatUser $user;
    private int $providerId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно.');
        }
        $this->pdo = LlmTestKit::database();
        $domainId = LlmTestKit::createDomain($this->pdo, 'Corp', 'corp.local');
        $this->providerId = LlmTestKit::createProvider($this->pdo, 'GPT');
        $groupId = (new DomainGroupRepository($this->pdo))->create($domainId, 'Users', 'CN=U,DC=corp,DC=local', true, 't');

        $this->transport = new FakeHttpTransport(static function (HttpRequest $r): HttpResponse {
            if (str_contains($r->url, 'anon.local')) {
                $text = json_decode((string) $r->body, true)['text'];

                return new HttpResponse(200, json_encode(['anonymized_text' => str_replace('Иванов', '[PERSON]', $text)], JSON_UNESCAPED_UNICODE));
            }

            return new HttpResponse(200, json_encode([
                'choices' => [['message' => ['content' => 'Ответ'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 700, 'completion_tokens' => 300],
            ]));
        });
        $this->module = new LlmModule($this->pdo, new AppSettings($this->pdo), new CaBundleBuilder(new CertificateRepository($this->pdo)), new DiagnosticLogger($this->pdo), null, $this->transport);
        $this->module->access()->setGroupProviders($groupId, [$this->providerId]);
        $this->module->anonymizationSettings()->save(['profile' => 'generic', 'base_url' => 'https://anon.local', 'endpoint' => '/a', 'use_corporate_ca' => '0']);
        $this->user = new ChatUser('ivanov', $domainId, 'corp.local', [$groupId], ['Users']);
        $this->module->usage()->touchProfile($this->user);
    }

    private function send(string $text, ?int $conversationId = null, ?int $projectId = null): array
    {
        return $this->module->chatService()->send($this->user, '10.0.0.1', $conversationId, $this->providerId, $text, [], $projectId);
    }

    public function testCompactTextRoundTripAndSaving(): void
    {
        $long = str_repeat('Длинный обезличенный ответ модели с повторяющимся текстом. ', 200);
        [$text, $blob] = CompactText::encode($long);
        $this->assertSame('', $text);
        $this->assertLessThan(strlen($long) / 5, strlen((string) $blob));
        $this->assertSame($long, CompactText::decode($text, $blob));
        $this->assertSame(['коротко', null], CompactText::encode('коротко'));
        $this->assertSame($long, CompactText::unpack(CompactText::pack($long)));
    }

    public function testLongMessagesAreStoredCompressedAndReadBack(): void
    {
        $repo = $this->module->conversations();
        $id = $repo->create($this->user->ownerKey(), 'Т', $this->providerId);
        $long = str_repeat('[PERSON] обсуждает договор поставки. ', 300);
        $repo->addMessage($id, 'user', $long, $this->providerId, null, null, null);

        $row = $this->pdo->query('SELECT content, length(content_z) AS z FROM llm_messages')->fetch();
        $this->assertSame('', $row['content']);
        $this->assertGreaterThan(0, (int) $row['z']);
        $this->assertSame($long, $repo->messages($id, $this->user->ownerKey())[0]['content']);
    }

    public function testPinRenameMoveAndSearch(): void
    {
        $repo = $this->module->conversations();
        $owner = $this->user->ownerKey();
        $a = $repo->create($owner, 'Отчёт за квартал', $this->providerId);
        $b = $repo->create($owner, 'Письмо', $this->providerId);
        $repo->addMessage($b, 'user', str_repeat('текст ', 300) . 'уникальнаяфраза договора', $this->providerId, null, null, null);
        $projectId = $this->module->projects()->create($owner, 'Проект');

        $this->assertTrue($repo->setPinned($a, $owner, true));
        $this->assertTrue($repo->rename($b, $owner, 'Письмо поставщику'));
        $this->assertTrue($repo->moveToProject($b, $owner, $projectId));
        $this->assertFalse($repo->setPinned($a, 'petrov@corp.local', false), 'Чужой диалог не меняется');

        $list = array_column($repo->listForOwner($owner), null, 'id');
        $this->assertTrue($list[$a]['pinned']);
        $this->assertSame($projectId, $list[$b]['project_id']);

        $this->assertSame([$a], array_column($repo->search($owner, 'квартал'), 'id'));
        $found = $repo->search($owner, 'уникальнаяфраза');
        $this->assertSame([$b], array_column($found, 'id'), 'Поиск находит текст в сжатом сообщении');
        $this->assertStringContainsString('уникальнаяфраза', (string) $found[0]['snippet']);
        $this->assertSame([], $repo->search('petrov@corp.local', 'квартал'));
    }

    public function testProjectContextIsSentAsAnonymizedSystemPrompt(): void
    {
        $owner = $this->user->ownerKey();
        $projectId = $this->module->projects()->create($owner, 'Договоры');
        $service = $this->module->chatService();
        $this->assertSame('Отвечай про [PERSON]', $service->setProjectInstructions($this->user, 'ip', $projectId, 'Отвечай про Иванов'));
        $service->addProjectSource($this->user, 'ip', $projectId, 'Регламент', 'Ответственный — Иванов', null);

        $result = $this->send('Кто ответственный?', null, $projectId);

        $this->assertSame($projectId, $result['conversation']['project_id']);
        $llmBody = json_decode((string) $this->transport->requestsTo('polza.ai')[0]->body, true);
        $system = $llmBody['messages'][0];
        $this->assertSame('system', $system['role']);
        $this->assertStringContainsString('Отвечай про [PERSON]', $system['content']);
        $this->assertStringContainsString('[Источник 1: Регламент]', $system['content']);
        $this->assertStringContainsString('Ответственный — [PERSON]', $system['content']);
        $this->assertStringNotContainsString('Иванов', json_encode($llmBody, JSON_UNESCAPED_UNICODE));

        $raw = json_encode($this->pdo->query('SELECT * FROM llm_project_sources')->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertStringNotContainsString('Иванов', $raw);

        // Чужой пользователь не видит ни проекта, ни его контекста.
        $this->assertNull($this->module->projects()->findOwned($projectId, 'petrov@corp.local'));
        $this->assertNull($this->module->projects()->contextFor($projectId, 'petrov@corp.local'));
    }

    public function testSourceTextReturnsAnonymizedContentOnlyToOwner(): void
    {
        $owner = $this->user->ownerKey();
        $projectId = $this->module->projects()->create($owner, 'Договоры');
        $service = $this->module->chatService();
        $source = $service->addProjectSource($this->user, 'ip', $projectId, 'Регламент', 'Ответственный — Иванов', null);

        $text = $this->module->projects()->sourceText($source['id'], $owner);
        $this->assertSame('Ответственный — [PERSON]', $text);
        $this->assertStringNotContainsString('Иванов', (string) $text);

        // Чужой владелец не может прочитать текст источника чужого проекта.
        $this->assertNull($this->module->projects()->sourceText($source['id'], 'petrov@corp.local'));
        // Несуществующий источник — тоже null, не исключение.
        $this->assertNull($this->module->projects()->sourceText(999999, $owner));
    }

    public function testProjectSourceCountLimitBlocksBeforeProcessingFile(): void
    {
        $owner = $this->user->ownerKey();
        $projectId = $this->module->projects()->create($owner, 'Договоры');
        $this->module->chatSettings()->save(['project_sources_max_files' => '2']);
        $service = $this->module->chatService();

        $service->addProjectSource($this->user, 'ip', $projectId, 'Первый', 'Текст один', null);
        $service->addProjectSource($this->user, 'ip', $projectId, 'Второй', 'Текст два', null);

        try {
            $service->addProjectSource($this->user, 'ip', $projectId, 'Третий', 'Текст три', null);
            $this->fail('Ожидался отказ по лимиту числа источников');
        } catch (ChatException $e) {
            $this->assertSame('sources_limit', $e->errorCode);
        }
        $this->assertSame(2, $this->module->projects()->sourceCount($projectId));
    }

    public function testProjectSourceCharsLimitBlocksTooLongSource(): void
    {
        $owner = $this->user->ownerKey();
        $projectId = $this->module->projects()->create($owner, 'Договоры');
        $this->module->chatSettings()->save(['project_sources_max_chars' => '1000']);
        $service = $this->module->chatService();

        try {
            $service->addProjectSource($this->user, 'ip', $projectId, 'Большой', str_repeat('текст ', 500), null);
            $this->fail('Ожидался отказ по лимиту суммарного объёма источников');
        } catch (ChatException $e) {
            $this->assertSame('sources_limit', $e->errorCode);
        }
    }

    public function testProjectInstructionBlocksAreAnonymizedAndSeparateFromSources(): void
    {
        $owner = $this->user->ownerKey();
        $projectId = $this->module->projects()->create($owner, 'Договоры');
        $service = $this->module->chatService();

        $instruction = $service->addProjectInstruction($this->user, 'ip', $projectId, 'Стиль', 'Обращайся к Иванов на "вы"');
        $service->addProjectSource($this->user, 'ip', $projectId, 'Регламент', 'Ответственный — Иванов', null);

        // Инструкция и источник хранятся раздельно — не путаются в списках.
        $this->assertSame(1, $this->module->projects()->instructionCount($projectId));
        $this->assertSame(1, $this->module->projects()->sourceCount($projectId));
        $this->assertCount(1, $this->module->projects()->instructions($projectId, $owner));
        $this->assertCount(1, $this->module->projects()->sources($projectId, $owner));

        $result = $this->send('Кто ответственный?', null, $projectId);
        $llmBody = json_decode((string) $this->transport->requestsTo('polza.ai')[0]->body, true);
        $system = $llmBody['messages'][0]['content'];

        // Блок инструкции идёт без нумерации "Источник N", источник — с нумерацией.
        $this->assertStringContainsString('[Инструкция: Стиль]', $system);
        $this->assertStringContainsString('Обращайся к [PERSON] на "вы"', $system);
        $this->assertStringContainsString('[Источник 1: Регламент]', $system);
        $this->assertStringNotContainsString('Иванов', json_encode($llmBody, JSON_UNESCAPED_UNICODE));

        // Текст блока инструкции читается тем же sourceText(), что и у источников.
        $this->assertSame('Обращайся к [PERSON] на "вы"', $this->module->projects()->sourceText($instruction['id'], $owner));
    }

    public function testProjectInstructionLimitsAreIndependentFromSourceLimits(): void
    {
        $owner = $this->user->ownerKey();
        $projectId = $this->module->projects()->create($owner, 'Договоры');
        $this->module->chatSettings()->save(['project_instructions_max_blocks' => '1', 'project_instruction_max_chars' => '20']);
        $service = $this->module->chatService();

        $service->addProjectInstruction($this->user, 'ip', $projectId, 'Первая', 'Короткий текст');

        try {
            $service->addProjectInstruction($this->user, 'ip', $projectId, 'Вторая', 'Ещё текст');
            $this->fail('Ожидался отказ по лимиту числа блоков инструкций');
        } catch (ChatException $e) {
            $this->assertSame('instructions_limit', $e->errorCode);
        }

        $this->module->chatSettings()->save(['project_instructions_max_blocks' => '5']);
        try {
            $service->addProjectInstruction($this->user, 'ip', $projectId, 'Длинная', str_repeat('текст ', 20));
            $this->fail('Ожидался отказ по лимиту длины одного блока');
        } catch (ChatException $e) {
            $this->assertSame('too_long', $e->errorCode);
        }
    }

    public function testDeletingProjectKeepsChats(): void
    {
        $owner = $this->user->ownerKey();
        $projectId = $this->module->projects()->create($owner, 'P');
        $chat = $this->send('Привет', null, $projectId);

        $this->assertTrue($this->module->projects()->delete($projectId, $owner));
        $conversation = $this->module->conversations()->findOwned($chat['conversation']['id'], $owner);
        $this->assertNotNull($conversation);
        $this->assertNull($conversation['project_id']);
    }

    public function testUsageIsRecordedAndDailyLimitBlocksBeforeAnyRequest(): void
    {
        $categories = $this->module->limitCategories();
        $default = $categories->defaultCategory();
        $categories->update($default['id'], $default['name'], 'day', 1500, $default['storage_mb'], true);

        $first = $this->send('Первый');
        $this->assertSame(1000, $first['usage']['used']);
        $this->assertSame(1500, $first['usage']['limit']);
        $this->send('Второй'); // 1000 < 1500 — ещё разрешено, после него 2000

        $requestsBefore = count($this->transport->requests);
        try {
            $this->send('Третий');
            $this->fail('Ожидался отказ по лимиту');
        } catch (ChatException $e) {
            $this->assertSame('limit_exceeded', $e->errorCode);
            $this->assertSame(429, $e->httpStatus);
        }
        $this->assertCount($requestsBefore, $this->transport->requests, 'При исчерпанном лимите ничего не отправляется');

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM llm_usage')->fetchColumn(), 'Одна строка на пользователя в день');
    }

    public function testIndividualLimitOverridesDefaultAndBlockWorks(): void
    {
        $usage = $this->module->usage();
        $owner = $this->user->ownerKey();
        $categories = $this->module->limitCategories();
        $default = $categories->defaultCategory();
        $categories->update($default['id'], $default['name'], 'day', 10, $default['storage_mb'], true);

        $usage->setIndividualLimit($owner, UsageLimiter::PERIOD_NONE, null);
        $this->assertNull($usage->status($owner)['limit']);
        $this->send('Можно — индивидуально без лимита');

        $usage->setIndividualLimit($owner, 'month', 5000);
        $status = $usage->status($owner);
        $this->assertSame(['month', 5000, true], [$status['period'], $status['limit'], $status['individual']]);

        $usage->setBlocked($owner, true);
        $this->expectException(ChatException::class);
        $this->send('Заблокирован');
    }

    public function testPeriodBounds(): void
    {
        $limiter = new UsageLimiter($this->pdo, new ChatSettings(new AppSettings($this->pdo)), new LimitCategoryRepository($this->pdo), new DomainGroupRepository($this->pdo));
        [$weekStart, $weekReset] = $limiter->periodBounds('week');
        $this->assertSame('1', (new \DateTimeImmutable($weekStart))->format('N'), 'Неделя начинается с понедельника');
        $this->assertSame(7, (int) (new \DateTimeImmutable($weekStart))->diff(new \DateTimeImmutable($weekReset))->days);
        [$monthStart] = $limiter->periodBounds('month');
        $this->assertStringEndsWith('-01', $monthStart);
    }

    public function testRetentionDeletesOnlyOldUnpinned(): void
    {
        $repo = $this->module->conversations();
        $owner = $this->user->ownerKey();
        $old = $repo->create($owner, 'Старый', $this->providerId);
        $pinned = $repo->create($owner, 'Закреплённый', $this->providerId);
        $repo->setPinned($pinned, $owner, true);
        $this->pdo->exec("UPDATE llm_conversations SET updated_at = '2020-01-01T00:00:00+00:00'");

        $this->assertSame(1, $repo->deleteOlderThan($owner, 30));
        $this->assertNull($repo->findOwned($old, $owner));
        $this->assertNotNull($repo->findOwned($pinned, $owner));
    }

    public function testGatewayContextMustBeAnonymizedText(): void
    {
        $this->send('Разогрев');
        $result = $this->module->gateway()->complete($this->user, $this->providerId, [['role' => 'user', 'content' => AnonymizedText::fromStorage('x')]], 'S', AnonymizedText::fromStorage('Контекст'));
        $this->assertSame('Ответ', $result->text);
        $body = json_decode((string) end($this->transport->requests)->body, true);
        $this->assertSame("S\n\nКонтекст", $body['messages'][0]['content']);
    }
}
