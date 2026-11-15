<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Http\View;
use App\Llm\Chat\ChatSettings;
use App\Llm\Chat\UsageLimiter;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */

$flashSuccess = null;
$flashError = null;

$usage = $llmModule->usage();
$chatSettings = $llmModule->chatSettings();
$limitCategories = $llmModule->limitCategories();
$storageQuota = $llmModule->storageQuota();
$events = $llmModule->events();
$admin = (string) AdminSession::username();
$periodLabels = ['none' => 'Без лимита', 'day' => 'В день', 'week' => 'В неделю', 'month' => 'В месяц'];

if ($method === 'POST' && in_array($action, ['save_defaults', 'save_user_limit', 'toggle_block', 'clear_user_history'], true)) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } elseif ($action === 'save_defaults') {
        $values = [
            'limit_period' => (string) ($_POST['limit_period'] ?? 'none'),
            'limit_tokens' => (string) (int) ($_POST['limit_tokens'] ?? 0),
            'retention_days' => (string) (int) ($_POST['retention_days'] ?? 0),
            'project_sources_max_chars' => (string) (int) ($_POST['project_sources_max_chars'] ?? 0),
            'project_sources_max_files' => (string) (int) ($_POST['project_sources_max_files'] ?? 0),
            'project_instructions_max_blocks' => (string) (int) ($_POST['project_instructions_max_blocks'] ?? 0),
            'project_instruction_max_chars' => (string) (int) ($_POST['project_instruction_max_chars'] ?? 0),
            'inactivity_content_purge_days' => (string) (int) ($_POST['inactivity_content_purge_days'] ?? 0),
        ];
        $errors = ChatSettings::validate($values);
        if ($errors !== []) {
            $flashError = 'Параметры не сохранены: ' . implode(' ', $errors);
        } else {
            $chatSettings->save($values);
            $events->admin($admin, $clientIp, 'llm_limit_default_change', true, [
                'period' => $values['limit_period'], 'limit' => $values['limit_tokens'],
                'retention_days' => $values['retention_days'], 'sources_max' => $values['project_sources_max_chars'],
            ]);
            $flashSuccess = 'Общие параметры сохранены (действуют, только пока не заведена ни одна категория лимита).';
        }
    } else {
        $ownerKey = (string) ($_POST['owner_key'] ?? '');
        $exists = array_filter($usage->allProfiles(), static fn (array $r): bool => $r['owner_key'] === $ownerKey) !== [];
        if (!$exists) {
            $flashError = 'Пользователь не найден.';
        } elseif ($action === 'toggle_block') {
            $block = !$usage->isBlocked($ownerKey);
            $usage->setBlocked($ownerKey, $block);
            $events->admin($admin, $clientIp, 'llm_user_block', true, ['user' => $ownerKey, 'blocked' => $block]);
            $flashSuccess = 'Пользователь ' . $ownerKey . ($block ? ' заблокирован.' : ' разблокирован.');
        } elseif ($action === 'clear_user_history') {
            $deleted = $llmModule->conversations()->deleteAllForOwner($ownerKey);
            $events->admin($admin, $clientIp, 'llm_user_history_clear', true, ['user' => $ownerKey, 'conversations_deleted' => $deleted]);
            $flashSuccess = 'История пользователя ' . $ownerKey . ' очищена (' . $deleted . ' диалогов).';
        } else {
            $categoryChoice = (string) ($_POST['category'] ?? 'inherit');
            if ($categoryChoice === 'inherit') {
                $usage->setLimitCategory($ownerKey, null);
                $events->admin($admin, $clientIp, 'llm_user_limit_change', true, ['user' => $ownerKey, 'category' => 'как у группы/по умолчанию']);
            } elseif ($categoryChoice === 'manual') {
                $period = (string) ($_POST['period'] ?? UsageLimiter::PERIOD_NONE);
                $tokens = max(0, (int) ($_POST['tokens'] ?? 0));
                if (!in_array($period, UsageLimiter::PERIODS, true)) {
                    $flashError = 'Недопустимый период.';
                } else {
                    $usage->setIndividualLimit($ownerKey, $period, $tokens);
                    $events->admin($admin, $clientIp, 'llm_user_limit_change', true, [
                        'user' => $ownerKey, 'period' => $period, 'limit' => $period === UsageLimiter::PERIOD_NONE ? '—' : $tokens,
                    ]);
                }
            } else {
                $categoryId = (int) $categoryChoice;
                if ($limitCategories->find($categoryId) === null) {
                    $flashError = 'Категория не найдена.';
                } else {
                    $usage->setLimitCategory($ownerKey, $categoryId);
                    $events->admin($admin, $clientIp, 'llm_user_limit_change', true, ['user' => $ownerKey, 'category_id' => $categoryId]);
                }
            }
            if ($flashError === null) {
                $flashSuccess = 'Лимит пользователя ' . $ownerKey . ' сохранён.';
            }
        }
    }
}

