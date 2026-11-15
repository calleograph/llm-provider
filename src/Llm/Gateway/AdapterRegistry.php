<?php

declare(strict_types=1);

namespace App\Llm\Gateway;

use App\Llm\Gateway\Adapter\AnthropicAdapter;
use App\Llm\Gateway\Adapter\OpenAiAdapter;
use App\Llm\Gateway\Adapter\OpenAiCompatibleAdapter;

/**
 * Реестр поддерживаемых форматов LLM API. Чтобы подключить новый тип
 * (например, GigaChat или YandexGPT), достаточно реализовать LlmAdapter и
 * добавить его в withDefaults().
 */
final class AdapterRegistry
{
    /** @var array<string,LlmAdapter> */
    private array $adapters = [];

    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register(new OpenAiAdapter());
        $registry->register(new AnthropicAdapter());
        $registry->register(new OpenAiCompatibleAdapter());

        return $registry;
    }

    public function register(LlmAdapter $adapter): void
    {
        $this->adapters[$adapter->type()] = $adapter;
    }

    public function has(string $type): bool
    {
        return isset($this->adapters[$type]);
    }

    public function get(string $type): LlmAdapter
    {
        if (!isset($this->adapters[$type])) {
            throw new LlmException('unsupported_type', 'Тип провайдера не поддерживается: ' . $type);
        }

        return $this->adapters[$type];
    }

    /** @return array<string,LlmAdapter> */
    public function all(): array
    {
        return $this->adapters;
    }
}
