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
    ) {
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

    public static function describeForAdmin(LlmException $e): string
    {
        $prefix = match (true) {
            $e->errorCode === 'auth_failed' => 'Ключ отклонён',
            $e->errorCode === 'rate_limited' => 'Лимит запросов',
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