$profiles = $usage->allProfiles();
$categoriesAll = $limitCategories->all();
$fmt = static fn (?int $n): string => $n === null ? '—' : number_format($n, 0, ',', ' ');

ob_start();
?>
<p class="muted llm-lead">
  Профиль создаётся автоматически при первом входе пользователя в чат. Лимит считается в токенах, которые вернул
  провайдер (вся переданная история, контекст проекта и ответ), либо по оценке «символы / 3». Расход хранится
  одной строкой на пользователя в день, длинные сообщения и источники — в сжатом виде.
</p>

<p class="section-title">Хранение</p>
<form method="post" action="index.php?section=llm_users&action=save_defaults">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <input type="hidden" name="limit_period" value="<?php echo View::e($chatSettings->get('limit_period')); ?>">
  <input type="hidden" name="limit_tokens" value="<?php echo View::e($chatSettings->get('limit_tokens')); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="retention_days">Хранить неиспользуемые чаты, дней (0 — бессрочно)<?php echo View::settingCode('CHAT_RETENTION_DAYS'); ?></label>
      <input class="text-input" type="number" min="0" id="retention_days" name="retention_days" value="<?php echo View::e($chatSettings->get('retention_days')); ?>">
    </div>
    <div class="field">
      <label class="field-label" for="project_sources_max_chars">Объём источников проекта, символов<?php echo View::settingCode('PROJECT_SOURCES_MAX_CHARS'); ?></label>
      <input class="text-input" type="number" min="1000" id="project_sources_max_chars" name="project_sources_max_chars" value="<?php echo View::e($chatSettings->get('project_sources_max_chars')); ?>">
      <p class="muted llm-note">Весь этот текст добавляется целиком в каждый запрос к модели — слишком большое значение может переполнить контекст компактных моделей.</p>
    </div>
    <div class="field">
      <label class="field-label" for="project_sources_max_files">Макс. источников в проекте<?php echo View::settingCode('PROJECT_SOURCES_MAX_FILES'); ?></label>
      <input class="text-input" type="number" min="1" max="50" id="project_sources_max_files" name="project_sources_max_files" value="<?php echo View::e($chatSettings->get('project_sources_max_files')); ?>">
    </div>
    <div class="field">
      <label class="field-label" for="project_instructions_max_blocks">Макс. блоков инструкций в проекте<?php echo View::settingCode('PROJECT_INSTRUCTIONS_MAX_BLOCKS'); ?></label>
      <input class="text-input" type="number" min="1" max="30" id="project_instructions_max_blocks" name="project_instructions_max_blocks" value="<?php echo View::e($chatSettings->get('project_instructions_max_blocks')); ?>">
    </div>
    <div class="field">
      <label class="field-label" for="project_instruction_max_chars">Макс. длина одного блока инструкций, символов<?php echo View::settingCode('PROJECT_INSTRUCTION_MAX_CHARS'); ?></label>
      <input class="text-input" type="number" min="100" max="20000" id="project_instruction_max_chars" name="project_instruction_max_chars" value="<?php echo View::e($chatSettings->get('project_instruction_max_chars')); ?>">
    </div>
    <div class="field">
      <label class="field-label" for="inactivity_content_purge_days">Зачищать содержимое чатов при неактивности, дней (0 — никогда)<?php echo View::settingCode('CHAT_INACTIVITY_PURGE_DAYS'); ?></label>
      <input class="text-input" type="number" min="0" id="inactivity_content_purge_days" name="inactivity_content_purge_days" value="<?php echo View::e($chatSettings->get('inactivity_content_purge_days')); ?>">
    </div>
  </div>
  <p class="muted llm-note">
    Закреплённые чаты сроком хранения не удаляются. Зачистка при неактивности удаляет только текст сообщений
    и источников проектов (для экономии места) — заголовки чатов и структура истории остаются видны пользователю.
  </p>
  <div class="llm-actions"><button class="btn btn-primary" type="submit">Сохранить</button></div>
