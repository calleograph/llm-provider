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
    ) {
    }

    public function withDuration(int $durationMs): self
    {
        return new self($this->text, $this->model, $this->inputTokens, $this->outputTokens, $this->finishReason, $durationMs);
    }
}
