<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Http\View;
use App\Llm\Anonymization\AnonymizationSettings;
use App\Llm\Files\FileProcessingSettings;
use App\Llm\Files\ProcessRunner;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */

$flashSuccess = null;
$flashError = null;
$fieldErrors = [];
$formValues = null;
$fileErrors = [];
$fileFormValues = null;
$checkResult = null;

$settings = $llmModule->anonymizationSettings();
$fileSettings = $llmModule->fileSettings();
$events = $llmModule->events();
$admin = (string) AdminSession::username();

if ($method === 'POST' && in_array($action, ['save_anonymization', 'check_anonymization', 'save_file_processing'], true)) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } elseif ($action === 'save_anonymization') {
        $values = [];
        foreach (['base_url', 'endpoint', 'health_endpoint', 'auth_type', 'auth_header_name', 'auth_username', 'connect_timeout', 'timeout',
                  'request_field', 'response_field', 'status_field', 'status_ok_value', 'extra_body', 'max_chunk_chars'] as $field) {
            $values[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $values['base_url'] = rtrim($values['base_url'], '/');
        $values['use_corporate_ca'] = !empty($_POST['use_corporate_ca']) ? '1' : '0';
        $values['allow_http'] = !empty($_POST['allow_http']) ? '1' : '0';
        $values['profile'] = (string) ($_POST['profile'] ?? AnonymizationSettings::PROFILE_DOCCLEAN);
        $values['docclean_mode'] = (string) ($_POST['docclean_mode'] ?? 'ai_ready');
        $values['file_mode'] = (string) ($_POST['file_mode'] ?? AnonymizationSettings::FILE_MODE_API);
        $values['second_pass'] = !empty($_POST['second_pass']) ? '1' : '0';
        $values['docclean_groups'] = implode(',', array_intersect(AnonymizationSettings::DOCCLEAN_GROUPS, (array) ($_POST['docclean_groups'] ?? [])));
        if ($values['profile'] === AnonymizationSettings::PROFILE_DOCCLEAN) {
            // DocClean: авторизация только Bearer-ключом, адреса действий фиксированы.
            $values['auth_type'] = AnonymizationSettings::AUTH_BEARER;
        }

        $fieldErrors = AnonymizationSettings::validate($values);
        $secret = (string) ($_POST['secret'] ?? '');
        if ($secret !== '' && (strlen($secret) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1)) {
            $fieldErrors['secret'] = 'Токен содержит недопустимые символы.';
        }
        $needsSecret = in_array($values['auth_type'], [AnonymizationSettings::AUTH_BEARER, AnonymizationSettings::AUTH_HEADER, AnonymizationSettings::AUTH_BASIC], true);
        if ($needsSecret && $secret === '' && !$settings->hasSecret()) {
            $fieldErrors['secret'] = 'Для выбранного типа авторизации укажите токен/пароль.';
        }

        if ($fieldErrors !== []) {
            $flashError = 'Проверьте выделенные поля формы.';
            $formValues = $values;
        } else {
            $before = $settings->all();
            $settings->save($values);
            $secretAction = 'kept';
            if (!$needsSecret) {
                $settings->clearSecret();
                $secretAction = 'cleared';
            } elseif ($secret !== '') {
                $settings->setSecret($secret);
                $secretAction = 'replaced';
            }
            $changed = [];
            foreach ($values as $field => $newValue) {
                if (($before[$field] ?? '') !== $newValue) {
                    $changed[] = $field;
                }
            }
            $events->admin($admin, $clientIp, 'anonymization_settings_update', true, [
                'profile' => $values['profile'], 'base_url' => $values['base_url'],
                'mode' => $values['profile'] === AnonymizationSettings::PROFILE_DOCCLEAN ? $values['docclean_mode'] : '—',
                'file_mode' => $values['profile'] === AnonymizationSettings::PROFILE_DOCCLEAN ? $values['file_mode'] : AnonymizationSettings::FILE_MODE_TEXT,
                'endpoint' => $values['endpoint'], 'auth_type' => $values['auth_type'],
                'changed' => $changed !== [] ? $changed : ['—'], 'auth_change' => $secretAction,
            ]);
            $flashSuccess = 'Настройки API обезличивания сохранены. Нажмите «Проверить подключение».';
        }
        $secret = str_repeat("\0", strlen($secret));
        unset($secret);
    } elseif ($action === 'check_anonymization') {
        $checkResult = $llmModule->anonymizer()->testConnection();
        $events->admin($admin, $clientIp, 'anonymization_check', $checkResult['ok'], ['duration_ms' => $checkResult['duration_ms']]);
    } else {
        $values = [];
        foreach (['max_upload_mb', 'max_extracted_chars', 'ocr_languages', 'ocr_max_pages', 'tesseract_path', 'pdftotext_path', 'pdftoppm_path', 'tool_timeout'] as $field) {
            $values[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $values['ocr_enabled'] = !empty($_POST['ocr_enabled']) ? '1' : '0';
        $fileErrors = FileProcessingSettings::validate($values);
        if ($fileErrors !== []) {
            $flashError = 'Проверьте выделенные поля обработки файлов.';
            $fileFormValues = $values;
        } else {
            $fileSettings->save($values);
            $events->admin($admin, $clientIp, 'file_processing_settings_update', true, [
                'max_upload_mb' => $values['max_upload_mb'], 'ocr_enabled' => $values['ocr_enabled'], 'ocr_languages' => $values['ocr_languages'],
            ]);
            $flashSuccess = 'Параметры обработки файлов сохранены.';
        }
    }
}

$current = $formValues ?? $settings->all();
$files = $fileFormValues ?? $fileSettings->all();
$field = static fn (string $name): string => View::e($current[$name] ?? '');
$err = static fn (array $errors, string $name): string => isset($errors[$name]) ? '<p class="field-error">' . View::e($errors[$name]) . '</p>' : '';
$cls = static fn (array $errors, string $name): string => isset($errors[$name]) ? ' has-error' : '';

$lastCheckAt = $settings->get('last_check_at');
$lastCheckOk = $settings->get('last_check_ok') === '1';
$tools = [
    'pdftotext' => ProcessRunner::isExecutable($fileSettings->get('pdftotext_path')),
    'pdftoppm' => ProcessRunner::isExecutable($fileSettings->get('pdftoppm_path')),
    'tesseract' => ProcessRunner::isExecutable($fileSettings->get('tesseract_path')),
    'ext-zip' => class_exists(\ZipArchive::class),
    'ext-curl' => function_exists('curl_init'),
];

ob_start();
?>
<p class="muted llm-lead">
  Anonymization Gateway — единственный компонент, через который приложение обращается к внутреннему
  сервису обезличивания. Весь пользовательский текст и текст файлов проходит через него до отправки во
  внешний LLM. При любой ошибке обезличивания запрос наружу не отправляется (fail closed).
</p>

<div class="grid grid-3 llm-status-row">
  <div class="stat-tile">
    <div class="label">Настройка</div>
    <div class="value <?php echo $settings->isConfigured() ? 'status-ok' : 'status-bad'; ?>"><?php echo $settings->isConfigured() ? 'Задана' : 'Не задана — чат заблокирован'; ?></div>
  </div>
  <div class="stat-tile">
    <div class="label">Доступность (последняя проверка)</div>
    <div class="value <?php echo $lastCheckAt === '' ? 'status-warn' : ($lastCheckOk ? 'status-ok' : 'status-bad'); ?>">
      <?php echo $lastCheckAt === '' ? 'Не проверялась' : ($lastCheckOk ? 'Доступен' : 'Ошибка'); ?>
    </div>
  </div>
  <div class="stat-tile">
    <div class="label">Когда</div>
    <div class="value"><?php echo $lastCheckAt !== '' ? View::e(str_replace('T', ' ', substr($lastCheckAt, 0, 19))) . ' UTC' : '—'; ?></div>
  </div>
</div>
<?php if ($lastCheckAt !== '' && !$lastCheckOk): ?>
<div class="alert alert-error"><?php echo View::e($settings->get('last_check_message')); ?></div>
<?php endif; ?>
<?php if ($checkResult !== null): ?>
<div class="alert <?php echo $checkResult['ok'] ? 'alert-success' : 'alert-error'; ?>"><?php echo View::e($checkResult['message']); ?> (<?php echo (int) $checkResult['duration_ms']; ?> мс)</div>
<?php endif; ?>

<?php
$profile = $current['profile'] ?? AnonymizationSettings::PROFILE_DOCCLEAN;
$isDocClean = $profile === AnonymizationSettings::PROFILE_DOCCLEAN;
$selectedGroups = array_filter(explode(',', $current['docclean_groups'] ?? ''));
?>
<p class="section-title">API обезличивания</p>
<form method="post" action="index.php?section=anonymization&action=save_anonymization">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
    <div class="field">
      <label class="field-label" for="profile">Тип API<?php echo View::settingCode('ANON_PROFILE'); ?></label>
      <select class="text-input" id="profile" name="profile" data-anon-profile>
        <option value="<?php echo AnonymizationSettings::PROFILE_DOCCLEAN; ?>" <?php echo $isDocClean ? 'selected' : ''; ?>>DocClean — локальный обезличиватель документов</option>
        <option value="<?php echo AnonymizationSettings::PROFILE_GENERIC; ?>" <?php echo $isDocClean ? '' : 'selected'; ?>>Произвольный JSON API</option>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="base_url">URL сервиса<?php echo View::settingCode('ANON_BASE_URL'); ?></label>
      <input class="text-input<?php echo $cls($fieldErrors, 'base_url'); ?>" id="base_url" name="base_url" required value="<?php echo $field('base_url'); ?>" placeholder="https://docclean.corp.local">
      <?php echo $err($fieldErrors, 'base_url'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="anon_secret">API-ключ / токен<?php echo View::settingCode('ANON_SECRET'); ?></label>
      <div class="password-field-wrap">
        <input class="text-input<?php echo $cls($fieldErrors, 'secret'); ?>" type="password" id="anon_secret" name="secret" autocomplete="new-password"
               placeholder="<?php echo $settings->hasSecret() ? 'сохранён — оставьте пустым, чтобы не менять' : 'хранится в зашифрованном виде'; ?>">
        <button type="button" class="toggle-password" data-target="anon_secret">Показать</button>
      </div>
      <?php echo $err($fieldErrors, 'secret'); ?>
    </div>
  </div>

  <div data-profile-section="<?php echo AnonymizationSettings::PROFILE_DOCCLEAN; ?>"<?php echo $isDocClean ? '' : ' hidden'; ?>>
    <div class="form-row">
      <div class="field">
        <label class="field-label" for="docclean_mode">Режим обезличивания<?php echo View::settingCode('ANON_MODE'); ?></label>
        <select class="text-input<?php echo $cls($fieldErrors, 'docclean_mode'); ?>" id="docclean_mode" name="docclean_mode">
<?php foreach (['ai_ready' => 'ai_ready — для передачи в ИИ (рекомендуется)', 'full' => 'full — полное обезличивание', 'personal_corporate' => 'personal_corporate — ПДн и корпоративные данные', 'personal' => 'personal — только ПДн'] as $mode => $label): ?>
          <option value="<?php echo $mode; ?>" <?php echo ($current['docclean_mode'] ?? 'ai_ready') === $mode ? 'selected' : ''; ?>><?php echo View::e($label); ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field-label" for="file_mode">Обработка файлов<?php echo View::settingCode('ANON_FILE_MODE'); ?></label>
        <select class="text-input<?php echo $cls($fieldErrors, 'file_mode'); ?>" id="file_mode" name="file_mode">
          <option value="<?php echo AnonymizationSettings::FILE_MODE_API; ?>" <?php echo ($current['file_mode'] ?? '') === AnonymizationSettings::FILE_MODE_API ? 'selected' : ''; ?>>Файл целиком в api_file (форматы и OCR сервиса) — рекомендуется</option>
          <option value="<?php echo AnonymizationSettings::FILE_MODE_TEXT; ?>" <?php echo ($current['file_mode'] ?? '') === AnonymizationSettings::FILE_MODE_TEXT ? 'selected' : ''; ?>>Локальное извлечение текста, затем api_text</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <span class="field-label">Группы категорий (ни одной — весь набор режима)</span>
        <div class="llm-check-list">
<?php foreach (['pdn' => 'ПДн и финансы', 'corporate' => 'Корпоративные', 'secrets' => 'Секреты', 'network' => 'Инфраструктура', 'other' => 'Прочее'] as $group => $label): ?>
          <label class="llm-inline-check"><input type="checkbox" name="docclean_groups[]" value="<?php echo $group; ?>" <?php echo in_array($group, $selectedGroups, true) ? 'checked' : ''; ?>> <?php echo View::e($label); ?> <span class="muted mono">(<?php echo $group; ?>)</span></label>
<?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="llm-switch-row">
      <label class="switch"><input type="checkbox" id="second_pass" name="second_pass" value="1" <?php echo ($current['second_pass'] ?? '1') === '1' ? 'checked' : ''; ?>><span class="track"></span> Повторно проверять текст, извлечённый из обезличенного файла, через api_text<?php echo View::settingCode('ANON_SECOND_PASS'); ?></label>
    </div>
    <p class="muted llm-note">
      Используются <code>api_text</code>, <code>api_file</code> + <code>api_download</code> и <code>openapi</code> (проверка подключения).
      Текст принимается только при <code>ok = true</code> и <code>verification.clean = true</code> — иначе запрос в нейросеть блокируется.
      Сервису передаётся нейтральное имя файла (<code>document.&lt;ext&gt;</code>), а не исходное.
    </p>
  </div>

  <div data-profile-section="<?php echo AnonymizationSettings::PROFILE_GENERIC; ?>"<?php echo $isDocClean ? ' hidden' : ''; ?>>
    <div class="form-row">
      <div class="field">
        <label class="field-label" for="endpoint">Endpoint обезличивания (POST)<?php echo View::settingCode('ANON_ENDPOINT'); ?></label>
        <input class="text-input<?php echo $cls($fieldErrors, 'endpoint'); ?>" id="endpoint" name="endpoint" value="<?php echo $field('endpoint'); ?>" placeholder="/anonymize">
        <?php echo $err($fieldErrors, 'endpoint'); ?>
      </div>
      <div class="field">
        <label class="field-label" for="health_endpoint">Health endpoint (GET, необязательно)<?php echo View::settingCode('ANON_HEALTH_ENDPOINT'); ?></label>
        <input class="text-input<?php echo $cls($fieldErrors, 'health_endpoint'); ?>" id="health_endpoint" name="health_endpoint" value="<?php echo $field('health_endpoint'); ?>" placeholder="/health">
        <?php echo $err($fieldErrors, 'health_endpoint'); ?>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label class="field-label" for="auth_type">Авторизация<?php echo View::settingCode('ANON_AUTH_TYPE'); ?></label>
        <select class="text-input<?php echo $cls($fieldErrors, 'auth_type'); ?>" id="auth_type" name="auth_type">
<?php foreach ([AnonymizationSettings::AUTH_NONE => 'Без авторизации', AnonymizationSettings::AUTH_BEARER => 'Bearer-токен (Authorization)', AnonymizationSettings::AUTH_HEADER => 'Ключ в заголовке', AnonymizationSettings::AUTH_BASIC => 'Basic (логин/пароль)'] as $type => $label): ?>
          <option value="<?php echo $type; ?>" <?php echo ($current['auth_type'] ?? '') === $type ? 'selected' : ''; ?>><?php echo View::e($label); ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field-label" for="auth_header_name">Имя заголовка (для «Ключ в заголовке»)<?php echo View::settingCode('ANON_AUTH_HEADER'); ?></label>
        <input class="text-input<?php echo $cls($fieldErrors, 'auth_header_name'); ?>" id="auth_header_name" name="auth_header_name" value="<?php echo $field('auth_header_name'); ?>">
        <?php echo $err($fieldErrors, 'auth_header_name'); ?>
      </div>
      <div class="field">
        <label class="field-label" for="auth_username">Логин (для Basic)<?php echo View::settingCode('ANON_AUTH_USER'); ?></label>
        <input class="text-input" id="auth_username" name="auth_username" autocomplete="off" value="<?php echo $field('auth_username'); ?>">
      </div>
    </div>
    <p class="section-title llm-subtitle">Формат обмена (JSON)</p>
    <div class="form-row">
      <div class="field">
        <label class="field-label" for="request_field">Поле запроса с текстом<?php echo View::settingCode('ANON_REQUEST_FIELD'); ?></label>
        <input class="text-input mono<?php echo $cls($fieldErrors, 'request_field'); ?>" id="request_field" name="request_field" value="<?php echo $field('request_field'); ?>" placeholder="text">
        <?php echo $err($fieldErrors, 'request_field'); ?>
      </div>
      <div class="field">
        <label class="field-label" for="response_field">Поле ответа с результатом<?php echo View::settingCode('ANON_RESPONSE_FIELD'); ?></label>
        <input class="text-input mono<?php echo $cls($fieldErrors, 'response_field'); ?>" id="response_field" name="response_field" value="<?php echo $field('response_field'); ?>" placeholder="anonymized_text или result.text">
        <?php echo $err($fieldErrors, 'response_field'); ?>
      </div>
      <div class="field">
        <label class="field-label" for="status_field">Поле статуса (необязательно)<?php echo View::settingCode('ANON_STATUS_FIELD'); ?></label>
        <input class="text-input mono<?php echo $cls($fieldErrors, 'status_field'); ?>" id="status_field" name="status_field" value="<?php echo $field('status_field'); ?>" placeholder="status">
        <?php echo $err($fieldErrors, 'status_field'); ?>
      </div>
      <div class="field">
        <label class="field-label" for="status_ok_value">Значение успешного статуса<?php echo View::settingCode('ANON_STATUS_OK'); ?></label>
        <input class="text-input mono" id="status_ok_value" name="status_ok_value" value="<?php echo $field('status_ok_value'); ?>" placeholder="ok">
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label class="field-label" for="extra_body">Дополнительные поля запроса (JSON-объект, необязательно)<?php echo View::settingCode('ANON_EXTRA_BODY'); ?></label>
        <input class="text-input mono<?php echo $cls($fieldErrors, 'extra_body'); ?>" id="extra_body" name="extra_body" value="<?php echo $field('extra_body'); ?>" placeholder='{"lang":"ru"}'>
        <?php echo $err($fieldErrors, 'extra_body'); ?>
      </div>
    </div>
    <p class="muted llm-note">
      Запрос: <code>POST {URL}{endpoint}</code> с телом <code>{"<?php echo $field('request_field'); ?>": "…текст…"}</code>.
      Успех — HTTP 2xx и непустая строка в поле ответа. Файлы обрабатываются локально, их текст отправляется как обычный текст.
    </p>
  </div>

  <div class="form-row">
    <div class="field">
      <label class="field-label" for="connect_timeout">Таймаут соединения (сек.)<?php echo View::settingCode('ANON_CONNECT_TIMEOUT'); ?></label>
      <input class="text-input<?php echo $cls($fieldErrors, 'connect_timeout'); ?>" type="number" id="connect_timeout" name="connect_timeout" value="<?php echo $field('connect_timeout'); ?>">
      <?php echo $err($fieldErrors, 'connect_timeout'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="timeout">Таймаут ответа (сек., для файлов — не меньше 60)<?php echo View::settingCode('ANON_TIMEOUT'); ?></label>
      <input class="text-input<?php echo $cls($fieldErrors, 'timeout'); ?>" type="number" id="timeout" name="timeout" value="<?php echo $field('timeout'); ?>">
      <?php echo $err($fieldErrors, 'timeout'); ?>
    </div>
    <div class="field">
      <label class="field-label" for="max_chunk_chars">Макс. размер фрагмента текста (символов)<?php echo View::settingCode('ANON_MAX_CHUNK'); ?></label>
      <input class="text-input<?php echo $cls($fieldErrors, 'max_chunk_chars'); ?>" type="number" id="max_chunk_chars" name="max_chunk_chars" value="<?php echo $field('max_chunk_chars'); ?>">
      <?php echo $err($fieldErrors, 'max_chunk_chars'); ?>
    </div>
  </div>
  <div class="llm-switch-row">
    <label class="switch"><input type="checkbox" id="anon_use_corporate_ca" name="use_corporate_ca" value="1" <?php echo ($current['use_corporate_ca'] ?? '1') === '1' ? 'checked' : ''; ?>><span class="track"></span> Проверять TLS по корпоративному CA bundle (раздел «LDAP-подключения»)<?php echo View::settingCode('ANON_CORPORATE_CA'); ?></label>
    <label class="switch"><input type="checkbox" id="allow_http" name="allow_http" value="1" <?php echo ($current['allow_http'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="track"></span> Разрешить http:// (только изолированная сеть — не рекомендуется)<?php echo View::settingCode('ANON_ALLOW_HTTP'); ?></label>
  </div>
  <div class="llm-actions">
    <button class="btn btn-primary" type="submit">Сохранить</button>
    <button class="btn" type="submit" form="checkAnonymization"<?php echo $settings->isConfigured() ? '' : ' disabled'; ?>>Проверить подключение</button>
  </div>
</form>
<form id="checkAnonymization" method="post" action="index.php?section=anonymization&action=check_anonymization">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
</form>

<hr class="sep">

<p class="section-title">Локальная обработка файлов (режим «Локальное извлечение» и произвольный API; извлечение текста из результата DocClean)</p>
<div class="grid grid-3 llm-status-row">
<?php foreach ($tools as $tool => $available): ?>
  <div class="stat-tile"><div class="label"><?php echo View::e($tool); ?></div><div class="value <?php echo $available ? 'status-ok' : 'status-warn'; ?>"><?php echo $available ? 'Доступен' : 'Не найден'; ?></div></div>
<?php endforeach; ?>
</div>
<p class="muted llm-note">
  TXT/CSV — встроенно; DOCX/XLSX — ext-zip; PDF — <code>pdftotext</code> (poppler-utils); сканы и изображения —
  <code>pdftoppm</code> + <code>tesseract</code> (языки rus+eng). Если для файла нужен недоступный инструмент,
  отправка блокируется. Исходный файл во внешний API не передаётся и удаляется сразу после извлечения текста.
</p>
<form method="post" action="index.php?section=anonymization&action=save_file_processing">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="form-row">
<?php
$fileFieldCodes = [
    'max_upload_mb' => 'FILE_MAX_UPLOAD_MB', 'max_extracted_chars' => 'FILE_MAX_EXTRACTED_CHARS',
    'ocr_max_pages' => 'FILE_OCR_MAX_PAGES', 'tool_timeout' => 'FILE_TOOL_TIMEOUT',
    'pdftotext_path' => 'FILE_PDFTOTEXT_PATH', 'pdftoppm_path' => 'FILE_PDFTOPPM_PATH',
    'tesseract_path' => 'FILE_TESSERACT_PATH', 'ocr_languages' => 'FILE_OCR_LANGUAGES',
];
?>
<?php foreach (['max_upload_mb' => 'Макс. размер файла (МБ)', 'max_extracted_chars' => 'Макс. извлекаемый текст (символов)', 'ocr_max_pages' => 'Макс. страниц OCR', 'tool_timeout' => 'Таймаут инструментов (сек.)'] as $name => $label): ?>
    <div class="field">
      <label class="field-label" for="<?php echo $name; ?>"><?php echo View::e($label); ?><?php echo View::settingCode($fileFieldCodes[$name]); ?></label>
      <input class="text-input<?php echo $cls($fileErrors, $name); ?>" type="number" id="<?php echo $name; ?>" name="<?php echo $name; ?>" value="<?php echo View::e($files[$name]); ?>">
      <?php echo $err($fileErrors, $name); ?>
    </div>
<?php endforeach; ?>
  </div>
  <div class="form-row">
<?php foreach (['pdftotext_path' => 'Путь к pdftotext', 'pdftoppm_path' => 'Путь к pdftoppm', 'tesseract_path' => 'Путь к tesseract', 'ocr_languages' => 'Языки OCR'] as $name => $label): ?>
    <div class="field">
      <label class="field-label" for="<?php echo $name; ?>"><?php echo View::e($label); ?><?php echo View::settingCode($fileFieldCodes[$name]); ?></label>
      <input class="text-input mono<?php echo $cls($fileErrors, $name); ?>" id="<?php echo $name; ?>" name="<?php echo $name; ?>" value="<?php echo View::e($files[$name]); ?>">
      <?php echo $err($fileErrors, $name); ?>
    </div>
<?php endforeach; ?>
  </div>
  <div class="llm-switch-row">
    <label class="switch"><input type="checkbox" id="ocr_enabled" name="ocr_enabled" value="1" <?php echo $files['ocr_enabled'] === '1' ? 'checked' : ''; ?>><span class="track"></span> Локальный OCR для сканов<?php echo View::settingCode('FILE_OCR_ENABLED'); ?></label>
  </div>
  <div class="llm-actions"><button class="btn btn-primary" type="submit">Сохранить параметры файлов</button></div>
</form>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'anonymization',
    'pageTitle' => 'API обезличивания',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
