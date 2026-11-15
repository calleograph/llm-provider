<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Config\Paths;
use App\Diagnostics\DiagnosticLevel;
use App\Http\View;
use App\Ldap\AdLinkStatusChecker;

/** @var array $context */
extract($context);

/** @var \App\Config\LdapConfigRepository $configRepo */
/** @var \App\Diagnostics\DiagnosticLogger $diagnosticLogger */
$activeConfig = $configRepo->findActive();

$certs = $certRepo->all();
$nearestExpiry = null;
foreach ($certs as $cert) {
    $expiry = strtotime(str_replace(' UTC', '', $cert['valid_to']) . ' UTC');
    if ($expiry !== false && ($nearestExpiry === null || $expiry < $nearestExpiry)) {
        $nearestExpiry = $expiry;
    }
}
$expirySoon = $nearestExpiry !== null && $nearestExpiry < (time() + 30 * 86400);

$caBundleExists = false;
try {
    $caBundleBuilder->currentBundlePath();
    $caBundleExists = is_file(Paths::certs() . '/ca-bundle.pem');
} catch (\Throwable) {
    $caBundleExists = false;
}

$checks = [
    'ext-ldap' => extension_loaded('ldap'),
    'ext-openssl' => extension_loaded('openssl'),
    'PDO SQLite' => extension_loaded('pdo_sqlite'),
    'ext-mbstring' => extension_loaded('mbstring'),
];

$dataWritable = is_writable(Paths::data());
$certsWritable = is_writable(Paths::certs());
$logsWritable = is_writable(Paths::logs());

// Живая проверка связи с AD прямо на странице «Состояние» — та же логика,
// что использует публичный маркер, но с явным запуском (не кэшируется),
// так как страница администратора не подвержена анонимной нагрузке.
$linkReachable = null;
if ($activeConfig !== null) {
    try {
        $checker = new AdLinkStatusChecker($caBundleBuilder);
        $linkReachable = $checker->isReachable($activeConfig);
    } catch (\Throwable) {
        $linkReachable = false;
    }
}

$recentErrors = $diagnosticLogger->query(['level' => DiagnosticLevel::ERROR, 'limit' => 5])['rows'];

$overallOk = $activeConfig !== null && $caBundleExists && $linkReachable === true
    && !in_array(false, $checks, true) && $dataWritable && $certsWritable && $logsWritable;

ob_start();
?>
<?php if (isset($_GET['first_setup'])): ?>
<div class="alert alert-success" style="margin-bottom:20px;">
  Аварийный доступ создан. Завершите настройку в разделе <a href="index.php?section=ldap">«LDAP-подключения»</a>:
  1. загрузите CA-сертификат, 2. создайте и проверьте подключение к AD,
  затем <a href="index.php?section=access">3. укажите группу администраторов</a>.
</div>
<?php endif; ?>
<div class="alert <?php echo $overallOk ? 'alert-success' : 'alert-warn'; ?>" style="margin-bottom:20px;">
<?php if ($overallOk): ?>
  Всё в порядке: активная конфигурация настроена, сертификаты загружены, связь с AD установлена.
<?php else: ?>
  Обнаружены проблемы конфигурации — см. подсветку ниже (<span class="status-bad">красным</span>/<span class="status-warn">жёлтым</span>).
<?php endif; ?>
</div>

<div class="grid grid-3">
  <div class="stat-tile">
    <div class="label">Активная конфигурация</div>
    <div class="value"><?php echo $activeConfig !== null ? View::e($activeConfig->name) : '<span class="status-bad">не задана</span>'; ?></div>
  </div>
  <div class="stat-tile">
    <div class="label">LDAPS host : port</div>
    <div class="value"><?php echo $activeConfig !== null ? View::e($activeConfig->host . ' : ' . $activeConfig->port) : '—'; ?></div>
  </div>
  <div class="stat-tile">
    <div class="label">Связь с AD (LDAPS)</div>
    <div class="value <?php echo $linkReachable === true ? 'status-ok' : ($linkReachable === false ? 'status-bad' : ''); ?>">
