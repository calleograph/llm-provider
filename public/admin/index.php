<?php

declare(strict_types=1);

namespace App;

use App\Audit\AuditLogger;
use App\Auth\AdminSession;
use App\Auth\DomainAdminAuthenticator;
use App\Auth\LocalAdminRepository;
use App\Certificate\CaBundleBuilder;
use App\Certificate\CertificateRepository;
use App\Config\AppSettings;
use App\Config\LdapConfigRepository;
use App\Diagnostics\DiagnosticLogger;
use App\Diagnostics\DiagnosticSource;
use App\Http\ClientIp;
use App\Http\View;
use App\Ldap\LdapException;
use App\Llm\LlmModule;
use App\Security\Csrf;
use App\Security\RateLimiter;
use App\Security\SessionManager;

require __DIR__ . '/../../src/bootstrap.php';

$pdo = bootstrap();
SessionManager::start(isHttps());
sendSecurityHeaders();

$appSettings = new AppSettings($pdo);
$clientIp = ClientIp::resolve($appSettings->trustedProxies());
$auditLogger = new AuditLogger($pdo);
$rateLimiter = new RateLimiter($pdo);
$diagnosticLogger = new DiagnosticLogger($pdo);
$localAdminRepo = new LocalAdminRepository($pdo);
$configRepo = new LdapConfigRepository($pdo);
$certRepo = new CertificateRepository($pdo);
$caBundleBuilder = new CaBundleBuilder($certRepo);

$csrfToken = Csrf::token();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

// Новый экземпляр не получает ни случайный пароль в stdout, ни настройки
// чужого домена. До создания локального break-glass администратора доступна
// только отдельная форма первого запуска.
if (!$localAdminRepo->exists()) {
    header('Location: setup.php');
    exit;
}

// ---- Вход ----
$loginError = null;
$loginRetryAfter = null;

if ($method === 'POST' && $action === 'login') {
    $submittedCsrf = $_POST['csrf'] ?? null;
    $loginMethod = ($_POST['method'] ?? 'domain') === 'local' ? 'local' : 'domain';
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $bucketKey = 'admin_login:' . $clientIp;
    $retryAfter = $rateLimiter->retryAfterSeconds($bucketKey);

    if (!Csrf::verify($submittedCsrf)) {
        $loginError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } elseif ($retryAfter > 0) {
        $loginError = 'Слишком много попыток входа. Повторите позже.';
        $loginRetryAfter = $retryAfter;
    } elseif ($username === '' || $password === '') {
        $loginError = 'Введите логин и пароль.';
    } else {
        $rateLimiter->recordAttempt($bucketKey);
        $authenticated = false;

        try {
            if ($loginMethod === 'local') {
                $authenticated = $localAdminRepo->verifyPassword($username, $password);
            } else {
                $activeConfig = $configRepo->findActive();
                if ($activeConfig !== null) {
                    $authenticator = new DomainAdminAuthenticator($activeConfig, $caBundleBuilder);
                    $authenticated = $authenticator->authenticate($username, $password);
                }
            }
        } catch (LdapException) {
            $authenticated = false;
        }

        if ($authenticated) {
            $rateLimiter->resetOnSuccess($bucketKey);
            AdminSession::login($username, $loginMethod);
            $auditLogger->log($username, $clientIp, 'admin_login', true, 'method=' . $loginMethod);
            Csrf::rotate();
            header('Location: index.php');
            exit;
        }

        $loginError = 'Неверный логин или пароль.';
        $auditLogger->log($username, $clientIp, 'admin_login', false, 'method=' . $loginMethod);
    }

    $password = str_repeat("\0", strlen($password));
    unset($password);
}

if ($action === 'logout') {
    $currentUser = AdminSession::username();
    if ($currentUser !== null) {
        $auditLogger->log($currentUser, $clientIp, 'admin_logout', true);
    }
    AdminSession::logout();
    header('Location: index.php');
    exit;
}

$adminIdleTimeoutMinutes = (int) $appSettings->get('admin_idle_timeout_minutes', (string) (SessionManager::DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS / 60));
if (!AdminSession::isLoggedIn($adminIdleTimeoutMinutes > 0 ? $adminIdleTimeoutMinutes * 60 : null)) {
    echo View::render(__DIR__ . '/../../templates/admin/login.php', [
        'csrfToken' => $csrfToken,
        'errorMessage' => $loginError,
        'retryAfter' => $loginRetryAfter,
    ]);
    exit;
}

// ---- Диспетчер разделов ----
$section = $_GET['section'] ?? 'status';
$allowedSections = [
    'status', 'services', 'ldap', 'access',
    'chat_access', 'llm_providers', 'llm_limits', 'llm_users', 'anonymization',
    'log', 'system',
];
if (!in_array($section, $allowedSections, true)) {
    $section = 'status';
}

$context = [
    'pdo' => $pdo,
    'auditLogger' => $auditLogger,
    'diagnosticLogger' => $diagnosticLogger,
    'configRepo' => $configRepo,
    'certRepo' => $certRepo,
    'caBundleBuilder' => $caBundleBuilder,
    'localAdminRepo' => $localAdminRepo,
    'appSettings' => $appSettings,
    'clientIp' => $clientIp,
    'csrfToken' => $csrfToken,
    'method' => $method,
    'action' => $action,
    // Модуль LLM-чата использует те же БД, настройки, CA bundle и журналы.
    'llmModule' => new LlmModule($pdo, $appSettings, $caBundleBuilder, $diagnosticLogger, $auditLogger),
];

try {
    require __DIR__ . '/sections/' . $section . '.php';
} catch (\Throwable $e) {
    // Любая непредвиденная ошибка внутри раздела не должна превращаться в
    // голую 500-ю страницу — фиксируем техническую причину в диагностический
    // журнал и показываем администратору понятное сообщение с возможностью
    // вернуться на «Состояние».
    $diagnosticLogger->error(
        DiagnosticSource::PHP_ERROR,
        get_class($e),
        $e->getMessage() . ' в ' . $e->getFile() . ':' . $e->getLine() . ' (раздел ' . $section . ')',
        ['admin' => (string) AdminSession::username()],
        $clientIp,
    );

    http_response_code(500);
    echo View::render(__DIR__ . '/../../templates/admin/layout_top.php', [
        'activeSection' => $section,
        'pageTitle' => 'Ошибка',
        'adminUsername' => AdminSession::username(),
        'flashSuccess' => null,
        'flashError' => 'Произошла непредвиденная ошибка при обработке раздела. '
            . 'Подробности записаны в диагностический журнал (раздел «Журнал»).',
    ]);
    echo View::render(__DIR__ . '/../../templates/admin/layout_bottom.php', []);
}
