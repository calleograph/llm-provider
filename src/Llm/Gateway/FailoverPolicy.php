<?php

declare(strict_types=1);

namespace App\Llm\Gateway;

/**
 * Что делать после неудачной попытки — по образцу Hermes Agent (agent/error_classifier.py,
 * turn_recovery.compute_error_backoff, fallback_cooldown, empty_response_guard):
 *
 *  - retry      — повторить ту же модель (ограниченно, с экспоненциальной задержкой и учётом Retry-After);
 *  - fallback   — перейти к следующей модели цепочки;
 *  - cooldown   — временно исключить модель из выбора (нарастающая пауза min(60·2ⁿ⁻¹, 4 ч), Retry-After сильнее);
 *  - stripParams — повторить без необязательных параметров (температура), если провайдер их не поддерживает;
 *  - needsLargerContext — подходит только резервная модель с бо́льшим окном контекста.
 *
 * Ошибки прав и настройки (нет доступа, провайдер выключен) не переключают: это не сбой модели.
 */
final class FailoverPolicy
{
    public const MAX_RETRIES_PER_MODEL = 2;
    public const MAX_ATTEMPTS_TOTAL = 8;
    /** Ждать внутри запроса пользователя не дольше — иначе сразу резерв, а модель на паузу. */
    public const MAX_INLINE_WAIT_SECONDS = 8;
    public const COOLDOWN_CAP_SECONDS = 14400;

    /**
     * @return array{retry:bool,fallback:bool,cooldown:int,stripParams:bool,needsLargerContext:bool}
     */
    public static function decide(LlmException $e, int $attempt, int $consecutiveFailures): array
    {
        $code = $e->errorCode;
        $escalating = (int) min(self::COOLDOWN_CAP_SECONDS, 60 * 2 ** max(0, $consecutiveFailures - 1));
        $after = $e->retryAfter !== null && $e->retryAfter > 0 ? min(self::COOLDOWN_CAP_SECONDS, $e->retryAfter) : null;
        $base = ['retry' => false, 'fallback' => true, 'cooldown' => 0, 'stripParams' => false, 'needsLargerContext' => false];

        return match (true) {
            in_array($code, ['access_denied', 'not_found', 'disabled', 'empty_input', 'unsupported_type'], true) => ['fallback' => false] + $base,
            $code === 'rate_limited' => ['retry' => $attempt < self::MAX_RETRIES_PER_MODEL && ($after ?? 1) <= self::MAX_INLINE_WAIT_SECONDS,
                                         'cooldown' => $after ?? $escalating] + $base,
            $code === 'overloaded', $code === 'server_error' => ['retry' => $attempt < self::MAX_RETRIES_PER_MODEL && ($after ?? 1) <= self::MAX_INLINE_WAIT_SECONDS,
                                                                 'cooldown' => $consecutiveFailures >= 2 ? ($after ?? $escalating) : 0] + $base,
            // пустой ответ HTTP 200: один повтор, затем резерв; два подряд — модель на паузу (детерминированная пустота)
            $code === 'empty_response' => ['retry' => $attempt < 1, 'cooldown' => $consecutiveFailures >= 2 ? $escalating : 0] + $base,
            $code === 'invalid_response' => ['retry' => $attempt < 1, 'cooldown' => $consecutiveFailures >= 2 ? $escalating : 0] + $base,
            $code === 'transport_connect', $code === 'transport_closed', $code === 'transport_other' => ['retry' => $attempt < 1, 'cooldown' => $consecutiveFailures >= 2 ? $escalating : 0] + $base,
            // таймаут ответа дорог: не повторяем ту же модель, сразу резерв
            $code === 'transport_timeout' => ['cooldown' => $consecutiveFailures >= 2 ? $escalating : 0] + $base,
            $code === 'transport_dns', $code === 'transport_tls', $code === 'transport_scheme' => ['cooldown' => 300] + $base,
            $code === 'auth_failed', $code === 'secret_unavailable' => ['cooldown' => 300] + $base,
            $code === 'billing' => ['cooldown' => $after ?? 3600] + $base,
            $code === 'model_not_found' => ['cooldown' => 600] + $base,
            $code === 'unsupported_param' => ['retry' => $attempt < 1, 'stripParams' => true] + $base,
            $code === 'context_overflow' => ['needsLargerContext' => true] + $base,
            default => $base,  // bad_request и прочее: без повтора, резервная модель может принять запрос
        };
    }

    /** Пауза перед повтором: Retry-After, иначе экспонента 1, 2, 4 … с джиттером (jittered_backoff Hermes), не дольше MAX_INLINE_WAIT. */
    public static function backoffSeconds(LlmException $e, int $attempt): float
    {
        if ($e->retryAfter !== null && $e->retryAfter > 0) {
            return (float) min(self::MAX_INLINE_WAIT_SECONDS, $e->retryAfter);
        }
        $delay = min((float) self::MAX_INLINE_WAIT_SECONDS, 2 ** $attempt);

        return min((float) self::MAX_INLINE_WAIT_SECONDS, $delay + $delay * 0.5 * (mt_rand() / mt_getrandmax()));
    }
}