<?php if ($linkReachable === null): ?>
      — (нет активной конфигурации)
<?php else: ?>
      <?php echo $linkReachable ? 'Установлена' : 'Недоступна'; ?>
<?php endif; ?>
    </div>
  </div>
  <div class="stat-tile">
    <div class="label">Состояние CA bundle</div>
    <div class="value <?php echo $caBundleExists ? 'status-ok' : 'status-bad'; ?>"><?php echo $caBundleExists ? 'Собран' : 'Не собран'; ?></div>
  </div>
  <div class="stat-tile">
    <div class="label">Ближайший срок окончания сертификата</div>
    <div class="value <?php echo $expirySoon ? 'status-warn' : ''; ?>"><?php echo $nearestExpiry !== null ? View::e(gmdate('Y-m-d', $nearestExpiry)) : '—'; ?></div>
  </div>
  <div class="stat-tile">
    <div class="label">Загружено сертификатов</div>
    <div class="value"><?php echo count($certs); ?></div>
  </div>
</div>

<?php if (!$linkReachable && $activeConfig !== null): ?>
<p class="muted" style="font-size:12px;margin-top:10px;">
  Нет связи с AD. Проверьте: доступен ли host <code><?php echo View::e($activeConfig->host); ?></code>
  по порту <code><?php echo (int) $activeConfig->port; ?></code> с сервера приложения, загружен ли
  корректный CA-сертификат в разделе «Сертификаты», и совпадает ли Subject/CN сертификата AD с
  тем, что настроен здесь. Подробности — в разделе «Журнал» → «Диагностика связи».
</p>
<?php endif; ?>

<hr class="sep">

<p class="section-title">Расширения PHP</p>
<div class="status-line-group">
<?php foreach ($checks as $label => $ok): ?>
  <span class="status-line"><span class="label"><?php echo View::e($label); ?>:</span> <span class="value <?php echo $ok ? 'status-ok' : 'status-bad'; ?>"><?php echo $ok ? 'Доступно' : 'Недоступно'; ?></span></span>
<?php endforeach; ?>
</div>

<hr class="sep">

<p class="section-title">Права на каталоги данных</p>
<div class="status-line-group">
  <span class="status-line"><span class="label">var/data:</span> <span class="value <?php echo $dataWritable ? 'status-ok' : 'status-bad'; ?>"><?php echo $dataWritable ? 'Запись разрешена' : 'Нет прав на запись'; ?></span></span>
  <span class="status-line"><span class="label">var/certs:</span> <span class="value <?php echo $certsWritable ? 'status-ok' : 'status-bad'; ?>"><?php echo $certsWritable ? 'Запись разрешена' : 'Нет прав на запись'; ?></span></span>
  <span class="status-line"><span class="label">var/logs:</span> <span class="value <?php echo $logsWritable ? 'status-ok' : 'status-bad'; ?>"><?php echo $logsWritable ? 'Запись разрешена' : 'Нет прав на запись'; ?></span></span>
</div>

<hr class="sep">

<p class="section-title">Последние ошибки диагностики</p>
<?php if ($recentErrors === []): ?>
<p class="muted" style="font-size:12.5px;">Ошибок не зафиксировано.</p>
<?php else: ?>
<table class="data-table">
  <thead><tr><th>Дата</th><th>Источник</th><th>Код</th><th>Сообщение</th></tr></thead>
  <tbody>
<?php foreach ($recentErrors as $row): ?>
    <tr>
      <td class="mono"><?php echo View::e($row['created_at']); ?></td>
      <td><?php echo View::e($row['source']); ?></td>
      <td class="mono"><?php echo View::e($row['event_code']); ?></td>
      <td style="max-width:400px;word-break:break-word;"><?php echo View::e($row['message']); ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<p style="margin-top:10px;"><a href="index.php?section=log&tab=diagnostics">Открыть полный диагностический журнал →</a></p>
<?php endif; ?>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'status',
    'pageTitle' => 'Состояние',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => null,
    'flashError' => null,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
