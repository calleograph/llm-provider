<?php

declare(strict_types=1);

namespace App\Llm\Gateway\Adapter;

/**
 * Любой сервис с OpenAI-совместимым Chat Completions API. Пример —
 * polza.ai: Base URL https://polza.ai/api/v1, endpoint /chat/completions,
 * модель в формате "openai/gpt-4o" или "anthropic/claude-3-5-sonnet".
 */
final class OpenAiCompatibleAdapter extends ChatCompletionsAdapter
{
    public function type(): string
    {
        return 'openai_compatible';
    }

    public function label(): string
    {
        return 'OpenAI-compatible API';
    }

    public function defaults(): array
    {
        return [
            'base_url' => 'https://polza.ai/api/v1',
            'endpoint' => '/chat/completions',
            'api_version' => '',
            'model_placeholder' => 'openai/gpt-4o',
        ];
    }

    protected function maxTokensField(): string
    {
        return 'max_tokens';
    }
}
