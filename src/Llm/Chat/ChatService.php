<?php

declare(strict_types=1);

namespace App\Llm\Chat;

use App\Llm\Access\AccessPolicy;
use App\Llm\Anonymization\AnonymizationException;
use App\Llm\Anonymization\AnonymizationGateway;
use App\Llm\Anonymization\AnonymizedFile;
use App\Llm\Anonymization\AnonymizedText;
use App\Llm\Files\FileProcessingException;
use App\Llm\Files\FileProcessor;
use App\Llm\Files\StagedFile;
use App\Llm\Gateway\LlmException;
use App\Llm\Gateway\LlmGateway;
use App\Llm\Context\ContextBuilder;
use App\Llm\Context\ContextOverflowException;
use App\Llm\Context\SummaryService;
use App\Llm\LlmEventLog;

/**
 * Оркестратор отправки сообщения:
 *
 *   права (AccessPolicy) → [файл: api_file сервиса обезличивания либо
 *   локальное извлечение/OCR] →
 *   AnonymizationGateway (fail closed) → LLM Gateway → сохранение
 *   ТОЛЬКО обезличенных реплик → ответ пользователю.
 *
 * Исходный текст пользователя и текст файла существуют лишь в памяти
 * текущего запроса и нигде не сохраняются и не журналируются. При
 * ошибке любого шага до LLM запрос наружу не отправляется.
 *
 * Исключение — явный флаг $skipAnonymization в send(): пользователь с
 * правом allow_unanonymized (см. AccessPolicy::canSkipAnonymization())
 * может подтвердить для конкретного сообщения, что в нём нет
 * конфиденциальных данных. В этом случае текст и файлы НЕ проходят
 * AnonymizationGateway (файл — только локальное извлечение, без похода
 * в сервис обезличивания даже целиком), уходят в LLM Gateway как есть и
 * сохраняются в истории в открытом виде. Каждый такой случай пишет
 * отдельное событие в аудит-лог (LLM_SEND_UNANONYMIZED).
 */
final class ChatService
{
    public function __construct(
        private readonly AccessPolicy $accessPolicy,
        private readonly ConversationRepository $conversations,
        private readonly AnonymizationGateway $anonymizer,
        private readonly LlmGateway $llmGateway,
        private readonly FileProcessor $fileProcessor,
        private readonly ChatSettings $settings,
        private readonly LlmEventLog $events,
        private readonly ProjectRepository $projects,
        private readonly UsageLimiter $usage,
        private readonly StorageQuotaService $storageQuota,
        private readonly \App\Llm\Provider\ModelRepository $models,
        private readonly ?SummaryService $summaries = null,
        // false — только в тестах: источник не из $_FILES (copy вместо move_uploaded_file).
        private readonly bool $isHttpUpload = true,
    ) {
    }

    /** Разом можно приложить не больше 5 файлов — защита от чрезмерно долгой/тяжёлой обработки одного запроса. */
    public const MAX_UPLOADS = 5;

    /**
     * @param array<int,array{tmp_name:string,name:string,size:int,error:int}> $uploads до self::MAX_UPLOADS штук
     * @param bool $skipAnonymization пользователь явно подтвердил, что в сообщении/файлах нет
     *   конфиденциальных данных — обезличивание пропускается, сообщение уходит в LLM как есть.
     *   Требует права allow_unanonymized хотя бы у одной группы пользователя (проверяется здесь).
     * @return array{conversation:array{id:int,title:string,provider_id:int},user_message:array<string,mixed>,assistant_message:array<string,mixed>}
     * @throws ChatException
     */
    public function send(ChatUser $user, string $ip, ?int $conversationId, int $providerId, string $text, array $uploads, ?int $projectId = null, bool $skipAnonymization = false, ?int $modelId = null): array
    {
        if (count($uploads) > self::MAX_UPLOADS) {
            throw new ChatException('too_many_files', 'Можно приложить не более ' . self::MAX_UPLOADS . ' файлов за раз.');
        }
        $log = $user->logName();

        if ($skipAnonymization && !$this->accessPolicy->canSkipAnonymization($user->groupIds, $user->ownerKey())) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_UNANONYMIZED_DENIED');
            throw new ChatException('access_denied', 'Отправка без обезличивания вам не разрешена.', 403);
        }

