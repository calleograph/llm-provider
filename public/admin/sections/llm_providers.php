<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Http\View;
use App\Llm\Chat\ChatSettings;
use App\Llm\Provider\ProviderValidator;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */

$flashSuccess = null;
$flashError = null;
$fieldErrors = [];
$formValues = null;
$formProviderId = null; // 0 — новая нейросеть
$testResults = [];      // provider_id => результат проверки

$providers = $llmModule->providers();
$registry = $llmModule->adapters();
$events = $llmModule->events();
$chatSettings = $llmModule->chatSettings();
$admin = (string) AdminSession::username();
$postedId = (int) ($_POST['id'] ?? 0);

$providerActions = ['create_provider', 'update_provider', 'delete_provider', 'test_provider', 'toggle_provider', 'set_default_provider', 'save_chat_settings'];

if ($method === 'POST' && in_array($action, $providerActions, true)) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } else {
        switch ($action) {
            case 'create_provider':
            case 'update_provider':
                $isCreate = $action === 'create_provider';
                $existing = $isCreate ? null : $providers->find($postedId);
                if (!$isCreate && $existing === null) {
                    $flashError = 'Подключение не найдено.';
                    break;
                }
                $data = ProviderValidator::normalize($_POST);
                $fieldErrors = ProviderValidator::validate($data, $registry);
                if (!isset($fieldErrors['name']) && $providers->nameExists($data['name'], $isCreate ? null : $postedId)) {
                    $fieldErrors['name'] = 'Нейросеть с таким названием уже есть.';
                }

                $apiKey = trim((string) ($_POST['api_key'] ?? ''));
                if ($apiKey !== '' && (strlen($apiKey) > 4096 || preg_match('/[\x00-\x20\x7F]/', $apiKey) === 1)) {
                    $fieldErrors['api_key'] = 'Ключ содержит недопустимые символы.';
                }

                $headersRaw = (string) ($_POST['extra_headers'] ?? '');
                $clearHeaders = !empty($_POST['clear_extra_headers']);
                $headers = false; // не менять
                if ($clearHeaders) {
                    $headers = null;
                } elseif (trim($headersRaw) !== '') {
                    [$parsed, $headerError] = ProviderValidator::parseHeaders($headersRaw);
                    if ($headerError !== null) {
                        $fieldErrors['extra_headers'] = $headerError;
                    } else {
                        $headers = $parsed;
                    }
                }

                if ($fieldErrors !== []) {
                    $flashError = 'Проверьте выделенные поля формы.';
                    $formValues = $data;
                    $formProviderId = $isCreate ? 0 : $postedId;
                } elseif ($isCreate) {
                    $id = $providers->create($data, $apiKey, $headers === false ? null : $headers);
                    $events->admin($admin, $clientIp, 'llm_provider_create', true, [
                        'id' => $id, 'name' => $data['name'], 'type' => $data['provider_type'], 'base_url' => $data['base_url'],
                        'model' => $data['model'], 'enabled' => $data['is_enabled'], 'key_changed' => $apiKey !== '',
                        'extra_header_count' => is_array($headers) ? count($headers) : 0,
                    ]);
                    $flashSuccess = 'Нейросеть «' . $data['name'] . '» добавлена. Проверьте подключение и назначьте права в разделе «Доступ к чату».';
                } else {
                    $providers->update($postedId, $data, $apiKey !== '' ? $apiKey : null, $headers);
                    $changed = [];
                    foreach (['name', 'provider_type', 'base_url', 'endpoint', 'model', 'api_version', 'routing_provider', 'sort_by_price', 'max_price_per_mtoken', 'allow_fallbacks', 'connect_timeout', 'response_timeout', 'max_tokens', 'use_corporate_ca', 'is_enabled'] as $field) {
                        $before = $existing[$field];
                        $after = is_bool($data[$field]) ? ($data[$field] ? 1 : 0) : $data[$field];
                        if ((string) $before !== (string) $after) {
                            $changed[] = $field;
                        }
                    }
                    $events->admin($admin, $clientIp, 'llm_provider_update', true, [
                        'id' => $postedId, 'name' => $data['name'], 'changed' => $changed !== [] ? $changed : ['—'],
                        'key_changed' => $apiKey !== '', 'headers_changed' => $headers !== false,
                    ]);
                    $flashSuccess = 'Нейросеть «' . $data['name'] . '» сохранена.';
                }
                $apiKey = str_repeat("\0", strlen($apiKey));
                unset($apiKey, $headersRaw, $headers);
                break;

            case 'toggle_provider':
                $existing = $providers->find($postedId);
                if ($existing === null) {
                    $flashError = 'Подключение не найдено.';
                    break;
                }
                $enable = !(bool) $existing['is_enabled'];
                $providers->setEnabled($postedId, $enable);
                $events->admin($admin, $clientIp, 'llm_provider_toggle', true, ['id' => $postedId, 'name' => $existing['name'], 'enabled' => $enable]);
                $flashSuccess = 'Нейросеть «' . $existing['name'] . '» ' . ($enable ? 'включена.' : 'отключена.');
                break;

            case 'set_default_provider':
                $existing = $providers->find($postedId);
                if ($existing === null) {
                    $flashError = 'Подключение не найдено.';
                    break;
                }
                if ((bool) $existing['is_default']) {
                    $providers->clearDefault();
                    $events->admin($admin, $clientIp, 'llm_provider_default_clear', true, ['id' => $postedId, 'name' => $existing['name']]);
                    $flashSuccess = 'Нейросеть «' . $existing['name'] . '» больше не открывается по умолчанию — снова действует порядок по алфавиту.';
                } else {
                    $providers->setDefault($postedId);
                    $events->admin($admin, $clientIp, 'llm_provider_default_set', true, ['id' => $postedId, 'name' => $existing['name']]);
                    $flashSuccess = 'Нейросеть «' . $existing['name'] . '» теперь открывается по умолчанию (для пользователей, которым она доступна).';
                }
                break;

            case 'delete_provider':
                $existing = $providers->find($postedId);
                if ($existing === null) {
                    $flashError = 'Подключение не найдено.';
                    break;
                }
                $providers->delete($postedId);
                $events->admin($admin, $clientIp, 'llm_provider_delete', true, ['id' => $postedId, 'name' => $existing['name']]);
                $flashSuccess = 'Нейросеть «' . $existing['name'] . '» удалена вместе с назначенными правами.';
                break;

            case 'test_provider':
                $existing = $providers->find($postedId);
                if ($existing === null) {
                    $flashError = 'Подключение не найдено.';
                    break;
                }
                $testResults[$postedId] = $llmModule->gateway()->testConnection($postedId);
                $events->admin($admin, $clientIp, 'llm_provider_test', $testResults[$postedId]['ok'], [
                    'id' => $postedId, 'name' => $existing['name'], 'duration_ms' => $testResults[$postedId]['duration_ms'],
                ]);
                break;

            case 'save_chat_settings':
                $values = [
                    'system_prompt' => trim(str_replace("\r\n", "\n", (string) ($_POST['system_prompt'] ?? ''))),
                    'history_max_chars' => (string) (int) ($_POST['history_max_chars'] ?? 0),
                    'max_message_chars' => (string) (int) ($_POST['max_message_chars'] ?? 0),
                    'style_default' => array_key_exists((string) ($_POST['style_default'] ?? ''), \App\Llm\Prompt\PromptAssembler::STYLES) ? (string) $_POST['style_default'] : 'balanced',
                    'styles_allowed' => implode(',', array_intersect(array_keys(\App\Llm\Prompt\PromptAssembler::STYLES), (array) ($_POST['styles_allowed'] ?? []))),
                    'summary_model_id' => (string) max(0, (int) ($_POST['summary_model_id'] ?? 0)),
                ];
                if (!in_array($values['style_default'], explode(',', $values['styles_allowed']), true)) {
                    $values['styles_allowed'] = trim($values['styles_allowed'] . ',' . $values['style_default'], ',');
                }
                $settingsErrors = ChatSettings::validate($values);
                if ($settingsErrors !== []) {
                    $flashError = 'Общие параметры не сохранены: ' . implode(' ', $settingsErrors);
                } else {
                    $chatSettings->save($values);
                    $events->admin($admin, $clientIp, 'llm_chat_settings_update', true, [
                        'history_max_chars' => $values['history_max_chars'], 'max_msg_chars' => $values['max_message_chars'],
                        'system_instruction_chars' => mb_strlen($values['system_prompt']),
                    ]);
                    $flashSuccess = 'Общие параметры чата сохранены.';
                }
                break;
        }
    }
}

