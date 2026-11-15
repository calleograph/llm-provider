<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\Database;
use App\Config\LdapConfigRepository;
use App\Llm\Domain\LlmDomainRepository;
use App\Llm\Provider\ProviderRepository;
use PDO;

/**
 * Общие заготовки для тестов LLM-модуля: SQLite в памяти со схемой,
 * созданной настоящей миграцией проекта (Database::migrate + LlmSchema).
 */
final class LlmTestKit
{
    public static function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        (new \ReflectionMethod(Database::class, 'migrate'))->invoke(null, $pdo);

        return $pdo;
    }

    public static function createDomain(PDO $pdo, string $name, string $fqdn, bool $llmEnabled = true): int
    {
        $id = (new LdapConfigRepository($pdo))->create([
            'name' => $name,
            'ldaps_url' => 'ldaps://dc.' . $fqdn . ':636/',
            'host' => 'dc.' . $fqdn,
            'port' => 636,
            'base_dn' => 'DC=' . str_replace('.', ',DC=', $fqdn),
            'domain' => $fqdn,
            'netbios_domain' => strtoupper(explode('.', $fqdn)[0]),
            'admin_group_dn' => '',
            'protocol_version' => 3,
            'starttls' => false,
            'network_timeout' => 5,
            'ca_bundle_file' => null,
        ]);
        (new LlmDomainRepository($pdo))->saveLlmSettings($id, $llmEnabled, '', null, false);

        return $id;
    }

    public static function createProvider(PDO $pdo, string $name, string $type = 'openai_compatible', bool $enabled = true, string $apiKey = 'sk-test-key-123456'): int
    {
        $defaults = [
            'openai' => ['https://api.openai.com/v1', '/chat/completions', ''],
            'anthropic' => ['https://api.anthropic.com', '/v1/messages', '2023-06-01'],
            'openai_compatible' => ['https://polza.ai/api/v1', '/chat/completions', ''],
        ][$type];

        return (new ProviderRepository($pdo))->create([
            'name' => $name,
            'provider_type' => $type,
            'base_url' => $defaults[0],
            'endpoint' => $defaults[1],
            'model' => $type === 'anthropic' ? 'claude-sonnet-5' : 'openai/gpt-4o',
            'api_version' => $defaults[2],
            'connect_timeout' => 5,
            'response_timeout' => 30,
            'max_tokens' => 512,
            'routing_provider' => '',
            'sort_by_price' => false,
            'max_price_per_mtoken' => '',
            'allow_fallbacks' => true,
            'use_corporate_ca' => false,
            'is_enabled' => $enabled,
        ], $apiKey, null);
    }
}
