<?php

declare(strict_types=1);

namespace App\Llm;

use App\Audit\AppLogger;
use App\Audit\AuditLogger;
use App\Certificate\CaBundleBuilder;
use App\Config\AppSettings;
use App\Diagnostics\DiagnosticLogger;
use App\Llm\Access\AccessPolicy;
use App\Llm\Access\UserProviderRepository;
use App\Llm\Anonymization\AnonymizationGateway;
use App\Llm\Anonymization\AnonymizationSettings;
use App\Llm\Chat\ChatService;
use App\Llm\Chat\ChatSettings;
use App\Llm\Chat\ConversationRepository;
use App\Llm\Chat\LimitCategoryRepository;
use App\Llm\Chat\InactivityCleanupService;
use App\Llm\Chat\ProjectRepository;
use App\Llm\Chat\StorageQuotaService;
use App\Llm\Chat\UsageLimiter;
use App\Llm\Domain\ChatAuthenticator;
use App\Llm\Domain\DomainGroupRepository;
use App\Llm\Domain\LlmDomainRepository;
use App\Llm\Files\FileProcessingSettings;
use App\Llm\Files\FileProcessor;
use App\Llm\Gateway\AdapterRegistry;
use App\Llm\Gateway\LlmGateway;
use App\Llm\Http\CurlHttpTransport;
use App\Llm\Http\HttpTransport;
use App\Llm\Provider\ProviderRepository;
use PDO;

/**
 * Сборка компонентов LLM-модуля поверх ОБЩИХ сервисов проекта: PDO/SQLite,
 * AppSettings, CaBundleBuilder (сертификаты), AppLogger/AuditLogger/
 * DiagnosticLogger (журналы). Отдельного хранилища, конфигурации или
 * системы логирования у модуля нет.
 */
final class LlmModule
{
    private array $instances = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AppSettings $appSettings,
        private readonly CaBundleBuilder $caBundleBuilder,
        private readonly DiagnosticLogger $diagnosticLogger,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?HttpTransport $transport = null,
    ) {
    }

    public function domains(): LlmDomainRepository
    {
        return $this->instances[__FUNCTION__] ??= new LlmDomainRepository($this->pdo);
    }

    public function groups(): DomainGroupRepository
    {
        return $this->instances[__FUNCTION__] ??= new DomainGroupRepository($this->pdo);
    }

    public function providers(): ProviderRepository
    {
        return $this->instances[__FUNCTION__] ??= new ProviderRepository($this->pdo);
    }

    public function access(): AccessPolicy
    {
        return $this->instances[__FUNCTION__] ??= new AccessPolicy($this->pdo, $this->userProviders());
    }

    public function userProviders(): UserProviderRepository
    {
        return $this->instances[__FUNCTION__] ??= new UserProviderRepository($this->pdo);
    }

    public function limitCategories(): LimitCategoryRepository
    {
        return $this->instances[__FUNCTION__] ??= new LimitCategoryRepository($this->pdo);
    }

    public function adapters(): AdapterRegistry
    {
        return $this->instances[__FUNCTION__] ??= AdapterRegistry::withDefaults();
    }

    public function anonymizationSettings(): AnonymizationSettings
    {
        return $this->instances[__FUNCTION__] ??= new AnonymizationSettings($this->appSettings);
    }

    public function anonymizer(): AnonymizationGateway
    {
        return $this->instances[__FUNCTION__] ??= new AnonymizationGateway(
            $this->anonymizationSettings(),
            $this->transport(),
            $this->caBundleResolver(),
            $this->diagnosticLogger,
        );
    }

    public function gateway(): LlmGateway
    {
        return $this->instances[__FUNCTION__] ??= new LlmGateway(
            $this->providers(),
            $this->access(),
            $this->adapters(),
            $this->transport(),
            $this->caBundleResolver(),
            $this->diagnosticLogger,
        );
    }

    public function fileSettings(): FileProcessingSettings
    {
        return $this->instances[__FUNCTION__] ??= new FileProcessingSettings($this->appSettings);
    }

    public function fileProcessor(): FileProcessor
    {
        return $this->instances[__FUNCTION__] ??= FileProcessor::withDefaults($this->fileSettings());
    }

    public function chatSettings(): ChatSettings
    {
        return $this->instances[__FUNCTION__] ??= new ChatSettings($this->appSettings);
    }

    public function conversations(): ConversationRepository
    {
        return $this->instances[__FUNCTION__] ??= new ConversationRepository($this->pdo);
    }

    public function projects(): ProjectRepository
    {
        return $this->instances[__FUNCTION__] ??= new ProjectRepository($this->pdo);
    }

    public function usage(): UsageLimiter
    {
        return $this->instances[__FUNCTION__] ??= new UsageLimiter($this->pdo, $this->chatSettings(), $this->limitCategories(), $this->groups());
    }

    public function storageQuota(): StorageQuotaService
    {
        return $this->instances[__FUNCTION__] ??= new StorageQuotaService($this->pdo, $this->limitCategories(), $this->groups());
    }

    public function inactivityCleanup(): InactivityCleanupService
    {
        return $this->instances[__FUNCTION__] ??= new InactivityCleanupService($this->pdo);
    }

    public function events(): LlmEventLog
    {
        return $this->instances[__FUNCTION__] ??= new LlmEventLog(new AppLogger($this->pdo), $this->auditLogger);
    }

    public function authenticator(): ChatAuthenticator
    {
        return $this->instances[__FUNCTION__] ??= new ChatAuthenticator(
            $this->groups(),
            $this->domains(),
            $this->caBundleBuilder,
            $this->diagnosticLogger,
        );
    }

    public function chatService(): ChatService
    {
        return $this->instances[__FUNCTION__] ??= new ChatService(
            $this->access(),
            $this->conversations(),
            $this->anonymizer(),
            $this->gateway(),
            $this->fileProcessor(),
            $this->chatSettings(),
            $this->events(),
            $this->projects(),
            $this->usage(),
            $this->storageQuota(),
        );
    }

    public function backup(\App\Config\LdapConfigRepository $ldapConfigs): \App\Llm\Backup\ConfigBackupService
    {
        return $this->instances[__FUNCTION__] ??= new \App\Llm\Backup\ConfigBackupService(
            $this->pdo,
            $ldapConfigs,
            $this->domains(),
            $this->groups(),
            $this->providers(),
            $this->access(),
            $this->chatSettings(),
            $this->adapters(),
        );
    }

    private function transport(): HttpTransport
    {
        return $this->instances[__FUNCTION__] ??= ($this->transport ?? new CurlHttpTransport());
    }

    /** @return \Closure():string */
    private function caBundleResolver(): \Closure
    {
        $builder = $this->caBundleBuilder;

        return static fn (): string => $builder->currentBundlePath();
    }
}
