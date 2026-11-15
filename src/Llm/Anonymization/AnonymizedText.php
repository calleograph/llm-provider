<?php

declare(strict_types=1);

namespace App\Llm\Anonymization;

/**
 * Текст, прошедший обезличивание. LLM Gateway принимает ТОЛЬКО этот тип —
 * обычную строку передать во внешний API нельзя на уровне сигнатур.
 *
 * Создаётся исключительно:
 *  - AnonymizationGateway::anonymize() — результат успешного обезличивания;
 *  - fromStorage() — чтение истории диалога, которая хранится только в
 *    обезличенном виде (исходный текст в БД не пишется).
 */
final class AnonymizedText
{
    private function __construct(public readonly string $value)
    {
    }

    /** @internal только для AnonymizationGateway */
    public static function fromGateway(string $value, AnonymizationGateway $issuer): self
    {
        return new self($value);
    }

    /** @internal только для чтения сохранённой (уже обезличенной) истории */
    public static function fromStorage(string $value): self
    {
        return new self($value);
    }

    /**
     * Добавляет служебную метку из кода (например "[Вложение: PDF]").
     * Формат метки ограничен, чтобы через неё нельзя было протащить
     * произвольный необезличенный текст.
     */
    public function withLabel(string $label): self
    {
        if (preg_match('/\A\[[\p{L}\p{N} :.,()-]{1,60}\]\z/u', $label) !== 1) {
            throw new \InvalidArgumentException('Недопустимая служебная метка.');
        }

        return new self($label . "\n" . $this->value);
    }

    /**
     * @param AnonymizedText[] $parts
     */
    public static function join(array $parts, string $glue = "\n\n"): self
    {
        $values = [];
        foreach ($parts as $part) {
            if (!$part->isEmpty()) {
                $values[] = $part->value;
            }
        }

        return new self(implode($glue === "\n\n" || $glue === "\n" ? $glue : "\n\n", $values));
    }

    public function isEmpty(): bool
    {
        return trim($this->value) === '';
    }
}
