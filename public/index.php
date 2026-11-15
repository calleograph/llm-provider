<?php

declare(strict_types=1);

namespace App;

use App\Certificate\CaBundleBuilder;
use App\Certificate\CertificateRepository;
use App\Config\AppSettings;
use App\Diagnostics\DiagnosticLogger;
use App\Diagnostics\DiagnosticSource;
use App\Http\ClientIp;
use App\Http\ErrorMessages;
use App\Http\View;
use App\Ldap\LdapErrorCode;
use App\Ldap\LdapException;
use App\Llm\Chat\ChatSession;
use App\Llm\Chat\ChatUser;
use App\Llm\Domain\ChatAuthenticator;
use App\Llm\Domain\LlmDomain;
use App\Llm\LlmEventLog;
use App\Llm\LlmModule;
use App\Security\Csrf;
use App\Security\RateLimiter;
use App\Security\SessionManager;

require __DIR__ . '/../src/bootstrap.php';

$pdo = bootstrap();
SessionManager::start(isHttps());
sendSecurityHeaders();

$appSettings = new AppSettings($pdo);
$clientIp = ClientIp::resolve($appSettings->trustedProxies());
$diagnosticLogger = new DiagnosticLogger($pdo);
$rateLimiter = new RateLimiter($pdo);
$llm = new LlmModule($pdo, $appSettings, new CaBundleBuilder(new CertificateRepository($pdo)), $diagnosticLogger);
$events = $llm->events();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
$domains = $llm->domains()->enabledForChat();
$loginError = null;
$prefillLogin = '';
$selectedDomainId = (int) ($domains[0] ?? null)?->id();

// ---- Вход: существующая доменная LDAPS-авторизация + проверка групп ----
if ($method === 'POST' && $action === 'login') {
    $login = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $selectedDomainId = (int) ($_POST['domain_id'] ?? $selectedDomainId);
    $prefillLogin = $login;
    $bucketKey = 'chat_login:' . $clientIp;
    $retryAfter = $rateLimiter->retryAfterSeconds($bucketKey);
    $parsed = ChatAuthenticator::parseLogin($login);

    $domain = null;
    if ($parsed !== null) {
        foreach ($domains as $candidate) {
            $hint = $parsed['domain_hint'];
            $matchesHint = $hint !== null && ($hint === mb_strtolower($candidate->config->domain) || $hint === mb_strtolower($candidate->config->netbiosDomain));
            if ($matchesHint || ($hint === null && $candidate->id() === $selectedDomainId)) {
                $domain = $candidate;
                break;
            }
        }
    }

    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $loginError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } elseif ($retryAfter > 0) {
        $loginError = 'Слишком много попыток входа. Повторите через ' . $retryAfter . ' сек.';
    } elseif ($parsed === null || $password === '') {
        $loginError = 'Введите логин и пароль.';
    } elseif ($domain === null) {
        $loginError = $domains === [] ? 'Вход в сервис ещё не настроен администратором.' : 'Домен не найден — выберите его из списка.';
    } else {
        $rateLimiter->recordAttempt($bucketKey);
        $logName = $parsed['sam'] . '@' . $domain->config->domain;
        try {
            $auth = $llm->authenticator()->authenticate($domain, $parsed['sam'], $password);
            if ($auth['user_dn'] === null) {
                $loginError = 'Неверный логин или пароль.';
                $events->user($clientIp, $logName, LlmEventLog::OP_AUTH, 'CHAT_LOGIN_FAILED', ['domain' => $domain->config->name, 'reason' => 'user_not_found']);
            } elseif ($auth['groups'] === []) {
                $loginError = 'Нет доступа к сервису: ваша учётная запись не входит ни в одну разрешённую группу. Обратитесь к администратору.';
                $events->user($clientIp, $logName, LlmEventLog::OP_AUTH, 'CHAT_LOGIN_FAILED', [
                    'domain' => $domain->config->name, 'reason' => 'no_allowed_group', 'unresolved_groups' => $auth['unresolved'] ?: ['—'],
                ]);
            } elseif ($llm->usage()->isBlocked(mb_strtolower($parsed['sam'] . '@' . $domain->config->domain))) {
                $loginError = 'Доступ к чату для вашей учётной записи приостановлен администратором.';
                $events->user($clientIp, $logName, LlmEventLog::OP_AUTH, 'CHAT_LOGIN_FAILED', ['domain' => $domain->config->name, 'reason' => 'blocked']);
            } else {
                $user = new ChatUser($parsed['sam'], $domain->id(), $domain->config->domain, array_keys($auth['groups']), array_values($auth['groups']));
                ChatSession::login($user);
                // Профиль пользователя (лимиты, статистика) создаётся при первом входе.
                $llm->usage()->touchProfile($user);
                $retentionDays = (int) $llm->chatSettings()->get('retention_days');
                if ($retentionDays > 0) {
                    $llm->conversations()->deleteOlderThan($user->ownerKey(), $retentionDays);
                }
                if (random_int(1, 50) === 1) {
                    $llm->usage()->purgeOldUsage();
                    $purgeDays = (int) $llm->chatSettings()->get('inactivity_content_purge_days');
                    if ($purgeDays > 0) {
                        $llm->inactivityCleanup()->cleanupInactive($purgeDays);
                    }
                }
                $rateLimiter->resetOnSuccess($bucketKey);
                $events->user($clientIp, $user->logName(), LlmEventLog::OP_AUTH, 'CHAT_LOGIN_SUCCESS', [
                    'domain' => $domain->config->name,
                    'groups' => array_values($auth['groups']),
                    'group_lookup' => $auth['lookup'],
                    'providers_available' => count($llm->access()->allowedProviders($user->groupIds, $user->domainId, $user->ownerKey())),
                ]);
                Csrf::rotate();
                $password = str_repeat("\0", strlen($password));
                header('Location: index.php');
                exit;
            }
        } catch (LdapException $e) {
            $loginError = match ($e->errorCode) {
                LdapErrorCode::INVALID_CREDENTIALS, LdapErrorCode::USER_NOT_FOUND => 'Неверный логин или пароль.',
                LdapErrorCode::PASSWORD_EXPIRED, LdapErrorCode::PASSWORD_MUST_CHANGE => 'Срок действия пароля истёк или требуется его смена. Смените пароль штатным способом (Ctrl+Alt+Del на рабочей станции или обратитесь к администратору) и войдите снова.',
                LdapErrorCode::ACCOUNT_RESTRICTION => 'Для вашей учётной записи вход по паролю через LDAP ограничен политикой домена. Обратитесь к администратору.',
                default => ErrorMessages::forCode($e->errorCode),
            };
            $events->user($clientIp, $logName, LlmEventLog::OP_AUTH, 'CHAT_LOGIN_FAILED', ['domain' => $domain->config->name, 'reason' => $e->errorCode]);
            if (in_array($e->errorCode, [LdapErrorCode::TLS_ERROR, LdapErrorCode::SERVER_UNAVAILABLE, LdapErrorCode::TIMEOUT, LdapErrorCode::UNKNOWN], true)) {
                $diagnosticLogger->error(DiagnosticSource::CHAT_AUTH, $e->errorCode, $e->getMessage(), ['domain' => $domain->config->name, 'host' => $domain->config->host], $clientIp);
            }
        } catch (\Throwable $e) {
            $loginError = 'Сервис временно недоступен. Попробуйте позже или обратитесь к администратору.';
            $events->user($clientIp, $logName, LlmEventLog::OP_AUTH, 'CHAT_LOGIN_FAILED', ['domain' => $domain->config->name, 'reason' => 'exception']);
            $diagnosticLogger->error(DiagnosticSource::CHAT_AUTH, get_class($e), $e->getMessage(), ['domain' => $domain->config->name], $clientIp);
        }
    }

    $password = str_repeat("\0", strlen($password));
    unset($password);
}

