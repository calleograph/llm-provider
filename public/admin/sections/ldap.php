<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Certificate\CertificateParser;
use App\Config\LdapConfigValidator;
use App\Diagnostics\DiagnosticSource;
use App\Http\View;
use App\Ldap\ConnectionDiagnostics;
use App\Ldap\LdapConnectionConfig;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Config\LdapConfigRepository $configRepo */
/** @var \App\Certificate\CertificateRepository $certRepo */
/** @var \App\Certificate\CaBundleBuilder $caBundleBuilder */
/** @var \App\Audit\AuditLogger $auditLogger */
/** @var \App\Diagnostics\DiagnosticLogger $diagnosticLogger */
/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */

$flashSuccess = null;
$flashError = null;
$testResults = [];       // domain_id => результат ConnectionDiagnostics
$editingId = null;       // непрошедшая валидацию карточка (null — нет, 0 — новая)
$fieldErrors = [];
$formValues = null;

$domainsRepo = $llmModule->domains();
$events = $llmModule->events();
$admin = (string) AdminSession::username();

/**
 * Единая форма домена: LDAPS-подключение (ldap_configs) + параметры
 * LLM-модуля (llm_enabled, сервисная учётка). Раньше это были два разных
 * раздела («Подключения» и «Домены и группы»), редактирующие одну и ту
 * же таблицу ldap_configs — сведены в один экран.
 */
function ldapForm(array $post, ?LdapConnectionConfig $existing): array
{
    return [
        'name' => trim((string) ($post['name'] ?? '')),
        'ldaps_url' => trim((string) ($post['ldaps_url'] ?? '')),
        'host' => trim((string) ($post['host'] ?? '')),
        'port' => (int) ($post['port'] ?? 636),
        'base_dn' => trim((string) ($post['base_dn'] ?? '')),
        'domain' => trim((string) ($post['domain'] ?? '')),
        'netbios_domain' => trim((string) ($post['netbios_domain'] ?? '')),
        // Группа администраторов сервиса задаётся в разделе «Доступ к админ-панели».
        'admin_group_dn' => $existing?->adminGroupDn ?? '',
        'protocol_version' => (int) ($post['protocol_version'] ?? 3),
        'starttls' => false,
        'network_timeout' => max(1, (int) ($post['network_timeout'] ?? 8)),
        'ca_bundle_file' => $existing?->caBundleFile,
        'llm_enabled' => !empty($post['llm_enabled']),
        'service_username' => trim((string) ($post['service_username'] ?? '')),
    ];
}

function ldapValidate(array $data): array
{
    $errors = LdapConfigValidator::validateFields($data);
    foreach (['name', 'ldaps_url', 'host', 'base_dn', 'domain', 'netbios_domain'] as $field) {
        if (!mb_check_encoding((string) $data[$field], 'UTF-8')) {
            $errors[$field] = 'Недопустимые символы (ожидается UTF-8).';
        }
    }
    if ($data['service_username'] !== '' && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $data['service_username']) !== 1) {
        $errors['service_username'] = 'Укажите sAMAccountName без домена, например svc-llm-read.';
    }

    return $errors;
}

function llmFieldError(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<p class="field-error">' . View::e($errors[$key]) . '</p>' : '';
}

$postedId = (int) ($_POST['id'] ?? 0);
$ldapActions = ['create_ldap', 'update_ldap', 'delete_ldap', 'set_active_ldap', 'test_ldap'];
$certActions = ['preview_certificate', 'preview_certificate_pem', 'confirm_certificate', 'cancel_certificate', 'delete_certificate'];

