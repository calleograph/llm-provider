<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Config\Paths;
use App\Http\View;
use App\Ldap\AdLinkStatusChecker;
use App\Llm\Files\ProcessRunner;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Certificate\CertificateRepository $certRepo */
/** @var \App\Certificate\CaBundleBuilder $caBundleBuilder */
/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */

$flashSuccess = null;
$flashError = null;
$events = $llmModule->events();
$admin = (string) AdminSession::username();

// Живая проверка внешних API — только по кнопке: каждая проверка LLM —
// реальный (синтетический) запрос к провайдеру.
if ($method === 'POST' && $action === 'check_external') {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        $okCount = 0;
        $total = 0;
        if ($llmModule->anonymizationSettings()->isConfigured()) {
            $total++;
            $okCount += $llmModule->anonymizer()->testConnection()['ok'] ? 1 : 0;
        }
        foreach ($llmModule->providers()->all() as $provider) {
            if (!$provider['is_enabled']) {
                continue;
            }
            $total++;
            $okCount += $llmModule->gateway()->testConnection((int) $provider['id'])['ok'] ? 1 : 0;
        }
        $events->admin($admin, $clientIp, 'services_check', $okCount === $total, ['checked' => $total, 'ok' => $okCount]);
        $flashSuccess = sprintf('Проверено сервисов: %d, доступно: %d.', $total, $okCount);
    }
}

// ---- LDAPS: каждое доменное подключение, живая проверка (DNS, TCP, TLS) ----
$checker = new AdLinkStatusChecker($caBundleBuilder);
$domainRows = [];
foreach ($llmModule->domains()->all() as $domain) {
    if (!$domain->llmEnabled && !$domain->config->isActive) {
        continue;
    }
    try {
        $reachable = $checker->isReachable($domain->config);
    } catch (\Throwable) {
        $reachable = false;
    }
    $domainRows[] = [
        'domain' => $domain,
        'reachable' => $reachable,
        'groups' => count($llmModule->groups()->forDomain($domain->id(), true)),
    ];
}

// ---- Сертификаты ----
$certs = $certRepo->all();
$now = time();
$nearestExpiry = null;
$expiredCount = 0;
foreach ($certs as $cert) {
    $expiry = strtotime(str_replace(' UTC', '', (string) $cert['valid_to']) . ' UTC');
    if ($expiry === false) {
        continue;
    }
    if ($expiry < $now) {
        $expiredCount++;
    }
    if ($nearestExpiry === null || $expiry < $nearestExpiry) {
        $nearestExpiry = $expiry;
    }
}
$caBundleExists = is_file(Paths::certs() . '/ca-bundle.pem');
$expirySoon = $nearestExpiry !== null && $nearestExpiry < $now + 30 * 86400;

$anon = $llmModule->anonymizationSettings();
$providers = $llmModule->providers()->all();
$fileSettings = $llmModule->fileSettings();

$lastCheck = static function (?string $at, $ok): array {
    if ($at === null || $at === '') {
        return ['class' => 'status-warn', 'label' => 'Не проверялся'];
    }

    return (string) $ok === '1' ? ['class' => 'status-ok', 'label' => 'Доступен'] : ['class' => 'status-bad', 'label' => 'Ошибка'];
};
$fmt = static fn (?string $at): string => $at ? str_replace('T', ' ', substr($at, 0, 16)) . ' UTC' : '—';

ob_start();
?>
<p class="muted llm-lead">
  Сводное состояние компонентов LLM-чата. LDAPS проверяется при открытии страницы (DNS, TCP и TLS по общему CA bundle,
  без учётных данных). API обезличивания и нейросети — по кнопке ниже или в их разделах: такая проверка отправляет
  синтетический запрос без пользовательских данных.
</p>

<form method="post" action="index.php?section=services&action=check_external" class="llm-actions">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <button class="btn btn-primary" type="submit">Проверить API обезличивания и нейросети сейчас</button>
</form>

<p class="section-title">LDAPS и доменные подключения</p>
<table class="data-table">
  <thead><tr><th class="llm-col-icon"></th><th>Подключение</th><th>Контроллер</th><th>Назначение</th><th>Групп для чата</th><th>LDAPS</th></tr></thead>
  <tbody>
<?php foreach ($domainRows as $row): $d = $row['domain']; ?>
    <tr>
      <td><span class="<?php echo $row['reachable'] ? 'status-ok' : 'status-bad'; ?>">●</span></td>
      <td><?php echo View::e($d->config->name); ?></td>
      <td class="mono"><?php echo View::e($d->config->host . ':' . $d->config->port); ?></td>
      <td>
        <?php echo $d->config->isActive ? '<span class="badge badge-active">вход администраторов</span>' : ''; ?>
        <?php echo $d->llmEnabled ? '<span class="badge badge-active">LLM-чат</span>' : ''; ?>
      </td>
      <td><?php echo $d->llmEnabled ? (int) $row['groups'] : '—'; ?></td>
      <td class="<?php echo $row['reachable'] ? 'status-ok' : 'status-bad'; ?>"><?php echo $row['reachable'] ? 'Связь установлена' : 'Недоступен'; ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($domainRows === []): ?>
    <tr><td colspan="6" class="muted">Нет подключений. Добавьте подключение в разделе «LDAP-подключения».</td></tr>
<?php endif; ?>
  </tbody>
