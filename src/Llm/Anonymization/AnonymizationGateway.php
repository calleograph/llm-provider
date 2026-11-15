<?php

declare(strict_types=1);

namespace App\Llm\Anonymization;

use App\Diagnostics\DiagnosticLogger;
use App\Diagnostics\DiagnosticSource;
use App\Llm\Http\HttpRequest;
use App\Llm\Http\HttpResponse;
use App\Llm\Http\HttpTransport;
use App\Llm\Http\HttpTransportException;

/**
 * ЕДИНСТВЕННЫЙ компонент приложения, который обращается к внутреннему
 * сервису обезличивания. Любой код, отправляющий пользовательские данные
 * во внешние сервисы, обязан сначала получить AnonymizedText отсюда.
 *
 * Fail closed: при любой неопределённости (сервис не настроен, таймаут,
 * не-2xx, некорректный JSON, нет поля результата, пустой результат для
 * непустого входа) выбрасывается AnonymizationException, и вызывающий код
 * НЕ отправляет данные наружу.
 *
 * В диагностический журнал пишутся только метаданные: длины, число
 * фрагментов, длительность, HTTP-статус. Ни вход, ни ответ сервиса не
 * журналируются.
 */
final class AnonymizationGateway
{
    /**
     * @param \Closure():string $caBundleResolver путь к корпоративному CA bundle
     */
    public function __construct(
        private readonly AnonymizationSettings $settings,
        private readonly HttpTransport $transport,
        private readonly \Closure $caBundleResolver,
        private readonly ?DiagnosticLogger $diagnosticLogger = null,
    ) {
    }

