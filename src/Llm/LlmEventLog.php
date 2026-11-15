<?php

declare(strict_types=1);

namespace App\Llm;

use App\Audit\AppLogger;
use App\Audit\AuditLogger;

/**
 * Единая точка записи событий LLM-модуля в СУЩЕСТВУЮЩИЕ журналы проекта:
 *  - app_log   — действия пользователей чата (operation = LLM_*);
 *  - audit_log — действия администраторов над доменами, группами,
 *                нейросетями, правами и обезличиванием.
 * Техническая диагностика пишется напрямую в DiagnosticLogger.
 *
 * Принцип: в журнал попадают только метаданные (коды, идентификаторы,
 * имена групп/провайдеров, размеры, длительности). Поля, которые по имени
 * похожи на содержимое или секрет (text, content, prompt, password, key,
 * token…), отбрасываются ещё до записи — даже если вызывающий код
 * передал их по ошибке.
 */
final class LlmEventLog
{
    public const OP_AUTH = 'LLM_AUTH';
    public const OP_CHAT = 'LLM_CHAT';
    public const OP_ANONYMIZATION = 'LLM_ANONYMIZATION';
    public const OP_FILE = 'LLM_FILE';

    private const FORBIDDEN_KEY_PARTS = [
        'text', 'content', 'prompt', 'message', 'body', 'password', 'pwd', 'secret',
        'token', 'key', 'credential', 'header', 'answer', 'response', 'filename', 'file_name',
    ];

    private const MAX_VALUE_LENGTH = 300;

    public function __construct(
        private readonly AppLogger $appLogger,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * @param array<string,scalar|null|array<int,scalar>> $fields
     */
    public function user(string $ip, ?string $username, string $operation, string $event, array $fields = []): void
    {
        $details = self::formatDetails($fields);
        $this->appLogger->log($ip, $username, $operation, $details !== '' ? $event . ':' . $details : $event);
    }

    /**
     * @param array<string,scalar|null|array<int,scalar>> $fields
     */
    public function admin(string $actor, string $ip, string $action, bool $success, array $fields = []): void
    {
        if ($this->auditLogger === null) {
            return;
        }
        $details = self::formatDetails($fields);
        $this->auditLogger->log($actor, $ip, $action, $success, $details !== '' ? $details : null);
    }

    /**
     * @param array<string,scalar|null|array<int,scalar>> $fields
     */
    public static function formatDetails(array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) {
            $key = (string) $key;
            if (!self::isAllowedKey($key)) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(',', array_map(static fn ($v): string => (string) $v, $value));
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value === null) {
                $value = '—';
            }
            $parts[] = $key . '=' . self::sanitizeValue((string) $value);
        }

        return implode('; ', $parts);
    }

    public static function isAllowedKey(string $key): bool
    {
        $normalized = strtolower($key);
        // Явно разрешённые безопасные имена, содержащие «запрещённые» подстроки.
        if (in_array($normalized, ['max_tokens', 'input_tokens', 'output_tokens', 'text_chars', 'content_chars', 'extra_header_count', 'key_changed', 'headers_changed'], true)) {
            return true;
        }
        foreach (self::FORBIDDEN_KEY_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return false;
            }
        }

        return true;
    }

    private static function sanitizeValue(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = str_replace(';', ',', $value);
        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…';
        }

        return trim($value);
    }
}
