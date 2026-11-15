<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Http\View;
use App\Llm\Chat\UsageLimiter;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */

$flashSuccess = null;
$flashError = null;
$fieldErrors = [];
$formValues = null;
$formCategoryId = null; // 0 — новая категория

$categories = $llmModule->limitCategories();
$events = $llmModule->events();
$admin = (string) AdminSession::username();
$periodLabels = ['day' => 'В день', 'week' => 'В неделю', 'month' => 'В месяц', UsageLimiter::PERIOD_NONE => 'Без лимита'];

/** @return array{name:string,period:string,tokens:int,storage_mb:int,unlimited_storage:bool,is_default:bool} */
function llmLimitCategoryForm(array $post): array
{
    return [
        'name' => trim((string) ($post['name'] ?? '')),
        'period' => (string) ($post['period'] ?? 'day'),
        'tokens' => max(0, (int) ($post['tokens'] ?? 0)),
        'storage_mb' => max(0, (int) ($post['storage_mb'] ?? 0)),
        'unlimited_storage' => !empty($post['unlimited_storage']),
        'is_default' => !empty($post['is_default']),
    ];
}

/** @return array<string,string> */
function llmLimitCategoryValidate(array $data): array
{
    $errors = [];
    if ($data['name'] === '' || mb_strlen($data['name']) > 80 || preg_match('/[\x00-\x1F]/', $data['name']) === 1) {
        $errors['name'] = 'Укажите название (до 80 символов).';
    }
    if (!in_array($data['period'], UsageLimiter::PERIODS, true)) {
        $errors['period'] = 'Недопустимый период.';
    }
    if ($data['period'] !== UsageLimiter::PERIOD_NONE && $data['tokens'] <= 0) {
        $errors['tokens'] = 'Укажите положительное число токенов.';
    }
    if (!$data['unlimited_storage'] && $data['storage_mb'] <= 0) {
        $errors['storage_mb'] = 'Укажите положительный объём (МБ) либо отметьте «без ограничения».';
    }

    return $errors;
}

$categoryActions = ['create_category', 'update_category', 'delete_category'];

if ($method === 'POST' && in_array($action, $categoryActions, true)) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        $postedId = (int) ($_POST['id'] ?? 0);
        switch ($action) {
            case 'create_category':
            case 'update_category':
                $isCreate = $action === 'create_category';
                $data = llmLimitCategoryForm($_POST);
                $fieldErrors = llmLimitCategoryValidate($data);
                if (!isset($fieldErrors['name']) && $categories->nameExists($data['name'], $isCreate ? null : $postedId)) {
                    $fieldErrors['name'] = 'Категория с таким названием уже есть.';
                }
                $tokens = $data['period'] === UsageLimiter::PERIOD_NONE ? null : $data['tokens'];
                $storageMb = $data['unlimited_storage'] ? null : $data['storage_mb'];

                if ($fieldErrors !== []) {
                    $flashError = 'Проверьте выделенные поля формы.';
                    $formValues = $data;
                    $formCategoryId = $isCreate ? 0 : $postedId;
                } elseif ($isCreate) {
                    $id = $categories->create($data['name'], $data['period'], $tokens, $storageMb, $data['is_default']);
                    $events->admin($admin, $clientIp, 'llm_limit_category_create', true, [
                        'id' => $id, 'name' => $data['name'], 'period' => $data['period'], 'tokens' => $tokens ?? '—',
                        'storage_mb' => $storageMb ?? 'без ограничения', 'is_default' => $data['is_default'],
                    ]);
                    $flashSuccess = 'Категория «' . $data['name'] . '» добавлена.';
                } else {
                    $categories->update($postedId, $data['name'], $data['period'], $tokens, $storageMb, $data['is_default']);
                    $events->admin($admin, $clientIp, 'llm_limit_category_update', true, [
                        'id' => $postedId, 'name' => $data['name'], 'period' => $data['period'], 'tokens' => $tokens ?? '—',
                        'storage_mb' => $storageMb ?? 'без ограничения', 'is_default' => $data['is_default'],
                    ]);
                    $flashSuccess = 'Категория «' . $data['name'] . '» сохранена.';
                }
                break;

            case 'delete_category':
                $category = $categories->find($postedId);
                if ($category === null) {
                    $flashError = 'Категория не найдена.';
                } elseif (!$categories->delete($postedId)) {
                    $flashError = 'Нельзя удалить категорию по умолчанию — сначала назначьте по умолчанию другую категорию.';
                } else {
                    $events->admin($admin, $clientIp, 'llm_limit_category_delete', true, ['id' => $postedId, 'name' => $category['name']]);
                    $flashSuccess = 'Категория «' . $category['name'] . '» удалена. Пользователи и группы, использовавшие её, вернулись к категории по умолчанию.';
                }
                break;
        }
    }
}

