<?php

declare(strict_types=1);

namespace App\Llm\Gateway;

use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use App\Llm\Provider\ProviderConfig;

/**
 * Адаптер конкретного формата внешнего LLM API. Адаптер — чистое
 * преобразование «сообщения → HTTP-запрос» и «HTTP-ответ → LlmResult»;
 * сетевой вызов, проверка прав и журналирование выполняет LlmGateway.
 * Новый тип API добавляется реализацией этого интерфейса и регистрацией
 * в AdapterRegistry — чат и админка при этом не меняются.
 */
interface LlmAdapter
{
    /** Машинное имя типа, хранится в llm_providers.provider_type. */
    public function type(): string;

    public function label(): string;

    /**
     * Значения по умолчанию для формы добавления нейросети.
     *
     * @return array{base_url:string,endpoint:string,api_version:string,model_placeholder:string}
     */
    public function defaults(): array;

    /**
     * @param list<array{role:string,content:string}> $messages уже обезличенные реплики
     */
    public function buildRequest(ProviderConfig $provider, array $messages, ?string $systemPrompt, int $maxTokens, ?string $caBundlePath): HttpRequest;

    /**
     * @throws LlmException
     */
    public function parseResponse(HttpResponse $response): LlmResult;
}
