<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Http\View;
use App\Llm\Provider\ModelRepository;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */

$flashSuccess = null;
$flashError = null;
$fieldErrors = [];
$formValues = null;
$checkResults = [];  // model_id => результат проверки

$models = $llmModule->models();
$health = $llmModule->modelHealth();
$events = $llmModule->events();
$chatSettings = $llmModule->chatSettings();
$admin = (string) AdminSession::username();
$postedId = (int) ($_POST['id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);

$actions = ['save_model', 'delete_model', 'check_model', 'generate_model', 'reset_cooldown', 'save_fallback_settings'];
if ($method === 'POST' && in_array($action, $actions, true)) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        switch ($action) {
            case 'save_model':
                $data = [
                    'provider_id' => (int) ($_POST['provider_id'] ?? 0),
                    'model' => trim((string) ($_POST['model'] ?? '')),
                    'display_name' => trim((string) ($_POST['display_name'] ?? '')),
                    'context_window' => (int) ($_POST['context_window'] ?? 0),
                    'max_output_tokens' => (int) ($_POST['max_output_tokens'] ?? 0),
                    'temperature' => trim((string) ($_POST['temperature'] ?? '')),
                    'priority' => (int) ($_POST['priority'] ?? 100),
                    'supports_streaming' => !empty($_POST['supports_streaming']),
                    'supports_json' => !empty($_POST['supports_json']),
                    'is_enabled' => !empty($_POST['is_enabled']),
                    'fallback_ids' => array_values(array_filter(array_map('intval', (array) ($_POST['fallback_ids'] ?? [])), static fn (int $x): bool => $x > 0 && $x !== $postedId)),
                    'price_in' => trim((string) ($_POST['price_in'] ?? '')),
                    'price_out' => trim((string) ($_POST['price_out'] ?? '')),
                ];
                $fieldErrors = ModelRepository::validate($data);
                if ($postedId === 0 && $llmModule->providers()->find($data['provider_id']) === null) {
                    $fieldErrors['provider_id'] = 'Выберите подключение.';
                }
                if ($fieldErrors !== []) {
                    $flashError = 'Проверьте выделенные поля.';
                    $formValues = $data + ['id' => $postedId];
                    $editId = $postedId;
                    break;
                }
                try {
                    if ($postedId > 0) {
                        $models->update($postedId, $data);
                    } else {
                        $postedId = $models->create($data);
                    }
                } catch (\PDOException) {
                    $flashError = 'У этого подключения уже есть модель с таким идентификатором.';
                    $formValues = $data + ['id' => $postedId];
                    break;
                }
                $events->admin($admin, $clientIp, 'llm_model_save', true, ['model_id' => $postedId, 'model' => $data['model'], 'fallbacks' => count($data['fallback_ids'])]);
                $flashSuccess = 'Модель сохранена.';
                $editId = 0;
                break;
            case 'delete_model':
                $ok = $models->delete($postedId);
                $events->admin($admin, $clientIp, 'llm_model_delete', $ok, ['model_id' => $postedId]);
                $ok ? $flashSuccess = 'Модель удалена.' : $flashError = 'Модель по умолчанию удаляется вместе с подключением (раздел «Подключения LLM API»).';
                break;
            case 'check_model':
            case 'generate_model':
                $checkResults[$postedId] = $llmModule->gateway()->testModel($postedId, $action === 'check_model' ? 'ping' : 'generate');
                $events->admin($admin, $clientIp, 'llm_model_check', $checkResults[$postedId]['ok'], ['model_id' => $postedId, 'mode' => $action, 'duration_ms' => $checkResults[$postedId]['duration_ms']]);
                break;
            case 'reset_cooldown':
                $health->resetCooldown($postedId);
                $events->admin($admin, $clientIp, 'llm_model_reset_cooldown', true, ['model_id' => $postedId]);
                $flashSuccess = 'Пауза снята — модель снова первая в своей цепочке.';
                break;
            case 'save_fallback_settings':
                $chatSettings->save(['fallback_auto' => !empty($_POST['fallback_auto']) ? '1' : '0']);
                $events->admin($admin, $clientIp, 'llm_fallback_settings', true, ['fallback_auto' => !empty($_POST['fallback_auto']) ? 1 : 0]);
                $flashSuccess = 'Настройки резервирования сохранены.';
                break;
        }
    }
}

