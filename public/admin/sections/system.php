<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Http\View;
use App\Llm\Backup\ConfigBackupException;
use App\Security\Csrf;
use App\Security\SessionManager;

/** @var array $context */
extract($context);

/** @var \App\Config\AppSettings $appSettings */
/** @var \App\Audit\AuditLogger $auditLogger */
/** @var \App\Config\LdapConfigRepository $configRepo */
/** @var \App\Llm\LlmModule $llmModule */

$flashSuccess = null;
$flashError = null;
$backupWarnings = [];

// ---- Экспорт конфигов LLM-чата в ZIP (до какого-либо HTML-вывода) ----
if ($method === 'GET' && $action === 'export_llm_config') {
    if (!Csrf::verify($_GET['csrf'] ?? null)) {
        http_response_code(403);
        echo 'Форма устарела, обновите страницу и попробуйте снова.';
        exit;
    }
    $zip = $llmModule->backup($configRepo)->exportZip();
    $auditLogger->log(AdminSession::username(), $clientIp, 'llm_config_backup_export', true);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="llm_config_backup_' . gmdate('Ymd_His') . '.zip"');
    header('Content-Length: ' . strlen($zip));
    echo $zip;
    exit;
}

if ($method === 'POST' && $action === 'import_llm_config') {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } elseif (!isset($_FILES['archive']) || (int) ($_FILES['archive']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flashError = 'Выберите файл архива резервной копии (.zip).';
    } else {
        $content = file_get_contents($_FILES['archive']['tmp_name']);
        if ($content === false || $content === '') {
            $flashError = 'Не удалось прочитать загруженный файл.';
        } else {
            try {
                $stats = $llmModule->backup($configRepo)->importZip($content, (string) AdminSession::username());
                $auditLogger->log(AdminSession::username(), $clientIp, 'llm_config_backup_import', true, json_encode($stats, JSON_UNESCAPED_UNICODE));
                $flashSuccess = sprintf(
                    'Восстановлено: доменов создано %d, обновлено %d; групп создано %d, обновлено %d; '
                    . 'нейросетей создано %d, обновлено %d; связей прав доступа — %d. '
                    . 'API-ключи и пароли служебных учёток нужно ввести заново в соответствующих разделах.',
                    $stats['domains_created'], $stats['domains_updated'],
                    $stats['groups_created'], $stats['groups_updated'],
                    $stats['providers_created'], $stats['providers_updated'],
                    $stats['access_links'],
                );
                $backupWarnings = $stats['warnings'];
            } catch (ConfigBackupException $e) {
                $auditLogger->log(AdminSession::username(), $clientIp, 'llm_config_backup_import', false, $e->getMessage());
                $flashError = $e->getMessage();
            }
        }
    }
}

if ($method === 'POST' && $action === 'update_admin_idle_timeout') {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        $minutes = (int) ($_POST['admin_idle_timeout_minutes'] ?? 0);
        $maxMinutes = (int) (SessionManager::MAX_ADMIN_IDLE_TIMEOUT_SECONDS / 60);
        if ($minutes < 5 || $minutes > $maxMinutes) {
            $flashError = "Таймаут — от 5 до {$maxMinutes} минут.";
        } else {
            $appSettings->set('admin_idle_timeout_minutes', (string) $minutes);
            $auditLogger->log(AdminSession::username(), $clientIp, 'admin_idle_timeout_update', true, 'minutes=' . $minutes);
            $flashSuccess = 'Таймаут бездействия администратора обновлён.';
        }
    }
}

if ($method === 'POST' && $action === 'update_trusted_proxies') {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        $raw = trim((string) ($_POST['trusted_proxies'] ?? ''));
        $list = array_values(array_filter(array_map('trim', explode(',', $raw))));

        foreach ($list as $entry) {
            $isCidr = str_contains($entry, '/');
            $checkIp = $isCidr ? explode('/', $entry, 2)[0] : $entry;
            if (filter_var($checkIp, FILTER_VALIDATE_IP) === false) {
                $flashError = 'Некорректный адрес или подсеть: ' . $entry;
                break;
            }
        }

        if ($flashError === null) {
            $appSettings->setTrustedProxies($list);
            $auditLogger->log(AdminSession::username(), $clientIp, 'trusted_proxies_update', true, 'count=' . count($list));
            $flashSuccess = 'Список доверенных reverse proxy обновлён.';
        }
    }
}