        // 0. Блокировка и лимит токенов пользователя на период.
        try {
            $this->usage->assertCanSend($user->ownerKey(), $user->groupIds);
        } catch (ChatException $e) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, $e->errorCode === 'limit_exceeded' ? 'LLM_LIMIT_EXCEEDED' : 'LLM_USER_BLOCKED');
            throw $e;
        }

        // 1. Права — на backend, по данным БД на момент запроса.
        $allowed = $this->accessPolicy->allowedProviders($user->groupIds, $user->domainId, $user->ownerKey());
        if (!isset($allowed[$providerId])) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_ACCESS_DENIED', ['provider_id' => $providerId]);
            throw new ChatException('access_denied', 'Выбранная нейросеть вам недоступна.', 403);
        }
        $providerName = $allowed[$providerId]['name'];
        // Модель: явно выбранная (должна принадлежать провайдеру) или модель провайдера по умолчанию.
        $allowedModels = $this->models->allowedFor($allowed);
        $modelId ??= $this->models->defaultFor($providerId);
        if ($modelId === null || !isset($allowedModels[$modelId]) || $allowedModels[$modelId]['provider_id'] !== $providerId) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_ACCESS_DENIED', ['provider_id' => $providerId, 'model_id' => $modelId]);
            throw new ChatException('access_denied', 'Выбранная модель вам недоступна.', 403);
        }

        $conversation = null;
        if ($conversationId !== null) {
            $conversation = $this->conversations->findOwned($conversationId, $user->ownerKey());
            if ($conversation === null) {
                throw new ChatException('not_found', 'Диалог не найден.', 404);
            }
            $projectId = $conversation['project_id'] !== null ? (int) $conversation['project_id'] : null;
        } elseif ($projectId !== null && $this->projects->findOwned($projectId, $user->ownerKey()) === null) {
            throw new ChatException('not_found', 'Проект не найден.', 404);
        }

        $text = trim($text);
        $maxChars = max(100, (int) $this->settings->get('max_message_chars'));
        if (mb_strlen($text) > $maxChars) {
            throw new ChatException('message_too_long', 'Сообщение длиннее ' . $maxChars . ' символов.');
        }
        if ($text === '' && $uploads === []) {
            throw new ChatException('empty', 'Введите сообщение или приложите файл.');
        }

        // Грубая предварительная проверка квоты хранения — до дорогостоящего
        // обращения к LLM. Точный итоговый размер (вопрос + ответ модели)
        // известен только после ответа, но ответ обычно сопоставим по объёму
        // с вопросом, поэтому запас x2 отсекает большинство переполнений
        // заранее, не тратя токены впустую на заведомо несохраняемый ответ.
        $this->storageQuota->assertCanStore($user->ownerKey(), strlen($text) * 2, $user->groupIds);

        // 2–3. Файлы и текст: обезличивание обязательно для всего, что уйдёт
        // наружу — КРОМЕ случая явного skipAnonymization (пользователь взял
        // на себя ответственность за конкретное сообщение). Обычный путь:
        // каждый файл либо целиком через api_file сервиса обезличивания,
        // либо локальное извлечение + обезличивание текста. При
        // skipAnonymization файл никогда не уходит в сервис обезличивания
        // (даже целиком) — только локальное извлечение текста, без
        // последующего вызова anonymize(). Временные файлы удаляются сразу
        // после обработки каждого. Если упадёт файл номер N — предыдущие
        // не сохраняются: send() либо целиком успевает, либо целиком
        // откатывается (ничего не уходит наружу и не пишется в историю).
        if ($skipAnonymization || $this->anonymizer->supportsBatch()) {
            // Встроенный сервис: сначала локальное извлечение всех вложений, затем текст
            // и вложения — ОДНИМ вызовом обезличивания (одна сессия замен: один человек —
            // одно обозначение в сообщении и во всех файлах). Без обезличивания — только
            // маскирование секретов, тоже одним вызовом.
            $files = array_map(fn (array $upload): object => $this->extractUpload($user, $ip, $upload, $skipAnonymization ? 'extracted_unanonymized' : 'extracted'), $uploads);
            $parts = [$text, ...array_map(static fn (object $f): string => $f->raw, $files)];
            $batch = $skipAnonymization
                ? $this->maskSecrets($user, $ip, $parts)
                : $this->anonymizeParts($user, $ip, $parts, $files === [] ? 'message' : 'message_files');
            $textContent = $batch[0];
            foreach ($files as $i => $file) {
                $file->content = $batch[$i + 1];
                unset($file->raw);
            }
            unset($parts, $batch);
        } else {
            $files = [];
            foreach ($uploads as $upload) {
                $files[] = $this->handleUpload($user, $ip, $upload);
            }
            $textContent = $this->anonymize($user, $ip, $text, 'message');
        }
        $text = '';
        $fileContents = array_map(
            static fn (object $file): AnonymizedText => $file->content->withLabel('[Вложение: ' . strtoupper($file->extension) . ']'),
            $files,
        );
        $userContent = AnonymizedText::join(array_values(array_filter([$textContent, ...$fileContents])));
        if ($userContent->isEmpty()) {
            throw new ChatException('empty', 'После обработки не осталось текста для отправки.');
        }
        if ($skipAnonymization) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_SEND_UNANONYMIZED', [
                'provider' => $providerName, 'provider_id' => $providerId, 'conversation_id' => $conversation['id'] ?? 'new',
                'text_chars' => mb_strlen($userContent->value), 'attachments' => count($files),
            ]);
        }

        // 4. Контекст по окну выбранной модели: иерархия инструкций, сводка, состояние проекта,
        // источники (целиком или релевантные фрагменты), свежая история. Только обезличенные данные.
        $plan = $this->buildContext($user, $conversation, $projectId, $allowedModels[$modelId], $userContent->value);
        $history = array_map(static fn (array $m): array => ['role' => $m['role'], 'content' => AnonymizedText::fromStorage($m['content'])], $plan['messages']);

        if ($conversation === null || (int) $conversation['provider_id'] !== $providerId) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_PROVIDER_SELECT_SUCCESS', [
                'provider' => $providerName, 'provider_id' => $providerId, 'conversation_id' => $conversation['id'] ?? 'new',
            ]);
        }

        // 5. LLM Gateway (повторно проверяет права).
        try {
            $result = $this->llmGateway->completeWithFailover($user, $modelId, $history, $plan['system'], null, $allowedModels,
                $this->settings->get('fallback_auto') === '1', 540, $plan['report']['total']);
        } catch (LlmException $e) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_REQUEST_FAILED', [
                'provider' => $providerName, 'provider_id' => $providerId, 'model_id' => $modelId, 'reason' => $e->errorCode, 'http_status' => $e->httpStatus,
                'last_reason' => $e->providerErrorType,
            ]);
            throw new ChatException('llm_' . $e->errorCode, $e->userMessage(), $e->errorCode === 'access_denied' ? 403 : 502);
        }

        // Расход: данные провайдера (вся история + контекст проекта), иначе оценка.
        $charged = ($result->inputTokens !== null || $result->outputTokens !== null)
            ? (int) $result->inputTokens + (int) $result->outputTokens
            : $plan['report']['total'] + UsageLimiter::estimateTokens(mb_strlen($result->text));
        $this->usage->record($user->ownerKey(), $charged);

        if ($result->fallbackFrom !== null) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_FALLBACK_USED', [
                'requested_model_id' => $modelId, 'answered_model_id' => $result->modelId, 'answered_provider' => $result->providerName, 'attempts' => $result->attempts,
            ]);
        }
        $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_REQUEST_SUCCESS', [
            'provider' => $result->providerName ?? $providerName, 'provider_id' => $result->providerId ?? $providerId, 'model' => $result->model,
            'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens, 'usage_charged' => $charged,
            'project_id' => $projectId, 'duration_ms' => $result->durationMs,
        ]);

        // 6. Сохранение: только обезличенный вопрос и ответ модели. Несколько
        // вложений записываются в одну строку через запятую и суммарным
        // размером — схема БД хранит одно текстовое поле на сообщение, а не
        // список, отдельная таблица ради счётчика в UI избыточна.
        $attachmentType = $files !== [] ? implode(', ', array_map(static fn (object $f): string => $f->extension, $files)) : null;
        $attachmentSize = $files !== [] ? array_sum(array_map(static fn (object $f): int => $f->size, $files)) : null;
        $saved = $this->conversations->transactional(function () use ($conversation, $user, $providerId, $projectId, $userContent, $result, $attachmentType, $attachmentSize, $charged, $skipAnonymization, $modelId, $plan): array {
            if ($conversation === null) {
                $title = self::titleFrom($userContent->value);
                $id = $this->conversations->create($user->ownerKey(), $title, $providerId, $projectId);
                $conversation = ['id' => $id, 'title' => $title, 'created' => true];
            } else {
                $this->conversations->touch((int) $conversation['id'], $providerId);
            }
            $id = (int) $conversation['id'];
            $userMessageId = $this->conversations->addMessage($id, 'user', $userContent->value, $providerId, null, $attachmentType, $attachmentSize, null, $skipAnonymization);
            $assistantMessageId = $this->conversations->addMessage($id, 'assistant', $result->text, $result->providerId ?? $providerId, $result->model, null, null, $charged);
            $this->conversations->setContextReport($assistantMessageId, $plan['report'] + ['input_tokens' => (int) $result->inputTokens, 'output_tokens' => (int) $result->outputTokens,
                'fallback_from' => $result->fallbackFrom, 'truncated' => $result->truncated()]);
            $this->conversations->setModel($id, $modelId);
            if ($projectId !== null) {
                $this->projects->touch($projectId);
            }

            return [
                'conversation' => ['id' => $id, 'title' => (string) $conversation['title'], 'provider_id' => $providerId, 'project_id' => $projectId, 'pinned' => (bool) ($conversation['pinned'] ?? false)],
                'created' => !empty($conversation['created']),
                'user_message_id' => $userMessageId,
                'assistant_message_id' => $assistantMessageId,
            ];
        });

        if ($saved['created']) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'CONVERSATION_CREATE_SUCCESS', [
                'conversation_id' => $saved['conversation']['id'], 'provider' => $providerName,
            ]);
        }

        return [
            'usage' => $this->usage->status($user->ownerKey(), $user->groupIds),
            'storage' => $this->storageQuota->status($user->ownerKey(), $user->groupIds),
            'conversation' => $saved['conversation'],
            'user_message' => [
                'id' => $saved['user_message_id'], 'role' => 'user', 'content' => $userContent->value,
                'attachment_type' => $attachmentType, 'attachment_size' => $attachmentSize,
                'unanonymized' => $skipAnonymization,
            ],
            'assistant_message' => [
                'id' => $saved['assistant_message_id'], 'role' => 'assistant', 'content' => $result->text,
                'model' => $result->model, 'provider' => $result->providerName ?? $providerName,
                // ответила резервная модель — пользователь видит это явно
                'fallback_from' => $result->fallbackFrom, 'truncated' => $result->truncated(),
                'context_report' => $plan['report'] + ['input_tokens' => (int) $result->inputTokens, 'output_tokens' => (int) $result->outputTokens],
            ],
            // Обслуживание контекста после ответа (сводка, состояние проекта) — вызывающий выполняет его, уже отдав ответ.
            'maintenance' => $plan['needsSummary'] ? ['conversation_id' => $saved['conversation']['id'], 'model_id' => $modelId, 'project_id' => $projectId] : null,
        ];
    }

    /**
     * @param array{tmp_name:string,name:string,size:int,error:int} $upload
     * @return object{content:AnonymizedText,extension:string,size:int}
     */
    private function handleUpload(ChatUser $user, string $ip, array $upload): object
    {
        $extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
        $fields = ['type' => $extension !== '' ? $extension : 'unknown', 'size' => (int) $upload['size']];
        $viaApi = $this->anonymizer->usesFileApi();

        try {
            if ((int) $upload['error'] === UPLOAD_ERR_INI_SIZE || (int) $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new FileProcessingException('too_large', 'Превышен upload_max_filesize.');
            }
            if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
                throw new FileProcessingException('upload_error', 'Код ошибки загрузки ' . (int) $upload['error']);
            }

            if ($viaApi) {
                // Файл целиком — во внутренний сервис обезличивания (его
                // обработчики форматов и OCR); наружу пойдёт только текст,
                // извлечённый из обезличенного результата.
                $result = $this->fileProcessor->withStagedFile(
                    (string) $upload['tmp_name'],
                    (string) $upload['name'],
                    (int) $upload['size'],
                    $this->isHttpUpload,
                    fn (StagedFile $staged): AnonymizedFile => $this->anonymizer->anonymizeFile($staged, $this->fileProcessor),
                );
                $content = $result->text;
                $this->events->user($ip, $user->logName(), LlmEventLog::OP_FILE, 'FILE_UPLOAD_SUCCESS', $fields + [
                    'result' => 'anonymized_by_service', 'detected_format' => $result->detectedFormat,
                    'result_format' => $result->resultFormat, 'ocr' => $result->convertedToText,
                    'text_chars' => mb_strlen($content->value),
                ]);
                $this->events->user($ip, $user->logName(), LlmEventLog::OP_ANONYMIZATION, 'ANONYMIZATION_SUCCESS', [
                    'purpose' => 'file_api', 'replaced' => $result->totalReplaced, 'residual_high' => $result->residualHigh,
                    'second_pass' => $result->secondPass,
                ]);
            } else {
                $file = $this->fileProcessor->process((string) $upload['tmp_name'], (string) $upload['name'], (int) $upload['size'], $this->isHttpUpload);
                $this->events->user($ip, $user->logName(), LlmEventLog::OP_FILE, 'FILE_UPLOAD_SUCCESS', $fields + [
                    'result' => 'extracted', 'ocr' => $file->ocrUsed, 'pages' => $file->pages, 'ocr_pages' => $file->ocrPages,
                    'text_chars' => mb_strlen($file->text),
                ]);
                $content = $this->anonymize($user, $ip, $file->text, 'file');
            }
        } catch (FileProcessingException $e) {
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_FILE, 'FILE_UPLOAD_FAILED', $fields + ['reason' => $e->errorCode]);
            throw new ChatException('file_' . $e->errorCode, $e->userMessage(), 422);
        } catch (AnonymizationException $e) {
            // Только ветка api_file: в текстовой ветке anonymize() уже сам
            // журналирует и превращает ошибку в ChatException.
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_FILE, 'FILE_UPLOAD_FAILED', $fields + ['reason' => 'anonymization']);
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_ANONYMIZATION, 'ANONYMIZATION_FAILED', [
                'purpose' => 'file_api', 'reason' => $e->errorCode,
            ]);
            throw new ChatException('anonymization_failed', $e->userMessage(), 503);
        }

        return (object) ['content' => $content, 'extension' => $extension, 'size' => (int) $upload['size']];
    }

    /**
     * Локальное извлечение текста вложения (FileProcessor: форматы, OCR) без
     * обращения к внешним сервисам. Исходный текст живёт только в памяти запроса
     * и сразу передаётся на обезличивание (или маскирование секретов).
     *
     * @param array{tmp_name:string,name:string,size:int,error:int} $upload
     * @return object{raw:string,extension:string,size:int}
     */
    private function extractUpload(ChatUser $user, string $ip, array $upload, string $resultLabel): object
    {
        $extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
        $fields = ['type' => $extension !== '' ? $extension : 'unknown', 'size' => (int) $upload['size']];

        try {
            if ((int) $upload['error'] === UPLOAD_ERR_INI_SIZE || (int) $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new FileProcessingException('too_large', 'Превышен upload_max_filesize.');
            }
            if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
                throw new FileProcessingException('upload_error', 'Код ошибки загрузки ' . (int) $upload['error']);
            }

            $file = $this->fileProcessor->process((string) $upload['tmp_name'], (string) $upload['name'], (int) $upload['size'], $this->isHttpUpload);
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_FILE, 'FILE_UPLOAD_SUCCESS', $fields + [
                'result' => $resultLabel, 'ocr' => $file->ocrUsed, 'pages' => $file->pages, 'ocr_pages' => $file->ocrPages,
                'text_chars' => mb_strlen($file->text),
            ]);
        } catch (FileProcessingException $e) {
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_FILE, 'FILE_UPLOAD_FAILED', $fields + ['reason' => $e->errorCode]);
            throw new ChatException('file_' . $e->errorCode, $e->userMessage(), 422);
        }

        return (object) ['raw' => $file->text, 'extension' => $extension, 'size' => (int) $upload['size']];
    }

    /**
     * Совместное обезличивание частей одного запроса (сообщение + вложения).
     *
     * @param list<string> $parts
     * @return list<AnonymizedText>
     */
    private function anonymizeParts(ChatUser $user, string $ip, array $parts, string $purpose): array
    {
        try {
            $batch = $this->anonymizer->anonymizeMany($parts, $purpose);
        } catch (AnonymizationException $e) {
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_ANONYMIZATION, 'ANONYMIZATION_FAILED', [
                'purpose' => $purpose, 'reason' => $e->errorCode, 'parts' => count($parts),
            ]);
            throw new ChatException('anonymization_failed', $e->userMessage(), 503);
        }
        $this->events->user($ip, $user->logName(), LlmEventLog::OP_ANONYMIZATION, 'ANONYMIZATION_SUCCESS', [
            'purpose' => $purpose, 'parts' => count($parts), 'text_chars' => array_sum(array_map('mb_strlen', $parts)),
            'replaced' => $batch->replaced, 'categories' => self::statsLine($batch->stats), 'duration_ms' => $batch->durationMs,
        ]);

        return $batch->texts;
    }

    /**
     * @param list<string> $parts
     * @return list<AnonymizedText>
     */
    private function maskSecrets(ChatUser $user, string $ip, array $parts): array
    {
        try {
            $batch = $this->anonymizer->maskSecrets($parts, 'unanonymized');
        } catch (AnonymizationException $e) {
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_ANONYMIZATION, 'SECRET_MASK_FAILED', ['reason' => $e->errorCode]);
            throw new ChatException('anonymization_failed', 'Не удалось проверить сообщение на пароли и ключи — запрос не отправлен.', 503);
        }
        if ($batch->replaced > 0) {
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_ANONYMIZATION, 'SECRET_MASKED', ['replaced' => $batch->replaced]);
        }

        return $batch->texts;
    }

    /** @param array<string,int> $stats */
    private static function statsLine(array $stats): string
    {
        return implode(',', array_map(static fn (string $k, int $v): string => $k . ':' . $v, array_keys($stats), $stats));
    }

    private function anonymize(ChatUser $user, string $ip, string $text, string $purpose): AnonymizedText
    {
        if (trim($text) === '') {
            return AnonymizedText::fromStorage('');
        }
        try {
            $result = $this->anonymizer->anonymize($text, $purpose);
        } catch (AnonymizationException $e) {
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_ANONYMIZATION, 'ANONYMIZATION_FAILED', [
                'purpose' => $purpose, 'reason' => $e->errorCode,
            ]);
            throw new ChatException('anonymization_failed', $e->userMessage(), 503);
        }

        $this->events->user($ip, $user->logName(), LlmEventLog::OP_ANONYMIZATION, 'ANONYMIZATION_SUCCESS', [
            'purpose' => $purpose, 'text_chars' => mb_strlen($text), 'result_chars' => mb_strlen($result->value),
        ]);

        return $result;
    }

    /**
     * Инструкции проекта уходят в модель как контекст — поэтому сначала
     * обезличиваются и хранятся только в обезличенном виде.
     *
     * @throws ChatException
     */
    public function setProjectInstructions(ChatUser $user, string $ip, int $projectId, string $instructions): string
    {
        if ($this->projects->findOwned($projectId, $user->ownerKey()) === null) {
            throw new ChatException('not_found', 'Проект не найден.', 404);
        }
        $instructions = trim($instructions);
        if (mb_strlen($instructions) > 8000) {
            throw new ChatException('too_long', 'Инструкции проекта — не длиннее 8000 символов.');
        }
        $anonymized = $this->anonymize($user, $ip, $instructions, 'project_instructions');
        $this->projects->setInstructions($projectId, $user->ownerKey(), $anonymized);
        $this->events->user($ip, $user->logName(), LlmEventLog::OP_CHAT, 'PROJECT_INSTRUCTIONS_UPDATE_SUCCESS', [
            'project_id' => $projectId, 'text_chars' => mb_strlen($anonymized->value),
        ]);

        return $anonymized->value;
    }

    /**
     * Стиль и личные постоянные инструкции пользователя (слой 6). Инструкции уходят в модель —
     * обезличиваются и хранятся только в обезличенном виде; пользователь видит итог.
     *
     * @return array{style:?string,instructions:string}
     */
    public function setUserPreferences(ChatUser $user, string $ip, ?string $style, string $instructions): array
    {
        $style = $this->checkStyle($style);
        $anon = $this->anonymizeInstruction($user, $ip, $instructions, 'user_instructions');
        $this->usage->touchProfile($user);
        $this->conversations->setUserPreferences($user->ownerKey(), $style, $anon);
        $this->events->user($ip, $user->logName(), LlmEventLog::OP_CHAT, 'USER_PREFERENCES_UPDATE', ['style' => $style ?? 'default', 'instructions_chars' => mb_strlen($anon)]);

        return ['style' => $style, 'instructions' => $anon];
    }

    /** @return array{style:?string,instructions:string} */
    public function setConversationPreferences(ChatUser $user, string $ip, int $conversationId, ?string $style, string $instructions): array
    {
        if ($this->conversations->findOwned($conversationId, $user->ownerKey()) === null) {
            throw new ChatException('not_found', 'Диалог не найден.', 404);
        }
        $style = $this->checkStyle($style);
        $anon = $this->anonymizeInstruction($user, $ip, $instructions, 'dialog_instructions');
        $this->conversations->setConversationPreferences($conversationId, $user->ownerKey(), $style, $anon);
        $this->events->user($ip, $user->logName(), LlmEventLog::OP_CHAT, 'CONVERSATION_PREFERENCES_UPDATE', ['conversation_id' => $conversationId, 'style' => $style ?? 'default', 'instructions_chars' => mb_strlen($anon)]);

        return ['style' => $style, 'instructions' => $anon];
    }

    public function setProjectDescription(ChatUser $user, string $ip, int $projectId, string $description): string
    {
        if ($this->projects->findOwned($projectId, $user->ownerKey()) === null) {
            throw new ChatException('not_found', 'Проект не найден.', 404);
        }
        $anon = $this->anonymizeInstruction($user, $ip, mb_substr($description, 0, 1000), 'project_description');
        $this->projects->setDescription($projectId, $anon);

        return $anon;
    }

    /** Разрешённые администратором стили: ключ → название. @return array<string,string> */
    public function allowedStyles(): array
    {
        $out = [];
        foreach (array_filter(explode(',', $this->settings->get('styles_allowed'))) as $key) {
            if (isset(\App\Llm\Prompt\PromptAssembler::STYLES[$key])) {
                $out[$key] = \App\Llm\Prompt\PromptAssembler::STYLES[$key][0];
            }
        }

        return $out;
    }

    private function checkStyle(?string $style): ?string
    {
        if ($style === null || $style === '') {
            return null;
        }
        if (!array_key_exists($style, $this->allowedStyles())) {
            throw new ChatException('style_denied', 'Этот стиль ответа недоступен.', 422);
        }

        return $style;
    }

    private function anonymizeInstruction(ChatUser $user, string $ip, string $text, string $purpose): string
    {
        $text = trim($text);
        if (mb_strlen($text) > 4000) {
            throw new ChatException('too_long', 'Инструкция — не длиннее 4000 символов.');
        }

        return $text === '' ? '' : $this->anonymize($user, $ip, $text, $purpose)->value;
    }

    /**
     * Именованный блок инструкций проекта — короткое правило поведения
     * модели (не документ), в дополнение к устаревшему единому полю
     * setProjectInstructions(). Хранится в той же таблице, что и
     * источники (llm_project_sources, source_type = 'instruction'), но
     * со своими лимитами: число блоков и длина ОДНОГО блока — блок
     * задуман коротким, чтобы «не закрывалось окно контекста», как и для
     * источников-документов есть отдельный больший суммарный лимит.
     *
     * @throws ChatException
     */
    public function addProjectInstruction(ChatUser $user, string $ip, int $projectId, string $title, string $text): array
    {
        if ($this->projects->findOwned($projectId, $user->ownerKey()) === null) {
            throw new ChatException('not_found', 'Проект не найден.', 404);
        }
        $text = trim($text);
        if ($text === '') {
            throw new ChatException('empty', 'Введите текст инструкции.');
        }
        $maxChars = max(100, (int) $this->settings->get('project_instruction_max_chars'));
        if (mb_strlen($text) > $maxChars) {
            throw new ChatException('too_long', 'Инструкция длиннее ' . $maxChars . ' символов — сократите или разбейте на несколько блоков.');
        }
        $maxBlocks = max(1, (int) $this->settings->get('project_instructions_max_blocks'));
        if ($this->projects->instructionCount($projectId) >= $maxBlocks) {
            throw new ChatException('instructions_limit', 'В проекте уже максимум блоков инструкций (' . $maxBlocks . '). Удалите ненужный, чтобы добавить новый.', 422);
        }

        $anonymized = $this->anonymize($user, $ip, $text, 'project_instructions');
        if ($anonymized->isEmpty()) {
            throw new ChatException('empty', 'После обработки не осталось текста для сохранения.');
        }
        $this->storageQuota->assertCanStore($user->ownerKey(), strlen($anonymized->value), $user->groupIds);

        $title = trim((string) preg_replace('/[\x00-\x1F]+/u', ' ', $title));
        $title = $title !== '' && mb_check_encoding($title, 'UTF-8') ? mb_substr($title, 0, 120) : 'Инструкция ' . ($this->projects->instructionCount($projectId) + 1);
        $id = $this->projects->addSource($projectId, $title, ProjectRepository::TYPE_INSTRUCTION, null, $anonymized);
        $this->events->user($ip, $user->logName(), LlmEventLog::OP_CHAT, 'PROJECT_INSTRUCTION_ADD_SUCCESS', [
            'project_id' => $projectId, 'source_id' => $id, 'text_chars' => mb_strlen($anonymized->value),
        ]);

        return ['id' => $id, 'title' => $title, 'chars' => mb_strlen($anonymized->value)];
    }

    /**
     * Источник проекта: файл (та же цепочка, что для вложений чата) или
     * вставленный текст. Сохраняется только обезличенный текст, сжатый.
     *
     * @param array{tmp_name:string,name:string,size:int,error:int}|null $upload
     * @return array<string,mixed>
     * @throws ChatException
     */
    public function addProjectSource(ChatUser $user, string $ip, int $projectId, string $title, string $text, ?array $upload): array
    {
        if ($this->projects->findOwned($projectId, $user->ownerKey()) === null) {
            throw new ChatException('not_found', 'Проект не найден.', 404);
        }
        $this->usage->assertCanSend($user->ownerKey(), $user->groupIds);

        // Проверяем ДО обработки/обезличивания файла — иначе впустую тратим
        // время на файл, который всё равно будет отклонён по лимиту.
        $maxFiles = max(1, (int) $this->settings->get('project_sources_max_files'));
        if ($this->projects->sourceCount($projectId) >= $maxFiles) {
            throw new ChatException('sources_limit', 'В проекте уже максимум источников (' . $maxFiles . '). Удалите ненужный, чтобы добавить новый.', 422);
        }

        if ($upload !== null) {
            $file = $this->handleUpload($user, $ip, $upload);
            $content = $file->content;
            $type = 'file';
            $fileType = $file->extension;
            // Исходное имя файла может содержать ПДн — по умолчанию не сохраняется.
            $defaultTitle = 'Документ ' . strtoupper($file->extension) . ' · ' . max(1, (int) ceil($file->size / 1024)) . ' КБ';
        } else {
            if (trim($text) === '') {
                throw new ChatException('empty', 'Вставьте текст источника или приложите файл.');
            }
            $content = $this->anonymize($user, $ip, $text, 'project_source');
            $type = 'text';
            $fileType = null;
            $defaultTitle = 'Текст · ' . mb_strlen($content->value) . ' симв.';
        }
        $text = '';

        $limit = max(1000, (int) $this->settings->get('project_sources_max_chars'));
        if ($this->projects->totalSourceChars($projectId) + mb_strlen($content->value) > $limit) {
            throw new ChatException('sources_limit', 'Превышен суммарный объём источников проекта (' . $limit . ' символов). Удалите ненужные источники.', 422);
        }
        $this->storageQuota->assertCanStore($user->ownerKey(), strlen($content->value), $user->groupIds);

        $title = trim((string) preg_replace('/[\x00-\x1F]+/u', ' ', $title));
        $title = $title !== '' && mb_check_encoding($title, 'UTF-8') ? mb_substr($title, 0, 120) : $defaultTitle;
        $id = $this->projects->addSource($projectId, $title, $type, $fileType, $content);
        $this->events->user($ip, $user->logName(), LlmEventLog::OP_CHAT, 'PROJECT_SOURCE_ADD_SUCCESS', [
            'project_id' => $projectId, 'source_id' => $id, 'type' => $fileType ?? 'text', 'text_chars' => mb_strlen($content->value),
        ]);

        return ['id' => $id, 'title' => $title, 'source_type' => $type, 'file_type' => $fileType, 'chars' => mb_strlen($content->value)];
    }

    /**
     * @param array<string,mixed>|null $conversation
     * @param array<string,mixed> $model строка llm_models (окно, длина ответа, особенности)
     * @return array{system:string,messages:list<array{role:string,content:string}>,report:array<string,int>,needsSummary:bool,droppedUncovered:int}
     * @throws ChatException
     */
    private function buildContext(ChatUser $user, ?array $conversation, ?int $projectId, array $model, string $current): array
    {
        $project = $projectId !== null ? $this->projects->contextParts($projectId, $user->ownerKey()) : null;
        $history = $conversation !== null ? $this->conversations->messages((int) $conversation['id'], $user->ownerKey()) : [];
        $summary = $conversation !== null && $this->summaries !== null ? $this->summaries->get((int) $conversation['id']) : null;
        $prefs = $this->conversations->preferences($conversation !== null ? (int) $conversation['id'] : null, $user->ownerKey());
        $allowedStyles = array_filter(explode(',', $this->settings->get('styles_allowed')));
        $style = $prefs['style'] ?? $this->settings->get('style_default');
        $layers = [
            'admin' => trim($this->settings->get('system_prompt')),
            'style' => in_array($style, $allowedStyles, true) ? $style : $this->settings->get('style_default'),
            'model' => trim((string) ($model['system_hint'] ?? '')),
            'project' => $project !== null ? trim(($project['description'] !== '' ? 'Проект: ' . $project['description'] . "\n" : '') . $project['instructions']) : '',
            'dialog' => $prefs['dialog'] ?? '',
            'personal' => $prefs['personal'] ?? '',
        ];
        try {
            return ContextBuilder::build((int) $model['context_window'], (int) $model['max_output_tokens'], $layers, $project['sources'] ?? [],
                array_map(static fn (array $m): array => ['id' => (int) $m['id'], 'role' => (string) $m['role'], 'content' => (string) $m['content'], 'unanonymized' => !empty($m['unanonymized'])], $history),
                $current, $summary !== null ? ['text' => SummaryService::PREFIX . "\n\n" . $summary['text'], 'covered_until_id' => $summary['covered_until_id']] : null,
                $project['state'] ?? null, (int) ceil(max(1000, (int) $this->settings->get('history_max_chars')) / ContextBuilder::CHARS_PER_TOKEN));
        } catch (ContextOverflowException $e) {
            throw new ChatException('context_overflow', 'Сообщение вместе с инструкциями не помещается в контекст выбранной модели (нужно ~' . $e->required . ' токенов, доступно ' . $e->budget . '). Сократите сообщение или выберите модель с бо́льшим окном.', 422);
        }
    }

    /**
     * Обслуживание контекста после ответа (вызывается, когда ответ пользователю уже отдан): обновить сводку
     * ранней части диалога и состояние проекта. Сбой не влияет на пользователя.
     *
     * @param array{conversation_id:int,model_id:int,project_id:?int} $task
     */
    public function maintainContext(ChatUser $user, array $task): void
    {
        if ($this->summaries === null) {
            return;
        }
        $allowedModels = $this->models->allowedFor($this->accessPolicy->allowedProviders($user->groupIds, $user->domainId, $user->ownerKey()));
        $summaryModel = (int) $this->settings->get('summary_model_id');
        $modelId = isset($allowedModels[$summaryModel]) ? $summaryModel : $task['model_id'];
        if (!isset($allowedModels[$modelId])) {
            return;
        }
        $history = array_map(static fn (array $m): array => ['id' => (int) $m['id'], 'role' => (string) $m['role'], 'content' => (string) $m['content'], 'unanonymized' => !empty($m['unanonymized'])],
            $this->conversations->messages($task['conversation_id'], $user->ownerKey()));
        if ($this->summaries->update($user, $task['conversation_id'], $history, $modelId, $allowedModels) && $task['project_id'] !== null) {
            $summary = $this->summaries->get($task['conversation_id']);
            if ($summary !== null) {
                $this->summaries->updateProjectState($user, $task['project_id'], $summary['text'], $modelId, $allowedModels);
            }
        }
    }

    private static function titleFrom(string $anonymizedText): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', preg_replace('/\A\[Вложение: [^\]]+\]\s*/u', '', $anonymizedText) ?? ''));
        if ($line === '') {
            return 'Новый диалог';
        }

        return mb_strlen($line) > 60 ? mb_substr($line, 0, 60) . '…' : $line;
    }
}