if ($method === 'POST' && in_array($action, $ldapActions, true)) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        switch ($action) {
            case 'create_ldap':
                $data = ldapForm($_POST, null);
                $fieldErrors = ldapValidate($data);
                if ($fieldErrors === [] && $configRepo->nameExists($data['name'])) {
                    $fieldErrors['name'] = 'Подключение с таким названием уже существует.';
                }
                if ($fieldErrors !== []) {
                    $flashError = 'Проверьте выделенные поля формы нового подключения.';
                    $formValues = $data;
                    $editingId = 0;
                    break;
                }
                $id = $configRepo->create($data, $configRepo->all() === []);
                $password = (string) ($_POST['service_password'] ?? '');
                $domainsRepo->saveLlmSettings($id, $data['llm_enabled'], $data['service_username'], $password !== '' ? $password : null, false);
                $events->admin($admin, $clientIp, 'ldap_create', true, [
                    'id' => $id, 'name' => $data['name'], 'host' => $data['host'], 'llm_enabled' => $data['llm_enabled'],
                    'service_account' => $data['service_username'] !== '' ? $data['service_username'] : '—',
                ]);
                $flashSuccess = 'Подключение «' . $data['name'] . '» добавлено.';
                $password = str_repeat("\0", strlen($password));
                unset($password);
                break;

            case 'update_ldap':
                $existing = $domainsRepo->find($postedId);
                if ($existing === null) {
                    $flashError = 'Подключение не найдено.';
                    break;
                }
                $data = ldapForm($_POST, $existing->config);
                $fieldErrors = ldapValidate($data);
                if ($fieldErrors === [] && $configRepo->nameExists($data['name'], $postedId)) {
                    $fieldErrors['name'] = 'Подключение с таким названием уже существует.';
                }
                if ($fieldErrors !== []) {
                    $flashError = 'Проверьте выделенные поля формы.';
                    $formValues = $data;
                    $editingId = $postedId;
                    break;
                }
                $configRepo->update($postedId, $data);
                $password = (string) ($_POST['service_password'] ?? '');
                $clearPassword = !empty($_POST['clear_service_password']);
                $domainsRepo->saveLlmSettings($postedId, $data['llm_enabled'], $data['service_username'], $password !== '' ? $password : null, $clearPassword);

                $changed = [];
                foreach (['name' => 'name', 'ldaps_url' => 'ldapsUrl', 'host' => 'host', 'port' => 'port', 'base_dn' => 'baseDn', 'domain' => 'domain', 'netbios_domain' => 'netbiosDomain', 'network_timeout' => 'networkTimeout', 'protocol_version' => 'protocolVersion'] as $field => $property) {
                    if ((string) $data[$field] !== (string) $existing->config->{$property}) {
                        $changed[] = $field;
                    }
                }
                if ($data['llm_enabled'] !== $existing->llmEnabled) {
                    $changed[] = 'llm_enabled';
                }
                if ($data['service_username'] !== (string) $existing->serviceUsername) {
                    $changed[] = 'service_username';
                }
                $events->admin($admin, $clientIp, 'ldap_update', true, [
                    'id' => $postedId, 'name' => $data['name'], 'changed' => $changed !== [] ? $changed : ['—'],
                    'llm_enabled' => $data['llm_enabled'],
                    'service_auth_change' => $clearPassword ? 'cleared' : ($password !== '' ? 'replaced' : 'kept'),
                ]);
                $flashSuccess = 'Подключение «' . $data['name'] . '» сохранено.';
                $password = str_repeat("\0", strlen($password));
                unset($password);
                break;

            case 'set_active_ldap':
                try {
                    $configRepo->setActive($postedId);
                    $events->admin($admin, $clientIp, 'ldap_activate', true, ['id' => $postedId]);
                    $flashSuccess = 'Подключение назначено активным (используется для входа администраторов).';
                } catch (\Throwable) {
                    $flashError = 'Не удалось назначить активное подключение.';
                    $events->admin($admin, $clientIp, 'ldap_activate', false, ['id' => $postedId]);
                }
                break;

            case 'delete_ldap':
                $existing = $domainsRepo->find($postedId);
                try {
                    if ($existing === null) {
                        throw new \RuntimeException('Подключение не найдено.');
                    }
                    $configRepo->delete($postedId);
                    $events->admin($admin, $clientIp, 'ldap_delete', true, ['id' => $postedId, 'name' => $existing->config->name]);
                    $flashSuccess = 'Подключение удалено вместе с его разрешёнными группами и пользователями.';
                } catch (\Throwable $e) {
                    $flashError = $e->getMessage();
                    $events->admin($admin, $clientIp, 'ldap_delete', false, ['id' => $postedId]);
                }
                break;

            case 'test_ldap':
                $existing = $domainsRepo->find($postedId);
                if ($existing === null) {
                    $flashError = 'Подключение не найдено.';
                    break;
                }
                // Приоритет — данные, введённые вручную для теста; иначе сервисная учётка домена.
                $testUsername = trim((string) ($_POST['test_username'] ?? ''));
                $testPassword = (string) ($_POST['test_password'] ?? '');
                if ($testUsername === '' && $existing->hasServiceAccount()) {
                    $testUsername = (string) $existing->serviceUsername;
                    $testPassword = (string) $domainsRepo->servicePassword($postedId);
                }
                $bindRequested = $testUsername !== '' && $testPassword !== '';
                try {
                    $diagnostics = new ConnectionDiagnostics($caBundleBuilder, $certRepo);
                    $testResults[$postedId] = $diagnostics->check($existing->config, $testUsername !== '' ? $testUsername : null, $testPassword !== '' ? $testPassword : null);
                    $bindOutcome = !$testResults[$postedId]['bind_tested']
                        ? 'not_requested'
                        : ($testResults[$postedId]['bind_successful'] ? 'success' : 'failed');
                    $events->admin($admin, $clientIp, 'ldap_test', true, [
                        'id' => $postedId, 'name' => $existing->config->name,
                        'tls' => $testResults[$postedId]['tls_valid'], 'bind_requested' => $bindRequested, 'bind' => $bindOutcome,
                    ]);
                    $diagnosticLogger->info(DiagnosticSource::CONNECTION_TEST, 'ldap_test', sprintf(
                        'dns=%s tcp=%s tls=%s bind_tested=%s bind_ok=%s',
                        $testResults[$postedId]['dns_resolved'] ? '1' : '0',
                        $testResults[$postedId]['tcp_reachable'] ? '1' : '0',
                        $testResults[$postedId]['tls_valid'] ? '1' : '0',
                        $testResults[$postedId]['bind_tested'] ? '1' : '0',
                        $testResults[$postedId]['bind_successful'] === null ? 'n/a' : ($testResults[$postedId]['bind_successful'] ? '1' : '0'),
                    ), ['config' => $existing->config->name, 'host' => $existing->config->host], $clientIp);
                } catch (\Throwable $e) {
                    $flashError = 'Не удалось выполнить проверку подключения.';
                    $events->admin($admin, $clientIp, 'ldap_test', false, ['id' => $postedId, 'name' => $existing->config->name]);
                    $diagnosticLogger->error(DiagnosticSource::CONNECTION_TEST, get_class($e), $e->getMessage(), ['config' => $existing->config->name], $clientIp);
                }
                $testPassword = str_repeat("\0", strlen($testPassword));
                unset($testPassword);
                break;
        }
    }
}