$all = $models->all();
$byId = [];
foreach ($all as $m) {
    $byId[$m['id']] = $m;
}
$stats = $health->stats();
$incidents = $health->incidents(50);
$providersList = $llmModule->providers()->all();
$editing = $formValues ?? ($editId > 0 ? ($byId[$editId] ?? null) : null);
if ($editing !== null && isset($editing['price_in_per_mtoken'])) {
    $editing['price_in'] = $editing['price_in_per_mtoken'];
    $editing['price_out'] = $editing['price_out_per_mtoken'];
}
$f = static fn (string $k, mixed $default = ''): string => View::e((string) ($editing[$k] ?? $default));
$err = static fn (string $k): string => isset($fieldErrors[$k]) ? '<p class="field-error">' . View::e($fieldErrors[$k]) . '</p>' : '';
$num = static fn (int|float $v): string => number_format((float) $v, 0, ',', ' ');
$when = static fn (?string $iso): string => $iso ? View::e(str_replace('T', ' ', substr($iso, 0, 16))) : '—';

ob_start();
?>
<p class="muted llm-lead">
  Провайдер — подключение (адрес, тип API, ключ); модель — то, что выбирает сотрудник. У провайдера может быть несколько моделей.
  Если модель не ответила (перегрузка, HTTP 429/5xx, таймаут, обрыв, пустой ответ при HTTP 200, ответ не по формату), запрос автоматически
  уходит следующей модели цепочки — только той, к которой у сотрудника есть доступ. Повторы ограничены, Retry-After учитывается,
  проблемная модель ставится на паузу. Сотрудник видит понятное сообщение, здесь — техническая причина.
</p>

<form method="post" action="index.php?section=llm_models&action=save_fallback_settings" class="llm-switch-row">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <label class="switch"><input type="checkbox" name="fallback_auto" value="1" <?php echo $chatSettings->get('fallback_auto') === '1' ? 'checked' : ''; ?> data-autosubmit><span class="track"></span>
    После резервных моделей пробовать остальные доступные сотруднику модели по приоритету</label>
</form>

<div class="llm-table-wrap">
<table class="data-table models-table">
  <thead><tr><th>Модель</th><th>Состояние</th><th>Запросы</th><th>Время</th><th>Токены</th><th>Резерв</th><th class="models-actions-col">Действия</th></tr></thead>
  <tbody>
<?php if ($all === []): ?>
    <tr><td colspan="7" class="muted">Моделей нет — добавьте подключение в разделе «Подключения LLM API».</td></tr>
<?php endif; ?>
<?php foreach ($all as $m): $h = $stats[$m['id']] ?? []; $cool = (int) ($h['cooldown_until'] ?? 0) > time(); $ok = (int) ($h['success_count'] ?? 0); $errs = (int) ($h['error_count'] ?? 0);
    $state = !$m['is_enabled'] || !$m['provider_enabled'] ? ['Выключена', 'status-warn'] : ($cool ? ['Пауза до ' . gmdate('H:i', (int) $h['cooldown_until']) . ' UTC', 'status-bad']
        : (($h['last_error_at'] ?? '') > ($h['last_success_at'] ?? '') ? ['Последний запрос с ошибкой', 'status-warn'] : ($ok > 0 ? ['Доступна', 'status-ok'] : ['Не проверялась', 'status-warn']))); ?>
    <tr>
      <td><strong><?php echo View::e($m['display_name']); ?></strong><?php echo $m['is_default'] ? ' <span class="badge">по умолчанию</span>' : ''; ?>
        <div class="muted mono"><?php echo View::e($m['provider_name']); ?> · <?php echo View::e($m['model']); ?></div>
        <div class="muted">окно <?php echo $num($m['context_window']); ?> · ответ до <?php echo $num($m['max_output_tokens']); ?> · приоритет <?php echo (int) $m['priority']; ?></div></td>
      <td><span class="<?php echo $state[1]; ?>"><?php echo View::e($state[0]); ?></span>
        <div class="muted">успех: <?php echo $when($h['last_success_at'] ?? null); ?></div>
        <?php if (!empty($h['last_error_at'])): ?><div class="muted">ошибка: <?php echo $when($h['last_error_at']); ?> · <?php echo View::e((string) $h['last_error_message']); ?></div><?php endif; ?></td>
      <td><?php echo $num($ok); ?> успешно<div class="muted"><?php echo $num($errs); ?> ошибок · <?php echo $num((int) ($h['empty_count'] ?? 0)); ?> пустых · <?php echo $num((int) ($h['fallback_count'] ?? 0)); ?> переключений</div></td>
      <td><?php echo $ok > 0 ? $num((int) $h['total_ms'] / max(1, $ok)) . ' мс' : '—'; ?><div class="muted">последний <?php echo isset($h['last_ms']) ? $num((int) $h['last_ms']) . ' мс' : '—'; ?></div></td>
      <td><?php echo $num((int) ($h['tokens_in'] ?? 0)); ?> / <?php echo $num((int) ($h['tokens_out'] ?? 0)); ?><?php if ((float) ($h['cost'] ?? 0) > 0): ?><div class="muted"><?php echo View::e(number_format((float) $h['cost'], 2, ',', ' ')); ?></div><?php endif; ?></td>
      <td><?php echo $m['fallback_ids'] === [] ? '<span class="muted">—</span>' : View::e(implode(' → ', array_map(static fn (int $id): string => $byId[$id]['display_name'] ?? '#' . $id, $m['fallback_ids']))); ?></td>
      <td class="models-actions">
        <form method="post" action="index.php?section=llm_models&action=check_model"><input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>"><button class="btn btn-small" type="submit">Проверить</button></form>
        <form method="post" action="index.php?section=llm_models&action=generate_model"><input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>"><button class="btn btn-small" type="submit">Тест генерации</button></form>
        <a class="btn btn-small btn-ghost" href="index.php?section=llm_models&edit=<?php echo (int) $m['id']; ?>#model-form">Изменить</a>
        <?php if ($cool): ?><form method="post" action="index.php?section=llm_models&action=reset_cooldown"><input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>"><button class="btn btn-small btn-ghost" type="submit">Снять паузу</button></form><?php endif; ?>
      </td>
    </tr>
