<?php

declare(strict_types=1);

namespace App\Llm\Gateway;

final class LlmResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $model = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $finishReason = null,
        public readonly int $durationMs = 0,
        /** llm_models.id ответившей модели и провайдер (после резервирования может отличаться от выбранной). */
        public readonly ?int $modelId = null,
        public readonly ?int $providerId = null,
        public readonly ?string $providerName = null,
        /** Название выбранной пользователем модели, если ответила резервная; null — ответила выбранная. */
        public readonly ?string $fallbackFrom = null,
        public readonly int $attempts = 1,
    ) {
    }

    public function withDuration(int $durationMs): self
    {
        return new self($this->text, $this->model, $this->inputTokens, $this->outputTokens, $this->finishReason, $durationMs, $this->modelId, $this->providerId, $this->providerName, $this->fallbackFrom, $this->attempts);
    }

    public function withRoute(?int $modelId, int $providerId, string $providerName, ?string $fallbackFrom, int $attempts): self
    {
        return new self($this->text, $this->model, $this->inputTokens, $this->outputTokens, $this->finishReason, $this->durationMs, $modelId, $providerId, $providerName, $fallbackFrom, $attempts);
    }

    /** Генерация остановлена лимитом длины ответа — ответ есть, но может быть неполным. */
    public function truncated(): bool
    {
        return in_array($this->finishReason, ['length', 'max_tokens'], true);
    }
}