// ---- Сертификаты (общий CA bundle, не привязан к конкретному подключению) ----
if ($method === 'POST' && in_array($action, $certActions, true)) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        switch ($action) {
            case 'preview_certificate':
                if (!isset($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
                    $flashError = 'Файл не выбран или произошла ошибка загрузки.';
                } elseif (!is_uploaded_file($_FILES['certificate']['tmp_name'])) {
                    $flashError = 'Файл не прошёл проверку загрузки.';
                } elseif ((int) $_FILES['certificate']['size'] > 512 * 1024) {
                    $flashError = 'Файл слишком большой (максимум 512 КБ).';
                } else {
                    $content = file_get_contents($_FILES['certificate']['tmp_name']);
                    try {
                        $previewParsed = CertificateParser::parse($content !== false ? $content : '');
                        $_SESSION['pending_cert_pem'] = $previewParsed['pem'];
                        $_SESSION['pending_cert_type'] = trim((string) ($_POST['cert_type'] ?? 'intermediate')) === 'root' ? 'root' : 'intermediate';
                    } catch (\Throwable $e) {
                        $flashError = $e->getMessage();
                    }
                }
                break;

            case 'preview_certificate_pem':
                $pastedPem = (string) ($_POST['certificate_pem'] ?? '');
                if (trim($pastedPem) === '') {
                    $flashError = 'Вставьте содержимое сертификата в формате PEM.';
                } elseif (strlen($pastedPem) > 512 * 1024) {
                    $flashError = 'Слишком много текста (максимум 512 КБ).';
                } else {
                    try {
                        $previewParsed = CertificateParser::parse($pastedPem);
                        $_SESSION['pending_cert_pem'] = $previewParsed['pem'];
                        $_SESSION['pending_cert_type'] = trim((string) ($_POST['cert_type'] ?? 'intermediate')) === 'root' ? 'root' : 'intermediate';
                    } catch (\Throwable $e) {
                        $flashError = $e->getMessage();
                    }
                }
                break;

            case 'confirm_certificate':
                if (empty($_SESSION['pending_cert_pem'])) {
                    $flashError = 'Нет сертификата, ожидающего подтверждения.';
                } else {
                    try {
                        $parsed = CertificateParser::parse($_SESSION['pending_cert_pem']);
                        $type = $_SESSION['pending_cert_type'] ?? 'intermediate';
                        $certRepo->store($parsed, $type, (string) AdminSession::username());
                        $caBundleBuilder->rebuild();
                        unset($_SESSION['pending_cert_pem'], $_SESSION['pending_cert_type']);
                        $auditLogger->log(AdminSession::username(), $clientIp, 'certificate_upload', true, 'fp=' . $parsed['fingerprint_sha256']);
                        $flashSuccess = 'Сертификат сохранён и включён в CA bundle.';
                    } catch (\Throwable $e) {
                        $flashError = 'Не удалось сохранить сертификат: ' . $e->getMessage();
                        $auditLogger->log(AdminSession::username(), $clientIp, 'certificate_upload', false);
                    }
                }
                break;

            case 'cancel_certificate':
                unset($_SESSION['pending_cert_pem'], $_SESSION['pending_cert_type']);
                break;

            case 'delete_certificate':
                $id = (int) ($_POST['id'] ?? 0);
                $certRepo->delete($id);
                try {
                    $caBundleBuilder->rebuild();
                } catch (\Throwable) {
                    // Допустимо: если удалён последний сертификат, bundle отсутствует —
                    // это осознанно приводит к fail-closed отказу LDAPS-подключений.
                }
                $auditLogger->log(AdminSession::username(), $clientIp, 'certificate_delete', true, 'id=' . $id);
                $flashSuccess = 'Сертификат удалён.';
                break;
        }
    }
}