if ($method === 'POST' && $action === 'logout') {
    $user = ChatSession::current();
    if ($user !== null && Csrf::verify($_POST['csrf'] ?? null)) {
        $events->user($clientIp, $user->logName(), LlmEventLog::OP_AUTH, 'CHAT_LOGOUT_SUCCESS');
        ChatSession::logout();
        Csrf::rotate();
    }
    header('Location: index.php');
    exit;
}

$user = ChatSession::current();
if ($user === null) {
    echo View::render(__DIR__ . '/../templates/chat/login.php', [
        'csrfToken' => Csrf::token(),
        'domains' => array_map(static fn (LlmDomain $d): array => ['id' => $d->id(), 'name' => $d->config->name, 'fqdn' => $d->config->domain], $domains),
        'selectedDomainId' => $selectedDomainId,
        'errorMessage' => $loginError,
        'prefillLogin' => $prefillLogin,
    ]);
    exit;
}

echo View::render(__DIR__ . '/../templates/chat/app.php', [
    'csrfToken' => Csrf::token(),
    'user' => $user,
    'providers' => array_values($llm->access()->allowedProviders($user->groupIds, $user->domainId, $user->ownerKey())),
    'anonymizationReady' => $llm->anonymizationSettings()->isConfigured(),
    'usage' => $llm->usage()->status($user->ownerKey(), $user->groupIds),
    'extensions' => $llm->fileProcessor()->supportedExtensions(),
    'maxUploadMb' => (int) $llm->fileSettings()->get('max_upload_mb'),
    'canSkipAnonymization' => $llm->access()->canSkipAnonymization($user->groupIds, $user->ownerKey()),
]);