<?php if (isset($checkResults[$m['id']])): $r = $checkResults[$m['id']]; ?>
    <tr><td colspan="7"><div class="alert <?php echo $r['ok'] ? 'alert-success' : 'alert-error'; ?>"><?php echo View::e($r['message']); ?> (<?php echo (int) $r['duration_ms']; ?> мс)<?php if ($r['preview'] !== null): ?><div class="muted"><?php echo View::e($r['preview']); ?></div><?php endif; ?></div></td></tr>
<?php endif; ?>
<?php endforeach; ?>
  </tbody>
</table>
</div>

<hr class="sep">
<p class="section-title" id="model-form"><?php echo $editing !== null && (int) ($editing['id'] ?? 0) > 0 ? 'Изменить модель' : 'Добавить модель'; ?></p>
<form method="post" action="index.php?section=llm_models&action=save_model#model-form">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <input type="hidden" name="id" value="<?php echo (int) ($editing['id'] ?? 0); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="provider_id">Подключение</label>
      <select class="text-input" id="provider_id" name="provider_id" <?php echo (int) ($editing['id'] ?? 0) > 0 ? 'disabled' : ''; ?>>
<?php foreach ($providersList as $p): ?>
        <option value="<?php echo (int) $p['id']; ?>" <?php echo (int) ($editing['provider_id'] ?? 0) === (int) $p['id'] ? 'selected' : ''; ?>><?php echo View::e((string) $p['name']); ?></option>
<?php endforeach; ?>
      </select>
      <?php echo $err('provider_id'); ?>
    </div>
    <div class="field"><label class="field-label" for="m_model">Идентификатор у провайдера</label>
      <input class="text-input mono" id="m_model" name="model" required value="<?php echo $f('model'); ?>" placeholder="openai/gpt-4o-mini"><?php echo $err('model'); ?></div>
    <div class="field"><label class="field-label" for="m_name">Название для сотрудников</label>
      <input class="text-input" id="m_name" name="display_name" required value="<?php echo $f('display_name'); ?>" placeholder="Быстрая"><?php echo $err('display_name'); ?></div>
  </div>
  <div class="form-row">
    <div class="field"><label class="field-label" for="m_ctx">Контекстное окно, токенов</label>
      <input class="text-input" type="number" id="m_ctx" name="context_window" value="<?php echo $f('context_window', 32000); ?>"><?php echo $err('context_window'); ?></div>
    <div class="field"><label class="field-label" for="m_out">Макс. длина ответа, токенов</label>
      <input class="text-input" type="number" id="m_out" name="max_output_tokens" value="<?php echo $f('max_output_tokens', 2048); ?>"><?php echo $err('max_output_tokens'); ?></div>
    <div class="field"><label class="field-label" for="m_temp">Температура (пусто — по умолчанию)</label>
      <input class="text-input" id="m_temp" name="temperature" inputmode="decimal" value="<?php echo $f('temperature'); ?>" placeholder="0.3"><?php echo $err('temperature'); ?></div>
    <div class="field"><label class="field-label" for="m_prio">Приоритет (меньше — раньше)</label>
      <input class="text-input" type="number" id="m_prio" name="priority" value="<?php echo $f('priority', 100); ?>"><?php echo $err('priority'); ?></div>
  </div>
  <div class="form-row">
    <div class="field"><label class="field-label" for="m_pin">Цена входа за 1 млн токенов</label>
      <input class="text-input" id="m_pin" name="price_in" inputmode="decimal" value="<?php echo $f('price_in'); ?>"><?php echo $err('price_in'); ?></div>
    <div class="field"><label class="field-label" for="m_pout">Цена ответа за 1 млн токенов</label>
      <input class="text-input" id="m_pout" name="price_out" inputmode="decimal" value="<?php echo $f('price_out'); ?>"><?php echo $err('price_out'); ?></div>
    <div class="field"><label class="field-label" for="m_fb">Резервные модели — по порядку (Ctrl+клик)</label>
      <select class="text-input models-fallback" id="m_fb" name="fallback_ids[]" multiple size="4">
