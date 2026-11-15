<?php

declare(strict_types=1);

namespace App\Llm\Gateway;

/**
 * Ошибка обращения к LLM. errorCode безопасен для журнала:
 * not_found | disabled | access_denied | unsupported_type | secret_unavailable |
 * auth_failed | rate_limited | bad_request | server_error | invalid_response |
 * empty_input | transport_<kind>.
 * technicalMessage может содержать текст ошибки провайдера — показывается
 * только администратору при проверке подключения и не журналируется.
 */
final class LlmException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $technicalMessage = '',
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerErrorType = null,
    ) {
        parent::__construct($technicalMessage !== '' ? $technicalMessage : $errorCode);
    }

    public function userMessage(): string
    {
        return match (true) {
            $this->errorCode === 'access_denied', $this->errorCode === 'not_found', $this->errorCode === 'disabled'
                => 'Выбранная нейросеть вам недоступна.',
            $this->errorCode === 'auth_failed' => 'Внешний сервис отклонил ключ доступа. Обратитесь к администратору.',
            $this->errorCode === 'rate_limited' => 'Внешний сервис перегружен или исчерпан лимит. Повторите позже.',
            $this->errorCode === 'transport_timeout' => 'Нейросеть не ответила вовремя. Повторите запрос позже.',
            str_starts_with($this->errorCode, 'transport_') => 'Нет связи с внешним сервисом нейросети.',
            $this->errorCode === 'bad_request' => 'Внешний сервис отклонил запрос (возможно, слишком длинный диалог или настройки маршрутизации нейросети слишком строгие — сообщите администратору).',
            default => 'Не удалось получить ответ нейросети.',
        };
    }

    public static function fromHttpStatus(int $status, ?array $json, string $bodyExcerpt): self
    {
        $code = match (true) {
            $status === 401, $status === 403 => 'auth_failed',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            default => 'bad_request',
        };
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $type = isset($error['type']) && is_scalar($error['type']) ? (string) $error['type']
            : (isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : null);
        $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : $bodyExcerpt;

        return new self($code, 'HTTP ' . $status . ': ' . mb_substr($message, 0, 300), $status, $type !== null ? mb_substr($type, 0, 60) : null);
    }
}
