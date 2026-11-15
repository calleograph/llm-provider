<?php

declare(strict_types=1);

namespace App;

use App\Certificate\CaBundleBuilder;
use App\Certificate\CertificateRepository;
use App\Config\AppSettings;
use App\Diagnostics\DiagnosticLogger;
use App\Diagnostics\DiagnosticSource;
use App\Http\ClientIp;
use App\Llm\Chat\ChatException;
use App\Llm\Chat\ChatSession;
use App\Llm\LlmEventLog;
use App\Llm\LlmModule;
use App\Security\Csrf;
use App\Security\SessionManager;

require __DIR__ . '/../src/bootstrap.php';

$pdo = bootstrap();
SessionManager::start(isHttps());
sendSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');

/** @param array<string,mixed> $data */
function chatJson(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Разбирает ini-значение размера ("8M", "512K", "1G", "0" — без ограничения) в байты. */
function parseIniSize(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '0') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

/** Короткое пользовательское название (чата, проекта, источника). */
function chatName(mixed $value, int $max = 120): string
{
    $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));
    if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
        throw new ChatException('invalid_name', 'Укажите название.');
    }

    return mb_substr($value, 0, $max);
}

/** @return array{tmp_name:string,name:string,size:int,error:int}|null */
function chatUpload(string $field): ?array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (is_array($_FILES[$field]['name'])) {
        throw new ChatException('multiple_files', 'Можно приложить только один файл.');
    }

    return [
        'tmp_name' => (string) $_FILES[$field]['tmp_name'],
        'name' => (string) $_FILES[$field]['name'],
        'size' => (int) $_FILES[$field]['size'],
        'error' => (int) $_FILES[$field]['error'],
    ];
}

/**
 * Множественная загрузка (`<input multiple>`/несколько File в FormData под
 * одним именем поля) — PHP собирает такие поля в параллельные массивы
 * ($_FILES[$field]['name'][0], ['name'][1], ...), а не в привычную по одной
 * записи на файл структуру, поэтому их нужно "развернуть" вручную.
 *
 * @return array<int,array{tmp_name:string,name:string,size:int,error:int}>
 */
function chatUploads(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }
    if (!is_array($_FILES[$field]['name'] ?? null)) {
        // Единственный файл под тем же именем поля — фронтенд всегда шлёт
        // массив, но на всякий случай поддержим и одиночную структуру.
        $single = chatUpload($field);

        return $single !== null ? [$single] : [];
    }

    $count = count($_FILES[$field]['name']);
    if ($count > \App\Llm\Chat\ChatService::MAX_UPLOADS) {
        throw new ChatException('too_many_files', 'Можно приложить не более ' . \App\Llm\Chat\ChatService::MAX_UPLOADS . ' файлов за раз.');
    }

    $uploads = [];
    for ($i = 0; $i < $count; $i++) {
        $error = (int) ($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $uploads[] = [
            'tmp_name' => (string) $_FILES[$field]['tmp_name'][$i],
            'name' => (string) $_FILES[$field]['name'][$i],
            'size' => (int) $_FILES[$field]['size'][$i],
            'error' => $error,
        ];
    }

    return $uploads;
}

$appSettings = new AppSettings($pdo);
$clientIp = ClientIp::resolve($appSettings->trustedProxies());
$diagnosticLogger = new DiagnosticLogger($pdo);
$llm = new LlmModule($pdo, $appSettings, new CaBundleBuilder(new CertificateRepository($pdo)), $diagnosticLogger);

