<?php

declare(strict_types=1);

/**
 * Тестовый стенд без Active Directory: создаёт домен, группу с доступом к провайдеру и сессию пользователя чата
 * прямо в каталоге сессий приложения. Запускается ТОЛЬКО из консоли контейнера php (нужен доступ к тому данных —
 * это уже права администратора сервера); через веб недоступен. Печатает JSON: cookie сессии и CSRF.
 *
 *   docker compose exec php php tests/e2e/seed_session.php [логин] [provider_id] [--admin]
 * --admin — в той же сессии ещё и вход администратора (метод e2e), чтобы проверять админку.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../vendor_autoload.php';

use App\Config\AppSettings;
use App\Config\Database;
use App\Config\LdapConfigRepository;
use App\Config\Paths;
use App\Llm\Domain\DomainGroupRepository;
use App\Llm\Domain\LlmDomainRepository;

$asAdmin = in_array('--admin', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--admin'));
$login = $args[0] ?? 'e2e.user';
$providerId = isset($args[1]) ? (int) $args[1] : null;
Paths::ensureDirectories();
$pdo = Database::connect(Paths::database());

$domainId = (int) ($pdo->query("SELECT id FROM ldap_configs WHERE domain = 'corp.local'")->fetchColumn() ?: 0);
if ($domainId === 0) {
    $domainId = (new LdapConfigRepository($pdo))->create([
        'name' => 'Тестовый домен', 'ldaps_url' => 'ldaps://dc1.corp.local:636/', 'host' => 'dc1.corp.local', 'port' => 636,
        'base_dn' => 'DC=corp,DC=local', 'domain' => 'corp.local', 'netbios_domain' => 'CORP', 'admin_group_dn' => '',
        'protocol_version' => 3, 'starttls' => false, 'network_timeout' => 5, 'ca_bundle_file' => null,
    ]);
    (new LlmDomainRepository($pdo))->saveLlmSettings($domainId, true, '', null, false);
}
$groups = new DomainGroupRepository($pdo);
$groupId = (int) ($pdo->query("SELECT id FROM llm_domain_groups WHERE group_ref = 'CN=LLM-E2E,DC=corp,DC=local'")->fetchColumn() ?: 0);
if ($groupId === 0) {
    $groupId = $groups->create($domainId, 'LLM E2E', 'CN=LLM-E2E,DC=corp,DC=local', true, 'e2e');
}
if ($providerId !== null) {
    $pdo->prepare('INSERT OR IGNORE INTO llm_group_providers (group_id, provider_id) VALUES (?, ?)')->execute([$groupId, $providerId]);
}

session_save_path(Paths::sessions());
session_name('ADPWD_SESSION');
session_id(bin2hex(random_bytes(16)));
session_start();
$now = time();
$_SESSION['llm_chat'] = [
    'username' => $login, 'domain_id' => $domainId, 'domain_fqdn' => 'corp.local',
    'group_ids' => [$groupId], 'group_names' => ['LLM E2E'], 'login_at' => $now, 'last_activity' => $now,
];
if ($asAdmin) {
    $_SESSION['admin_username'] = $login;
    $_SESSION['admin_method'] = 'e2e';
    $_SESSION['admin_last_activity'] = $now;
}
$csrf = \App\Security\Csrf::token();
$sid = session_id();
session_write_close();

echo json_encode(['cookie' => 'ADPWD_SESSION=' . $sid, 'session' => $sid, 'csrf' => $csrf, 'group_id' => $groupId, 'domain_id' => $domainId]), "\n";
