<?php

declare(strict_types=1);

namespace App\Llm\Chat;

use App\Config\AppSettings;

/**
 * Общие параметры чата (app_settings, префикс llm_chat_).
 */
final class ChatSettings
{
    private const PREFIX = 'llm_chat_';

    private const DEFAULTS = [
        'system_prompt' => 'Ты — ассистент сотрудников компании. Персональные и конфиденциальные данные в запросах заменены обезличенными метками — сохраняй эти метки в ответе без изменений.',
        'history_max_chars' => '60000',
        'max_message_chars' => '20000',
        // Общий лимит токенов на пользователя; индивидуальный задаётся в «Пользователи чата».
        'limit_period' => 'none',
        'limit_tokens' => '200000',
        // Срок хранения неиспользуемых диалогов, дней (0 — хранить бессрочно).
        'retention_days' => '0',
        // Суммарный объём источников одного проекта (символов обезличенного
        // текста) — весь этот текст добавляется целиком в системный промпт
        // КАЖДОГО запроса к модели в этом проекте (см. ProjectRepository::
        // contextFor(), LlmGateway::complete()), без учёта реального
        // контекстного окна конкретной модели. Прежнее значение 100000
        // (≈25000 токенов) легко переполняло контекст компактных моделей —
        // снижено до более безопасного запаса, оставляющего место истории
        // переписки, системному промпту и ответу.
        'project_sources_max_chars' => '30000',
        // Максимум источников в одном проекте — само по себе не защищает от
        // переполнения контекста (несколько крупных файлов всё равно могут
        // превысить лимит символов выше), но ограничивает типичный сценарий
        // «нахватал десяток файлов» и даёт пользователю понятный ориентир.
        'project_sources_max_files' => '3',
        // Именованные блоки инструкций (правила поведения модели в проекте,
        // отдельно от источников-документов) — свои независимые лимиты:
        // число блоков и длина ОДНОГО блока (не суммарно — блок инструкции
        // задумывается как короткое правило, а не документ; для документов
        // есть источники с бóльшим суммарным лимитом выше).
        'project_instructions_max_blocks' => '5',
        'project_instruction_max_chars' => '2000',
        // Порог неактивности пользователя (дней), после которого содержимое его
        // сообщений и источников проектов зачищается (заголовки чатов остаются).
        // 0 — не зачищать никогда.
        'inactivity_content_purge_days' => '90',
    ];

    public function __construct(private readonly AppSettings $appSettings)
    {
    }

    public function get(string $name): string
    {
        return (string) $this->appSettings->get(self::PREFIX . $name, self::DEFAULTS[$name] ?? '');
    }

    /** @return array<string,string> */
    public function all(): array
    {
        $result = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            $result[$name] = $this->get($name);
        }

        return $result;
    }

    /** @param array<string,string> $values */
    public function save(array $values): void
    {
        foreach ($values as $name => $value) {
            if (array_key_exists($name, self::DEFAULTS)) {
                $this->appSettings->set(self::PREFIX . $name, (string) $value);
            }
        }
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    public static function validate(array $values): array
    {
        $errors = [];
        if (mb_strlen($values['system_prompt'] ?? '') > 8000) {
            $errors['system_prompt'] = 'Не длиннее 8000 символов.';
        }
        if (isset($values['limit_period']) && !in_array($values['limit_period'], UsageLimiter::PERIODS, true)) {
            $errors['limit_period'] = 'Недопустимый период.';
        }
        $ranges = [
            'history_max_chars' => [1000, 2000000], 'max_message_chars' => [100, 500000], 'limit_tokens' => [0, 1000000000],
            'retention_days' => [0, 3650], 'project_sources_max_chars' => [1000, 2000000], 'project_sources_max_files' => [1, 50],
            'project_instructions_max_blocks' => [1, 30], 'project_instruction_max_chars' => [100, 20000],
            'inactivity_content_purge_days' => [0, 3650],
        ];
        foreach (array_intersect_key($ranges, $values) as $field => [$min, $max]) {
            $value = (int) ($values[$field] ?? 0);
            if ($value < $min || $value > $max) {
                $errors[$field] = "Допустимый диапазон: {$min}–{$max}.";
            }
        }

        return $errors;
    }
}
