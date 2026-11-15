<?php

declare(strict_types=1);

namespace App\Llm\Gateway;

/**
 * Ошибка обращения к LLM. errorCode безопасен для журнала:
 * not_found | disabled | access_denied | unsupported_type | secret_unavailable |
 * auth_failed | billing | rate_limited | overloaded | server_error | bad_request |
 * context_overflow | unsupported_param | model_not_found | invalid_response |
 * empty_response | empty_input | all_failed | transport_<kind>.
 * Классификация по образцу agent/error_classifier.py Hermes: от кода зависит,
 * повторять ли запрос, переходить ли на резервную модель и ставить ли паузу (FailoverPolicy).
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
        /** Retry-After провайдера, секунд. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($technicalMessage !== '' ? $technicalMessage : $errorCode);
    }

    public function userMessage(): string
    {
        return match (true) {
            $this->errorCode === 'access_denied', $this->errorCode === 'not_found', $this->errorCode === 'disabled'
                => 'Выбранная нейросеть вам недоступна.',
            $this->errorCode === 'auth_failed' => 'Внешний сервис отклонил ключ доступа. Обратитесь к администратору.',
            $this->errorCode === 'rate_limited', $this->errorCode === 'overloaded' => 'Внешний сервис перегружен или исчерпан лимит. Повторите позже.',
            $this->errorCode === 'billing' => 'У внешнего сервиса исчерпан оплаченный лимит. Обратитесь к администратору.',
            $this->errorCode === 'empty_response' => 'Нейросеть вернула пустой ответ. Повторите запрос или выберите другую модель.',
            $this->errorCode === 'context_overflow' => 'Диалог и материалы не помещаются в контекст модели. Начните новый чат или уберите часть источников.',
            $this->errorCode === 'all_failed' => 'Все доступные вам нейросети сейчас не отвечают. Сообщение не потеряно — повторите попытку позже.',
            $this->errorCode === 'transport_timeout' => 'Нейросеть не ответила вовремя. Повторите запрос позже.',
            str_starts_with($this->errorCode, 'transport_') => 'Нет связи с внешним сервисом нейросети.',
            $this->errorCode === 'bad_request' => 'Внешний сервис отклонил запрос (возможно, слишком длинный диалог или настройки маршрутизации нейросети слишком строгие — сообщите администратору).',
            default => 'Не удалось получить ответ нейросети.',
        };
    }

    public static function fromHttpStatus(int $status, ?array $json, string $bodyExcerpt, ?int $retryAfter = null): self
    {
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $type = isset($error['type']) && is_scalar($error['type']) ? (string) $error['type']
            : (isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : null);
        $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : (is_string($json['message'] ?? null) ? $json['message'] : $bodyExcerpt);
        $haystack = mb_strtolower(($type ?? '') . ' ' . $message);
        $code = match (true) {
            $status === 401, $status === 403 => 'auth_failed',
            $status === 402, str_contains($haystack, 'insufficient_quota'), str_contains($haystack, 'billing'), str_contains($haystack, 'credit balance') => 'billing',
            $status === 413, preg_match('/context.{0,20}(length|window)|maximum context|too many tokens|prompt is too long|token limit|exceeds the (model|maximum)/u', $haystack) === 1 => 'context_overflow',
            $status === 429 => 'rate_limited',
            $status === 529, str_contains($haystack, 'overloaded') => 'overloaded',
            $status === 408 => 'transport_timeout',
            $status >= 500 => 'server_error',
            $status === 404 => 'model_not_found',
            preg_match('/unsupported|not supported|does not support|unrecognized|unknown (parameter|field|argument)|invalid.{0,10}(temperature|parameter)/u', $haystack) === 1 => 'unsupported_param',
            default => 'bad_request',
        };

        return new self($code, 'HTTP ' . $status . ': ' . mb_substr($message, 0, 300), $status, $type !== null ? mb_substr($type, 0, 60) : null, $retryAfter);
    }
}