$trustedProxies = $appSettings->trustedProxies();
$adminIdleTimeoutMinutes = (int) $appSettings->get('admin_idle_timeout_minutes', (string) (SessionManager::DEFAULT_ADMIN_IDLE_TIMEOUT_SECONDS / 60));
$maxIdleTimeoutMinutes = (int) (SessionManager::MAX_ADMIN_IDLE_TIMEOUT_SECONDS / 60);

ob_start();
?>
<p class="section-title">Сессия администратора</p>
<form method="post" action="index.php?section=system&action=update_admin_idle_timeout">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="admin_idle_timeout_minutes">Таймаут бездействия, минут (5–<?php echo $maxIdleTimeoutMinutes; ?>)<?php echo View::settingCode('ADMIN_IDLE_TIMEOUT'); ?></label>
      <input class="text-input" type="number" min="5" max="<?php echo $maxIdleTimeoutMinutes; ?>" id="admin_idle_timeout_minutes" name="admin_idle_timeout_minutes" value="<?php echo $adminIdleTimeoutMinutes; ?>">
    </div>
  </div>
  <p class="muted llm-note">Сеанс администратора завершается автоматически после указанного периода без действий в панели.</p>
  <button class="btn btn-primary" type="submit">Сохранить</button>
</form>

<hr class="sep">

<p class="section-title">Доверенные reverse proxy</p>
<p class="muted" style="font-size:12.5px;">Заголовок X-Forwarded-For учитывается только если непосредственный адрес подключения входит в этот список (IP или CIDR через запятую).</p>
<form method="post" action="index.php?section=system&action=update_trusted_proxies">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="trusted_proxies">Список доверенных адресов<?php echo View::settingCode('TRUSTED_PROXIES'); ?></label>
      <textarea class="text-input" id="trusted_proxies" name="trusted_proxies" rows="2" placeholder="10.0.0.10, 10.0.0.0/24"><?php echo View::e(implode(', ', $trustedProxies)); ?></textarea>
    </div>
  </div>
  <button class="btn btn-primary" type="submit">Сохранить</button>
</form>

<hr class="sep">

<p class="section-title">Резервное копирование</p>
<p class="muted" style="font-size:12.5px;">
  Для полного резервного копирования сохраняйте каталог <code>var/data</code> (SQLite база настроек,
  master key) и <code>var/certs</code> (CA bundle и загруженные сертификаты). Подробности — в README.md.
</p>

<p class="section-title" style="margin-top:18px;">Конфигурация LLM-чата</p>
<p class="muted" style="font-size:12.5px;">
  Экспорт/восстановление доменов и групп, нейросетей, прав доступа «группа → нейросеть» и общих
  параметров чата в один ZIP-архив. API-ключи нейросетей и пароли служебных учёток LDAP в архив
  <strong>не попадают</strong> ни в открытом, ни в зашифрованном виде — после восстановления их нужно
  ввести заново вручную в разделах «Подключения LLM API» и «LDAP-подключения»/«Доступ к чату».
</p>
<div class="form-row">
  <div class="field">
    <a class="btn btn-primary" href="index.php?section=system&action=export_llm_config&csrf=<?php echo urlencode($csrfToken); ?>">Скачать архив конфигурации</a>
  </div>
</div>

<form method="post" action="index.php?section=system&action=import_llm_config" enctype="multipart/form-data" style="margin-top:10px;"
      data-confirm-title="Восстановить конфигурацию из архива?"
      data-confirm-text="Настройки доменов, нейросетей и прав доступа из архива будут применены поверх текущих (по имени). Действие необратимо без отдельной резервной копии.">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="archive">Восстановить из архива (.zip)<?php echo View::settingCode('CONFIG_RESTORE'); ?></label>
      <input class="text-input" type="file" id="archive" name="archive" accept=".zip" required>
      <p class="muted llm-note">Существующие домены/нейросети (по имени) будут обновлены, новые — добавлены. Ничего не удаляется.</p>
    </div>
  </div>
  <button class="btn btn-danger" type="submit">Восстановить</button>
</form>

<?php if ($backupWarnings !== []): ?>
<div class="alert alert-warn" style="margin-top:10px;">
  <p style="margin:0 0 6px;font-weight:650;">Предупреждения при восстановлении:</p>
  <ul style="margin:0;padding-left:18px;">
<?php foreach ($backupWarnings as $warning): ?>
    <li><?php echo View::e($warning); ?></li>
<?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'system',
    'pageTitle' => 'Настройки',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