    /**
     * @param string $purpose безопасная метка для журнала: message | file | check
     * @throws AnonymizationException
     */
    public function anonymize(string $text, string $purpose): AnonymizedText
    {
        if ($this->settings->isInternal()) {
            return $this->anonymizeMany([$text], $purpose)->texts[0];
        }
        if (!$this->settings->isConfigured()) {
            throw new AnonymizationException('not_configured', 'Сервис обезличивания не настроен.');
        }
        if (trim($text) === '') {
            return AnonymizedText::fromGateway('', $this);
        }

        $maxChunk = max(500, (int) $this->settings->get('max_chunk_chars'));
        $chunks = self::splitIntoChunks($text, $maxChunk);
        $started = microtime(true);
        $result = '';
        $replaced = 0;
        $docClean = $this->settings->isDocClean();

        try {
            foreach ($chunks as $chunk) {
                $result .= $docClean ? $this->anonymizeChunkDocClean($chunk, $replaced) : $this->anonymizeChunk($chunk);
            }
        } catch (AnonymizationException $e) {
            $this->diagnosticLogger?->error(
                DiagnosticSource::ANONYMIZATION,
                'anonymize_failed_' . $e->errorCode,
                $e->getMessage(),
                [
                    'purpose' => $purpose,
                    'chunks' => count($chunks),
                    'input_chars' => mb_strlen($text),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ],
            );
            throw $e;
        }

        $this->diagnosticLogger?->info(
            DiagnosticSource::ANONYMIZATION,
            'anonymize_success',
            'Текст обезличен.',
            [
                'purpose' => $purpose,
                'chunks' => count($chunks),
                'input_chars' => mb_strlen($text),
                'output_chars' => mb_strlen($result),
                'replaced' => $docClean ? $replaced : null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
        );

        return AnonymizedText::fromGateway($result, $this);
    }

    /**
     * Совместное обезличивание нескольких текстов одной сессией замен.
     * Встроенный сервис обрабатывает их одним запросом (согласованные обозначения,
     * исправление 0.56.1 «Переговорки»); внешние профили — по одному (совместимость).
     *
     * @param list<string> $texts
     * @param array<string,string> $draft черновик настроек (только лаборатория)
     * @throws AnonymizationException
     */
    public function anonymizeMany(array $texts, string $purpose, bool $detail = false, ?string $profile = null, array $draft = []): AnonymizationBatch
    {
        if (!$this->settings->isInternal()) {
            return new AnonymizationBatch(array_map(fn (string $t): AnonymizedText => $this->anonymize($t, $purpose), array_values($texts)));
        }

        return $this->callInternal(array_values($texts), $purpose, $detail, $profile ?? $this->settings->get('internal_profile'), $this->settings->internalEngineSettings($draft));
    }

    /**
     * Только секреты (пароли, токены, ключи) — для сообщений, которые пользователь с правом
     * allow_unanonymized отправляет без обезличивания: секреты скрываются при любых настройках
     * доступа (как guard.mask_secrets «Переговорки» на каждом обращении к модели). Остальные
     * категории остаются как есть. Без встроенного сервиса (установка без контейнеров) — текст
     * не меняется, как раньше.
     *
     * @param list<string> $texts
     * @throws AnonymizationException
     */
    public function maskSecrets(array $texts, string $purpose): AnonymizationBatch
    {
        $texts = array_values($texts);
        if (AnonymizationSettings::internalUrl() === '' || AnonymizationSettings::internalToken() === '') {
            return new AnonymizationBatch(array_map(fn (string $t): AnonymizedText => AnonymizedText::fromGateway($t, $this), $texts));
        }
        $keepAll = array_fill_keys(array_keys(AnonymizationSettings::INTERNAL_CATEGORIES), 'keep');

        return $this->callInternal($texts, $purpose . '_secrets', false, 'custom', ['custom_actions' => $keepAll]);
    }

    /**
     * @param list<string> $texts
     * @param array<string,mixed> $engineSettings
     */
    private function callInternal(array $texts, string $purpose, bool $detail, string $profile, array $engineSettings): AnonymizationBatch
    {
        if (AnonymizationSettings::internalUrl() === '' || AnonymizationSettings::internalToken() === '') {
            throw new AnonymizationException('not_configured', 'Встроенный сервис обезличивания не настроен (ANONYMIZER_URL/INTERNAL_API_TOKEN).');
        }
        if (array_filter($texts, static fn (string $t): bool => trim($t) !== '') === []) {
            return new AnonymizationBatch(array_map(fn (string $t): AnonymizedText => AnonymizedText::fromGateway($t, $this), $texts));
        }
        $started = microtime(true);
        $chars = array_sum(array_map('mb_strlen', $texts));
        $context = ['purpose' => $purpose, 'texts' => count($texts), 'input_chars' => $chars];
        try {
            $timeout = max(5, (int) $this->settings->get('internal_timeout'));
            $response = $this->send(HttpRequest::json(
                AnonymizationSettings::internalUrl() . '/v1/anonymize',
                [
                    'texts' => $texts,
                    'profile' => $profile,
                    'settings' => $engineSettings,
                    'detail' => $detail,
                ],
                ['X-Internal-Token' => AnonymizationSettings::internalToken()],
                5,
                $timeout + 10,
                null,
                true,
            ));
            $json = $response->json();
            if (!$response->isSuccess()) {
                $code = is_array($json) && is_array($json['detail'] ?? null) && is_string($json['detail']['code'] ?? null) ? $json['detail']['code']
                    : (is_array($json) && is_string($json['detail'] ?? null) && preg_match('/\A[a-z_]{2,40}\z/', $json['detail']) === 1 ? $json['detail'] : 'http_' . $response->status);
                $message = is_array($json) && is_array($json['detail'] ?? null) && is_string($json['detail']['message'] ?? null) ? $json['detail']['message'] : 'HTTP ' . $response->status;
                throw new AnonymizationException(match ($code) {
                    'verification_failed' => 'not_clean',
                    'nlp_unavailable' => 'nlp_unavailable',
                    'timeout' => 'timeout',
                    default => $code,
                }, $message);
            }
            if (!is_array($json) || !is_array($json['texts'] ?? null) || count($json['texts']) !== count($texts) || ($json['verified'] ?? null) !== true) {
                throw new AnonymizationException('invalid_json', 'Ответ сервиса обезличивания не соответствует контракту.');
            }
            $out = [];
            foreach ($texts as $i => $source) {
                $value = $json['texts'][$i] ?? null;
                if (!is_string($value)) {
                    throw new AnonymizationException('missing_field', 'Нет текста №' . $i . ' в ответе.');
                }
                if (trim($source) !== '' && trim($value) === '') {
                    throw new AnonymizationException('empty_result', 'Пустой результат для непустого текста.');
                }
                $out[] = AnonymizedText::fromGateway($value, $this);
            }
            $stats = [];
            foreach ((array) ($json['stats'] ?? []) as $category => $count) {
                if (is_string($category) && preg_match('/\A[a-z_]{2,20}\z/', $category) === 1 && is_int($count)) {
                    $stats[$category] = $count;
                }
            }
            $batch = new AnonymizationBatch(
                $out,
                (int) ($json['replaced'] ?? array_sum($stats)),
                $stats,
                array_values(array_filter((array) ($json['warnings'] ?? []), 'is_string')),
                (int) round((microtime(true) - $started) * 1000),
                $detail && is_array($json['replacements'] ?? null) ? $json['replacements'] : [],
                $detail && is_array($json['candidates'] ?? null) ? $json['candidates'] : [],
            );
        } catch (AnonymizationException $e) {
            $this->diagnosticLogger?->error(DiagnosticSource::ANONYMIZATION, 'anonymize_failed_' . $e->errorCode, 'Обезличивание не выполнено: ' . $e->errorCode . '.',
                $context + ['duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
            throw $e;
        }

        // Только объёмы и счётчики по категориям — без текста и найденных значений.
        $this->diagnosticLogger?->info(DiagnosticSource::ANONYMIZATION, 'anonymize_success', 'Текст обезличен встроенным сервисом.', $context + [
            'replaced' => $batch->replaced, 'categories' => implode(',', array_map(static fn ($k, $v) => $k . ':' . $v, array_keys($batch->stats), $batch->stats)),
            'duration_ms' => $batch->durationMs,
        ]);

        return $batch;
    }

    /** Встроенный сервис: обезличивание всех частей запроса одним вызовом. */
    public function supportsBatch(): bool
    {
        return $this->settings->isInternal();
    }

    /** Файлы целиком обрабатываются сервисом (DocClean api_file). */
    public function usesFileApi(): bool
    {
        return $this->settings->isConfigured() && $this->settings->usesFileApi();
    }

    /**
     * Обезличивание файла сервисом DocClean:
     *   api_file (multipart, нейтральное имя файла) → проверка ok и
     *   verification.clean → api_download по одноразовому токену →
     *   сохранение результата в рабочий каталог запроса → локальное
     *   извлечение текста из УЖЕ обезличенного результата → (по настройке)
     *   повторный прогон текста через api_text.
     *
     * OCR сканов и изображений выполняет сервис (converted_to_text).
     * Любое отклонение от ожидаемого ответа — AnonymizationException.
     *
     * @throws AnonymizationException
     * @throws \App\Llm\Files\FileProcessingException при ошибке локального извлечения
     */
    public function anonymizeFile(\App\Llm\Files\StagedFile $file, \App\Llm\Files\FileProcessor $processor): AnonymizedFile
    {
        if (!$this->usesFileApi()) {
            throw new AnonymizationException('not_configured', 'Обезличивание файлов через api_file не настроено.');
        }

        $started = microtime(true);
        $context = ['purpose' => 'file_api', 'type' => $file->extension, 'size' => $file->size];

        try {
            [$connectTimeout, $timeout, $caBundle, $allowHttp] = $this->requestOptions();
            $fields = [
                // Исходное имя может само содержать ПДн — сервису передаётся нейтральное.
                'document' => new \CURLFile($file->path, 'application/octet-stream', 'document.' . $file->extension),
                'mode' => $this->settings->get('docclean_mode'),
            ];
            $groups = $this->settings->docCleanGroups();
            if ($groups !== []) {
                $fields['groups'] = implode(',', $groups);
            }

            $response = $this->send(HttpRequest::multipart(
                $this->settings->docCleanUrl('api_file'),
                $fields,
                $this->authHeaders(),
                $connectTimeout,
                max($timeout, 60),
                $caBundle,
                $allowHttp,
            ));
            $json = $this->docCleanJson($response);

            $verification = is_array($json['verification'] ?? null) ? $json['verification'] : [];
            if (($verification['clean'] ?? null) !== true) {
                throw new AnonymizationException('not_clean', 'api_file: verification.clean !== true, leaked=' . (int) ($verification['leaked_values_count'] ?? -1));
            }

            $token = (string) ($json['download_token'] ?? '');
            $resultFormat = strtolower((string) ($json['file']['result_format'] ?? ''));
            if (preg_match('/\A[A-Za-z0-9_-]{8,200}\z/', $token) !== 1) {
                throw new AnonymizationException('missing_field', 'api_file: нет корректного download_token.');
            }
            if (!in_array($resultFormat, $processor->supportedExtensions(), true)) {
                throw new AnonymizationException('unsupported_result', 'api_file: формат результата не поддерживается: ' . $resultFormat);
            }

            $download = $this->send(new HttpRequest(
                'GET',
                $this->settings->docCleanUrl('api_download', ['token' => $token]),
                $this->authHeaders(),
                null,
                $connectTimeout,
                max($timeout, 60),
                $caBundle,
                $allowHttp,
                64 * 1024 * 1024,
            ));
            if ($download->status !== 200 || $download->body === '') {
                throw new AnonymizationException('http_' . $download->status, 'api_download вернул HTTP ' . $download->status . '.');
            }

            $resultPath = $file->workDir . '/anonymized.' . $resultFormat;
            if (file_put_contents($resultPath, $download->body) === false) {
                throw new AnonymizationException('storage_error', 'Не удалось сохранить результат обезличивания.');
            }
            @chmod($resultPath, 0600);

            $extracted = $processor->extractText($resultPath, $resultFormat, $file->workDir);

            // Повторная проверка извлечённого текста (например, метаданные,
            // которые обработчик формата мог не затронуть). Прошедший
            // обезличивание текст повторно не ломается: метки остаются метками.
            $secondPass = $this->settings->get('second_pass') === '1';
            $text = $secondPass
                ? $this->anonymize($extracted->text, 'file_recheck')
                : AnonymizedText::fromGateway($extracted->text, $this);
        } catch (AnonymizationException $e) {
            $this->diagnosticLogger?->error(
                DiagnosticSource::ANONYMIZATION,
                'anonymize_file_failed_' . $e->errorCode,
                $e->getMessage(),
                $context + ['duration_ms' => (int) round((microtime(true) - $started) * 1000)],
            );
            throw $e;
        }

        $result = new AnonymizedFile(
            $text,
            (string) ($json['file']['detected_format'] ?? $file->extension),
            $resultFormat,
            (bool) ($json['file']['converted_to_text'] ?? false),
            (int) ($json['report']['total_replaced'] ?? 0),
            (int) ($json['residualRiskScan']['high'] ?? 0),
            $secondPass,
        );

        $this->diagnosticLogger?->info(
            DiagnosticSource::ANONYMIZATION,
            'anonymize_file_success',
            'Файл обезличен сервисом.',
            $context + [
                'detected_format' => $result->detectedFormat,
                'result_format' => $result->resultFormat,
                'converted_to_text' => $result->convertedToText ? 1 : 0,
                'replaced' => $result->totalReplaced,
                'residual_high' => $result->residualHigh,
                'second_pass' => $secondPass ? 1 : 0,
                'output_chars' => mb_strlen($text->value),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
        );

        return $result;
    }

    /**
     * Проверка подключения из админки: синтетическая строка без реальных
     * данных проходит полный путь (запрос, авторизация, разбор ответа).
     *
     * @return array{ok:bool,message:string,duration_ms:int}
     */
    public function testConnection(): array
    {
        $started = microtime(true);
        try {
            if ($this->settings->isInternal()) {
                if (!$this->settings->isConfigured()) {
                    throw new AnonymizationException('not_configured', 'ANONYMIZER_URL/INTERNAL_API_TOKEN не заданы.');
                }
                $ready = $this->send(new HttpRequest('GET', AnonymizationSettings::internalUrl() . '/readyz', [], null, 3, 5, null, true));
                if ($ready->status !== 200) {
                    throw new AnonymizationException('nlp_unavailable', 'Сервис запущен, но модели распознавания имён не готовы (HTTP ' . $ready->status . ').');
                }
            }
            if ($this->settings->isDocClean()) {
                // Спецификация открыта без ключа: подтверждает, что по Base URL
                // отвечает именно DocClean, а не заглушка веб-сервера.
                [$connectTimeout, $timeout, $caBundle, $allowHttp] = $this->requestOptions();
                $spec = $this->send(new HttpRequest('GET', $this->settings->docCleanUrl('openapi'), ['Accept' => 'application/json'], null, $connectTimeout, $timeout, $caBundle, $allowHttp));
                $specJson = $spec->json();
                if ($spec->status !== 200 || !isset($specJson['openapi'], $specJson['paths']) || !is_array($specJson['paths'])
                    || !array_key_exists('/index.php?action=api_text', $specJson['paths'])) {
                    throw new AnonymizationException('not_docclean', 'По указанному Base URL не найден API DocClean (openapi).');
                }
            }
            $healthEndpoint = $this->settings->isDocClean() ? '' : $this->settings->get('health_endpoint');
            if ($healthEndpoint !== '') {
                $response = $this->send(new HttpRequest(
                    'GET',
                    $this->settings->url($healthEndpoint),
                    $this->authHeaders() + ['Accept' => 'application/json'],
                    null,
                    ...$this->requestOptions(),
                ));
                if (!$response->isSuccess()) {
                    throw new AnonymizationException('http_' . $response->status, 'Health endpoint вернул HTTP ' . $response->status . '.');
                }
            }
            $this->anonymize('Проверка связи: Иванов Иван Иванович, тел. +7 900 000-00-00.', 'check');
            $message = 'Сервис отвечает, результат обезличивания получен.';
            $ok = true;
        } catch (AnonymizationException $e) {
            $message = self::describe($e);
            $ok = false;
        }

        $this->settings->recordCheck($ok, $message);

        return ['ok' => $ok, 'message' => $message, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    public static function describe(AnonymizationException $e): string
    {
        return match (true) {
            $e->errorCode === 'not_configured' => 'Сервис обезличивания не настроен.',
            $e->errorCode === 'nlp_unavailable' => 'Встроенный сервис обезличивания не готов: модели распознавания имён не загружены.',
            $e->errorCode === 'timeout' => 'Обезличивание не уложилось в отведённое время.',
            $e->errorCode === 'invalid_json' => 'Сервис вернул не JSON.',
            $e->errorCode === 'missing_field' => 'В ответе нет поля с обезличенным текстом (проверьте «Поле ответа»).',
            $e->errorCode === 'status_not_ok' => 'Сервис сообщил о неуспешной обработке.',
            $e->errorCode === 'empty_result' => 'Сервис вернул пустой результат для непустого текста.',
            $e->errorCode === 'secret_unavailable' => 'Не удалось расшифровать сохранённый токен (проверьте master.key).',
            $e->errorCode === 'not_docclean' => 'По указанному URL не отвечает API DocClean (нет спецификации openapi).',
            $e->errorCode === 'not_clean' => 'Сервис вернул verification.clean = false — такой текст не отправляется наружу.',
            $e->errorCode === 'api_unauthorized' => 'DocClean отклонил API-ключ (UNAUTHORIZED).',
            $e->errorCode === 'api_api_disabled' => 'API в DocClean выключен (API_DISABLED) — включите на вкладке API его админки.',
            $e->errorCode === 'status_not_ok' => 'Сервис вернул ok = false.',
            str_starts_with($e->errorCode, 'api_') => 'DocClean вернул ошибку ' . strtoupper(substr($e->errorCode, 4)) . '.',
            str_starts_with($e->errorCode, 'http_') => 'Сервис ответил HTTP ' . substr($e->errorCode, 5) . '.',
            str_starts_with($e->errorCode, 'transport_') => 'Сетевая ошибка: ' . substr($e->errorCode, 10) . '.',
            default => 'Ошибка обезличивания: ' . $e->errorCode . '.',
        };
    }

    /**
     * DocClean api_text: {text, mode, groups} → {ok, text, report, verification}.
     * Принимается только ok === true, строковый text и verification.clean === true —
     * это прямое требование документации сервиса перед отправкой во внешнюю модель.
     */
    private function anonymizeChunkDocClean(string $chunk, int &$replaced): string
    {
        $payload = ['text' => $chunk, 'mode' => $this->settings->get('docclean_mode')];
        $groups = $this->settings->docCleanGroups();
        if ($groups !== []) {
            $payload['groups'] = $groups;
        }

        [$connectTimeout, $timeout, $caBundle, $allowHttp] = $this->requestOptions();
        $json = $this->docCleanJson($this->send(HttpRequest::json(
            $this->settings->docCleanUrl('api_text'),
            $payload,
            $this->authHeaders(),
            $connectTimeout,
            $timeout,
            $caBundle,
            $allowHttp,
        )));

        if (!isset($json['text']) || !is_string($json['text'])) {
            throw new AnonymizationException('missing_field', 'В ответе api_text нет строкового поля text.');
        }
        $verification = is_array($json['verification'] ?? null) ? $json['verification'] : [];
        if (($verification['clean'] ?? null) !== true) {
            throw new AnonymizationException('not_clean', 'api_text: verification.clean !== true, leaked=' . (int) ($verification['leaked_values_count'] ?? -1));
        }
        if (trim($chunk) !== '' && trim($json['text']) === '') {
            throw new AnonymizationException('empty_result', 'api_text вернул пустой текст.');
        }
        $replaced += (int) ($json['report']['total_replaced'] ?? 0);

        return $json['text'];
    }

    /**
     * Общая проверка ответа DocClean: JSON-объект, HTTP 2xx и ok === true.
     * Ошибки сервиса отдают стабильное поле code (UNAUTHORIZED, API_DISABLED,
     * FILE_REJECTED, PAYLOAD_TOO_LARGE, OCR_TIMEOUT, …) — оно становится
     * кодом исключения api_<code>. Текст error/trace_id не пробрасывается
     * пользователю; trace_id попадает только в техническое сообщение.
     *
     * @return array<mixed>
     */
    private function docCleanJson(HttpResponse $response): array
    {
        $json = $response->json();
        if (!$response->isSuccess()) {
            $code = is_array($json) && isset($json['code']) && is_string($json['code']) && preg_match('/\A[A-Z0-9_]{2,60}\z/', $json['code']) === 1
                ? 'api_' . strtolower($json['code'])
                : 'http_' . $response->status;
            $trace = is_array($json) && isset($json['trace_id']) && is_scalar($json['trace_id']) ? ' trace_id=' . mb_substr((string) $json['trace_id'], 0, 64) : '';
            throw new AnonymizationException($code, 'DocClean вернул HTTP ' . $response->status . $trace . '.');
        }
        if ($json === null) {
            throw new AnonymizationException('invalid_json', 'Ответ DocClean не является JSON (проверьте Base URL — возможно, это страница-заглушка веб-сервера).');
        }
        if (($json['ok'] ?? null) !== true) {
            throw new AnonymizationException('status_not_ok', 'DocClean вернул ok !== true.');
        }

        return $json;
    }

    private function anonymizeChunk(string $chunk): string
    {
        $payload = [];
        $extra = trim($this->settings->get('extra_body'));
        if ($extra !== '') {
            $decoded = json_decode($extra, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        self::setPath($payload, $this->settings->get('request_field'), $chunk);

        $options = $this->requestOptions();
        $request = HttpRequest::json(
            $this->settings->url($this->settings->get('endpoint')),
            $payload,
            $this->authHeaders(),
            $options[0],
            $options[1],
            $options[2],
            $options[3],
        );
        $response = $this->send($request);

        if (!$response->isSuccess()) {
            throw new AnonymizationException('http_' . $response->status, 'Сервис обезличивания вернул HTTP ' . $response->status . '.');
        }
        $json = $response->json();
        if ($json === null) {
            throw new AnonymizationException('invalid_json', 'Ответ сервиса обезличивания не является JSON.');
        }

        $statusField = $this->settings->get('status_field');
        if ($statusField !== '') {
            $status = self::getPath($json, $statusField);
            $expected = $this->settings->get('status_ok_value');
            if (!is_scalar($status) || ($expected !== '' && (string) $status !== $expected) || ($expected === '' && !$status)) {
                throw new AnonymizationException('status_not_ok', 'Сервис обезличивания сообщил о неуспешной обработке.');
            }
        }

        $value = self::getPath($json, $this->settings->get('response_field'));
        if (!is_string($value)) {
            throw new AnonymizationException('missing_field', 'В ответе нет строкового поля ' . $this->settings->get('response_field') . '.');
        }
        if (trim($chunk) !== '' && trim($value) === '') {
            throw new AnonymizationException('empty_result', 'Сервис обезличивания вернул пустой текст.');
        }

        return $value;
    }

    private function send(HttpRequest $request): HttpResponse
    {
        try {
            return $this->transport->send($request);
        } catch (HttpTransportException $e) {
            throw new AnonymizationException('transport_' . $e->kind, $e->getMessage());
        }
    }

    /** @return array{0:int,1:int,2:?string,3:bool} */
    private function requestOptions(): array
    {
        $caBundle = null;
        if ($this->settings->get('use_corporate_ca') === '1') {
            try {
                $caBundle = ($this->caBundleResolver)();
            } catch (\Throwable $e) {
                throw new AnonymizationException('transport_tls', 'CA bundle приложения недоступен: ' . $e->getMessage());
            }
        }

        return [
            max(1, (int) $this->settings->get('connect_timeout')),
            max(1, (int) $this->settings->get('timeout')),
            $caBundle,
            $this->settings->get('allow_http') === '1',
        ];
    }

    /** @return array<string,string> */
    private function authHeaders(): array
    {
        $type = $this->settings->isDocClean() ? AnonymizationSettings::AUTH_BEARER : $this->settings->get('auth_type');
        if ($type === AnonymizationSettings::AUTH_NONE) {
            return [];
        }
        try {
            $secret = (string) $this->settings->secret();
        } catch (\Throwable) {
            throw new AnonymizationException('secret_unavailable', 'Не удалось расшифровать секрет сервиса обезличивания.');
        }

        return match ($type) {
            AnonymizationSettings::AUTH_BEARER => ['Authorization' => 'Bearer ' . $secret],
            AnonymizationSettings::AUTH_HEADER => [$this->settings->get('auth_header_name') => $secret],
            AnonymizationSettings::AUTH_BASIC => ['Authorization' => 'Basic ' . base64_encode($this->settings->get('auth_username') . ':' . $secret)],
            default => [],
        };
    }

    /**
     * Делит текст на фрагменты не длиннее $max символов по границам абзацев
     * и строк, чтобы не разрывать сущности (ФИО, адреса) посередине.
     *
     * @return string[]
     */
    public static function splitIntoChunks(string $text, int $max): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text];
        }

        $chunks = [];
        $current = '';
        foreach (preg_split('/(?<=\n)/u', $text) ?: [$text] as $line) {
            while (mb_strlen($line) > $max) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $piece = mb_substr($line, 0, $max);
                $cut = max(mb_strrpos($piece, ' ') ?: 0, (int) ($max * 0.5));
                $chunks[] = mb_substr($line, 0, $cut);
                $line = mb_substr($line, $cut);
            }
            if (mb_strlen($current) + mb_strlen($line) > $max) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $line;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** @param array<mixed> $data */
    private static function getPath(array $data, string $path): mixed
    {
        $node = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /** @param array<mixed> $data */
    private static function setPath(array &$data, string $path, mixed $value): void
    {
        $node = &$data;
        foreach (explode('.', $path) as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node = $value;
    }
}
