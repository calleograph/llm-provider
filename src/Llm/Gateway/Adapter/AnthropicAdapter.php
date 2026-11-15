<?php

declare(strict_types=1);

namespace App\Llm\Gateway\Adapter;

use App\Llm\Gateway\LlmAdapter;
use App\Llm\Gateway\LlmException;
use App\Llm\Gateway\LlmResult;
use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use App\Llm\Provider\ProviderConfig;

/**
 * Anthropic Messages API: POST {base}/v1/messages, заголовки x-api-key и
 * anthropic-version, системная инструкция — отдельным полем system.
 */
final class AnthropicAdapter implements LlmAdapter
{
    private const DEFAULT_VERSION = '2023-06-01';

    public function type(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Anthropic API';
    }

    public function defaults(): array
    {
        return [
            'base_url' => 'https://api.anthropic.com',
            'endpoint' => '/v1/messages',
            'api_version' => self::DEFAULT_VERSION,
            'model_placeholder' => 'claude-sonnet-5',
        ];
    }

    public function buildRequest(ProviderConfig $provider, array $messages, ?string $systemPrompt, int $maxTokens, ?string $caBundlePath): HttpRequest
    {
        $payload = [
            'model' => $provider->model,
            'max_tokens' => $maxTokens,
            'messages' => MessageNormalizer::alternate($messages),
        ];
        if ($systemPrompt !== null && trim($systemPrompt) !== '') {
            $payload['system'] = $systemPrompt;
        }

        $auth = ['anthropic-version' => $provider->apiVersion ?? self::DEFAULT_VERSION];
        if ($provider->apiKey !== '') {
            $auth['x-api-key'] = $provider->apiKey;
        }

        return HttpRequest::json(
            $provider->url(),
            $payload,
            MessageNormalizer::mergeHeaders($provider->extraHeaders, $auth),
            $provider->connectTimeout,
            $provider->responseTimeout,
            $caBundlePath,
        );
    }

    public function parseResponse(HttpResponse $response): LlmResult
    {
        $json = $response->json();
        if (!$response->isSuccess()) {
            throw LlmException::fromHttpStatus($response->status, $json, mb_substr($response->body, 0, 300));
        }
        if ($json === null || !isset($json['content']) || !is_array($json['content'])) {
            throw new LlmException('invalid_response', 'Ответ не содержит массива content.');
        }

        $text = '';
        foreach ($json['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }
        }

        return new LlmResult(
            text: $text,
            model: isset($json['model']) && is_string($json['model']) ? $json['model'] : null,
            inputTokens: isset($json['usage']['input_tokens']) ? (int) $json['usage']['input_tokens'] : null,
            outputTokens: isset($json['usage']['output_tokens']) ? (int) $json['usage']['output_tokens'] : null,
            finishReason: isset($json['stop_reason']) && is_string($json['stop_reason']) ? $json['stop_reason'] : null,
        );
    }
}
