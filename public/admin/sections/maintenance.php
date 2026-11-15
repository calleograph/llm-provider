<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminSession;
use App\Config\Paths;
use App\Http\View;
use App\Security\Csrf;

/** @var array $context */
extract($context);

/** @var \App\Llm\LlmModule $llmModule */
/** @var string $clientIp */
/** @var \PDO $pdo */

$flashSuccess = null;
$flashError = null;
$exchange = '/data/updater';
$admin = (string) AdminSession::username();
$events = $llmModule->events();
$actions = ['check' => 'Проверить обновления', 'update' => 'Обновить', 'backup' => 'Создать резервную копию', 'verify' => 'Проверить службы', 'diag' => 'Собрать диагностику'];

if ($method === 'POST' && $action === 'helper_request') {
    $want = (string) ($_POST['helper_action'] ?? '');
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        $flashError = 'Форма устарела, обновите страницу.';
    } elseif (!array_key_exists($want, $actions)) {
        $flashError = 'Недопустимое действие.';
    } elseif (!is_dir($exchange) || !is_writable($exchange)) {
        $flashError = 'Каталог обмена с помощником недоступен.';
    } elseif (is_file($exchange . '/request.txt')) {
        $flashError = 'Предыдущий запрос ещё не забран помощником.';
    } else {
        // Только белый список «ключ=значение»: помощник на хосте проверит формат ещё раз и выполнит штатный скрипт.
        $tmp = $exchange . '/.request.' . bin2hex(random_bytes(6));
        file_put_contents($tmp, 'action=' . $want . "\nid=" . bin2hex(random_bytes(8)) . "\n");
        rename($tmp, $exchange . '/request.txt');
        $events->admin($admin, $clientIp, 'maintenance_request', true, ['action' => $want]);
        $flashSuccess = 'Запрос «' . $actions[$want] . '» передан помощнику обслуживания.';
    }
}

$readJson = static fn (string $f): ?array => is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: null) : null;
$get = static function (string $url): array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
    $body = curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $json = is_string($body) ? json_decode($body, true) : null;

    return [$code, is_array($json) ? ($json['detail'] ?? $json) : []];
};
[$anonCode, $anon] = $get(rtrim((string) getenv('ANONYMIZER_URL'), '/') . '/readyz');
$asrOn = getenv('ASR_ENABLED') === '1';
[$asrCode, $asr] = $asrOn ? $get(rtrim((string) getenv('ASR_URL'), '/') . '/readyz') : [0, []];
$dbOk = $pdo->query('PRAGMA quick_check')->fetchColumn() === 'ok';
$dbSize = @filesize(Paths::database()) ?: 0;
$free = @disk_free_space(Paths::data()) ?: 0;
$total = @disk_total_space(Paths::data()) ?: 1;
$host = $readJson($exchange . '/host-status.json');
$helper = $readJson($exchange . '/helper.json');
$helperAlive = $helper !== null && (time() - (int) strtotime((string) ($helper['at'] ?? ''))) < 120;
$status = $readJson($exchange . '/status.json');
$history = is_file($exchange . '/history.jsonl') ? array_reverse(array_map(static fn (string $l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($exchange . '/history.jsonl'))))) : [];
$lastUpdate = null;
foreach ($history as $h) {
    if (($h['action'] ?? '') === 'update' && ($h['result'] ?? '') === 'ok') {
        $lastUpdate = $h;
        break;
    }
}
$models = $llmModule->models()->all();
$health = $llmModule->modelHealth()->stats();
$cooling = count(array_filter($models, static fn (array $m): bool => (int) ($health[$m['id']]['cooldown_until'] ?? 0) > time()));
$mail = $llmModule->mailSettings();
$errors = $pdo->query("SELECT created_at, source, event_code, message FROM diagnostic_log WHERE level = 'error' ORDER BY id DESC LIMIT 15")->fetchAll();
$tile = static fn (string $label, string $value, string $class = '', string $sub = ''): string => '<div class="stat-tile"><div class="label">' . View::e($label) . '</div><div class="value ' . $class . '">' . View::e($value) . '</div>' . ($sub !== '' ? '<div class="muted">' . View::e($sub) . '</div>' : '') . '</div>';
$gb = static fn (float $b): string => number_format($b / 1073741824, 1, ',', ' ') . ' ГБ';

