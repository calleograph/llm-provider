<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Diagnostics\DiagnosticSource;
use App\Http\View;
use App\Ldap\LdapClient;
use App\Ldap\LdapException;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Config\LdapConfigRepository $configRepo */
/** @var \App\Auth\LocalAdminRepository $localAdminRepo */
/** @var \App\Audit\AuditLogger $auditLogger */
/** @var \App\Diagnostics\DiagnosticLogger $diagnosticLogger */

$flashSuccess = null;
$flashError = null;

$activeConfig = $configRepo->findActive();

if ($method === 'POST' && $action === 'update_admin_group') {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } elseif ($activeConfig === null) {
        $flashError = 'Нет активной конфигурации подключения.';
    } else {
        $newGroupDn = trim((string) ($_POST['admin_group_dn'] ?? ''));
        if ($newGroupDn === '') {
            $flashError = 'Укажите DN группы.';
        } else {
            try {
                $client = new LdapClient($activeConfig, $caBundleBuilder->currentBundlePath());
                $client->connect();

                // Требуется собственный bind для проверки — используем креды текущего
                // администратора, если он вошёл доменным способом и подтвердил пароль повторно.
                $confirmUsername = trim((string) ($_POST['confirm_username'] ?? ''));
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

                if ($confirmUsername === '' || $confirmPassword === '') {
                    $flashError = 'Для проверки существования группы укажите логин и пароль доменной учётной записи с правом чтения каталога.';
                } else {
                    $principal = $confirmUsername . '@' . $activeConfig->domain;
                    $client->bind($principal, $confirmPassword);

                    if (!$client->groupExists($newGroupDn)) {
                        $flashError = 'Указанная группа не найдена в каталоге. DN не изменён.';
                    } else {
                        $warning = '';
                        $currentUserDn = $client->findUserDnBySamAccountName($confirmUsername);
                        if (AdminSession::method() === 'domain' && $currentUserDn !== null) {
                            $stillMember = $client->isMemberOfGroupRecursive($currentUserDn, $newGroupDn);
                            if (!$stillMember) {
                                $warning = ' Внимание: текущий администратор НЕ входит в новую группу — после сохранения возможна потеря доступа через доменный вход.';
                            }
                        }

                        $data = [
                            'name' => $activeConfig->name,
                            'ldaps_url' => $activeConfig->ldapsUrl,
                            'host' => $activeConfig->host,
                            'port' => $activeConfig->port,
                            'base_dn' => $activeConfig->baseDn,
                            'domain' => $activeConfig->domain,
                            'netbios_domain' => $activeConfig->netbiosDomain,
                            'admin_group_dn' => $newGroupDn,
                            'protocol_version' => $activeConfig->protocolVersion,
                            'starttls' => $activeConfig->startTls,
                            'network_timeout' => $activeConfig->networkTimeout,
                            'ca_bundle_file' => $activeConfig->caBundleFile,
                        ];
                        $configRepo->update($activeConfig->id, $data);
                        $auditLogger->log(AdminSession::username(), $clientIp, 'admin_group_change', true, 'new_dn=' . $newGroupDn);
                        $flashSuccess = 'DN группы администраторов обновлён.' . $warning;
                        $activeConfig = $configRepo->findById($activeConfig->id);
                    }
                }
                $client->close();
                $confirmPassword = str_repeat("\0", strlen($confirmPassword));
                unset($confirmPassword);
            } catch (LdapException $e) {
                $flashError = 'Не удалось подключиться к каталогу для проверки группы.';
                $auditLogger->log(AdminSession::username(), $clientIp, 'admin_group_change', false);
                $diagnosticLogger->error(
                    DiagnosticSource::LDAP_BIND,
                    $e->errorCode,
                    $e->getMessage(),
                    ['admin' => (string) AdminSession::username()],
                    $clientIp,
                );
            } catch (\Throwable $e) {
                $flashError = 'Не удалось подключиться к каталогу для проверки группы.';
                $auditLogger->log(AdminSession::username(), $clientIp, 'admin_group_change', false);
                $diagnosticLogger->error(
                    DiagnosticSource::LDAP_CONNECT,
                    get_class($e),
                    $e->getMessage(),
                    ['admin' => (string) AdminSession::username()],
                    $clientIp,
                );
            }
        }
    }
}

