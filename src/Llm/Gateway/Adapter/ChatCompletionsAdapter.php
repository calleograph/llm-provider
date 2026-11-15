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
 * Общий формат OpenAI Chat Completions (POST {base}/chat/completions).
 * Используется OpenAI и любыми OpenAI-совместимыми API (polza.ai,
 * vLLM, LiteLLM, Azure OpenAI и т.п.).
 */
abstract class ChatCompletionsAdapter implements LlmAdapter
{
    /** Имя поля лимита токенов: у OpenAI — max_completion_tokens, у совместимых — max_tokens. */
    abstract protected function maxTokensField(): string;

    public function buildRequest(ProviderConfig $provider, array $messages, ?string $systemPrompt, int $maxTokens, ?string $caBundlePath): HttpRequest
    {
        $payloadMessages = [];
        if ($systemPrompt !== null && trim($systemPrompt) !== '') {
            $payloadMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach (MessageNormalizer::alternate($messages) as $message) {
            $payloadMessages[] = $message;
        }

        $payload = [
            'model' => $provider->model,
            'messages' => $payloadMessages,
            $this->maxTokensField() => $maxTokens,
        ];

        if ($provider->routingProvider !== null) {
            // Жёсткая фиксация маршрута у мультипровайдерного шлюза (см. LlmSchema).
            $payload['provider'] = ['only' => [$provider->routingProvider]];
        } elseif ($provider->sortByPrice) {
            // Автовыбор самого дешёвого доступного маршрута; max_price (если
            // задан) отсекает дорогие варианты по обоим направлениям сразу
            // (в терминах Polza — prompt/completion, ₽ за млн токенов).
            $providerOptions = ['sort' => 'price', 'allow_fallbacks' => $provider->allowFallbacks];
            if ($provider->maxPricePerMtoken !== null) {
                $providerOptions['max_price'] = [
                    'prompt' => $provider->maxPricePerMtoken,
                    'completion' => $provider->maxPricePerMtoken,
                ];
            }
            $payload['provider'] = $providerOptions;
        }

        $url = $provider->url();
        if ($provider->apiVersion !== null) {
            // Azure OpenAI и ряд шлюзов требуют версию API в query string.
            $url .= (str_contains($url, '?') ? '&' : '?') . 'api-version=' . rawurlencode($provider->apiVersion);
        }

        $auth = $provider->apiKey !== '' ? ['Authorization' => 'Bearer ' . $provider->apiKey] : [];

        return HttpRequest::json(
            $url,
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
        if ($json === null || !isset($json['choices'][0]) || !is_array($json['choices'][0])) {
            throw new LlmException('invalid_response', 'Ответ не содержит choices[0].');
        }

        $choice = $json['choices'][0];
        $content = $choice['message']['content'] ?? '';
        if (is_array($content)) {
            // Формат "content parts": [{type: text, text: ...}, ...]
            $text = '';
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
            $content = $text;
        }
        if (!is_string($content)) {
            throw new LlmException('invalid_response', 'Поле message.content имеет неожиданный тип.');
        }

        return new LlmResult(
            text: $content,
            model: isset($json['model']) && is_string($json['model']) ? $json['model'] : null,
            inputTokens: isset($json['usage']['prompt_tokens']) ? (int) $json['usage']['prompt_tokens'] : null,
            outputTokens: isset($json['usage']['completion_tokens']) ? (int) $json['usage']['completion_tokens'] : null,
            finishReason: isset($choice['finish_reason']) && is_string($choice['finish_reason']) ? $choice['finish_reason'] : null,
        );
    }
}