$rows = $categories->all();

$val = static function (int $cardId, string $field, string $default = '') use ($formValues, $formCategoryId, $rows): string {
    if ($formValues !== null && $formCategoryId === $cardId && array_key_exists($field, $formValues)) {
        return is_bool($formValues[$field]) ? ($formValues[$field] ? '1' : '') : (string) $formValues[$field];
    }
    if ($field === 'unlimited_storage') {
        foreach ($rows as $row) {
            if ($row['id'] === $cardId) {
                return $row['storage_mb'] === null ? '1' : '';
            }
        }

        return $default;
    }
    foreach ($rows as $row) {
        if ($row['id'] === $cardId) {
            return is_bool($row[$field] ?? null) ? ($row[$field] ? '1' : '') : (string) ($row[$field] ?? $default);
        }
    }

    return $default;
};

$renderCategoryFields = static function (int $cardId) use ($val, $fieldErrors, $formCategoryId, $periodLabels): string {
    $errors = $formCategoryId === $cardId ? $fieldErrors : [];
    $err = static fn (string $key): string => isset($errors[$key]) ? '<p class="field-error">' . View::e($errors[$key]) . '</p>' : '';
    $cls = static fn (string $key): string => isset($errors[$key]) ? ' has-error' : '';
    $sfx = $cardId === 0 ? 'new' : (string) $cardId;
    $period = $val($cardId, 'period', 'day');

    ob_start();
    ?>
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="name-<?php echo $sfx; ?>">Название</label>
      <input class="text-input<?php echo $cls('name'); ?>" id="name-<?php echo $sfx; ?>" name="name" required maxlength="80" value="<?php echo View::e($val($cardId, 'name')); ?>" placeholder="Базовый">
      <?php echo $err('name'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="period-<?php echo $sfx; ?>">Период</label>
      <select class="text-input<?php echo $cls('period'); ?>" id="period-<?php echo $sfx; ?>" name="period">
<?php foreach ($periodLabels as $value => $label): ?>
        <option value="<?php echo $value; ?>" <?php echo $period === $value ? 'selected' : ''; ?>><?php echo View::e($label); ?></option>
<?php endforeach; ?>
      </select>
      <?php echo $err('period'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="tokens-<?php echo $sfx; ?>">Токенов за период</label>
      <input class="text-input<?php echo $cls('tokens'); ?>" type="number" min="0" id="tokens-<?php echo $sfx; ?>" name="tokens" value="<?php echo View::e($val($cardId, 'tokens', '50000')); ?>">
      <?php echo $err('tokens'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="storage_mb-<?php echo $sfx; ?>">Квота хранения (МБ)</label>
      <input class="text-input<?php echo $cls('storage_mb'); ?>" type="number" min="0" id="storage_mb-<?php echo $sfx; ?>" name="storage_mb" data-storage-input
             value="<?php echo View::e($val($cardId, 'storage_mb', '50')); ?>" <?php echo $val($cardId, 'unlimited_storage') === '1' ? 'disabled' : ''; ?>>
      <label class="llm-inline-check"><input type="checkbox" name="unlimited_storage" value="1" data-storage-unlimited <?php echo $val($cardId, 'unlimited_storage') === '1' ? 'checked' : ''; ?>> Без ограничения</label>
      <?php echo $err('storage_mb'); ?>
    </div>
  </div>
  <p class="muted llm-note">
    Квота хранения считается по обезличенному тексту в базе (сообщения всех чатов + источники всех проектов
    пользователя), а не по загруженным файлам — исходные файлы на сервере не хранятся, они обрабатываются во
    временном каталоге и удаляются сразу после обезличивания.
  </p>
  <label class="llm-inline-check"><input type="checkbox" name="is_default" value="1" <?php echo $val($cardId, 'is_default') === '1' ? 'checked' : ''; ?>> Категория по умолчанию для новых пользователей чата</label>
<?php
    return (string) ob_get_clean();
};

ob_start();
?>
<p class="muted llm-lead">
  Именованные категории лимита токенов и хранения («Базовый», «Расширенный»...) — назначаются пользователю
  чата индивидуально (раздел «Пользователи чата») или доменной группе (раздел «Доступ к чату»). Пользователь
  без персональной категории получает самую щедрую категорию среди своих групп; если ни группа, ни он сам
  не имеют категории — применяется категория по умолчанию. Расход токенов не переносится на следующий период.
</p>

<div class="llm-toolbar">
  <p class="section-title">Категории (<?php echo count($rows); ?>)</p>
  <button type="button" class="btn btn-primary llm-plus" data-reveal="newCategoryCard"<?php echo $formCategoryId === 0 ? ' hidden' : ''; ?>>+ Добавить категорию</button>
</div>

<div class="llm-card" id="newCategoryCard"<?php echo $formCategoryId === 0 ? '' : ' hidden'; ?>>
  <div class="llm-card-head"><h2>Новая категория</h2></div>
  <form method="post" action="index.php?section=llm_limits&action=create_category">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <?php echo $renderCategoryFields(0); ?>
    <div class="llm-actions"><button class="btn btn-primary" type="submit">Добавить</button></div>
  </form>
</div>

<?php if ($rows === []): ?>
<p class="muted">Категорий пока нет — нажмите «+ Добавить категорию». Пока их нет, действуют общие параметры лимита из раздела «Пользователи чата».</p>
<?php endif; ?>

<?php foreach ($rows as $row): $cid = $row['id']; ?>
<div class="llm-card" id="category-<?php echo $cid; ?>">
  <div class="llm-card-head">
    <h2><?php echo View::e($row['name']); ?></h2>
    <?php if ($row['is_default']): ?><span class="badge badge-active">По умолчанию</span><?php endif; ?>
  </div>
  <form method="post" action="index.php?section=llm_limits&action=update_category#category-<?php echo $cid; ?>">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <input type="hidden" name="id" value="<?php echo $cid; ?>">
    <?php echo $renderCategoryFields($cid); ?>
    <div class="llm-actions">
      <button class="btn btn-primary" type="submit">Сохранить</button>
<?php if ($row['is_default']): ?>
      <span class="muted llm-note" title="Сначала сделайте по умолчанию другую категорию">Категория по умолчанию — удаление недоступно</span>
<?php else: ?>
      <button class="btn btn-danger" type="submit" form="deleteCategory<?php echo $cid; ?>">Удалить</button>
<?php endif; ?>
    </div>
  </form>
<?php if (!$row['is_default']): ?>
  <form id="deleteCategory<?php echo $cid; ?>" method="post" action="index.php?section=llm_limits&action=delete_category"
        data-confirm-title="Удалить категорию?"
        data-confirm-text="Пользователи и группы, использующие категорию «<?php echo View::e($row['name']); ?>», вернутся к категории по умолчанию.">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo $cid; ?>">
  </form>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'llm_limits',
    'pageTitle' => 'Категории лимитов',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