if ($method === 'POST' && $action === 'change_local_admin_password') {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        $newPassword = (string) ($_POST['new_local_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_local_password'] ?? '');

        // Действие доступно только после входа в административную сессию;
        // повторный ввод пароля локальной аварийной учётки здесь намеренно не
        // требуется. Это позволяет сменить её пароль из доменной админ-сессии
        // и восстановить локальный доступ, не зная прежний пароль.
        if (mb_strlen($newPassword) < 16) {
            $flashError = 'Новый пароль локального администратора должен быть не короче 16 символов.';
            $auditLogger->log(
                (string) AdminSession::username(),
                $clientIp,
                'local_admin_password_change',
                false,
                'reason=password_too_short; session_method=' . (string) AdminSession::method(),
            );
        } elseif (!hash_equals($newPassword, $confirmPassword)) {
            $flashError = 'Новый пароль и подтверждение не совпадают.';
            $auditLogger->log(
                (string) AdminSession::username(),
                $clientIp,
                'local_admin_password_change',
                false,
                'reason=confirmation_mismatch; session_method=' . (string) AdminSession::method(),
            );
        } else {
            $localAdminRepo->changePassword($newPassword);
            $auditLogger->log(
                (string) AdminSession::username(),
                $clientIp,
                'local_admin_password_change',
                true,
                'target=' . (string) $localAdminRepo->username() . '; session_method=' . (string) AdminSession::method(),
            );
            $flashSuccess = 'Пароль локального администратора изменён.';
        }

        $newPassword = str_repeat("\0", strlen($newPassword));
        $confirmPassword = str_repeat("\0", strlen($confirmPassword));
        unset($newPassword, $confirmPassword);
    }
}

ob_start();
?>
<p class="section-title">Группа администраторов Active Directory</p>
<p class="muted" style="font-size:12.5px;">Пользователи, состоящие в этой группе (прямо или через вложенные группы), могут входить в административную часть доменной учётной записью.</p>
<dl class="kv">
  <dt>Текущий DN группы</dt><dd class="mono"><?php echo $activeConfig !== null ? View::e($activeConfig->adminGroupDn) : '—'; ?></dd>
</dl>

<form method="post" action="index.php?section=access&action=update_admin_group" style="margin-top:16px;">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="admin_group_dn">Новый DN группы администраторов<?php echo View::settingCode('ADMIN_GROUP_DN'); ?></label>
      <input class="text-input" id="admin_group_dn" name="admin_group_dn" value="<?php echo View::e($activeConfig->adminGroupDn ?? ''); ?>" required>
    </div>
  </div>
  <p class="muted" style="font-size:12px;">Для проверки существования группы в каталоге укажите доменные логин/пароль (не сохраняются).</p>
  <div class="form-row">
    <div class="field">
      <label class="field-label">Доменный логин (для проверки)</label>
      <input class="text-input" name="confirm_username" autocomplete="off">
    </div>
    <div class="field">
      <label class="field-label">Пароль</label>
      <input class="text-input" type="password" name="confirm_password" autocomplete="off">
    </div>
  </div>
  <button class="btn btn-primary" type="submit">Проверить и сохранить</button>
</form>

<hr class="sep">

<p class="section-title">Локальный аварийный администратор</p>
<p class="muted" style="font-size:12.5px;">Логин: <strong><?php echo View::e((string) $localAdminRepo->username()); ?></strong>. Независим от Active Directory. Создание второго локального администратора не предусмотрено. Пароль меняется из уже подтверждённой административной сессии — старый пароль повторно не запрашивается.</p>
<form method="post" action="index.php?section=access&action=change_local_admin_password">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label">Новый пароль (не короче 16 символов)</label>
      <div class="password-field-wrap">
        <input class="text-input" type="password" id="new_local_password" name="new_local_password" required minlength="16" autocomplete="new-password">
        <button type="button" class="toggle-password" data-target="new_local_password">Показать</button>
      </div>
    </div>
    <div class="field">
      <label class="field-label">Повторите новый пароль</label>
      <div class="password-field-wrap">
        <input class="text-input" type="password" id="confirm_local_password" name="confirm_local_password" required minlength="16" autocomplete="new-password">
        <button type="button" class="toggle-password" data-target="confirm_local_password">Показать</button>
      </div>
    </div>
  </div>
  <button class="btn btn-primary" type="submit">Изменить пароль локального администратора</button>
</form>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'access',
    'pageTitle' => 'Доступ к админ-панели',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