</form>

<details style="margin-top:14px;">
  <summary class="section-title" style="cursor:pointer;display:inline-block;">Лимит по умолчанию — устаревший способ (используется, только пока нет ни одной категории лимита)</summary>
  <form method="post" action="index.php?section=llm_users&action=save_defaults" style="margin-top:10px;">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <input type="hidden" name="retention_days" value="<?php echo View::e($chatSettings->get('retention_days')); ?>">
    <input type="hidden" name="project_sources_max_chars" value="<?php echo View::e($chatSettings->get('project_sources_max_chars')); ?>">
    <input type="hidden" name="project_sources_max_files" value="<?php echo View::e($chatSettings->get('project_sources_max_files')); ?>">
    <input type="hidden" name="project_instructions_max_blocks" value="<?php echo View::e($chatSettings->get('project_instructions_max_blocks')); ?>">
    <input type="hidden" name="project_instruction_max_chars" value="<?php echo View::e($chatSettings->get('project_instruction_max_chars')); ?>">
    <input type="hidden" name="inactivity_content_purge_days" value="<?php echo View::e($chatSettings->get('inactivity_content_purge_days')); ?>">
    <div class="form-row">
      <div class="field">
        <label class="field-label" for="limit_period">Период</label>
        <select class="text-input" id="limit_period" name="limit_period">
<?php foreach ($periodLabels as $value => $label): ?>
          <option value="<?php echo $value; ?>" <?php echo $chatSettings->get('limit_period') === $value ? 'selected' : ''; ?>><?php echo View::e($label); ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field-label" for="limit_tokens">Токенов на период</label>
        <input class="text-input" type="number" min="0" id="limit_tokens" name="limit_tokens" value="<?php echo View::e($chatSettings->get('limit_tokens')); ?>">
      </div>
    </div>
    <p class="muted llm-note">
      Категории лимита (раздел <a href="index.php?section=llm_limits">«Категории лимитов»</a>) — рекомендуемый способ
      управления лимитами; эти поля применяются только для пользователей без персональной/групповой категории, если
      категорий вообще не заведено.
    </p>
    <div class="llm-actions"><button class="btn btn-primary" type="submit">Сохранить</button></div>
  </form>
</details>

<hr class="sep">

<p class="section-title">Пользователи (<?php echo count($profiles); ?>)</p>
<?php if ($profiles === []): ?>
<p class="muted">Пользователи ещё не входили в чат.</p>
<?php else: ?>
<div class="llm-table-wrap">
<table class="data-table">
  <thead><tr><th>Пользователь</th><th>Последний вход</th><th>Израсходовано / лимит</th><th>Хранилище</th><th>Запросов за 30 дн.</th><th>Лимит</th><th>Доступ</th></tr></thead>
  <tbody>
<?php foreach ($profiles as $row):
    $status = $row['status'];
    $over = $status['limit'] !== null && $status['used'] >= $status['limit'];
    $storageStatus = $storageQuota->status((string) $row['owner_key']);
    $storageOver = $storageStatus['limit_bytes'] !== null && $storageStatus['used_bytes'] >= $storageStatus['limit_bytes'];
    $formId = 'limit-' . md5((string) $row['owner_key']);
    $isManual = $row['limit_period'] !== null;
    $currentCategoryId = $row['limit_category_id'] !== null ? (int) $row['limit_category_id'] : null;
    $selectValue = $isManual ? 'manual' : ($currentCategoryId !== null ? (string) $currentCategoryId : 'inherit');