$user = ChatSession::current();
if ($user === null) {
    chatJson(401, ['error' => 'Сеанс завершён. Войдите снова.', 'code' => 'unauthorized']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

if ($method === 'POST') {
    // Превышение post_max_size: PHP молча отбрасывает тело целиком, но
    // оставляет Content-Length. Отличаем это от намеренно пустого тела
    // (например form({}) для delete_all_conversations) — даже пустой
    // multipart-запрос имеет ненулевую длину (граница-разделитель), так
    // что одного "$_POST === [] && CONTENT_LENGTH > 0" недостаточно:
    // сверяем длину именно с настроенным пределом post_max_size.
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxBytes = parseIniSize(ini_get('post_max_size') ?: '0');
    if ($_POST === [] && $_FILES === [] && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        chatJson(413, ['error' => 'Файл превышает допустимый размер.', 'code' => 'file_too_large']);
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
    if (!Csrf::verify(is_string($token) ? $token : null)) {
        chatJson(403, ['error' => 'Сеанс устарел, обновите страницу.', 'code' => 'csrf']);
    }
}

// Данные сессии больше не меняются — освобождаем блокировку, чтобы
// длительный запрос к LLM не блокировал другие запросы пользователя.
session_write_close();

$owner = $user->ownerKey();
$conversations = $llm->conversations();
$projects = $llm->projects();
$events = $llm->events();
$log = $user->logName();
$postId = (int) ($_POST['id'] ?? 0);

try {
    switch ($method . ' ' . $action) {
        case 'GET bootstrap':
            chatJson(200, [
                'user' => ['name' => $log, 'groups' => $user->groupNames],
                'providers' => array_values($llm->access()->allowedProviders($user->groupIds, $user->domainId, $user->ownerKey())),
                'conversations' => $conversations->listForOwner($owner),
                'projects' => $projects->listForOwner($owner),
                'usage' => $llm->usage()->status($owner, $user->groupIds),
                'storage' => $llm->storageQuota()->status($owner, $user->groupIds),
                'can_skip_anonymization' => $llm->access()->canSkipAnonymization($user->groupIds, $user->ownerKey()),
                'dictation' => ['enabled' => $llm->dictation()->enabled(), 'max_seconds' => $llm->dictation()->maxSeconds()],
                'styles' => $llm->chatService()->allowedStyles(),
                'style_default' => $llm->chatSettings()->get('style_default'),
                'prefs' => (static function () use ($conversations, $owner): array {
                    $p = $conversations->preferences(null, $owner);

                    return ['style' => $p['style'], 'instructions' => $p['personal']];
                })(),
            ]);

            // no break
        case 'POST user_prefs':
            chatJson(200, $llm->chatService()->setUserPreferences($user, $clientIp, (string) ($_POST['style'] ?? '') ?: null, (string) ($_POST['instructions'] ?? '')));

            // no break
        case 'POST conversation_prefs':
            chatJson(200, $llm->chatService()->setConversationPreferences($user, $clientIp, $postId, (string) ($_POST['style'] ?? '') ?: null, (string) ($_POST['instructions'] ?? '')));

            // no break
        case 'POST dictation_ticket':
            // Голосовой ввод: одноразовый билет на 60 с для WebSocket-шлюза ASR (вход по сессии и CSRF — здесь).
            $dictation = $llm->dictation();
            if (!$dictation->enabled()) {
                chatJson(403, ['error' => 'Голосовой ввод отключён администратором.', 'code' => 'dictation_disabled']);
            }
            if (!$dictation->asrReady()) {
                chatJson(503, ['error' => 'Распознавание речи сейчас недоступно. Введите текст вручную.', 'code' => 'asr_unavailable']);
            }
            $events->user($clientIp, $log, LlmEventLog::OP_CHAT, 'DICTATION_START');
            chatJson(200, ['ticket' => $dictation->issue($owner), 'path' => 'ws/dictation', 'max_seconds' => $dictation->maxSeconds()]);

            // no break
        case 'GET storage':
            chatJson(200, ['storage' => $llm->storageQuota()->status($owner, $user->groupIds)]);

            // no break
        case 'POST bulk_delete_conversations':
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            $deleted = 0;
            foreach ($ids as $conversationId) {
                if ($conversations->delete($conversationId, $owner)) {
                    $deleted++;
                }
            }
            if ($deleted > 0) {
                $events->user($clientIp, $log, LlmEventLog::OP_CHAT, 'CONVERSATION_BULK_DELETE_SUCCESS', ['count' => $deleted]);
            }
            chatJson(200, [
                'ok' => true, 'deleted' => $deleted,
                'conversations' => $conversations->listForOwner($owner),
                'storage' => $llm->storageQuota()->status($owner, $user->groupIds),
            ]);

            // no break
        case 'POST delete_all_conversations':
            $deleted = $conversations->deleteAllForOwner($owner);
            if ($deleted > 0) {
                $events->user($clientIp, $log, LlmEventLog::OP_CHAT, 'CONVERSATION_BULK_DELETE_SUCCESS', ['count' => $deleted, 'all' => true]);
            }
            chatJson(200, [
                'ok' => true, 'deleted' => $deleted,
                'conversations' => $conversations->listForOwner($owner),
                'storage' => $llm->storageQuota()->status($owner, $user->groupIds),
            ]);

            // no break
        case 'GET conversation':
            $id = (int) ($_GET['id'] ?? 0);
            $conversation = $conversations->findOwned($id, $owner);
            if ($conversation === null) {
                chatJson(404, ['error' => 'Диалог не найден.', 'code' => 'not_found']);
            }
            chatJson(200, [
                'conversation' => [
                    'id' => (int) $conversation['id'], 'title' => $conversation['title'],
                    'provider_id' => $conversation['provider_id'] !== null ? (int) $conversation['provider_id'] : null,
                    'project_id' => $conversation['project_id'] !== null ? (int) $conversation['project_id'] : null,
                    'model_id' => isset($conversation['model_id']) ? (int) $conversation['model_id'] : null,
                    'pinned' => (bool) $conversation['pinned'],
                    'style' => $conversation['style'] ?? null,
                    'instructions' => \App\Llm\Storage\CompactText::unpack(is_string($conversation['instructions_z'] ?? null) ? $conversation['instructions_z'] : null),
                ],
                'messages' => $conversations->messages($id, $owner),
            ]);

            // no break
        case 'GET search':
            chatJson(200, ['results' => $conversations->search($owner, (string) ($_GET['q'] ?? ''))]);

            // no break
        case 'POST send':
            // OCR, обезличивание и ответ внешней модели могут занимать минуты;
            // собственные таймауты у каждого шага заданы в админке.
            set_time_limit(900);
            $conversationId = (int) ($_POST['conversation_id'] ?? 0);
            $projectId = (int) ($_POST['project_id'] ?? 0);
            $result = $llm->chatService()->send(
                $user,
                $clientIp,
                $conversationId > 0 ? $conversationId : null,
                (int) ($_POST['provider_id'] ?? 0),
                (string) ($_POST['text'] ?? ''),
                chatUploads('files'),
                $projectId > 0 ? $projectId : null,
                !empty($_POST['skip_anonymization']),
                (int) ($_POST['model_id'] ?? 0) > 0 ? (int) $_POST['model_id'] : null,
            );
            $maintenance = $result['maintenance'] ?? null;
            unset($result['maintenance']);
            if ($maintenance === null) {
                chatJson(200, $result);
            }
            // Ответ уходит пользователю сразу; сводка диалога и состояние проекта обновляются после — не задерживая его.
            http_response_code(200);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                $llm->chatService()->maintainContext($user, $maintenance);
            } catch (\Throwable $e) {
                error_log('context maintenance: ' . get_class($e));
            }
            exit;

            // no break
        case 'POST conversation_update':
            if ($conversations->findOwned($postId, $owner) === null) {
                chatJson(404, ['error' => 'Диалог не найден.', 'code' => 'not_found']);
            }
            if (isset($_POST['pinned'])) {
                $conversations->setPinned($postId, $owner, $_POST['pinned'] === '1');
            }
            if (isset($_POST['title'])) {
                $conversations->rename($postId, $owner, chatName($_POST['title']));
            }
            if (isset($_POST['project_id'])) {
                $target = (int) $_POST['project_id'];
                if ($target > 0 && $projects->findOwned($target, $owner) === null) {
                    chatJson(404, ['error' => 'Проект не найден.', 'code' => 'not_found']);
                }
                $conversations->moveToProject($postId, $owner, $target > 0 ? $target : null);
            }
            chatJson(200, ['ok' => true, 'conversations' => $conversations->listForOwner($owner)]);

            // no break
        case 'POST delete_conversation':
            if (!$conversations->delete($postId, $owner)) {
                chatJson(404, ['error' => 'Диалог не найден.', 'code' => 'not_found']);
            }
            $events->user($clientIp, $log, LlmEventLog::OP_CHAT, 'CONVERSATION_DELETE_SUCCESS', ['conversation_id' => $postId]);
            chatJson(200, ['ok' => true]);

            // no break
        case 'GET project':
            $id = (int) ($_GET['id'] ?? 0);
            $project = $projects->findOwned($id, $owner);
            if ($project === null) {
                chatJson(404, ['error' => 'Проект не найден.', 'code' => 'not_found']);
            }
            // Разовый перенос устаревшего единого поля инструкций в первый
            // именованный блок — не задевает проекты, где нечего переносить.
            $projects->migrateLegacyInstructions($id, $owner);
            $parts = $projects->contextParts($id, $owner);
            chatJson(200, [
                'project' => $project + ['description' => $parts['description'] ?? '', 'state' => $parts['state'] ?? ''],
                'instructions' => $projects->instructions($id, $owner),
                'instructions_limit' => (int) $llm->chatSettings()->get('project_instruction_max_chars'),
                'instructions_max_blocks' => (int) $llm->chatSettings()->get('project_instructions_max_blocks'),
                'sources' => $projects->sources($id, $owner),
                'sources_limit' => (int) $llm->chatSettings()->get('project_sources_max_chars'),
                'sources_max_files' => (int) $llm->chatSettings()->get('project_sources_max_files'),
                'conversations' => array_values(array_filter($conversations->listForOwner($owner), static fn (array $c): bool => $c['project_id'] === $id)),
            ]);

            // no break
        case 'POST project_instruction_add':
            $instruction = $llm->chatService()->addProjectInstruction(
                $user,
                $clientIp,
                (int) ($_POST['project_id'] ?? 0),
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['text'] ?? ''),
            );
            chatJson(200, ['instruction' => $instruction]);

            // no break
        case 'POST project_create':
            $id = $projects->create($owner, chatName($_POST['name'] ?? '', 80));
            $events->user($clientIp, $log, LlmEventLog::OP_CHAT, 'PROJECT_CREATE_SUCCESS', ['project_id' => $id]);
            chatJson(200, ['id' => $id, 'projects' => $projects->listForOwner($owner)]);

            // no break
        case 'POST project_update':
            if ($projects->findOwned($postId, $owner) === null) {
                chatJson(404, ['error' => 'Проект не найден.', 'code' => 'not_found']);
            }
            $response = ['ok' => true];
            if (isset($_POST['name'])) {
                $projects->rename($postId, $owner, chatName($_POST['name'], 80));
            }
            if (isset($_POST['pinned'])) {
                $projects->setPinned($postId, $owner, $_POST['pinned'] === '1');
            }
            if (isset($_POST['description'])) {
                // Описание уходит в модель (слой проекта) — обезличивается.
                $response['description'] = $llm->chatService()->setProjectDescription($user, $clientIp, $postId, (string) $_POST['description']);
            }
            if (isset($_POST['instructions'])) {
                // Инструкции пойдут в модель — обезличиваются; пользователь видит итог.
                $response['instructions'] = $llm->chatService()->setProjectInstructions($user, $clientIp, $postId, (string) $_POST['instructions']);
            }
            $response['projects'] = $projects->listForOwner($owner);
            chatJson(200, $response);

            // no break
        case 'POST project_delete':
            if (!$projects->delete($postId, $owner)) {
                chatJson(404, ['error' => 'Проект не найден.', 'code' => 'not_found']);
            }
            $events->user($clientIp, $log, LlmEventLog::OP_CHAT, 'PROJECT_DELETE_SUCCESS', ['project_id' => $postId]);
            chatJson(200, ['ok' => true, 'projects' => $projects->listForOwner($owner), 'conversations' => $conversations->listForOwner($owner)]);

            // no break
        case 'POST project_source_add':
            set_time_limit(900);
            $source = $llm->chatService()->addProjectSource(
                $user,
                $clientIp,
                (int) ($_POST['project_id'] ?? 0),
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['text'] ?? ''),
                chatUpload('file'),
            );
            chatJson(200, ['source' => $source]);

            // no break
        case 'POST project_source_delete':
            if (!$projects->deleteSource($postId, $owner)) {
                chatJson(404, ['error' => 'Источник не найден.', 'code' => 'not_found']);
            }
            $events->user($clientIp, $log, LlmEventLog::OP_CHAT, 'PROJECT_SOURCE_DELETE_SUCCESS', ['source_id' => $postId]);
            chatJson(200, ['ok' => true]);

            // no break
        case 'GET project_source_text':
            $sourceId = (int) ($_GET['id'] ?? 0);
            $text = $projects->sourceText($sourceId, $owner);
            if ($text === null) {
                chatJson(404, ['error' => 'Источник не найден.', 'code' => 'not_found']);
            }
            chatJson(200, ['text' => $text]);

            // no break
        default:
            chatJson(404, ['error' => 'Неизвестное действие.', 'code' => 'unknown_action']);
    }
} catch (ChatException $e) {
    chatJson($e->httpStatus, ['error' => $e->userMessage, 'code' => $e->errorCode]);
} catch (\Throwable $e) {
    $diagnosticLogger->error(
        DiagnosticSource::PHP_ERROR,
        get_class($e),
        $e->getMessage() . ' в ' . $e->getFile() . ':' . $e->getLine() . ' (chat api ' . $action . ')',
        ['user' => $log],
        $clientIp,
    );
    chatJson(500, ['error' => 'Внутренняя ошибка сервиса. Запрос не выполнен.', 'code' => 'internal']);
}