$rows = $providers->all();
$typeDefaults = [];
foreach ($registry->all() as $type => $adapter) {
    $typeDefaults[$type] = $adapter->defaults() + ['label' => $adapter->label()];
}

$val = static function (?array $row, string $field, string $default = '') use ($formValues, $formProviderId): string {
    $cardId = $row !== null ? (int) $row['id'] : 0;
    if ($formValues !== null && $formProviderId === $cardId && array_key_exists($field, $formValues)) {
        return is_bool($formValues[$field]) ? ($formValues[$field] ? '1' : '') : (string) $formValues[$field];
    }
    if ($row === null) {
        return $default;
    }

    return (string) ($row[$field] ?? $default);
};

$renderProviderFields = static function (?array $row) use ($val, $fieldErrors, $formProviderId, $typeDefaults): string {
    $cardId = $row !== null ? (int) $row['id'] : 0;
    $errors = $formProviderId === $cardId ? $fieldErrors : [];
    $sfx = $row !== null ? (string) $row['id'] : 'new';
    $currentType = $val($row, 'provider_type', 'openai_compatible');
    $defaults = $typeDefaults[$currentType] ?? reset($typeDefaults);
    $err = static fn (string $key): string => isset($errors[$key]) ? '<p class="field-error">' . View::e($errors[$key]) . '</p>' : '';
    $cls = static fn (string $key): string => isset($errors[$key]) ? ' has-error' : '';

    ob_start();
    ?>
  <div class="form-row" data-provider-form>
    <div class="field">
      <label class="field-label" for="name-<?php echo $sfx; ?>">Отображаемое название</label>
      <input class="text-input<?php echo $cls('name'); ?>" id="name-<?php echo $sfx; ?>" name="name" required maxlength="100" value="<?php echo View::e($val($row, 'name')); ?>" placeholder="GPT-4o через polza.ai">
      <?php echo $err('name'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="provider_type-<?php echo $sfx; ?>">Тип провайдера</label>
      <select class="text-input<?php echo $cls('provider_type'); ?>" id="provider_type-<?php echo $sfx; ?>" name="provider_type" data-provider-type
              data-defaults="<?php echo View::e((string) json_encode($typeDefaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
<?php foreach ($typeDefaults as $type => $info): ?>
        <option value="<?php echo View::e($type); ?>" <?php echo $currentType === $type ? 'selected' : ''; ?>><?php echo View::e($info['label']); ?></option>
<?php endforeach; ?>
      </select>
      <?php echo $err('provider_type'); ?>
    </div>
  </div>
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="base_url-<?php echo $sfx; ?>">Base URL</label>
      <input class="text-input<?php echo $cls('base_url'); ?>" id="base_url-<?php echo $sfx; ?>" name="base_url" required data-default-field="base_url"
             value="<?php echo View::e($val($row, 'base_url', $row === null ? $defaults['base_url'] : '')); ?>" placeholder="<?php echo View::e($defaults['base_url']); ?>">
      <?php echo $err('base_url'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="endpoint-<?php echo $sfx; ?>">API endpoint</label>
      <input class="text-input<?php echo $cls('endpoint'); ?>" id="endpoint-<?php echo $sfx; ?>" name="endpoint" required data-default-field="endpoint"
             value="<?php echo View::e($val($row, 'endpoint', $row === null ? $defaults['endpoint'] : '')); ?>" placeholder="<?php echo View::e($defaults['endpoint']); ?>">
      <?php echo $err('endpoint'); ?>
    </div>
  </div>
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="api_key-<?php echo $sfx; ?>">API key</label>
      <div class="password-field-wrap">
        <input class="text-input<?php echo $cls('api_key'); ?>" type="password" id="api_key-<?php echo $sfx; ?>" name="api_key" autocomplete="new-password"
               placeholder="<?php echo $row !== null && $row['has_api_key'] ? 'сохранён (' . View::e((string) $row['api_key_hint']) . ') — оставьте пустым, чтобы не менять' : 'ключ хранится только на сервере в зашифрованном виде'; ?>">
        <button type="button" class="toggle-password" data-target="api_key-<?php echo $sfx; ?>">Показать</button>
      </div>
      <?php echo $err('api_key'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="model-<?php echo $sfx; ?>">Название модели (ID)</label>
      <input class="text-input mono<?php echo $cls('model'); ?>" id="model-<?php echo $sfx; ?>" name="model" required data-default-placeholder="model_placeholder"
             value="<?php echo View::e($val($row, 'model')); ?>" placeholder="<?php echo View::e($defaults['model_placeholder']); ?>">
      <?php echo $err('model'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="api_version-<?php echo $sfx; ?>">Версия API (при необходимости)</label>
      <input class="text-input<?php echo $cls('api_version'); ?>" id="api_version-<?php echo $sfx; ?>" name="api_version" data-default-field="api_version"
             value="<?php echo View::e($val($row, 'api_version', $row === null ? $defaults['api_version'] : '')); ?>" placeholder="<?php echo View::e($defaults['api_version'] !== '' ? $defaults['api_version'] : 'не требуется'); ?>">
      <?php echo $err('api_version'); ?>
    </div>
  </div>
  <div class="form-row" data-routing-provider-row<?php echo $currentType === 'openai_compatible' ? '' : ' hidden'; ?>>
    <div class="field">
      <label class="field-label" for="routing_provider-<?php echo $sfx; ?>">Провайдер маршрута (необязательно)</label>
      <input class="text-input<?php echo $cls('routing_provider'); ?>" id="routing_provider-<?php echo $sfx; ?>" name="routing_provider" maxlength="80"
             value="<?php echo View::e($val($row, 'routing_provider')); ?>" placeholder="например DeepInfra">
      <?php echo $err('routing_provider'); ?>
      <p class="muted llm-note">Жёстко фиксирует маршрут у мультипровайдерных шлюзов (например polza.ai) — подставляется как provider.only в запрос. Несовместимо с сортировкой по цене ниже. Оставьте пустым, чтобы шлюз выбирал маршрут сам.</p>
    </div>
    <div class="field">
      <label class="switch"><input type="checkbox" name="sort_by_price" value="1" id="sort_by_price-<?php echo $sfx; ?>"<?php echo $val($row, 'sort_by_price', '') === '1' ? ' checked' : ''; ?>><span class="track"></span> Выбирать самый дешёвый маршрут (provider.sort=price)</label>
      <?php echo $err('sort_by_price'); ?>
      <label class="field-label" for="max_price_per_mtoken-<?php echo $sfx; ?>">Потолок цены, ₽ за млн токенов (необязательно)</label>
      <input class="text-input<?php echo $cls('max_price_per_mtoken'); ?>" id="max_price_per_mtoken-<?php echo $sfx; ?>" name="max_price_per_mtoken" inputmode="decimal"
             value="<?php echo View::e($val($row, 'max_price_per_mtoken')); ?>" placeholder="без ограничения">
      <?php echo $err('max_price_per_mtoken'); ?>
      <p class="muted llm-note">Действует только вместе с сортировкой по цене — отсекает маршруты дороже указанной цены (provider.max_price, одно и то же значение для prompt и completion). Слишком низкое значение может не пропустить ни одного маршрута — см. галочку ниже.</p>
      <label class="switch"><input type="checkbox" name="allow_fallbacks" value="1" id="allow_fallbacks-<?php echo $sfx; ?>"<?php echo $val($row, 'allow_fallbacks', '1') === '1' ? ' checked' : ''; ?>><span class="track"></span> Если по потолку цены не осталось ни одного маршрута — не отклонять запрос, а взять любой доступный (provider.allow_fallbacks)</label>
      <?php echo $err('allow_fallbacks'); ?>
    </div>
  </div>
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="connect_timeout-<?php echo $sfx; ?>">Таймаут соединения (сек.)</label>
      <input class="text-input<?php echo $cls('connect_timeout'); ?>" type="number" min="1" max="60" id="connect_timeout-<?php echo $sfx; ?>" name="connect_timeout" value="<?php echo View::e($val($row, 'connect_timeout', '10')); ?>">
      <?php echo $err('connect_timeout'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="response_timeout-<?php echo $sfx; ?>">Таймаут ответа (сек.)</label>
      <input class="text-input<?php echo $cls('response_timeout'); ?>" type="number" min="5" max="900" id="response_timeout-<?php echo $sfx; ?>" name="response_timeout" value="<?php echo View::e($val($row, 'response_timeout', '120')); ?>">
      <?php echo $err('response_timeout'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="max_tokens-<?php echo $sfx; ?>">Максимум токенов ответа</label>
      <input class="text-input<?php echo $cls('max_tokens'); ?>" type="number" min="1" max="200000" id="max_tokens-<?php echo $sfx; ?>" name="max_tokens" value="<?php echo View::e($val($row, 'max_tokens', '2048')); ?>">
      <?php echo $err('max_tokens'); ?>
    </div>
  </div>
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="extra_headers-<?php echo $sfx; ?>">Дополнительные HTTP-заголовки (необязательно, «Имя: значение» по строкам)</label>
      <textarea class="text-input mono<?php echo $cls('extra_headers'); ?>" rows="2" id="extra_headers-<?php echo $sfx; ?>" name="extra_headers" autocomplete="off"
                placeholder="<?php echo $row !== null && $row['extra_header_names'] ? 'сохранены: ' . View::e((string) $row['extra_header_names']) . ' — введите заново, чтобы заменить' : 'X-Organization: corp'; ?>"></textarea>
<?php if ($row !== null && $row['extra_header_names']): ?>
      <label class="llm-inline-check"><input type="checkbox" name="clear_extra_headers" value="1"> удалить сохранённые заголовки</label>
<?php endif; ?>
      <?php echo $err('extra_headers'); ?>
    </div>
  </div>
  <div class="llm-switch-row">
    <label class="switch"><input type="checkbox" name="is_enabled" value="1" <?php echo $val($row, 'is_enabled', '1') === '1' ? 'checked' : ''; ?>><span class="track"></span> Включена</label>
    <label class="switch"><input type="checkbox" name="use_corporate_ca" value="1" <?php echo $val($row, 'use_corporate_ca', '') === '1' ? 'checked' : ''; ?>><span class="track"></span> Проверять TLS по корпоративному CA bundle (для внутренних шлюзов)</label>
  </div>
<?php
    return (string) ob_get_clean();
};

ob_start();
?>
<p class="muted llm-lead">
  Подключения к внешним LLM API. Все обращения идут через единый LLM Gateway; пользовательский текст
  попадает туда только после обезличивания. API-ключ хранится на сервере в зашифрованном виде
  (тот же master key, что для остальных секретов) и после сохранения не отображается.
</p>

<div class="llm-toolbar">
  <p class="section-title">Подключённые нейросети</p>
  <button type="button" class="btn btn-primary llm-plus" data-reveal="newProviderCard"<?php echo $formProviderId === 0 ? ' hidden' : ''; ?>>+ Добавить нейросеть</button>
</div>

<div class="llm-card" id="newProviderCard"<?php echo $formProviderId === 0 ? '' : ' hidden'; ?>>
  <div class="llm-card-head"><h2>Новая нейросеть</h2></div>
  <form method="post" action="index.php?section=llm_providers&action=create_provider">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <?php echo $renderProviderFields(null); ?>
    <div class="llm-actions"><button class="btn btn-primary" type="submit">Добавить</button></div>
  </form>
</div>

<?php if ($rows === []): ?>
<p class="muted">Нейросети ещё не добавлены — нажмите «+ Добавить нейросеть».</p>
<?php endif; ?>

<?php foreach ($rows as $row): $pid = (int) $row['id']; ?>
<div class="llm-card" id="provider-<?php echo $pid; ?>">
  <div class="llm-card-head">
    <h2><?php echo View::e((string) $row['name']); ?> <span class="muted mono"><?php echo View::e((string) $row['model']); ?></span></h2>
    <div>
      <span class="badge badge-inactive"><?php echo View::e($typeDefaults[$row['provider_type']]['label'] ?? (string) $row['provider_type']); ?></span>
      <span class="badge <?php echo $row['is_enabled'] ? 'badge-active' : 'badge-inactive'; ?>"><?php echo $row['is_enabled'] ? 'Включена' : 'Отключена'; ?></span>
<?php if (!empty($row['is_default'])): ?>
      <span class="badge badge-active" title="Открывается в чате первой, если доступна пользователю">По умолчанию</span>
<?php endif; ?>
<?php if ($row['last_check_at'] !== null): ?>
      <span class="badge <?php echo (int) $row['last_check_ok'] === 1 ? 'badge-active' : 'llm-badge-bad'; ?>" title="<?php echo View::e((string) $row['last_check_message']); ?>">
        Проверка <?php echo View::e(substr((string) $row['last_check_at'], 0, 16)); ?>: <?php echo (int) $row['last_check_ok'] === 1 ? 'OK' : 'ошибка'; ?>
      </span>
<?php endif; ?>
    </div>
  </div>
  <form method="post" action="index.php?section=llm_providers&action=update_provider#provider-<?php echo $pid; ?>">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
    <input type="hidden" name="id" value="<?php echo $pid; ?>">
    <?php echo $renderProviderFields($row); ?>
    <div class="llm-actions">
      <button class="btn btn-primary" type="submit">Сохранить</button>
      <button class="btn" type="submit" form="testProvider<?php echo $pid; ?>">Проверить подключение</button>
      <button class="btn" type="submit" form="toggleProvider<?php echo $pid; ?>"><?php echo $row['is_enabled'] ? 'Отключить' : 'Включить'; ?></button>
      <button class="btn" type="submit" form="defaultProvider<?php echo $pid; ?>"><?php echo !empty($row['is_default']) ? 'Снять «по умолчанию»' : 'Сделать по умолчанию'; ?></button>
      <button class="btn btn-danger" type="submit" form="deleteProvider<?php echo $pid; ?>">Удалить</button>
    </div>
  </form>
  <form id="testProvider<?php echo $pid; ?>" method="post" action="index.php?section=llm_providers&action=test_provider#provider-<?php echo $pid; ?>">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo $pid; ?>">
  </form>
  <form id="toggleProvider<?php echo $pid; ?>" method="post" action="index.php?section=llm_providers&action=toggle_provider#provider-<?php echo $pid; ?>">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo $pid; ?>">
  </form>
  <form id="defaultProvider<?php echo $pid; ?>" method="post" action="index.php?section=llm_providers&action=set_default_provider#provider-<?php echo $pid; ?>">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo $pid; ?>">
  </form>
  <form id="deleteProvider<?php echo $pid; ?>" method="post" action="index.php?section=llm_providers&action=delete_provider"
        data-confirm-title="Удалить нейросеть?"
        data-confirm-text="Подключение «<?php echo View::e((string) $row['name']); ?>» и все назначенные на него права будут удалены. Сохранённые диалоги останутся.">
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo $pid; ?>">
  </form>
<?php if (isset($testResults[$pid])): ?>
  <div class="alert <?php echo $testResults[$pid]['ok'] ? 'alert-success' : 'alert-error'; ?> llm-result">
    <?php echo View::e($testResults[$pid]['message']); ?> (<?php echo (int) $testResults[$pid]['duration_ms']; ?> мс)
  </div>
  <p class="muted llm-note">Для проверки отправляется синтетическая фраза без пользовательских данных.</p>
<?php endif; ?>
</div>
<?php endforeach; ?>

<hr class="sep">

<p class="section-title">Общие параметры чата</p>
<form method="post" action="index.php?section=llm_providers&action=save_chat_settings">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="system_prompt">Системная инструкция для всех нейросетей (не содержит пользовательских данных)<?php echo View::settingCode('CHAT_SYSTEM_PROMPT'); ?></label>
      <textarea class="text-input" id="system_prompt" name="system_prompt" rows="3" maxlength="8000"><?php echo View::e($chatSettings->get('system_prompt')); ?></textarea>
      <p class="muted llm-note">Слой 3 из 6: базовые правила, правила достоверности и безопасности встроены и важнее; инструкции модели, проекта и диалога идут после.</p>
    </div>
  </div>
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="style_default">Стиль ответа по умолчанию</label>
      <select class="text-input" id="style_default" name="style_default">
<?php foreach (\App\Llm\Prompt\PromptAssembler::STYLES as $key => [$label]): ?>
        <option value="<?php echo $key; ?>" <?php echo $chatSettings->get('style_default') === $key ? 'selected' : ''; ?>><?php echo View::e($label); ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="summary_model_id">Модель для сводок длинных диалогов</label>
      <select class="text-input" id="summary_model_id" name="summary_model_id">
        <option value="0">та же, что отвечает</option>
<?php foreach ($llmModule->models()->all() as $m): ?>
        <option value="<?php echo (int) $m['id']; ?>" <?php echo (int) $chatSettings->get('summary_model_id') === (int) $m['id'] ? 'selected' : ''; ?>><?php echo View::e($m['display_name'] . ' · ' . $m['provider_name']); ?></option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-row">
    <div class="field">
      <span class="field-label">Стили, доступные сотрудникам</span>
      <div class="llm-check-list">
<?php $allowedStyles = explode(',', $chatSettings->get('styles_allowed')); foreach (\App\Llm\Prompt\PromptAssembler::STYLES as $key => [$label]): ?>
        <label class="llm-inline-check"><input type="checkbox" name="styles_allowed[]" value="<?php echo $key; ?>" <?php echo in_array($key, $allowedStyles, true) ? 'checked' : ''; ?>> <?php echo View::e($label); ?></label>
<?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="history_max_chars">Потолок истории в контексте (символов) — дополнительно к окну модели<?php echo View::settingCode('CHAT_HISTORY_MAX_CHARS'); ?></label>
      <input class="text-input" type="number" id="history_max_chars" name="history_max_chars" value="<?php echo View::e($chatSettings->get('history_max_chars')); ?>">
    </div>
    <div class="field">
      <label class="field-label" for="max_message_chars">Максимальная длина сообщения (символов)<?php echo View::settingCode('CHAT_MAX_MESSAGE_CHARS'); ?></label>
      <input class="text-input" type="number" id="max_message_chars" name="max_message_chars" value="<?php echo View::e($chatSettings->get('max_message_chars')); ?>">
    </div>
  </div>
  <button class="btn btn-primary" type="submit">Сохранить параметры</button>
</form>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'llm_providers',
    'pageTitle' => 'Подключения LLM API',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