$pendingPreview = null;
if (!empty($_SESSION['pending_cert_pem'])) {
    try {
        $pendingPreview = CertificateParser::parse($_SESSION['pending_cert_pem']);
    } catch (\Throwable) {
        unset($_SESSION['pending_cert_pem'], $_SESSION['pending_cert_type']);
    }
}

$domains = $domainsRepo->all();
$certificates = $certRepo->all();

/** Значение поля карточки: из непрошедшей валидацию формы либо из БД. */
$value = static function (?\App\Llm\Domain\LlmDomain $domain, string $field, string $default = '') use ($formValues, $editingId): string {
    $cardId = $domain?->id() ?? 0;
    if ($formValues !== null && $editingId === $cardId && array_key_exists($field, $formValues)) {
        return is_bool($formValues[$field]) ? ($formValues[$field] ? '1' : '') : (string) $formValues[$field];
    }
    if ($domain === null) {
        return $default;
    }

    return match ($field) {
        'name' => $domain->config->name,
        'ldaps_url' => $domain->config->ldapsUrl,
        'host' => $domain->config->host,
        'port' => (string) $domain->config->port,
        'base_dn' => $domain->config->baseDn,
        'domain' => $domain->config->domain,
        'netbios_domain' => $domain->config->netbiosDomain,
        'network_timeout' => (string) $domain->config->networkTimeout,
        'protocol_version' => (string) $domain->config->protocolVersion,
        'llm_enabled' => $domain->llmEnabled ? '1' : '',
        'service_username' => (string) $domain->serviceUsername,
        default => $default,
    };
};

