<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Diagnostics\DiagnosticSource;
use App\Http\View;
use App\Ldap\LdapException;
use App\Llm\Domain\DomainGroupRepository;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Diagnostics\DiagnosticLogger $diagnosticLogger */
/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */

$flashSuccess = null;
$flashError = null;
$verifyResults = []; // domain_id => результат проверки

$domainsRepo = $llmModule->domains();
$groupsRepo = $llmModule->groups();
$accessPolicy = $llmModule->access();
$usersRepo = $llmModule->usage();
$userProviders = $llmModule->userProviders();
$events = $llmModule->events();
$admin = (string) AdminSession::username();

function llmFieldError(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<p class="field-error">' . View::e($errors[$key]) . '</p>' : '';
}

$postedId = (int) ($_POST['id'] ?? 0);
$actions = ['save_subjects', 'verify_groups', 'save_permissions'];

if ($method === 'POST' && in_array($action, $actions, true)) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        switch ($action) {
            case 'save_subjects':
                $existing = $domainsRepo->find($postedId);
                if ($existing === null) {
                    $flashError = 'Подключение не найдено.';
                    break;
                }
                $providerNames = $llmModule->providers()->names();
                $errors = [];
                $changes = 0;

                foreach ((array) ($_POST['groups'] ?? []) as $groupId => $row) {
                    $groupId = (int) $groupId;
                    $current = $groupsRepo->find($groupId);
                    if ($current === null || (int) $current['ldap_config_id'] !== $postedId || !is_array($row)) {
                        continue;
                    }
                    if (!empty($row['delete'])) {
                        $groupsRepo->delete($groupId);
                        $events->admin($admin, $clientIp, 'chat_access_delete', true, [
                            'domain' => $existing->config->name, 'subject' => $current['display_name'], 'ref' => $current['group_ref'],
                        ]);
                        $changes++;
                        continue;
                    }
                    $displayName = trim((string) ($row['display_name'] ?? ''));
                    $groupRef = trim((string) ($row['group_ref'] ?? ''));
                    $subjectType = (string) ($current['subject_type'] ?? DomainGroupRepository::SUBJECT_GROUP);
                    $error = DomainGroupRepository::validateDisplayName($displayName)
                        ?? ($subjectType === DomainGroupRepository::SUBJECT_USER
                            ? DomainGroupRepository::validateSamAccountName($groupRef)
                            : DomainGroupRepository::validateRef($groupRef));
                    if ($error === null && $groupsRepo->refExists($postedId, $groupRef, $groupId)) {
                        $error = 'такая запись уже добавлена';
                    }
                    if ($error !== null) {
                        $errors[] = '«' . mb_scrub($displayName !== '' ? $displayName : (string) $current['display_name'], 'UTF-8') . '»: ' . $error;
                        continue;
                    }
                    $enabled = !empty($row['enabled']);
                    if ($displayName !== $current['display_name'] || $groupRef !== $current['group_ref'] || $enabled !== (bool) $current['is_enabled']) {
                        $groupsRepo->update($groupId, $displayName, $groupRef, $enabled);
                        $events->admin($admin, $clientIp, 'chat_access_update', true, [
                            'domain' => $existing->config->name, 'subject' => $displayName, 'ref' => $groupRef, 'enabled' => $enabled,
                        ]);
                        $changes++;
                    }
                    $newCategoryId = (int) ($row['limit_category_id'] ?? 0);
                    $newCategoryId = $newCategoryId > 0 ? $newCategoryId : null;
                    if ($newCategoryId !== ($current['limit_category_id'] !== null ? (int) $current['limit_category_id'] : null)) {
                        $groupsRepo->setLimitCategory($groupId, $newCategoryId);
                        $changes++;
                    }
                    $newAllowUnanonymized = !empty($row['allow_unanonymized']);
                    if ($newAllowUnanonymized !== (bool) $current['allow_unanonymized']) {
                        $groupsRepo->setAllowUnanonymized($groupId, $newAllowUnanonymized);
                        $events->admin($admin, $clientIp, 'chat_access_unanonymized_change', true, [
                            'domain' => $existing->config->name, 'subject' => $displayName, 'allowed' => $newAllowUnanonymized,
                        ]);
                        $changes++;
                    }
                }

                foreach ((array) ($_POST['new_groups'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $displayName = trim((string) ($row['display_name'] ?? ''));
                    $groupRef = trim((string) ($row['group_ref'] ?? ''));
                    if ($displayName === '' && $groupRef === '') {
                        continue;
                    }
                    $error = DomainGroupRepository::validateDisplayName($displayName) ?? DomainGroupRepository::validateRef($groupRef);
                    if ($error === null && $groupsRepo->refExists($postedId, $groupRef)) {
                        $error = 'такая запись уже добавлена';
                    }
                    if ($error !== null) {
                        $errors[] = 'Новая группа «' . mb_scrub($displayName !== '' ? $displayName : $groupRef, 'UTF-8') . '»: ' . $error;
                        continue;
                    }
                    $newId = $groupsRepo->create($postedId, $displayName, $groupRef, !empty($row['enabled']), $admin, DomainGroupRepository::SUBJECT_GROUP);
                    $newCategoryId = (int) ($row['limit_category_id'] ?? 0);
                    if ($newCategoryId > 0) {
                        $groupsRepo->setLimitCategory($newId, $newCategoryId);
                    }
                    if (!empty($row['allow_unanonymized'])) {
                        $groupsRepo->setAllowUnanonymized($newId, true);
                    }
                    $events->admin($admin, $clientIp, 'chat_access_create', true, [
                        'domain' => $existing->config->name, 'subject' => $displayName, 'ref' => $groupRef, 'type' => 'group',
                    ]);
                    $changes++;
                }

                foreach ((array) ($_POST['new_users'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $samAccountName = trim((string) ($row['sam_account_name'] ?? ''));
                    if ($samAccountName === '') {
                        continue;
                    }
                    $displayName = $samAccountName;
                    $error = DomainGroupRepository::validateSamAccountName($samAccountName);
                    if ($error === null && $groupsRepo->refExists($postedId, $samAccountName)) {
                        $error = 'такой пользователь уже добавлен';
                    }
                    if ($error !== null) {
                        $errors[] = 'Новый пользователь «' . mb_scrub($samAccountName, 'UTF-8') . '»: ' . $error;
                        continue;
                    }
                    $newId = $groupsRepo->create($postedId, $displayName, $samAccountName, !empty($row['enabled']), $admin, DomainGroupRepository::SUBJECT_USER);
                    $newCategoryId = (int) ($row['limit_category_id'] ?? 0);
                    if ($newCategoryId > 0) {
                        $groupsRepo->setLimitCategory($newId, $newCategoryId);
                    }
                    if (!empty($row['allow_unanonymized'])) {
                        $groupsRepo->setAllowUnanonymized($newId, true);
                    }
                    $events->admin($admin, $clientIp, 'chat_access_create', true, [
                        'domain' => $existing->config->name, 'subject' => $displayName, 'type' => 'user',
                    ]);
                    $changes++;
                }

                if ($errors !== []) {
                    $flashError = 'Часть строк не сохранена: ' . implode('; ', $errors) . '.';
                }
                if ($changes > 0) {
                    $flashSuccess = 'Доступ к чату для «' . $existing->config->name . '» сохранён (изменений: ' . $changes . ').';
                } elseif ($errors === []) {
                    $flashSuccess = 'Изменений нет.';
                }
                break;

            case 'verify_groups':
                $existing = $domainsRepo->find($postedId);
                if ($existing === null) {
                    $flashError = 'Подключение не найдено.';
                    break;
                }
                try {
                    $verifyResults[$postedId] = $llmModule->authenticator()->verifyGroups($existing);
                    $missing = array_values(array_filter($verifyResults[$postedId], static fn (array $r): bool => !$r['ok']));
                    $events->admin($admin, $clientIp, 'chat_access_verify', $missing === [], [
                        'domain' => $existing->config->name, 'subjects' => count($verifyResults[$postedId]), 'not_found' => count($missing),
                    ]);
                } catch (LdapException $e) {
                    $flashError = $e->errorCode === 'SERVICE_ACCOUNT_MISSING'
                        ? $e->getMessage()
                        : 'Не удалось проверить группы/пользователей: ошибка LDAPS (' . $e->errorCode . '). Подробности — в журнале диагностики.';
                    $events->admin($admin, $clientIp, 'chat_access_verify', false, ['domain' => $existing->config->name, 'error' => $e->errorCode]);
                    $diagnosticLogger->error(DiagnosticSource::LDAP_BIND, $e->errorCode, $e->getMessage(), ['config' => $existing->config->name], $clientIp);
                } catch (\Throwable $e) {
                    $flashError = 'Не удалось проверить: ' . $e->getMessage();
                    $diagnosticLogger->error(DiagnosticSource::LDAP_CONNECT, get_class($e), $e->getMessage(), ['config' => $existing->config->name], $clientIp);
                }
                break;

            case 'save_permissions':
                $providerNames = $llmModule->providers()->names();
                $posted = (array) ($_POST['perm'] ?? []);
                $changedGroupSubjects = 0;
                $changedUserSubjects = 0;
                foreach ($groupsRepo->all() as $group) {
                    $groupId = (int) $group['id'];
                    $diff = $accessPolicy->setGroupProviders($groupId, (array) ($posted[$groupId] ?? []));
                    if ($diff['added'] === [] && $diff['removed'] === []) {
                        continue;
                    }
                    $isUserSubject = ($group['subject_type'] ?? DomainGroupRepository::SUBJECT_GROUP) === DomainGroupRepository::SUBJECT_USER;
                    $isUserSubject ? $changedUserSubjects++ : $changedGroupSubjects++;
                    $events->admin($admin, $clientIp, 'llm_permission_change', true, [
                        'domain' => $group['domain_name'], 'subject' => $group['display_name'],
                        'added' => array_map(static fn (int $id): string => $providerNames[$id] ?? (string) $id, $diff['added']) ?: ['—'],
                        'removed' => array_map(static fn (int $id): string => $providerNames[$id] ?? (string) $id, $diff['removed']) ?: ['—'],
                    ]);
                }

                $postedUsers = (array) ($_POST['user_perm'] ?? []);
                $changedIndividual = 0;
                foreach ($postedUsers as $ownerKey => $providerIds) {
                    $before = $userProviders->providersFor((string) $ownerKey);
                    $userProviders->setProviders((string) $ownerKey, array_map('intval', (array) $providerIds));
                    $after = $userProviders->providersFor((string) $ownerKey);
                    if ($before !== $after) {
                        $changedIndividual++;
                        $events->admin($admin, $clientIp, 'llm_user_provider_change', true, [
                            'user' => $ownerKey,
                            'providers' => array_map(static fn (int $id): string => $providerNames[$id] ?? (string) $id, $after) ?: ['—'],
                        ]);
                    }
                }
                // Пользователи, у которых сняли все галочки, не попадают в
                // $_POST['user_perm'] вообще — обнулить их права явно.
                foreach (array_keys($userProviders->matrix()) as $ownerKey) {
                    if (!array_key_exists($ownerKey, $postedUsers) && $userProviders->providersFor($ownerKey) !== []) {
                        $userProviders->setProviders($ownerKey, []);
                        $changedIndividual++;
                        $events->admin($admin, $clientIp, 'llm_user_provider_change', true, ['user' => $ownerKey, 'providers' => ['—']]);
                    }
                }

                // Персональное «без обезличивания» — тот же чекбокс-массив
                // по owner_key, что и права на нейросети, но отдельное поле
                // формы (unanon_perm), не привязанное к конкретной нейросети.
                $postedUnanon = (array) ($_POST['unanon_perm'] ?? []);
                $changedUnanonymized = 0;
                foreach ($usersRepo->allProfiles() as $profileRow) {
                    $ownerKey = (string) $profileRow['owner_key'];
                    $before = (bool) ($profileRow['allow_unanonymized'] ?? false);
                    $after = !empty($postedUnanon[$ownerKey]);
                    if ($before !== $after) {
                        $usersRepo->setAllowUnanonymized($ownerKey, $after);
                        $changedUnanonymized++;
                        $events->admin($admin, $clientIp, 'llm_user_unanonymized_change', true, ['user' => $ownerKey, 'allowed' => $after]);
                    }
                }

                $totalChanged = $changedGroupSubjects + $changedUserSubjects + $changedIndividual + $changedUnanonymized;
                $flashSuccess = $totalChanged > 0
                    ? 'Права сохранены (изменено: группы — ' . $changedGroupSubjects . ', отдельные пользователи — ' . $changedUserSubjects
                        . ', индивидуальный доступ вошедших — ' . $changedIndividual . ', без обезличивания — ' . $changedUnanonymized . ').'
                    : 'Изменений нет.';
                break;
        }
    }
}

$domains = $domainsRepo->all();
$providerNames = $llmModule->providers()->names();
$providers = $llmModule->providers()->all();
$matrix = $accessPolicy->matrix();
$limitCategories = $llmModule->limitCategories()->all();
$userProfiles = $usersRepo->allProfiles();
$userProviderMatrix = $userProviders->matrix();

$renderCategorySelect = static function (string $namePrefix, ?int $selectedId) use ($limitCategories): string {
    $html = '<select class="text-input" name="' . $namePrefix . '[limit_category_id]" aria-label="Категория лимита"><option value="0">По умолчанию</option>';
    foreach ($limitCategories as $cat) {
        $html .= '<option value="' . $cat['id'] . '"' . ($selectedId === $cat['id'] ? ' selected' : '') . '>' . View::e($cat['name']) . '</option>';
    }

    return $html . '</select>';
};

ob_start();
?>
<p class="muted llm-lead">
  Кому разрешён доступ к LLM-чату: доменные группы или отдельные пользователи домена. Пользователь
  видит объединение нейросетей всех своих прав (по группам и персональных). Права проверяются на
  сервере при каждом запросе — подмена идентификатора нейросети в запросе доступа не даёт.
  Колонка «Без обезличивания» — разрешает пользователям этой группы/учётной записи ставить в чате
  галочку «не обезличивать» для конкретного сообщения (текст и вложения уйдут в нейросеть напрямую,
  минуя обезличивание) — включайте только для доверенных сотрудников; каждое такое сообщение
  фиксируется в журнале.
</p>

<?php foreach ($domains as $domain):
    $id = $domain->id();
    $subjects = $groupsRepo->forDomain($id);
    $groupSubjects = array_values(array_filter($subjects, static fn (array $s): bool => ($s['subject_type'] ?? 'group') !== DomainGroupRepository::SUBJECT_USER));
    $userSubjects = array_values(array_filter($subjects, static fn (array $s): bool => ($s['subject_type'] ?? 'group') === DomainGroupRepository::SUBJECT_USER));
?>
<div class="llm-card" id="domain-<?php echo $id; ?>">
  <div class="llm-card-head">
    <h2><?php echo View::e($domain->config->name); ?></h2>
    <div>
      <span class="badge <?php echo $domain->llmEnabled ? 'badge-active' : 'badge-inactive'; ?>">Чат: <?php echo $domain->llmEnabled ? 'включён' : 'выключен'; ?></span>
      <button class="btn btn-small" type="submit" form="verifyGroups<?php echo $id; ?>"<?php echo $subjects === [] ? ' disabled' : ''; ?>>Проверить в AD</button>
    </div>
  </div>
<?php if (!$domain->llmEnabled): ?>
  <p class="muted llm-note">Домен выключен для чата (раздел «LDAP-подключения») — доступ ниже не действует, пока домен не включён.</p>
<?php endif; ?>

  <form method="post" action="index.php?section=chat_access&action=save_subjects#domain-<?php echo $id; ?>">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <input type="hidden" name="id" value="<?php echo $id; ?>">

    <p class="section-title" style="margin-top:12px;">Группы</p>
    <div class="llm-table-wrap">
    <table class="data-table">
      <thead><tr><th>Отображаемое имя</th><th>CN или полный DN группы</th><th>Статус</th><th>Категория лимита</th><th>Без обезличивания</th><th></th></tr></thead>
      <tbody id="groupRows<?php echo $id; ?>">
<?php foreach ($groupSubjects as $group): $gid = (int) $group['id']; ?>
        <tr>
          <td><input class="text-input" name="groups[<?php echo $gid; ?>][display_name]" value="<?php echo View::e((string) $group['display_name']); ?>" required></td>
          <td><input class="text-input mono" name="groups[<?php echo $gid; ?>][group_ref]" value="<?php echo View::e((string) $group['group_ref']); ?>" required></td>
          <td><label class="switch"><input type="checkbox" name="groups[<?php echo $gid; ?>][enabled]" value="1" <?php echo $group['is_enabled'] ? 'checked' : ''; ?>><span class="track"></span></label></td>
          <td><?php echo $renderCategorySelect('groups[' . $gid . ']', $group['limit_category_id'] !== null ? (int) $group['limit_category_id'] : null); ?></td>
          <td><label class="switch switch-danger" title="Разрешить членам группы отправлять сообщения без обезличивания (галочка на стороне пользователя, в чате)"><input type="checkbox" name="groups[<?php echo $gid; ?>][allow_unanonymized]" value="1" <?php echo !empty($group['allow_unanonymized']) ? 'checked' : ''; ?>><span class="track"></span></label></td>
          <td><label class="llm-inline-check llm-danger-check"><input type="checkbox" name="groups[<?php echo $gid; ?>][delete]" value="1"> удалить</label></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <template id="groupTemplate<?php echo $id; ?>">
      <tr class="llm-new-row">
        <td><input class="text-input" name="new_groups[__INDEX__][display_name]" placeholder="Пользователи ChatGPT"></td>
        <td><input class="text-input mono" name="new_groups[__INDEX__][group_ref]" placeholder="CN=LLM-Users,OU=Groups,DC=example,DC=local или LLM-Users"></td>
        <td><label class="switch"><input type="checkbox" name="new_groups[__INDEX__][enabled]" value="1" checked><span class="track"></span></label></td>
        <td><?php echo $renderCategorySelect('new_groups[__INDEX__]', null); ?></td>
        <td><label class="switch switch-danger"><input type="checkbox" name="new_groups[__INDEX__][allow_unanonymized]" value="1"><span class="track"></span></label></td>
        <td><button type="button" class="btn btn-small" data-remove-row>Убрать</button></td>
      </tr>
    </template>
<?php if ($groupSubjects === []): ?>
    <p class="muted llm-note">Групп пока нет.</p>
<?php endif; ?>
    <div class="llm-actions">
      <button type="button" class="btn btn-small" data-add-row="groupTemplate<?php echo $id; ?>" data-add-row-target="groupRows<?php echo $id; ?>">+ Добавить группу</button>
    </div>

    <p class="section-title" style="margin-top:18px;">Отдельные пользователи</p>
    <div class="llm-table-wrap">
    <table class="data-table">
      <thead><tr><th>Доменное имя (sAMAccountName)</th><th>Статус</th><th>Категория лимита</th><th>Без обезличивания</th><th></th></tr></thead>
      <tbody id="userRows<?php echo $id; ?>">
<?php foreach ($userSubjects as $userSubject): $uid = (int) $userSubject['id']; ?>
        <tr>
          <td><input class="text-input mono" name="groups[<?php echo $uid; ?>][display_name]" value="<?php echo View::e((string) $userSubject['display_name']); ?>" required></td>
          <td><label class="switch"><input type="checkbox" name="groups[<?php echo $uid; ?>][enabled]" value="1" <?php echo $userSubject['is_enabled'] ? 'checked' : ''; ?>><span class="track"></span></label></td>
          <td><?php echo $renderCategorySelect('groups[' . $uid . ']', $userSubject['limit_category_id'] !== null ? (int) $userSubject['limit_category_id'] : null); ?></td>
          <td><label class="switch switch-danger" title="Разрешить пользователю отправлять сообщения без обезличивания (галочка на стороне пользователя, в чате)"><input type="checkbox" name="groups[<?php echo $uid; ?>][allow_unanonymized]" value="1" <?php echo !empty($userSubject['allow_unanonymized']) ? 'checked' : ''; ?>><span class="track"></span></label></td>
          <td><label class="llm-inline-check llm-danger-check"><input type="checkbox" name="groups[<?php echo $uid; ?>][delete]" value="1"> удалить</label>
              <input type="hidden" name="groups[<?php echo $uid; ?>][group_ref]" value="<?php echo View::e((string) $userSubject['group_ref']); ?>"></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <template id="userTemplate<?php echo $id; ?>">
      <tr class="llm-new-row">
        <td><input class="text-input mono" name="new_users[__INDEX__][sam_account_name]" placeholder="ivanov"></td>
        <td><label class="switch"><input type="checkbox" name="new_users[__INDEX__][enabled]" value="1" checked><span class="track"></span></label></td>
        <td><?php echo $renderCategorySelect('new_users[__INDEX__]', null); ?></td>
        <td><label class="switch switch-danger"><input type="checkbox" name="new_users[__INDEX__][allow_unanonymized]" value="1"><span class="track"></span></label></td>
        <td><button type="button" class="btn btn-small" data-remove-row>Убрать</button></td>
      </tr>
    </template>
<?php if ($userSubjects === []): ?>
    <p class="muted llm-note">Отдельных пользователей пока нет.</p>
<?php endif; ?>
    <div class="llm-actions">
      <button type="button" class="btn btn-small" data-add-row="userTemplate<?php echo $id; ?>" data-add-row-target="userRows<?php echo $id; ?>">+ Добавить пользователя</button>
      <button class="btn btn-primary" type="submit">Сохранить</button>
    </div>
  </form>
  <form id="verifyGroups<?php echo $id; ?>" method="post" action="index.php?section=chat_access&action=verify_groups#domain-<?php echo $id; ?>">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
  </form>

<?php if (isset($verifyResults[$id])): ?>
  <table class="data-table llm-result">
    <thead><tr><th class="llm-col-icon"></th><th>Кому</th><th>Задано</th><th>Найдено в каталоге</th></tr></thead>
    <tbody>
<?php foreach ($verifyResults[$id] as $row): ?>
      <tr>
        <td><span class="<?php echo $row['ok'] ? 'status-ok' : 'status-bad'; ?>"><?php echo $row['ok'] ? '✓' : '✗'; ?></span></td>
        <td><?php echo View::e($row['display_name']); ?></td>
        <td class="mono"><?php echo View::e($row['group_ref']); ?></td>
        <td class="mono"><?php echo $row['dn'] !== null ? View::e($row['dn']) : '<span class="status-bad">не найдено (или CN неоднозначен)</span>'; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($domains === []): ?>
<div class="alert alert-warn">Нет ни одного LDAP-подключения — добавьте в разделе <a href="index.php?section=ldap">«LDAP-подключения»</a>.</div>
<?php endif; ?>

<hr class="sep">

<p class="section-title">Права на нейросети</p>
<p class="muted llm-lead">
  Единая матрица: и группы, и отдельные пользователи выше, и индивидуальные права уже вошедших
  пользователей чата (в дополнение к правам их групп) — настраиваются в одном месте. Последняя
  колонка «Без обезличивания» — личное разрешение конкретному уже вошедшему пользователю ставить
  в чате галочку «не обезличивать» (в дополнение к групповому праву из таблиц выше); доступна
  только для строк с персональным доступом, так как групповое право задаётся отдельно.
</p>
<?php if ($providers === []): ?>
<div class="alert alert-warn">Нужна хотя бы одна нейросеть (раздел <a href="index.php?section=llm_providers">«Подключения LLM API»</a>).</div>
<?php else: ?>
<form method="post" action="index.php?section=chat_access&action=save_permissions">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="llm-table-wrap">
  <table class="data-table llm-matrix">
    <thead>
      <tr>
        <th>Домен</th>
        <th>Кому</th>
<?php foreach ($providers as $provider): ?>
        <th class="llm-matrix-col">
          <?php echo View::e((string) $provider['name']); ?>
          <span class="muted mono llm-matrix-sub"><?php echo View::e((string) $provider['model']); ?><?php echo $provider['is_enabled'] ? '' : ' · откл.'; ?></span>
        </th>
<?php endforeach; ?>
        <th class="llm-matrix-col" title="Личное разрешение отправлять сообщения без обезличивания — только для уже вошедших пользователей">Без обезличивания</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($groupsRepo->all() as $group): $gid = (int) $group['id']; $isUserSubject = ($group['subject_type'] ?? 'group') === DomainGroupRepository::SUBJECT_USER; ?>
      <tr class="<?php echo $group['is_enabled'] ? '' : 'llm-row-muted'; ?>">
        <td><?php echo View::e((string) $group['domain_name']); ?></td>
        <td>
          <?php echo $isUserSubject ? '👤 ' : '👥 '; ?><?php echo View::e((string) $group['display_name']); ?>
          <span class="muted mono llm-matrix-sub"><?php echo $isUserSubject ? 'пользователь' : View::e((string) $group['group_ref']); ?><?php echo $group['is_enabled'] ? '' : ' · отключено'; ?></span>
        </td>
<?php foreach ($providers as $provider): $pid = (int) $provider['id']; ?>
        <td class="llm-matrix-col">
          <input type="checkbox" name="perm[<?php echo $gid; ?>][]" value="<?php echo $pid; ?>" aria-label="<?php echo View::e($group['display_name'] . ' → ' . $provider['name']); ?>"
                 <?php echo in_array($pid, $matrix[$gid] ?? [], true) ? 'checked' : ''; ?>>
        </td>
<?php endforeach; ?>
        <td class="llm-matrix-col muted" title="Задаётся выше, в таблицах групп/отдельных пользователей">—</td>
      </tr>
<?php endforeach; ?>
<?php if ($userProfiles !== []): ?>
      <tr><td colspan="<?php echo 3 + count($providers); ?>" class="muted" style="padding-top:14px;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;">Индивидуальный доступ вошедших пользователей (в дополнение к группам)</td></tr>
<?php foreach ($userProfiles as $profileRow): $ownerKey = (string) $profileRow['owner_key']; $selected = $userProviderMatrix[$ownerKey] ?? []; ?>
      <tr>
        <td><?php echo View::e((string) $profileRow['domain_fqdn']); ?></td>
        <td>👤 <?php echo View::e((string) $profileRow['username']); ?><span class="muted mono llm-matrix-sub">персональный доступ</span></td>
<?php foreach ($providers as $provider): $pid = (int) $provider['id']; ?>
        <td class="llm-matrix-col">
          <input type="checkbox" name="user_perm[<?php echo View::e($ownerKey); ?>][]" value="<?php echo $pid; ?>"
                 aria-label="<?php echo View::e($profileRow['username'] . ' → ' . $provider['name']); ?>" <?php echo in_array($pid, $selected, true) ? 'checked' : ''; ?>>
        </td>
<?php endforeach; ?>
        <td class="llm-matrix-col">
          <input type="checkbox" name="unanon_perm[<?php echo View::e($ownerKey); ?>]" value="1" class="llm-unanon-check"
                 aria-label="<?php echo View::e($profileRow['username'] . ' → без обезличивания'); ?>" <?php echo !empty($profileRow['allow_unanonymized']) ? 'checked' : ''; ?>>
        </td>
<?php endforeach; ?>
<?php endif; ?>
    </tbody>
  </table>
  </div>
  <div class="llm-actions"><button class="btn btn-primary" type="submit">Сохранить права</button></div>
</form>
<?php endif; ?>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'chat_access',
    'pageTitle' => 'Доступ к чату',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
