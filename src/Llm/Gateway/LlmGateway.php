<?php

declare(strict_types=1);

namespace App\Llm\Gateway;

use App\Diagnostics\DiagnosticLogger;
use App\Diagnostics\DiagnosticSource;
use App\Llm\Access\AccessPolicy;
use App\Llm\Anonymization\AnonymizedText;
use App\Llm\Chat\ChatUser;
use App\Llm\Http\HttpTransport;
use App\Llm\Http\HttpTransportException;
use App\Llm\Provider\ProviderConfig;
use App\Llm\Provider\ProviderRepository;

/**
 * ЕДИНСТВЕННАЯ точка обращения приложения к внешним LLM API.
 *
 *  - принимает только AnonymizedText — необезличенную строку передать
 *    нельзя на уровне типов;
 *  - повторно проверяет право пользователя на провайдера (AccessPolicy),
 *    даже если вызывающий код уже проверил: подмена provider_id в запросе
 *    не даёт доступа;
 *  - выбирает адаптер по типу провайдера из AdapterRegistry;
 *  - пишет в диагностический журнал только метаданные (провайдер, модель,
 *    HTTP-статус, токены, длительность), без текста запросов/ответов.
 */
final class LlmGateway
{
    /**
     * @param \Closure():string $caBundleResolver
     */
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly AccessPolicy $accessPolicy,
        private readonly AdapterRegistry $adapters,
        private readonly HttpTransport $transport,
        private readonly \Closure $caBundleResolver,
        private readonly ?DiagnosticLogger $diagnosticLogger = null,
        private readonly ?\App\Llm\Provider\ModelRepository $models = null,
        private readonly ?ModelHealthRepository $health = null,
        /** @var \Closure(float):void пауза перед повтором (в тестах — без реального ожидания) */
        private readonly ?\Closure $sleeper = null,
        /** @var \Closure(string,array<string,string>,string):void уведомление администраторам (шаблон, переменные, ключ ограничения частоты) */
        private readonly ?\Closure $alert = null,
    ) {
    }

    /**
     * Запрос с резервированием (по образцу Hermes): выбранная модель → её резервные модели → (по настройке)
     * остальные доступные пользователю модели по приоритету. Каждая модель — не больше одного раза, повторы
     * ограничены, общее число попыток и время ограничены, поэтому бесконечных повторов и циклов нет.
     * Ответ принимается только полноценный: HTTP 2xx, корректная структура, непустой текст.
     *
     * @param list<array{role:string,content:AnonymizedText}> $history
     * @param array<int,array<string,mixed>> $allowedModels модели, доступные пользователю (ModelRepository::allowedFor)
     * @throws LlmException последняя ошибка либо all_failed
     */
    public function completeWithFailover(ChatUser $user, int $modelId, array $history, ?string $systemPrompt, ?AnonymizedText $context, array $allowedModels, bool $autoFallback = true, int $timeBudgetSeconds = 540, int $requiredContext = 0): LlmResult
    {
        if ($this->models === null || $this->health === null) {
            throw new LlmException('unsupported_type', 'Резервирование не настроено.');
        }
        if ($context !== null && !$context->isEmpty()) {
            $systemPrompt = trim(($systemPrompt ?? '') . "\n\n" . $context->value);
        }
        $messages = $this->plainMessages($history);
        if (!isset($allowedModels[$modelId])) {
            throw new LlmException('access_denied', 'Модель недоступна пользователю.');
        }

        // Модели на паузе после сбоев — в конец цепочки (если на паузе все, всё равно пробуем по порядку).
        $chain = $this->models->chain($modelId, $allowedModels, $autoFallback);
        $ready = array_values(array_filter($chain, fn (int $id): bool => $this->health->coolingUntil($id) === 0));
        $chain = array_values(array_unique([...$ready, ...$chain]));

        $started = microtime(true);
        $primaryName = (string) $allowedModels[$modelId]['display_name'];
        $attemptsTotal = 0;
        $last = null;
        $minContext = 0;
        foreach ($chain as $position => $candidateId) {
            $model = $allowedModels[$candidateId] ?? null;
            // Резервная модель с меньшим окном, чем нужно собранному контексту, пропускается (иначе гарантированный отказ).
            if ($model === null || (int) $model['context_window'] <= $minContext || ($candidateId !== $modelId && (int) $model['context_window'] - (int) $model['max_output_tokens'] < $requiredContext)) {
                continue;
            }
            try {
                $this->accessPolicy->assertAllowed($user, (int) $model['provider_id']);   // права — на каждую резервную модель отдельно
                $provider = $this->loadProvider((int) $model['provider_id']);
            } catch (LlmException $e) {
                $last = $e;
                continue;
            }
            if (!$provider->isEnabled) {
                continue;
            }
            $config = $provider->withModel($candidateId, (string) $model['model'], (int) $model['max_output_tokens'], $model['temperature']);
            for ($attempt = 0; ; $attempt++) {
                if ($attemptsTotal >= FailoverPolicy::MAX_ATTEMPTS_TOTAL
                    || (microtime(true) - $started) + min($config->responseTimeout, 60) > $timeBudgetSeconds) {
                    break 2;
                }
                $attemptsTotal++;
                try {
                    $result = $this->execute($config, $messages, $systemPrompt, $config->maxTokens, ['user' => $user->logName(), 'model_id' => $candidateId]);
                    $cost = (float) ($model['price_in_per_mtoken'] ?? 0) * (int) $result->inputTokens / 1e6 + (float) ($model['price_out_per_mtoken'] ?? 0) * (int) $result->outputTokens / 1e6;
                    $this->health->recordSuccess($candidateId, $result->durationMs, $result->inputTokens, $result->outputTokens, $cost);

                    return $result->withRoute($candidateId, $provider->id, $provider->name, $candidateId !== $modelId ? $primaryName : null, $attemptsTotal);
                } catch (LlmException $e) {
                    $last = $e;
                    $failures = $this->health->consecutiveFailures($candidateId) + 1;
                    $decision = FailoverPolicy::decide($e, $attempt, $failures);
                    $this->health->recordFailure($candidateId, $e->errorCode, self::describeForAdmin($e), $decision['cooldown']);
                    if ($decision['cooldown'] >= 300 && $this->alert !== null) {
                        ($this->alert)('model_alert', ['model' => (string) $model['display_name'], 'provider' => $provider->name, 'reason' => self::describeForAdmin($e),
                            'until' => gmdate('Y-m-d H:i', time() + $decision['cooldown'])], 'model' . $candidateId);
                    }
                    if ($decision['stripParams'] && $config->temperature !== null) {
                        $config = $config->withoutOptionalParams();
                        $this->health->incident($candidateId, $provider->id, $e->errorCode, $e->httpStatus, self::describeForAdmin($e), 'retry_without_params');
                        continue;
                    }
                    if ($decision['retry']) {
                        $this->health->incident($candidateId, $provider->id, $e->errorCode, $e->httpStatus, self::describeForAdmin($e), 'retry');
                        ($this->sleeper ?? static fn (float $s) => usleep((int) ($s * 1_000_000)))(FailoverPolicy::backoffSeconds($e, $attempt));
                        continue;
                    }
                    if (!$decision['fallback']) {
                        throw $e;
                    }
                    if ($decision['needsLargerContext']) {
                        $minContext = max($minContext, (int) $model['context_window']);
                    }
                    $next = $chain[$position + 1] ?? null;
                    $this->health->recordFallback($candidateId);
                    $this->health->incident($candidateId, $provider->id, $e->errorCode, $e->httpStatus, self::describeForAdmin($e), $next !== null ? 'fallback' : 'give_up', $next);
                    break;
                }
            }
        }

        if ($last !== null && count($chain) === 1) {
            throw $last;   // резервных моделей нет — пользователю причина как есть (лимит, ключ, пустой ответ…)
        }
        $this->alert?->__invoke('all_failed', ['reason' => $last !== null ? self::describeForAdmin($last) : 'нет попыток'], 'all');
        throw new LlmException('all_failed', 'Все модели цепочки не ответили; последняя ошибка: ' . ($last !== null ? self::describeForAdmin($last) : 'нет попыток'), $last?->httpStatus, $last?->errorCode);
    }

    /**
     * @param list<array{role:string,content:AnonymizedText}> $history
     * @return list<array{role:string,content:string}>
     */
    private function plainMessages(array $history): array
    {
        $messages = [];
        foreach ($history as $item) {
            if (!($item['content'] instanceof AnonymizedText)) {
                throw new LlmException('empty_input', 'В LLM Gateway передан необезличенный текст.');
            }
            $messages[] = ['role' => $item['role'], 'content' => $item['content']->value];
        }
        if ($messages === []) {
            throw new LlmException('empty_input', 'Пустой запрос.');
        }

        return $messages;
    }

    /**
     * @param list<array{role:string,content:AnonymizedText}> $history обезличенная история, последняя — новая реплика пользователя
     * @throws LlmException
     */
    public function complete(ChatUser $user, int $providerId, array $history, ?string $systemPrompt = null, ?AnonymizedText $context = null): LlmResult
    {
        // Контекст проекта (инструкции, источники) — только обезличенный.
        if ($context !== null && !$context->isEmpty()) {
            $systemPrompt = trim(($systemPrompt ?? '') . "\n\n" . $context->value);
        }

        $this->accessPolicy->assertAllowed($user, $providerId);

        $provider = $this->loadProvider($providerId);
        if (!$provider->isEnabled) {
            throw new LlmException('disabled', 'Провайдер отключён.');
        }

        $messages = [];
        foreach ($history as $item) {
            if (!($item['content'] instanceof AnonymizedText)) {
                // Защита от ошибок программирования: сюда не должен попасть сырой текст.
                throw new LlmException('empty_input', 'В LLM Gateway передан необезличенный текст.');
            }
            $messages[] = ['role' => $item['role'], 'content' => $item['content']->value];
        }
        if ($messages === []) {
            throw new LlmException('empty_input', 'Пустой запрос.');
        }

        return $this->execute($provider, $messages, $systemPrompt, $provider->maxTokens, ['user' => $user->logName()]);
    }

    /**
     * Проверка подключения из админки: синтетический запрос без
     * пользовательских данных. Права не проверяются (вызывает администратор),
     * провайдер может быть и выключен.
     *
     * @return array{ok:bool,message:string,duration_ms:int}
     */
    public function testConnection(int $providerId): array
    {
        $started = microtime(true);
        try {
            $provider = $this->loadProvider($providerId);
            $result = $this->execute(
                $provider,
                [['role' => 'user', 'content' => 'Ответь одним словом: OK']],
                null,
                min(64, max(16, $provider->maxTokens)),
                ['check' => 'admin'],
            );
            $ok = true;
            $message = 'Подключение успешно'
                . ($result->model !== null ? ', модель ' . $result->model : '')
                . ($result->finishReason !== null ? ', finish_reason=' . $result->finishReason : '')
                . '.';
        } catch (LlmException $e) {
            $ok = false;
            $message = self::describeForAdmin($e);
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->providers->recordCheck($providerId, $ok, $message);

        return ['ok' => $ok, 'message' => $message, 'duration_ms' => $durationMs];
    }

    /**
     * Проверка модели из админки (без пользовательских данных): ping — минимальный ответ «OK»,
     * generate — тестовая генерация осмысленного текста (видно качество и время). Результат пишется
     * в диагностику модели; пауза после ошибки проверки не ставится.
     *
     * @return array{ok:bool,message:string,duration_ms:int,preview:?string}
     */
    public function testModel(int $modelId, string $mode = 'ping'): array
    {
        $started = microtime(true);
        $preview = null;
        try {
            $model = $this->models?->find($modelId) ?? throw new LlmException('not_found', 'Модель не найдена.');
            $provider = $this->loadProvider((int) $model['provider_id']);
            $config = $provider->withModel($modelId, (string) $model['model'], $mode === 'ping' ? min(64, (int) $model['max_output_tokens']) : min(400, (int) $model['max_output_tokens']), $model['temperature']);
            $prompt = $mode === 'ping' ? 'Ответь одним словом: OK' : 'Объясни в трёх предложениях, зачем компании резервные языковые модели.';
            $result = $this->execute($config, [['role' => 'user', 'content' => $prompt]], null, $config->maxTokens, ['check' => 'admin_' . $mode]);
            $this->health?->recordSuccess($modelId, $result->durationMs, $result->inputTokens, $result->outputTokens, 0.0);
            $preview = mb_substr($result->text, 0, 600);
            $ok = true;
            $message = ($mode === 'ping' ? 'Подключение успешно' : 'Генерация успешна') . ($result->model !== null ? ', модель ' . $result->model : '')
                . ', токены ' . (int) $result->inputTokens . '/' . (int) $result->outputTokens . ($result->finishReason !== null ? ', finish_reason=' . $result->finishReason : '') . '.';
        } catch (LlmException $e) {
            $ok = false;
            $message = self::describeForAdmin($e);
            $this->health?->recordFailure($modelId, $e->errorCode, $message, 0);
            $this->health?->incident($modelId, null, $e->errorCode, $e->httpStatus, $message, 'admin_check');
        }

        return ['ok' => $ok, 'message' => $message, 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'preview' => $preview];
    }

    public static function describeForAdmin(LlmException $e): string
    {
        $prefix = match (true) {
            $e->errorCode === 'auth_failed' => 'Ключ отклонён',
            $e->errorCode === 'rate_limited' => 'Лимит запросов (HTTP 429)',
            $e->errorCode === 'overloaded' => 'Провайдер перегружен',
            $e->errorCode === 'billing' => 'Исчерпан оплаченный лимит',
            $e->errorCode === 'empty_response' => 'Пустой ответ при HTTP 200',
            $e->errorCode === 'context_overflow' => 'Превышено контекстное окно',
            $e->errorCode === 'unsupported_param' => 'Неподдерживаемый параметр',
            $e->errorCode === 'model_not_found' => 'Модель не найдена у провайдера',
            $e->errorCode === 'transport_closed' => 'Соединение оборвалось',
            $e->errorCode === 'all_failed' => 'Все модели недоступны',
            $e->errorCode === 'bad_request' => 'Запрос отклонён (проверьте модель/endpoint)',
            $e->errorCode === 'server_error' => 'Ошибка на стороне провайдера',
            $e->errorCode === 'invalid_response' => 'Неожиданный формат ответа (проверьте тип API/endpoint)',
            $e->errorCode === 'unsupported_type' => 'Неизвестный тип API',
            $e->errorCode === 'secret_unavailable' => 'Не удалось расшифровать ключ',
            $e->errorCode === 'transport_timeout' => 'Таймаут',
            $e->errorCode === 'transport_dns' => 'DNS-имя не разрешается',
            $e->errorCode === 'transport_connect' => 'Нет TCP-соединения',
            $e->errorCode === 'transport_tls' => 'Ошибка TLS-сертификата',
            $e->errorCode === 'transport_scheme' => 'Разрешён только https://',
            default => 'Ошибка ' . $e->errorCode,
        };

        return $prefix . ': ' . mb_substr($e->getMessage(), 0, 250);
    }

    private function loadProvider(int $providerId): ProviderConfig
    {
        try {
            $provider = $this->providers->loadForGateway($providerId);
        } catch (\RuntimeException $e) {
            throw new LlmException('secret_unavailable', $e->getMessage());
        }
        if ($provider === null) {
            throw new LlmException('not_found', 'Провайдер не найден.');
        }

        return $provider;
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,string> $logContext
     */
    private function execute(ProviderConfig $provider, array $messages, ?string $systemPrompt, int $maxTokens, array $logContext): LlmResult
    {
        $adapter = $this->adapters->get($provider->type);

        $caBundlePath = null;
        if ($provider->useCorporateCa) {
            try {
                $caBundlePath = ($this->caBundleResolver)();
            } catch (\Throwable $e) {
                throw new LlmException('transport_tls', 'CA bundle приложения недоступен: ' . $e->getMessage());
            }
        }

        $request = $adapter->buildRequest($provider, $messages, $systemPrompt, $maxTokens, $caBundlePath);
        $context = $logContext + ['provider' => $provider->name, 'provider_id' => $provider->id, 'type' => $provider->type, 'model' => $provider->model];
        $started = microtime(true);

        try {
            $response = $this->transport->send($request);
            $result = $adapter->parseResponse($response)->withDuration((int) round((microtime(true) - $started) * 1000));
            // HTTP 200 с пустым содержимым — ошибка генерации, а не ответ (его нельзя сохранять и тарифицировать).
            if (trim($result->text) === '') {
                throw new LlmException('empty_response', 'HTTP ' . $response->status . ', пустой content' . ($result->finishReason !== null ? ' (finish_reason=' . $result->finishReason . ')' : '') . '.', $response->status);
            }
        } catch (HttpTransportException $e) {
            $exception = new LlmException('transport_' . $e->kind, $e->getMessage());
            $this->logFailure($exception, $context, $started);
            throw $exception;
        } catch (LlmException $e) {
            $this->logFailure($e, $context, $started);
            throw $e;
        }

        $this->diagnosticLogger?->info(
            DiagnosticSource::LLM_GATEWAY,
            'llm_request_success',
            'Ответ LLM получен.',
            $context + [
                'http_status' => $response->status,
                'messages' => count($messages),
                // Не "*_tokens": DiagnosticLogger редактирует ключи со словом token.
                'usage_in' => $result->inputTokens,
                'usage_out' => $result->outputTokens,
                'finish_reason' => $result->finishReason,
                'duration_ms' => $result->durationMs,
            ],
        );

        return $result;
    }

    /** @param array<string,scalar|null> $context */
    private function logFailure(LlmException $e, array $context, float $started): void
    {
        // Текст ошибки провайдера намеренно не пишем: только код, статус и тип.
        $this->diagnosticLogger?->error(
            DiagnosticSource::LLM_GATEWAY,
            'llm_request_failed_' . $e->errorCode,
            'Запрос к LLM завершился ошибкой ' . $e->errorCode . '.',
            $context + [
                'http_status' => $e->httpStatus,
                'provider_error_type' => $e->providerErrorType,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
        );
    }
}
