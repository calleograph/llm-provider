<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Http\View;
use App\Mail\MailSettings;
use App\Mail\SmtpClient;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */

$flashSuccess = null;
$flashError = null;
$fieldErrors = [];
$formValues = null;
$checkResult = null;

$settings = $llmModule->mailSettings();
$queue = $llmModule->mailQueue();
$events = $llmModule->events();
$admin = (string) AdminSession::username();
$stageNames = ['dns' => 'Имя сервера', 'tcp' => 'TCP-соединение', 'greeting' => 'Приветствие сервера', 'ehlo' => 'EHLO', 'tls' => 'Шифрование', 'certificate' => 'Сертификат',
    'methods' => 'Способы входа', 'auth' => 'Авторизация', 'sender' => 'Отправитель', 'recipient' => 'Получатель', 'data' => 'Передача письма'];
$codeNames = ['dns' => 'имя не разрешается', 'connect' => 'нет соединения (порт закрыт или файрвол)', 'timeout' => 'тайм-аут', 'closed' => 'сервер закрыл соединение',
    'certificate' => 'сертификат не прошёл проверку', 'starttls' => 'STARTTLS недоступен', 'protocol' => 'нарушение протокола', 'auth_failed' => 'логин или пароль отклонены',
    'auth_unsupported' => 'способ входа не поддерживается', 'relay_denied' => 'пересылка запрещена', 'sender_rejected' => 'отправитель отклонён',
    'recipient_rejected' => 'получатель отклонён', 'temporary' => 'временная ошибка сервера', 'limit' => 'превышен лимит сервера', 'data' => 'письмо не принято',
    'forbidden_target' => 'адрес или порт запрещён'];

