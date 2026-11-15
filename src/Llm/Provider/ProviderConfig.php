<?php

declare(strict_types=1);

namespace App\Llm\Provider;

/**
 * Неизменяемый снимок подключения к LLM-провайдеру для LLM Gateway.
 * Содержит расшифрованный ключ, поэтому создаётся только
 * ProviderRepository::loadForGateway() непосредственно перед запросом и
 * никогда не передаётся в шаблоны/журналы.
 */
final class ProviderConfig
{
    /**
     * @param array<string,string> $extraHeaders
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $baseUrl,
        public readonly string $endpoint,
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $model,
        public readonly ?string $apiVersion,
        public readonly int $connectTimeout,
        public readonly int $responseTimeout,
        public readonly int $maxTokens,
        public readonly array $extraHeaders,
        public readonly bool $useCorporateCa,
        public readonly bool $isEnabled,
        /** Жёсткая фиксация маршрута у мультипровайдерного шлюза (см. LlmSchema). */
        public readonly ?string $routingProvider = null,
        /** Автовыбор самого дешёвого маршрута у мультипровайдерного шлюза (provider.sort=price). */
        public readonly bool $sortByPrice = false,
        /** Потолок цены (provider.max_price), ₽ за миллион токенов, для prompt и completion. */
        public readonly ?float $maxPricePerMtoken = null,
        /** provider.allow_fallbacks — при исчерпании подходящих маршрутов не отказывать, а взять любой доступный. */
        public readonly bool $allowFallbacks = true,
    ) {
    }

    public function url(): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($this->endpoint, '/');
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type, 'model' => $this->model, 'apiKey' => '[redacted]'];
    }
}