<?php foreach ($all as $m): if ((int) ($editing['id'] ?? 0) === $m['id']) { continue; } ?>
        <option value="<?php echo (int) $m['id']; ?>" <?php echo in_array($m['id'], (array) ($editing['fallback_ids'] ?? []), true) ? 'selected' : ''; ?>><?php echo View::e($m['display_name'] . ' · ' . $m['provider_name']); ?></option>
<?php endforeach; ?>
      </select></div>
  </div>
  <div class="llm-switch-row">
    <label class="switch"><input type="checkbox" name="is_enabled" value="1" <?php echo ($editing['is_enabled'] ?? true) ? 'checked' : ''; ?>><span class="track"></span> Доступна сотрудникам</label>
    <label class="switch"><input type="checkbox" name="supports_streaming" value="1" <?php echo ($editing['supports_streaming'] ?? true) ? 'checked' : ''; ?>><span class="track"></span> Поддерживает потоковую выдачу</label>
    <label class="switch"><input type="checkbox" name="supports_json" value="1" <?php echo !empty($editing['supports_json']) ? 'checked' : ''; ?>><span class="track"></span> Поддерживает JSON-ответ</label>
  </div>
  <div class="llm-actions">
    <button class="btn btn-primary" type="submit">Сохранить модель</button>
    <?php if ($editing !== null): ?><a class="btn btn-ghost" href="index.php?section=llm_models">Отмена</a><?php endif; ?>
  </div>
</form>
<?php if ($editing !== null && (int) ($editing['id'] ?? 0) > 0 && empty($editing['is_default'])): ?>
<form method="post" action="index.php?section=llm_models&action=delete_model" data-confirm="Удалить модель? Она исчезнет и из цепочек резервирования.">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo (int) $editing['id']; ?>">
  <button class="btn btn-small btn-danger" type="submit">Удалить модель</button>
</form>
<?php endif; ?>

<hr class="sep">
<p class="section-title">Журнал проблемных моделей</p>
<div class="llm-table-wrap">
<table class="data-table">
  <thead><tr><th>Когда (UTC)</th><th>Модель</th><th>Причина</th><th>HTTP</th><th>Действие</th></tr></thead>
  <tbody>
<?php if ($incidents === []): ?><tr><td colspan="5" class="muted">Сбоев не было.</td></tr><?php endif; ?>
<?php foreach ($incidents as $i): ?>
    <tr><td><?php echo $when((string) $i['created_at']); ?></td><td><?php echo View::e((string) ($i['model_name'] ?? '—')); ?></td>
      <td><span class="mono"><?php echo View::e((string) $i['code']); ?></span><div class="muted"><?php echo View::e((string) $i['message']); ?></div></td>
      <td><?php echo $i['http_status'] !== null ? (int) $i['http_status'] : '—'; ?></td>
      <td><?php echo View::e(['retry' => 'повтор', 'retry_without_params' => 'повтор без параметров', 'fallback' => 'переход → ' . ($i['fallback_name'] ?? 'следующая'), 'give_up' => 'резервных больше нет', 'admin_check' => 'проверка администратора'][$i['action']] ?? (string) $i['action']); ?></td></tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'llm_models',
    'pageTitle' => 'Модели и резервирование',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