if ($method === 'POST' && in_array($action, ['save_mail', 'check_mail', 'test_mail', 'process_queue'], true)) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу и попробуйте снова.';
    } elseif ($action === 'save_mail') {
        $values = ['enabled' => !empty($_POST['enabled']) ? '1' : '0', 'verify_cert' => !empty($_POST['verify_cert']) ? '1' : '0', 'alerts_enabled' => !empty($_POST['alerts_enabled']) ? '1' : '0'];
        foreach (['host', 'port', 'security', 'auth', 'username', 'from_address', 'from_name', 'timeout', 'helo', 'alert_recipients'] as $k) {
            $values[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        $fieldErrors = MailSettings::validate($values);
        $password = (string) ($_POST['password'] ?? '');
        if (preg_match('/[\r\n\0]/', $password) === 1) {
            $fieldErrors['password'] = 'Недопустимые символы.';
        }
        if ($fieldErrors !== []) {
            $flashError = 'Проверьте выделенные поля.';
            $formValues = $values;
        } else {
            $settings->save($values, $password !== '' ? $password : null);
            $events->admin($admin, $clientIp, 'mail_settings_update', true, ['host' => $values['host'], 'port' => $values['port'], 'security' => $values['security'],
                'verify_cert' => $values['verify_cert'], 'password' => $password !== '' ? 'replaced' : 'kept']);
            $flashSuccess = 'Настройки почты сохранены. Проверьте соединение.';
        }
        $password = str_repeat("\0", strlen($password));
    } elseif ($action === 'check_mail') {
        $checkResult = (new SmtpClient($settings))->check();
        $events->admin($admin, $clientIp, 'mail_check', $checkResult['ok'], ['code' => $checkResult['code'] ?? '—']);
    } elseif ($action === 'test_mail') {
        $to = trim((string) ($_POST['to'] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $flashError = 'Укажите адрес получателя.';
        } else {
            $checkResult = (new SmtpClient($settings))->check($to, 'Проверка почты LLM-провайдера', "Это тестовое письмо. Если вы его получили — отправка почты настроена правильно.\n\nВремя: " . gmdate('Y-m-d H:i') . ' UTC');
            $events->admin($admin, $clientIp, 'mail_test', $checkResult['ok'], ['code' => $checkResult['code'] ?? '—']);
            $checkResult['ok'] && $flashSuccess = 'Письмо принято сервером.';
        }
    } else {
        $n = $queue->process($settings);
        $flashSuccess = 'Обработка очереди: отправлено ' . $n . '.';
    }
}

$current = $formValues ?? $settings->all();
$f = static fn (string $k): string => View::e($current[$k] ?? '');
$err = static fn (string $k): string => isset($fieldErrors[$k]) ? '<p class="field-error">' . View::e($fieldErrors[$k]) . '</p>' : '';

ob_start();
?>
<p class="muted llm-lead">
  Почта нужна для уведомлений администраторам: модель ушла на паузу после сбоев, все модели недоступны. Пароль хранится зашифрованным и в форму не
  возвращается; при смене сервера или порта сохранённый пароль сбрасывается. Письма ждут отправки в очереди зашифрованными, временные ошибки
  повторяются до 5 раз, неверный пароль — никогда (чтобы не заблокировать учётную запись в каталоге).
</p>
<form method="post" action="index.php?section=mail&action=save_mail" autocomplete="off">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="llm-switch-row"><label class="switch"><input type="checkbox" name="enabled" value="1" <?php echo ($current['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="track"></span> Отправка почты включена</label></div>
  <div class="form-row">
    <div class="field"><label class="field-label" for="host">SMTP-сервер</label><input class="text-input" id="host" name="host" value="<?php echo $f('host'); ?>" placeholder="smtp.example.com"><?php echo $err('host'); ?></div>
    <div class="field"><label class="field-label" for="port">Порт</label><input class="text-input" type="number" id="port" name="port" value="<?php echo $f('port'); ?>"><?php echo $err('port'); ?></div>
    <div class="field"><label class="field-label" for="security">Шифрование</label>
      <select class="text-input" id="security" name="security"><?php foreach (MailSettings::SECURITY as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo ($current['security'] ?? '') === $k ? 'selected' : ''; ?>><?php echo View::e($l); ?></option><?php endforeach; ?></select><?php echo $err('security'); ?></div>
  </div>
  <div class="form-row">
    <div class="field"><label class="field-label" for="auth">Авторизация</label>
      <select class="text-input" id="auth" name="auth"><?php foreach (MailSettings::AUTH as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo ($current['auth'] ?? '') === $k ? 'selected' : ''; ?>><?php echo View::e($l); ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="field-label" for="username">Логин</label><input class="text-input" id="username" name="username" value="<?php echo $f('username'); ?>"></div>
    <div class="field"><label class="field-label" for="password">Пароль</label>
      <input class="text-input" type="password" id="password" name="password" autocomplete="new-password" placeholder="<?php echo $settings->hasPassword() ? 'сохранён — оставьте пустым, чтобы не менять' : 'хранится зашифрованным'; ?>"><?php echo $err('password'); ?></div>
  </div>
  <div class="form-row">
    <div class="field"><label class="field-label" for="from_address">Адрес отправителя</label><input class="text-input" id="from_address" name="from_address" value="<?php echo $f('from_address'); ?>" placeholder="llm-noreply@example.com"><?php echo $err('from_address'); ?></div>
    <div class="field"><label class="field-label" for="from_name">Имя отправителя</label><input class="text-input" id="from_name" name="from_name" value="<?php echo $f('from_name'); ?>"><?php echo $err('from_name'); ?></div>
    <div class="field"><label class="field-label" for="timeout">Таймаут этапа, сек.</label><input class="text-input" type="number" id="timeout" name="timeout" value="<?php echo $f('timeout'); ?>"><?php echo $err('timeout'); ?></div>
  </div>
  <div class="form-row">
    <div class="field"><label class="field-label" for="alert_recipients">Кому отправлять уведомления (через запятую)</label><input class="text-input" id="alert_recipients" name="alert_recipients" value="<?php echo $f('alert_recipients'); ?>" placeholder="it-admins@example.com"></div>
    <div class="field"><label class="field-label" for="helo">Имя для EHLO (необязательно)</label><input class="text-input" id="helo" name="helo" value="<?php echo $f('helo'); ?>" placeholder="llm.example.com"></div>
  </div>
  <div class="llm-switch-row">
    <label class="switch"><input type="checkbox" name="verify_cert" value="1" <?php echo ($current['verify_cert'] ?? '1') === '1' ? 'checked' : ''; ?>><span class="track"></span> Проверять сертификат сервера (отключать только во внутренней сети)</label>
    <label class="switch"><input type="checkbox" name="alerts_enabled" value="1" <?php echo ($current['alerts_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>><span class="track"></span> Уведомлять о проблемах моделей (не чаще раза в час на повод)</label>
  </div>
  <div class="llm-actions"><button class="btn btn-primary" type="submit">Сохранить</button>
    <button class="btn" type="submit" form="mailCheck">Проверить соединение</button></div>
</form>
<form id="mailCheck" method="post" action="index.php?section=mail&action=check_mail"><input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"></form>

<form method="post" action="index.php?section=mail&action=test_mail" class="form-row">
  <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="field"><label class="field-label" for="to">Тестовое письмо на адрес</label><input class="text-input" id="to" name="to" type="email" placeholder="you@example.com"></div>
  <div class="field field-actions"><button class="btn" type="submit">Отправить тестовое письмо</button></div>
</form>

<?php if ($checkResult !== null): ?>
<div class="alert <?php echo $checkResult['ok'] ? 'alert-success' : 'alert-error'; ?>"><?php echo $checkResult['ok'] ? 'Все этапы пройдены.' : 'Остановка: ' . View::e($codeNames[$checkResult['code']] ?? (string) $checkResult['code']) . '.'; ?></div>
<div class="llm-table-wrap"><table class="data-table">
  <thead><tr><th>Этап</th><th>Результат</th><th>Подробности</th></tr></thead>
  <tbody>
<?php foreach ($checkResult['stages'] as $st): ?>
    <tr><td><?php echo View::e($stageNames[$st['stage']] ?? $st['stage']); ?></td>
      <td class="<?php echo !empty($st['skipped']) ? 'muted' : ($st['ok'] ? 'status-ok' : 'status-bad'); ?>"><?php echo !empty($st['skipped']) ? 'не применяется' : ($st['ok'] ? 'успешно' : View::e($codeNames[$st['code']] ?? 'ошибка')); ?></td>
      <td class="mono"><?php echo View::e((string) $st['info']); ?></td></tr>
<?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<hr class="sep">
<p class="section-title">Очередь и журнал писем (30 дней)</p>
<form method="post" action="index.php?section=mail&action=process_queue" class="llm-actions"><input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><button class="btn btn-small" type="submit">Отправить ожидающие сейчас</button></form>
<div class="llm-table-wrap"><table class="data-table">
  <thead><tr><th>Создано (UTC)</th><th>Шаблон</th><th>Кому</th><th>Статус</th><th>Попыток</th><th>Ошибка</th></tr></thead>
  <tbody>
<?php $rows = $queue->recent(); if ($rows === []): ?><tr><td colspan="6" class="muted">Писем пока не было.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
    <tr><td><?php echo View::e(str_replace('T', ' ', substr((string) $r['created_at'], 0, 16))); ?></td><td><?php echo View::e((string) $r['template']); ?></td><td><?php echo View::e((string) $r['to_addr']); ?></td>
      <td><?php echo View::e(['queued' => 'ждёт', 'sending' => 'отправляется', 'sent' => 'отправлено', 'failed' => 'не отправлено'][$r['status']] ?? (string) $r['status']); ?></td>
      <td><?php echo (int) $r['attempts']; ?></td><td><?php echo View::e($codeNames[(string) $r['last_error']] ?? (string) $r['last_error']); ?></td></tr>
<?php endforeach; ?>
  </tbody>
</table></div>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'mail',
    'pageTitle' => 'Почта и уведомления',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