ob_start();
?>
<p class="muted llm-lead">Состояние всех частей LLM-провайдера. Действия с контейнерами выполняет помощник обслуживания на сервере
  (служба systemd, белый список действий); у веб-приложения нет доступа к Docker и команде root.</p>

<div class="grid grid-3 llm-status-row">
<?php
echo $tile('Приложение (PHP)', (string) (getenv('APP_VERSION') ?: '—'), 'status-ok', 'PHP ' . PHP_VERSION . ' · ' . substr((string) (getenv('APP_GIT_COMMIT') ?: ''), 0, 12));
echo $tile('Обезличивание', $anonCode === 200 ? 'Готово' : 'Недоступно — чат заблокирован', $anonCode === 200 ? 'status-ok' : 'status-bad',
    $anonCode === 200 ? 'модели за ' . (int) ($anon['load_ms'] ?? 0) . ' мс · запросов ' . (int) ($anon['requests'] ?? 0) . ' · отказов ' . (int) ($anon['failures'] ?? 0) : 'HTTP ' . $anonCode);
echo $tile('Распознавание речи', !$asrOn ? 'Выключено' : ($asrCode === 200 ? 'Готово' : 'Недоступно'), !$asrOn ? 'status-warn' : ($asrCode === 200 ? 'status-ok' : 'status-bad'),
    $asrOn ? (string) ($asr['active_model'] ?? '') . ' · очередь ' . (int) ($asr['queue_depth'] ?? 0) . ' · диктовок ' . (int) ($asr['dictation']['active'] ?? 0) : 'голосовой ввод не установлен');
echo $tile('Redis', !$asrOn ? 'Не используется' : (!empty($asr['redis_ok']) ? 'Доступен' : 'Нет связи'), !$asrOn ? 'status-warn' : (!empty($asr['redis_ok']) ? 'status-ok' : 'status-bad'));
echo $tile('База данных', $dbOk ? 'В порядке' : 'Ошибка целостности', $dbOk ? 'status-ok' : 'status-bad', 'SQLite · ' . number_format($dbSize / 1048576, 1, ',', ' ') . ' МБ');
echo $tile('Модели', count($models) . ' настроено', $cooling > 0 ? 'status-warn' : 'status-ok', $cooling > 0 ? $cooling . ' на паузе после сбоев' : 'все в работе');
echo $tile('Почта', $mail->isConfigured() ? 'Настроена' : 'Не настроена', $mail->isConfigured() ? 'status-ok' : 'status-warn');
echo $tile('Диск данных', 'Свободно ' . $gb((float) $free), $free / $total < 0.1 ? 'status-bad' : 'status-ok', 'из ' . $gb((float) $total));
echo $tile('Последнее обновление', $lastUpdate !== null ? str_replace('T', ' ', substr((string) $lastUpdate['at'], 0, 16)) . ' UTC' : 'не выполнялось', '', $lastUpdate !== null ? (string) ($lastUpdate['from'] ?? '') . ' → ' . (string) ($lastUpdate['to'] ?? '') : '');
?>
</div>

<p class="section-title">Контейнеры и ресурсы</p>
<?php if ($host === null): ?>
<div class="alert alert-warn">Помощник обслуживания не установлен или не отвечает — состояние контейнеров недоступно. Установка на сервере: <code>sudo scripts/updater.sh install</code>.</div>
<?php else: ?>
<p class="muted maint-meta">Обновлено <?php echo View::e(str_replace('T', ' ', substr((string) $host['at'], 0, 19))); ?> UTC · нагрузка <?php echo View::e((string) $host['load']); ?> ·
  память свободно <?php echo View::e((string) ($host['memory']['available_gb'] ?? '?')); ?> из <?php echo View::e((string) ($host['memory']['total_gb'] ?? '?')); ?> ГБ</p>
