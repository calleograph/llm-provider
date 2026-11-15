<?php

declare(strict_types=1);

namespace App\Llm\Gateway\Adapter;

final class OpenAiAdapter extends ChatCompletionsAdapter
{
    public function type(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI API';
    }

    public function defaults(): array
    {
        return [
            'base_url' => 'https://api.openai.com/v1',
            'endpoint' => '/chat/completions',
            'api_version' => '',
            'model_placeholder' => 'gpt-4o',
        ];
    }

    protected function maxTokensField(): string
    {
        return 'max_completion_tokens';
    }
}