?>
    <tr class="<?php echo $row['is_blocked'] ? 'llm-row-muted' : ''; ?>">
      <td><strong><?php echo View::e((string) $row['username']); ?></strong><span class="muted mono llm-matrix-sub"><?php echo View::e((string) $row['domain_fqdn']); ?></span></td>
      <td class="mono"><?php echo View::e(str_replace('T', ' ', substr((string) $row['last_seen_at'], 0, 16))); ?></td>
      <td class="<?php echo $over ? 'status-bad' : ''; ?>">
        <?php echo $fmt($status['used']); ?> / <?php echo $status['limit'] === null ? 'без лимита' : $fmt($status['limit']); ?>
        <span class="muted llm-matrix-sub"><?php echo View::e($periodLabels[$status['period']] ?? $status['period']); ?> · <?php echo View::e($status['source']); ?></span>
      </td>
      <td class="<?php echo $storageOver ? 'status-bad' : ''; ?>">
        <?php echo number_format($storageStatus['used_bytes'] / 1024 / 1024, 1, ',', ' '); ?> МБ
        <?php echo $storageStatus['limit_bytes'] === null ? '' : ' / ' . number_format($storageStatus['limit_bytes'] / 1024 / 1024, 0, ',', ' ') . ' МБ'; ?>
      </td>
      <td><?php echo (int) $row['requests_30d']; ?></td>
      <td>
        <form id="<?php echo $formId; ?>" method="post" action="index.php?section=llm_users&action=save_user_limit" class="llm-inline-form" data-limit-form>
          <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
          <input type="hidden" name="owner_key" value="<?php echo View::e((string) $row['owner_key']); ?>">
          <select class="text-input" name="category" aria-label="Лимит" data-limit-category>
            <option value="inherit" <?php echo $selectValue === 'inherit' ? 'selected' : ''; ?>>Как у группы / по умолчанию</option>
<?php foreach ($categoriesAll as $cat): ?>
            <option value="<?php echo $cat['id']; ?>" <?php echo $selectValue === (string) $cat['id'] ? 'selected' : ''; ?>><?php echo View::e($cat['name']); ?></option>
<?php endforeach; ?>
            <option value="manual" <?php echo $selectValue === 'manual' ? 'selected' : ''; ?>>Вручную…</option>
          </select>
          <span data-limit-manual-fields<?php echo $selectValue === 'manual' ? '' : ' hidden'; ?>>
            <select class="text-input" name="period" aria-label="Период">
<?php foreach ($periodLabels as $value => $label): ?>
              <option value="<?php echo $value; ?>" <?php echo $row['limit_period'] === $value ? 'selected' : ''; ?>><?php echo View::e($label); ?></option>
<?php endforeach; ?>
            </select>
            <input class="text-input" type="number" min="0" name="tokens" aria-label="Токенов" placeholder="токенов" value="<?php echo $row['limit_tokens'] !== null ? (int) $row['limit_tokens'] : ''; ?>">
          </span>
          <button class="btn btn-small" type="submit">Сохранить</button>
        </form>
      </td>
      <td>
        <form method="post" action="index.php?section=llm_users&action=toggle_block">
          <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
          <input type="hidden" name="owner_key" value="<?php echo View::e((string) $row['owner_key']); ?>">
          <button class="btn btn-small<?php echo $row['is_blocked'] ? '' : ' btn-danger'; ?>" type="submit"><?php echo $row['is_blocked'] ? 'Разблокировать' : 'Заблокировать'; ?></button>
        </form>
        <form method="post" action="index.php?section=llm_users&action=clear_user_history"
              data-confirm-title="Очистить всю историю пользователя?"
              data-confirm-text="Все диалоги пользователя «<?php echo View::e((string) $row['username']); ?>» будут удалены безвозвратно.">
          <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
          <input type="hidden" name="owner_key" value="<?php echo View::e((string) $row['owner_key']); ?>">
          <button class="btn btn-small btn-danger" type="submit">Очистить историю</button>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'llm_users',
    'pageTitle' => 'Пользователи чата',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