<div class="llm-table-wrap"><table class="data-table">
  <thead><tr><th>Служба</th><th>Состояние</th><th>CPU</th><th>Память</th><th>Образ</th></tr></thead>
  <tbody>
<?php $stats = []; foreach ((array) ($host['stats'] ?? []) as $s) { $stats[(string) $s['name']] = $s; }
foreach ((array) $host['services'] as $s): $st = null; foreach ($stats as $n => $v) { if (str_contains($n, '-' . $s['service'] . '-')) { $st = $v; } } ?>
    <tr><td><?php echo View::e((string) $s['service']); ?></td>
      <td class="<?php echo ($s['state'] ?? '') === 'running' && in_array($s['health'] ?? '', ['healthy', ''], true) ? 'status-ok' : 'status-bad'; ?>"><?php echo View::e((string) $s['status']); ?></td>
      <td><?php echo View::e((string) ($st['cpu'] ?? '—')); ?></td><td><?php echo View::e((string) ($st['mem'] ?? '—')); ?></td><td class="mono maint-image"><?php echo View::e((string) preg_replace('/@sha256:[0-9a-f]+$/', '', (string) $s['image'])); ?></td></tr>
<?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<p class="section-title">Действия</p>
<div class="maint-actions">
<?php foreach ($actions as $key => $label): ?>
  <form method="post" action="index.php?section=maintenance&action=helper_request"<?php echo $key === 'update' ? ' data-confirm="Обновить приложение? Перед обновлением создаётся резервная копия; при сбое — автоматический откат."' : ''; ?>>
    <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>"><input type="hidden" name="helper_action" value="<?php echo $key; ?>">
    <button class="btn<?php echo $key === 'update' ? ' btn-primary' : ''; ?>" type="submit"<?php echo $helperAlive ? '' : ' disabled title="Помощник обслуживания не отвечает"'; ?>><?php echo View::e($label); ?></button>
  </form>
<?php endforeach; ?>
</div>
<?php if ($status !== null): ?>
<div class="alert <?php echo ['done' => 'alert-success', 'failed' => 'alert-error'][$status['state']] ?? 'alert-warn'; ?>">
  <?php echo View::e(($actions[$status['action']] ?? (string) $status['action']) . ': ' . ['running' => 'выполняется', 'done' => 'готово', 'failed' => 'ошибка'][$status['state']] ?? (string) $status['state']); ?>
  (<?php echo View::e(str_replace('T', ' ', substr((string) $status['at'], 0, 19))); ?> UTC) — <?php echo View::e((string) $status['message']); ?>
</div>
<?php endif; ?>

<p class="section-title">Последние ошибки</p>
<div class="llm-table-wrap"><table class="data-table">
  <thead><tr><th>Когда (UTC)</th><th>Источник</th><th>Этап</th><th>Причина</th></tr></thead>
  <tbody>
<?php if ($errors === []): ?><tr><td colspan="4" class="muted">Ошибок нет.</td></tr><?php endif; ?>
<?php foreach ($errors as $e): ?>
    <tr><td><?php echo View::e(str_replace('T', ' ', substr((string) $e['created_at'], 0, 16))); ?></td><td><?php echo View::e((string) $e['source']); ?></td>
      <td class="mono"><?php echo View::e((string) $e['event_code']); ?></td><td><?php echo View::e(mb_substr((string) $e['message'], 0, 200)); ?></td></tr>
<?php endforeach; ?>
  </tbody>
</table></div>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'maintenance',
    'pageTitle' => 'Обслуживание и состояние',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => $flashSuccess,
    'flashError' => $flashError,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
