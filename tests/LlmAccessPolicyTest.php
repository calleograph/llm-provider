<?php

declare(strict_types=1);

namespace App\Tests;

use App\Llm\Access\AccessPolicy;
use App\Llm\Chat\ChatUser;
use App\Llm\Chat\ConversationRepository;
use App\Llm\Domain\DomainGroupRepository;
use App\Llm\Domain\LlmDomainRepository;
use App\Llm\Gateway\LlmException;
use App\Llm\Provider\ProviderRepository;
use PHPUnit\Framework\TestCase;

/**
 * Права «группа → нейросети»: объединение по всем группам пользователя,
 * учёт статусов группы/домена/нейросети, отсутствие доступа к чужим
 * провайдерам и чужим диалогам.
 */
final class LlmAccessPolicyTest extends TestCase
{
    private \PDO $pdo;
    private AccessPolicy $policy;
    private DomainGroupRepository $groups;
    private int $domainId;
    private int $openAi;
    private int $anthropic;
    private int $compatible;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite недоступно.');
        }
        $this->pdo = LlmTestKit::database();
        $this->policy = new AccessPolicy($this->pdo);
        $this->groups = new DomainGroupRepository($this->pdo);
        $this->domainId = LlmTestKit::createDomain($this->pdo, 'Corp', 'corp.local');
        $this->openAi = LlmTestKit::createProvider($this->pdo, 'OpenAI', 'openai');
        $this->anthropic = LlmTestKit::createProvider($this->pdo, 'Anthropic', 'anthropic');
        $this->compatible = LlmTestKit::createProvider($this->pdo, 'Polza', 'openai_compatible');
    }

    private function group(string $name, array $providers, bool $enabled = true, ?int $domainId = null): int
    {
        $id = $this->groups->create($domainId ?? $this->domainId, $name, 'CN=' . $name . ',DC=corp,DC=local', $enabled, 'test');
        $this->policy->setGroupProviders($id, $providers);

        return $id;
    }

    private function user(array $groupIds, ?int $domainId = null): ChatUser
    {
        return new ChatUser('ivanov', $domainId ?? $this->domainId, 'corp.local', $groupIds, []);
    }

    public function testSingleGroupSeesOnlyItsProviders(): void
    {
        $g = $this->group('OnlyOpenAI', [$this->openAi]);

        $this->assertSame([$this->openAi], array_keys($this->policy->allowedProviders([$g], $this->domainId)));
        $this->assertFalse($this->policy->isAllowed($this->user([$g]), $this->anthropic));
    }

    public function testUnionAcrossSeveralGroups(): void
    {
        $g1 = $this->group('A', [$this->openAi]);
        $g2 = $this->group('B', [$this->anthropic, $this->openAi]);

        $allowed = array_keys($this->policy->allowedProviders([$g1, $g2], $this->domainId));
        sort($allowed);
        $expected = [$this->openAi, $this->anthropic];
        sort($expected);

        $this->assertSame($expected, $allowed);
        $this->assertFalse($this->policy->isAllowed($this->user([$g1, $g2]), $this->compatible));
    }

    public function testDisabledGroupProviderOrDomainGrantNothing(): void
    {
        $disabledGroup = $this->group('Off', [$this->compatible], false);
        $this->assertSame([], $this->policy->allowedProviders([$disabledGroup], $this->domainId));

        $g = $this->group('On', [$this->openAi]);
        (new ProviderRepository($this->pdo))->setEnabled($this->openAi, false);
        $this->assertSame([], $this->policy->allowedProviders([$g], $this->domainId));

        (new ProviderRepository($this->pdo))->setEnabled($this->openAi, true);
        (new LlmDomainRepository($this->pdo))->saveLlmSettings($this->domainId, false, '', null, false);
        $this->assertSame([], $this->policy->allowedProviders([$g], $this->domainId));
    }

    public function testGroupOfAnotherDomainIsIgnored(): void
    {
        $otherDomain = LlmTestKit::createDomain($this->pdo, 'Other', 'other.local');
        $foreignGroup = $this->group('Foreign', [$this->anthropic], true, $otherDomain);

        // Подмена group_id чужого домена в сессии не даёт прав.
        $this->assertSame([], $this->policy->allowedProviders([$foreignGroup], $this->domainId));
    }

    public function testAssertAllowedThrowsForTamperedProviderId(): void
    {
        $g = $this->group('A', [$this->openAi]);

        $this->expectException(LlmException::class);
        $this->policy->assertAllowed($this->user([$g]), $this->anthropic);
    }

    public function testSetGroupProvidersReportsDiffAndIgnoresUnknownIds(): void
    {
        $g = $this->group('A', [$this->openAi]);
        $diff = $this->policy->setGroupProviders($g, [$this->anthropic, 99999]);

        $this->assertSame([$this->anthropic], $diff['added']);
        $this->assertSame([$this->openAi], $diff['removed']);
        $this->assertSame([$this->anthropic], $this->policy->providersForGroup($g));
    }

    public function testDeletingProviderRemovesPermissions(): void
    {
        $g = $this->group('A', [$this->openAi, $this->anthropic]);
        (new ProviderRepository($this->pdo))->delete($this->openAi);

        $this->assertSame([$this->anthropic], $this->policy->providersForGroup($g));
    }

    public function testConversationsAreIsolatedByOwner(): void
    {
        $repo = new ConversationRepository($this->pdo);
        $mine = $repo->create('ivanov@corp.local', 'Мой', $this->openAi);
        $repo->addMessage($mine, 'user', '[PERSON_1] вопрос', $this->openAi, null, null, null);

        $this->assertNull($repo->findOwned($mine, 'petrov@corp.local'));
        $this->assertSame([], $repo->messages($mine, 'petrov@corp.local'));
        $this->assertFalse($repo->delete($mine, 'petrov@corp.local'));
        $this->assertSame([], $repo->listForOwner('petrov@corp.local'));

        $this->assertCount(1, $repo->messages($mine, 'ivanov@corp.local'));
        $this->assertTrue($repo->delete($mine, 'ivanov@corp.local'));
    }
}