</table>

<hr class="sep">

<p class="section-title">Сертификаты</p>
<div class="status-line-group">
  <span class="status-line"><span class="label">CA bundle:</span> <span class="value <?php echo $caBundleExists ? 'status-ok' : 'status-bad'; ?>"><?php echo $caBundleExists ? 'Собран' : 'Не собран'; ?></span></span>
  <span class="status-line"><span class="label">Загружено / просрочено:</span> <span class="value <?php echo $expiredCount > 0 ? 'status-bad' : ''; ?>"><?php echo count($certs); ?> / <?php echo $expiredCount; ?></span></span>
  <span class="status-line"><span class="label">Ближайшее окончание:</span> <span class="value <?php echo $expirySoon ? 'status-warn' : ''; ?>"><?php echo $nearestExpiry !== null ? View::e(gmdate('Y-m-d', $nearestExpiry)) : '—'; ?></span></span>
</div>

<hr class="sep">

<p class="section-title">API обезличивания</p>
<?php $anonState = $lastCheck($anon->get('last_check_at'), $anon->get('last_check_ok')); ?>
<table class="data-table">
  <thead><tr><th class="llm-col-icon"></th><th>Сервис</th><th>Состояние</th><th>Последняя проверка</th><th>Результат</th></tr></thead>
  <tbody>
    <tr>
      <td><span class="<?php echo $anon->isConfigured() ? $anonState['class'] : 'status-bad'; ?>">●</span></td>
      <td class="mono"><?php echo $anon->isConfigured() ? View::e($anon->url($anon->get('endpoint'))) : 'не настроен'; ?></td>
      <td class="<?php echo $anon->isConfigured() ? $anonState['class'] : 'status-bad'; ?>"><?php echo $anon->isConfigured() ? $anonState['label'] : 'Чат заблокирован'; ?></td>
      <td class="mono"><?php echo View::e($fmt($anon->get('last_check_at'))); ?></td>
      <td><?php echo View::e($anon->get('last_check_message')); ?></td>
    </tr>
  </tbody>
</table>

<hr class="sep">

<p class="section-title">LLM-провайдеры</p>
<table class="data-table">
  <thead><tr><th class="llm-col-icon"></th><th>Нейросеть</th><th>Модель</th><th>Статус</th><th>Последняя проверка</th><th>Результат</th></tr></thead>
  <tbody>
<?php foreach ($providers as $provider): $state = $lastCheck($provider['last_check_at'], $provider['last_check_ok']); ?>
    <tr>
      <td><span class="<?php echo $provider['is_enabled'] ? $state['class'] : 'muted'; ?>">●</span></td>
      <td><a href="index.php?section=llm_providers#provider-<?php echo (int) $provider['id']; ?>"><?php echo View::e((string) $provider['name']); ?></a></td>
      <td class="mono"><?php echo View::e((string) $provider['model']); ?></td>
      <td><?php echo $provider['is_enabled'] ? '<span class="badge badge-active">Включена</span>' : '<span class="badge badge-inactive">Отключена</span>'; ?></td>
      <td class="mono"><?php echo View::e($fmt($provider['last_check_at'])); ?></td>
      <td class="<?php echo $state['class']; ?>"><?php echo View::e($state['label']); ?><?php if ($provider['last_check_message']): ?><br><span class="muted llm-matrix-sub"><?php echo View::e((string) $provider['last_check_message']); ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($providers === []): ?>
    <tr><td colspan="6" class="muted">Нейросети не добавлены.</td></tr>
<?php endif; ?>
  </tbody>
</table>

<hr class="sep">

<p class="section-title">Локальная обработка файлов</p>
<div class="status-line-group">
<?php foreach (['pdftotext' => $fileSettings->get('pdftotext_path'), 'pdftoppm' => $fileSettings->get('pdftoppm_path'), 'tesseract (OCR)' => $fileSettings->get('tesseract_path')] as $label => $path): $ok = ProcessRunner::isExecutable($path); ?>
  <span class="status-line"><span class="label"><?php echo View::e($label); ?>:</span> <span class="value <?php echo $ok ? 'status-ok' : 'status-warn'; ?>"><?php echo $ok ? 'Доступен' : 'Не найден'; ?></span></span>
<?php endforeach; ?>
  <span class="status-line"><span class="label">ext-curl:</span> <span class="value <?php echo function_exists('curl_init') ? 'status-ok' : 'status-bad'; ?>"><?php echo function_exists('curl_init') ? 'Доступно' : 'Нет — LLM недоступны'; ?></span></span>
  <span class="status-line"><span class="label">ext-zip (DOCX/XLSX):</span> <span class="value <?php echo class_exists(\ZipArchive::class) ? 'status-ok' : 'status-warn'; ?>"><?php echo class_exists(\ZipArchive::class) ? 'Доступно' : 'Не установлено'; ?></span></span>
  <span class="status-line"><span class="label">Временный каталог:</span> <span class="value <?php echo is_dir(Paths::llmTmp()) && is_writable(Paths::llmTmp()) ? 'status-ok' : 'status-bad'; ?>"><?php echo is_dir(Paths::llmTmp()) && is_writable(Paths::llmTmp()) ? 'Запись разрешена' : 'Нет прав на запись'; ?></span></span>
</div>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'services',
    'pageTitle' => 'Состояние сервисов',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