$renderLdapFields = static function (?\App\Llm\Domain\LlmDomain $domain) use ($value, $fieldErrors, $editingId): string {
    $errors = $editingId === ($domain?->id() ?? 0) ? $fieldErrors : [];
    $suffix = $domain?->id() ?? 'new';
    $input = static function (string $name, string $label, string $placeholder = '', string $type = 'text', bool $required = true) use ($value, $domain, $errors, $suffix): string {
        return '<div class="field"><label class="field-label" for="' . $name . '-' . $suffix . '">' . View::e($label) . '</label>'
            . '<input class="text-input' . (isset($errors[$name]) ? ' has-error' : '') . '" id="' . $name . '-' . $suffix . '" type="' . $type . '" name="' . $name . '"'
            . ' value="' . View::e($value($domain, $name, $name === 'port' ? '636' : ($name === 'network_timeout' ? '8' : ''))) . '"'
            . ($placeholder !== '' ? ' placeholder="' . View::e($placeholder) . '"' : '') . ($required ? ' required' : '') . '>'
            . llmFieldError($errors, $name) . '</div>';
    };

    ob_start();
    ?>
  <div class="form-row">
    <?php echo $input('name', 'Отображаемое название', 'Корпоративный домен'); ?>
    <?php echo $input('ldaps_url', 'LDAPS URL (LDAP endpoint)', 'ldaps://dc01.example.local:636/'); ?>
  </div>
  <div class="form-row">
    <?php echo $input('host', 'Контроллер домена (host)', 'dc01.example.local'); ?>
    <?php echo $input('port', 'Порт', '636', 'number'); ?>
    <div class="field">
      <label class="field-label" for="protocol_version-<?php echo $suffix; ?>">Версия протокола LDAP</label>
      <select class="text-input" id="protocol_version-<?php echo $suffix; ?>" name="protocol_version">
        <option value="3" <?php echo $value($domain, 'protocol_version', '3') === '3' ? 'selected' : ''; ?>>3</option>
        <option value="2" <?php echo $value($domain, 'protocol_version', '3') === '2' ? 'selected' : ''; ?>>2</option>
      </select>
    </div>
    <?php echo $input('network_timeout', 'Таймаут сети (сек.)', '8', 'number'); ?>
  </div>
  <div class="form-row">
    <?php echo $input('base_dn', 'Base DN', 'DC=example,DC=local'); ?>
    <?php echo $input('domain', 'Домен (для UPN)', 'example.local'); ?>
    <?php echo $input('netbios_domain', 'NetBIOS домен', 'EXAMPLE', 'text', false); ?>
  </div>
  <p class="muted" style="font-size:12px;">Admin group DN задаётся в разделе «Доступ к админ-панели». StartTLS выключен (ldaps:// уже зашифровано на транспортном уровне).</p>
  <div class="form-row">
    <?php echo $input('service_username', 'Сервисная учётка для чата (sAMAccountName, read-only)', 'svc-llm-read', 'text', false); ?>
    <div class="field">
      <label class="field-label" for="service_password-<?php echo $suffix; ?>">Пароль сервисной учётки</label>
      <div class="password-field-wrap">
        <input class="text-input" type="password" id="service_password-<?php echo $suffix; ?>" name="service_password" autocomplete="new-password"
               placeholder="<?php echo $domain !== null && $domain->hasServicePassword ? 'сохранён — оставьте пустым, чтобы не менять' : ''; ?>">
        <button type="button" class="toggle-password" data-target="service_password-<?php echo $suffix; ?>">Показать</button>
      </div>
<?php if ($domain !== null && $domain->hasServicePassword): ?>
      <label class="llm-inline-check"><input type="checkbox" name="clear_service_password" value="1"> удалить сохранённый пароль</label>
<?php endif; ?>
      <?php echo llmFieldError($errors, 'service_username'); ?>
    </div>
  </div>
  <label class="switch">
    <input type="checkbox" name="llm_enabled" value="1" <?php echo $value($domain, 'llm_enabled', '1') === '1' ? 'checked' : ''; ?>>
    <span class="track"></span> Разрешить вход в LLM-чат пользователям этого домена
  </label>
<?php
    return (string) ob_get_clean();
};

ob_start();
?>
<p class="muted llm-lead">
  LDAPS-подключения к домену Active Directory: используются и для входа администраторов (одно
  «активное» подключение), и для входа пользователей в LLM-чат (можно включить сразу несколько
  доменов). Сертификаты ниже — общий доверенный CA bundle для всех подключений сразу.
</p>

<div class="llm-toolbar">
  <p class="section-title">Подключения</p>
  <button type="button" class="btn btn-primary llm-plus" data-reveal="newLdapCard" title="Добавить подключение" aria-label="Добавить подключение"<?php echo $editingId === 0 ? ' hidden' : ''; ?>>+ Добавить подключение</button>
</div>

<div id="draftRestoreBanner" class="draft-banner" hidden>
  <span>Найден несохранённый черновик этой формы (например, после случайного закрытия вкладки).</span>
  <span>
    <button type="button" class="btn btn-small draft-apply">Восстановить</button>
    <button type="button" class="btn btn-small draft-discard">Отклонить</button>
  </span>
</div>

<div class="llm-card" id="newLdapCard"<?php echo $editingId === 0 ? '' : ' hidden'; ?>>
  <div class="llm-card-head"><h2>Новое подключение</h2></div>
  <form method="post" id="connectionForm" action="index.php?section=ldap&action=create_ldap">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <?php echo $renderLdapFields(null); ?>
    <div class="llm-actions"><button class="btn btn-primary" type="submit">Добавить подключение</button></div>
  </form>
</div>

<?php if ($domains === []): ?>
<p class="muted">Подключений пока нет — нажмите «+ Добавить подключение».</p>
<?php endif; ?>

<?php foreach ($domains as $domain): $id = $domain->id(); ?>
<div class="llm-card" id="ldap-<?php echo $id; ?>">
  <div class="llm-card-head">
    <h2><?php echo View::e($domain->config->name); ?> <span class="muted mono"><?php echo View::e($domain->config->host . ':' . $domain->config->port); ?></span></h2>
    <div>
<?php if ($domain->config->isActive): ?>
      <span class="badge badge-active" title="Используется для входа администраторов">Активно для входа администраторов</span>
<?php else: ?>
      <form method="post" action="index.php?section=ldap&action=set_active_ldap#ldap-<?php echo $id; ?>" style="display:inline">
        <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <button class="btn btn-small" type="submit">Сделать активным для входа администраторов</button>
      </form>
<?php endif; ?>
      <span class="badge <?php echo $domain->llmEnabled ? 'badge-active' : 'badge-inactive'; ?>">Чат: <?php echo $domain->llmEnabled ? 'включён' : 'выключен'; ?></span>
      <span class="badge <?php echo $domain->hasServiceAccount() ? 'badge-active' : 'badge-inactive'; ?>">Сервисная учётка: <?php echo $domain->hasServiceAccount() ? 'задана' : 'нет'; ?></span>
    </div>
  </div>

  <form method="post" action="index.php?section=ldap&action=update_ldap#ldap-<?php echo $id; ?>">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <?php echo $renderLdapFields($domain); ?>
    <div class="llm-actions">
      <button class="btn btn-primary" type="submit">Сохранить</button>
      <button class="btn" type="submit" form="testLdap<?php echo $id; ?>">Проверить подключение</button>
<?php if (!$domain->config->isActive): ?>
      <button class="btn btn-danger" type="submit" form="deleteLdap<?php echo $id; ?>">Удалить</button>
<?php endif; ?>
    </div>
  </form>
  <form id="testLdap<?php echo $id; ?>" method="post" action="index.php?section=ldap&action=test_ldap#ldap-<?php echo $id; ?>">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
  </form>
<?php if (!$domain->config->isActive): ?>
  <form id="deleteLdap<?php echo $id; ?>" method="post" action="index.php?section=ldap&action=delete_ldap"
        data-confirm-title="Удалить подключение?"
        data-confirm-text="Подключение «<?php echo View::e($domain->config->name); ?>» и его разрешённые группы/пользователи будут удалены. История диалогов пользователей сохранится.">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
  </form>
<?php endif; ?>

<?php if (isset($testResults[$id])): $test = $testResults[$id]; ?>
  <table class="data-table llm-result">
    <thead><tr><th class="llm-col-icon"></th><th>Шаг проверки</th><th>Подробности</th></tr></thead>
    <tbody>
<?php foreach ($test['steps'] as $step): ?>
      <tr>
        <td><span class="<?php echo $step['ok'] ? 'status-ok' : 'status-bad'; ?>"><?php echo $step['ok'] ? '✓' : '✗'; ?></span></td>
        <td><?php echo View::e($step['label']); ?></td>
        <td class="muted"><?php echo View::e($step['detail']); ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php if (!$test['bind_tested']): ?>
  <p class="muted llm-note">Bind не проверялся — не задан ни тестовый логин, ни сервисная учётка.</p>
<?php endif; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>

<hr class="sep">

<p class="section-title">Сертификаты (общий CA bundle для всех подключений)</p>
<table class="data-table">
  <thead><tr><th>Тип</th><th>Subject</th><th>Issuer</th><th>Serial</th><th>SHA-256</th><th>Действует до</th><th></th></tr></thead>
  <tbody>
<?php foreach ($certificates as $cert):
    $expiryTimestamp = strtotime(str_replace(' UTC', '', $cert['valid_to']) . ' UTC');
    $isExpired = $expiryTimestamp !== false && $expiryTimestamp < time();
    $expiresSoon = !$isExpired && $expiryTimestamp !== false && $expiryTimestamp < (time() + 30 * 86400);
?>
    <tr>
      <td><?php echo $cert['type'] === 'root' ? 'Root CA' : 'Intermediate CA'; ?></td>
      <td class="mono" style="max-width:220px;word-break:break-all;"><?php echo View::e($cert['subject']); ?></td>
      <td class="mono" style="max-width:220px;word-break:break-all;"><?php echo View::e($cert['issuer']); ?></td>
      <td class="mono"><?php echo View::e($cert['serial']); ?></td>
      <td class="mono" style="max-width:180px;word-break:break-all;"><?php echo View::e($cert['fingerprint_sha256']); ?></td>
      <td class="<?php echo $isExpired ? 'expiry-warning' : ($expiresSoon ? 'status-warn' : ''); ?>">
        <?php echo View::e($cert['valid_to']); ?>
<?php if ($isExpired): ?>
        <br><span class="expiry-warning">— срок истёк</span>
<?php elseif ($expiresSoon): ?>
        <br><span class="status-warn">— истекает менее чем через 30 дней</span>
<?php endif; ?>
      </td>
      <td>
        <form method="post" action="index.php?section=ldap&action=delete_certificate"
              data-confirm-title="Удалить сертификат?"
              data-confirm-text="Сертификат будет удалён из доверенного CA bundle. Если это единственный сертификат или он входит в текущую цепочку доверия, LDAPS-подключения к AD перестанут проходить проверку TLS.">
          <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
          <input type="hidden" name="id" value="<?php echo (int) $cert['id']; ?>">
          <button class="btn btn-small btn-danger" type="submit">Удалить</button>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
<?php if ($certificates === []): ?>
    <tr><td colspan="7" class="muted">Сертификаты пока не загружены. LDAPS-подключения будут отклонены (fail-closed), пока не будет загружен хотя бы Root CA.</td></tr>
<?php endif; ?>
  </tbody>
</table>

<?php if ($pendingPreview !== null): ?>
<hr class="sep">
<p class="section-title">Подтверждение загрузки сертификата</p>
<div class="alert alert-warn">Проверьте параметры сертификата перед сохранением.</div>
<dl class="kv">
  <dt>Тип</dt><dd><?php echo ($_SESSION['pending_cert_type'] ?? 'intermediate') === 'root' ? 'Root CA' : 'Intermediate CA'; ?></dd>
  <dt>Subject</dt><dd><?php echo View::e($pendingPreview['subject']); ?></dd>
  <dt>Issuer</dt><dd><?php echo View::e($pendingPreview['issuer']); ?></dd>
  <dt>Serial Number</dt><dd><?php echo View::e($pendingPreview['serial']); ?></dd>
  <dt>SHA-256 fingerprint</dt><dd><?php echo View::e($pendingPreview['fingerprint_sha256']); ?></dd>
  <dt>Valid From</dt><dd><?php echo View::e($pendingPreview['valid_from']); ?></dd>
  <dt>Valid To</dt><dd><?php echo View::e($pendingPreview['valid_to']); ?></dd>
</dl>
<form method="post" action="index.php?section=ldap&action=confirm_certificate" style="margin-top:14px;display:inline">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <button class="btn btn-primary" type="submit">Подтвердить и сохранить</button>
</form>
<form method="post" action="index.php?section=ldap&action=cancel_certificate" style="display:inline">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <button class="btn" type="submit">Отменить</button>
</form>
<?php endif; ?>

<hr class="sep">

<p class="section-title">Загрузить новый сертификат</p>
<form method="post" action="index.php?section=ldap&action=preview_certificate" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label">Тип сертификата</label>
      <select class="text-input" name="cert_type">
        <option value="root">Root CA</option>
        <option value="intermediate">Intermediate CA</option>
      </select>
    </div>
    <div class="field">
      <label class="field-label">Файл сертификата (PEM или DER)</label>
      <input type="file" name="certificate" accept=".pem,.crt,.cer,.der" required>
    </div>
  </div>
  <p class="muted" style="font-size:12px;">Приватные ключи не принимаются. Сертификат будет разобран и показан для подтверждения перед сохранением.</p>
  <button class="btn" type="submit">Разобрать и просмотреть</button>
</form>

<hr class="sep">

<p class="section-title">Или вставить сертификат текстом (PEM)</p>
<form method="post" action="index.php?section=ldap&action=preview_certificate_pem">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label">Тип сертификата</label>
      <select class="text-input" name="cert_type">
        <option value="root">Root CA</option>
        <option value="intermediate">Intermediate CA</option>
      </select>
    </div>
  </div>
  <div class="form-row">
    <div class="field">
      <label class="field-label">Содержимое сертификата (-----BEGIN CERTIFICATE-----…)</label>
      <textarea class="text-input cert-pem-input" name="certificate_pem" rows="10" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"></textarea>
    </div>
  </div>
  <p class="muted" style="font-size:12px;">
    Только сам сертификат (PEM, base64 между BEGIN/END CERTIFICATE). Приватные ключи
    и другие данные не принимаются — проходит ту же проверку OpenSSL, что и файл.
  </p>
  <button class="btn" type="submit">Разобрать и просмотреть</button>
</form>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'ldap',
    'pageTitle' => 'LDAP-подключения',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
