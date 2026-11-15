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
    public function send(ChatUser $user, string $ip, ?int $conversationId, int $providerId, string $text, array $uploads, ?int $projectId = null, bool $skipAnonymization = false): array
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
        $files = [];
        foreach ($uploads as $upload) {
            $files[] = $skipAnonymization
                ? $this->handleUploadRaw($user, $ip, $upload)
                : $this->handleUpload($user, $ip, $upload);
        }
        $textContent = $skipAnonymization ? AnonymizedText::fromStorage($text) : $this->anonymize($user, $ip, $text, 'message');
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

        // 4. История — только обезличенные сохранённые реплики, в пределах лимита.
        $history = $conversation !== null ? $this->historyFor((int) $conversation['id'], $user) : [];
        $history[] = ['role' => 'user', 'content' => $userContent];

        if ($conversation === null || (int) $conversation['provider_id'] !== $providerId) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_PROVIDER_SELECT_SUCCESS', [
                'provider' => $providerName, 'provider_id' => $providerId, 'conversation_id' => $conversation['id'] ?? 'new',
            ]);
        }

        // 5. LLM Gateway (повторно проверяет права).
        try {
            $systemPrompt = trim($this->settings->get('system_prompt'));
            $context = $projectId !== null ? $this->projects->contextFor($projectId, $user->ownerKey()) : null;
            $result = $this->llmGateway->complete($user, $providerId, $history, $systemPrompt !== '' ? $systemPrompt : null, $context);
        } catch (LlmException $e) {
            $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_REQUEST_FAILED', [
                'provider' => $providerName, 'provider_id' => $providerId, 'reason' => $e->errorCode, 'http_status' => $e->httpStatus,
            ]);
            throw new ChatException('llm_' . $e->errorCode, $e->userMessage(), $e->errorCode === 'access_denied' ? 403 : 502);
        }

        // Расход: данные провайдера (вся история + контекст проекта), иначе оценка.
        $charged = ($result->inputTokens !== null || $result->outputTokens !== null)
            ? (int) $result->inputTokens + (int) $result->outputTokens
            : UsageLimiter::estimateTokens(array_sum(array_map(static fn (array $m): int => mb_strlen($m['content']->value), $history)) + mb_strlen($result->text) + ($context !== null ? mb_strlen($context->value) : 0));
        $this->usage->record($user->ownerKey(), $charged);

        $this->events->user($ip, $log, LlmEventLog::OP_CHAT, 'LLM_REQUEST_SUCCESS', [
            'provider' => $providerName, 'provider_id' => $providerId, 'model' => $result->model,
            'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens, 'usage_charged' => $charged,
            'project_id' => $projectId, 'duration_ms' => $result->durationMs,
        ]);

        // 6. Сохранение: только обезличенный вопрос и ответ модели. Несколько
        // вложений записываются в одну строку через запятую и суммарным
        // размером — схема БД хранит одно текстовое поле на сообщение, а не
        // список, отдельная таблица ради счётчика в UI избыточна.
        $attachmentType = $files !== [] ? implode(', ', array_map(static fn (object $f): string => $f->extension, $files)) : null;
        $attachmentSize = $files !== [] ? array_sum(array_map(static fn (object $f): int => $f->size, $files)) : null;
        $saved = $this->conversations->transactional(function () use ($conversation, $user, $providerId, $projectId, $userContent, $result, $attachmentType, $attachmentSize, $charged, $skipAnonymization): array {
            if ($conversation === null) {
                $title = self::titleFrom($userContent->value);
                $id = $this->conversations->create($user->ownerKey(), $title, $providerId, $projectId);
                $conversation = ['id' => $id, 'title' => $title, 'created' => true];
            } else {
                $this->conversations->touch((int) $conversation['id'], $providerId);
            }
            $id = (int) $conversation['id'];
            $userMessageId = $this->conversations->addMessage($id, 'user', $userContent->value, $providerId, null, $attachmentType, $attachmentSize, null, $skipAnonymization);
            $assistantMessageId = $this->conversations->addMessage($id, 'assistant', $result->text, $providerId, $result->model, null, null, $charged);
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
                'model' => $result->model, 'provider' => $providerName,
            ],
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
     * Вариант handleUpload() для skipAnonymization=true: файл НИКОГДА не
     * уходит в сервис обезличивания (даже целиком через api_file) — только
     * локальное извлечение текста тем же FileProcessor, без последующего
     * anonymize(). Пользователь явно подтвердил отсутствие конфиденциальных
     * данных для всего сообщения — распространяется и на вложения к нему.
     *
     * @param array{tmp_name:string,name:string,size:int,error:int} $upload
     * @return object{content:AnonymizedText,extension:string,size:int}
     */
    private function handleUploadRaw(ChatUser $user, string $ip, array $upload): object
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
                'result' => 'extracted_unanonymized', 'ocr' => $file->ocrUsed, 'pages' => $file->pages, 'ocr_pages' => $file->ocrPages,
                'text_chars' => mb_strlen($file->text),
            ]);
            $content = AnonymizedText::fromStorage($file->text);
        } catch (FileProcessingException $e) {
            $this->events->user($ip, $user->logName(), LlmEventLog::OP_FILE, 'FILE_UPLOAD_FAILED', $fields + ['reason' => $e->errorCode]);
            throw new ChatException('file_' . $e->errorCode, $e->userMessage(), 422);
        }

        return (object) ['content' => $content, 'extension' => $extension, 'size' => (int) $upload['size']];
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

    /** @return list<array{role:string,content:AnonymizedText}> */
    private function historyFor(int $conversationId, ChatUser $user): array
    {
        $limit = max(1000, (int) $this->settings->get('history_max_chars'));
        $messages = $this->conversations->messages($conversationId, $user->ownerKey());

        $selected = [];
        $total = 0;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $length = mb_strlen((string) $messages[$i]['content']);
            if ($total + $length > $limit) {
                break;
            }
            $total += $length;
            array_unshift($selected, [
                'role' => (string) $messages[$i]['role'],
                'content' => AnonymizedText::fromStorage((string) $messages[$i]['content']),
            ]);
        }

        return $selected;
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
