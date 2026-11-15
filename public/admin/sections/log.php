<?php

declare(strict_types=1);

namespace App;

use App\Audit\AppLogger;
use App\Audit\UnifiedEventLog;
use App\Auth\AdminSession;
use App\Diagnostics\DiagnosticLevel;
use App\Diagnostics\DiagnosticSource;
use App\Http\View;

/** @var array $context */
extract($context);

/** @var \App\Audit\AuditLogger $auditLogger */
/** @var \App\Diagnostics\DiagnosticLogger $diagnosticLogger */
$appLogger = new AppLogger($pdo);
$unifiedLog = new UnifiedEventLog($pdo);

$tab = $_GET['tab'] ?? 'all';
if (!in_array($tab, ['all', 'operations', 'audit', 'diagnostics'], true)) {
    $tab = 'all';
}

const PER_PAGE = 50;

$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = (string) ($_GET['sort'] ?? 'created_at');
$dir = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$search = trim((string) ($_GET['search'] ?? ''));

/**
 * @param array<string,mixed> $query
 * @return array{level?:string,source?:string,search?:string,since?:string,until?:string}
 */
function buildDiagnosticFilters(array $query): array
{
    $filters = [];
    if (!empty($query['level'])) {
        $filters['level'] = (string) $query['level'];
    }
    if (!empty($query['source'])) {
        $filters['source'] = (string) $query['source'];
    }
    if (!empty($query['search'])) {
        $filters['search'] = (string) $query['search'];
    }
    if (!empty($query['since'])) {
        $filters['since'] = (string) $query['since'];
    }
    if (!empty($query['until'])) {
        $filters['until'] = (string) $query['until'];
    }

    return $filters;
}

function sortLink(string $column, string $label, string $currentSort, string $currentDir, string $tab, array $extraQuery = []): string
{
    $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = $currentSort === $column ? ($currentDir === 'asc' ? ' ↑' : ' ↓') : '';
    $query = array_merge($extraQuery, ['section' => 'log', 'tab' => $tab, 'sort' => $column, 'dir' => $nextDir]);

    return '<a href="index.php?' . http_build_query($query) . '" style="color:inherit;text-decoration:none;">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $arrow . '</a>';
}

function outcomeIcon(string $outcome): string
{
    return match ($outcome) {
        'success' => '<span class="status-ok" title="Успех">●</span>',
        'warning' => '<span class="status-warn" title="Требует внимания">●</span>',
        default => '<span class="status-bad" title="Ошибка">●</span>',
    };
}

function categoryLabel(string $category): string
{
    return match ($category) {
        'password_change' => 'Прочие операции',
        'llm_auth' => 'Вход в чат',
        'llm_chat' => 'Переписка и лимиты',
        'llm_anonymization' => 'Обезличивание',
        'llm_file' => 'Файлы',
        'admin_action' => 'Админ. действие',
        'diagnostic' => 'Диагностика',
        default => $category,
    };
}

/** @return array<string,string> value => label, для выпадающего списка фильтра. */
function categoryFilterOptions(): array
{
    return [
        'password_change' => 'Прочие операции',
        'llm_auth' => 'Нейросети — вход в чат',
        'llm_chat' => 'Нейросети — переписка и лимиты',
        'llm_anonymization' => 'Нейросети — обезличивание',
        'llm_file' => 'Нейросети — файлы',
        'admin_action' => 'Административные действия',
        'diagnostic' => 'Диагностика',
    ];
}

// ---- Экспорт в TXT (до какого-либо HTML-вывода) ----
if ($action === 'export') {
    $exportTab = (string) ($_GET['export_tab'] ?? $tab);
    if (!in_array($exportTab, ['all', 'operations', 'audit', 'diagnostics'], true)) {
        $exportTab = 'all';
    }
    $filename = 'log_' . $exportTab . '_' . gmdate('Ymd_His') . '.txt';

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    if ($exportTab === 'audit') {
        foreach ($auditLogger->recent(5000) as $row) {
            echo sprintf(
                "%s\t%s\t%s\t%s\t%s\t%s\n",
                $row['created_at'],
                $row['actor'],
                $row['ip'],
                $row['action'],
                (string) $row['details'],
                ((int) $row['success']) === 1 ? 'success' : 'failure',
            );
        }
    } elseif ($exportTab === 'diagnostics') {
        $filters = buildDiagnosticFilters($_GET);
        $filters['limit'] = 5000;
        $result = $diagnosticLogger->query($filters);
        foreach ($result['rows'] as $row) {
            echo sprintf(
                "%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
                $row['created_at'],
                $row['level'],
                $row['source'],
                $row['event_code'],
                $row['message'],
                (string) $row['context'],
                (string) $row['ip'],
            );
        }
    } elseif ($exportTab === 'operations') {
        foreach ($appLogger->recent(5000) as $row) {
            echo sprintf(
                "%s\t%s\t%s\t%s\t%s\n",
                $row['created_at'],
                $row['ip'],
                (string) $row['username'],
                $row['operation'],
                $row['result'],
            );
        }
    } else {
        $result = $unifiedLog->query(['search' => $search !== '' ? $search : null, 'limit' => 5000]);
        foreach ($result['rows'] as $row) {
            echo sprintf(
                "%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
                $row['created_at'],
                categoryLabel($row['category']),
                $row['outcome'],
                (string) $row['actor'],
                (string) $row['ip'],
                $row['event_type'],
                str_replace(["\t", "\n"], ' ', $row['summary'] . ' ' . (string) $row['details']),
            );
        }
    }
    exit;
}

$diagnosticFilters = buildDiagnosticFilters($_GET);
$categoryFilterOptions = categoryFilterOptions();

if ($tab === 'all') {
    $pageResult = $unifiedLog->query([
        'search' => $search !== '' ? $search : null,
        'category' => $_GET['category'] ?? null,
        'limit' => PER_PAGE,
        'offset' => ($page - 1) * PER_PAGE,
    ]);
} elseif ($tab === 'operations') {
    $pageResult = $appLogger->paginated($page, PER_PAGE, $sort, $dir);
} elseif ($tab === 'audit') {
    $pageResult = $auditLogger->paginated($page, PER_PAGE, $sort, $dir);
} else {
    $diagFilters = $diagnosticFilters;
    $diagFilters['limit'] = PER_PAGE;
    $diagFilters['offset'] = ($page - 1) * PER_PAGE;
    $diagFilters['sort'] = $sort;
    $diagFilters['dir'] = $dir;
    $pageResult = $diagnosticLogger->query($diagFilters);
}

$totalPages = max(1, (int) ceil($pageResult['total'] / PER_PAGE));

ob_start();
?>
<div class="llm-toolbar log-tabs">
  <div class="log-tab-links">
    <a class="btn btn-small <?php echo $tab === 'all' ? 'btn-primary' : ''; ?>" href="index.php?section=log&tab=all">Все события</a>
    <a class="btn btn-small <?php echo $tab === 'operations' ? 'btn-primary' : ''; ?>" href="index.php?section=log&tab=operations">Операции пользователей</a>
    <a class="btn btn-small <?php echo $tab === 'audit' ? 'btn-primary' : ''; ?>" href="index.php?section=log&tab=audit">Административный аудит</a>
    <a class="btn btn-small <?php echo $tab === 'diagnostics' ? 'btn-primary' : ''; ?>" href="index.php?section=log&tab=diagnostics">Диагностика связи</a>
  </div>
  <a class="btn btn-small" href="index.php?section=log&action=export&export_tab=<?php echo View::e($tab); ?>&search=<?php echo urlencode($search); ?><?php
    foreach ($diagnosticFilters as $key => $value) {
        echo '&' . urlencode($key) . '=' . urlencode($value);
    }
  ?>">Скачать .txt</a>
</div>

<?php if ($tab === 'all'): ?>
<form method="get" action="index.php" class="form-row log-filter-row">
  <input type="hidden" name="section" value="log">
  <input type="hidden" name="tab" value="all">
  <div class="field">
    <label class="field-label">Поиск по логину / IP / тексту события</label>
    <input class="text-input" name="search" value="<?php echo View::e($search); ?>" placeholder="например: ivanov или 10.0.0.10.176">
  </div>
  <div class="field">
    <label class="field-label">Категория</label>
    <select class="text-input" name="category">
      <option value="">Все категории</option>
<?php foreach ($categoryFilterOptions as $value => $label): ?>
      <option value="<?php echo View::e($value); ?>" <?php echo ($_GET['category'] ?? '') === $value ? 'selected' : ''; ?>><?php echo View::e($label); ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field log-filter-submit">
    <button class="btn" type="submit">Найти</button>
  </div>
</form>
<p class="muted" style="font-size:12px;">
  Единая лента: работа пользователей с нейросетями, действия администраторов и техническая диагностика — всё
  вместе по времени. Найдите конкретную учётную запись поиском выше, чтобы увидеть всю её историю сразу.
</p>
<?php endif; ?>

<p class="muted" style="font-size:12px;">Всего записей: <?php echo $pageResult['total']; ?>. Страница <?php echo $page; ?> из <?php echo $totalPages; ?>.</p>

<?php if ($tab === 'all'): ?>
<table class="data-table">
  <thead><tr><th></th><th>Дата</th><th>Категория</th><th>Кто / откуда</th><th>Событие</th><th>Подробности</th></tr></thead>
  <tbody>
<?php foreach ($pageResult['rows'] as $row): ?>
    <tr>
      <td><?php echo outcomeIcon($row['outcome']); ?></td>
      <td class="mono"><?php echo View::e($row['created_at']); ?></td>
      <td><span class="badge badge-inactive"><?php echo View::e(categoryLabel($row['category'])); ?></span></td>
      <td><?php echo View::e((string) ($row['actor'] ?? '—')); ?> <span class="muted mono" style="font-size:11px;"><?php echo View::e((string) $row['ip']); ?></span></td>
      <td><?php echo View::e($row['event_type']); ?></td>
      <td style="max-width:340px;word-break:break-word;"><?php echo View::e($row['summary']); ?><?php if (!empty($row['details'])): ?><br><span class="muted mono" style="font-size:11px;"><?php echo View::e((string) $row['details']); ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($pageResult['rows'] === []): ?>
    <tr><td colspan="6" class="muted">Записей не найдено.</td></tr>
<?php endif; ?>
  </tbody>
</table>
<?php elseif ($tab === 'operations'): ?>
<table class="data-table">
  <thead><tr>
    <th></th>
    <th><?php echo sortLink('created_at', 'Дата', $sort, $dir, 'operations'); ?></th>
    <th><?php echo sortLink('ip', 'IP', $sort, $dir, 'operations'); ?></th>
    <th><?php echo sortLink('username', 'Логин', $sort, $dir, 'operations'); ?></th>
    <th><?php echo sortLink('operation', 'Операция', $sort, $dir, 'operations'); ?></th>
    <th><?php echo sortLink('result', 'Результат', $sort, $dir, 'operations'); ?></th>
  </tr></thead>
  <tbody>
<?php foreach ($pageResult['rows'] as $row):
    $isSuccess = str_contains($row['result'], 'SUCCESS') || str_starts_with($row['result'], 'success');
    $isWarning = str_contains($row['result'], 'REQUIRED');
?>
    <tr>
      <td><?php echo outcomeIcon($isSuccess ? 'success' : ($isWarning ? 'warning' : 'failure')); ?></td>
      <td class="mono"><?php echo View::e($row['created_at']); ?></td>
      <td class="mono"><?php echo View::e($row['ip']); ?></td>
      <td><?php echo View::e((string) $row['username']); ?></td>
      <td><?php echo View::e($row['operation']); ?></td>
      <td><?php echo $isSuccess ? '<span class="status-ok">' . View::e($row['result']) . '</span>' : ($isWarning ? '<span class="status-warn">' . View::e($row['result']) . '</span>' : '<span class="status-bad">' . View::e($row['result']) . '</span>'); ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($pageResult['rows'] === []): ?>
    <tr><td colspan="6" class="muted">Записей пока нет.</td></tr>
<?php endif; ?>
  </tbody>
</table>
<?php elseif ($tab === 'audit'): ?>
<table class="data-table">
  <thead><tr>
    <th></th>
    <th><?php echo sortLink('created_at', 'Дата', $sort, $dir, 'audit'); ?></th>
    <th><?php echo sortLink('actor', 'Кто', $sort, $dir, 'audit'); ?></th>
    <th><?php echo sortLink('ip', 'Откуда', $sort, $dir, 'audit'); ?></th>
    <th><?php echo sortLink('action', 'Действие', $sort, $dir, 'audit'); ?></th>
    <th>Детали</th>
    <th><?php echo sortLink('success', 'Результат', $sort, $dir, 'audit'); ?></th>
  </tr></thead>
  <tbody>
<?php foreach ($pageResult['rows'] as $row): ?>
    <tr>
      <td><?php echo outcomeIcon(((int) $row['success']) === 1 ? 'success' : 'failure'); ?></td>
      <td class="mono"><?php echo View::e($row['created_at']); ?></td>
      <td><?php echo View::e($row['actor']); ?></td>
      <td class="mono"><?php echo View::e($row['ip']); ?></td>
      <td><?php echo View::e($row['action']); ?></td>
      <td class="mono"><?php echo View::e((string) $row['details']); ?></td>
      <td><?php echo ((int) $row['success']) === 1 ? '<span class="status-ok">успех</span>' : '<span class="status-bad">ошибка</span>'; ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($pageResult['rows'] === []): ?>
    <tr><td colspan="7" class="muted">Записей пока нет.</td></tr>
<?php endif; ?>
  </tbody>
</table>
<?php else: ?>
<form method="get" action="index.php" class="form-row log-filter-row">
  <input type="hidden" name="section" value="log">
  <input type="hidden" name="tab" value="diagnostics">
  <div class="field">
    <label class="field-label">Уровень</label>
    <select class="text-input" name="level">
      <option value="">Все</option>
<?php foreach (DiagnosticLevel::ALL as $level): ?>
      <option value="<?php echo $level; ?>" <?php echo ($diagnosticFilters['level'] ?? '') === $level ? 'selected' : ''; ?>><?php echo $level; ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label class="field-label">Источник</label>
    <select class="text-input" name="source">
      <option value="">Все</option>
<?php foreach (DiagnosticSource::ALL as $source): ?>
      <option value="<?php echo $source; ?>" <?php echo ($diagnosticFilters['source'] ?? '') === $source ? 'selected' : ''; ?>><?php echo $source; ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label class="field-label">Поиск по тексту</label>
    <input class="text-input" name="search" value="<?php echo View::e($diagnosticFilters['search'] ?? ''); ?>" placeholder="код ошибки, host, сообщение…">
  </div>
  <div class="field">
    <label class="field-label">С даты (ISO 8601)</label>
    <input class="text-input" name="since" value="<?php echo View::e($diagnosticFilters['since'] ?? ''); ?>" placeholder="2026-09-01T00:00:00Z">
  </div>
  <div class="field">
    <label class="field-label">По дату (ISO 8601)</label>
    <input class="text-input" name="until" value="<?php echo View::e($diagnosticFilters['until'] ?? ''); ?>" placeholder="2026-09-16T23:59:59Z">
  </div>
  <div class="field log-filter-submit">
    <button class="btn" type="submit">Применить фильтр</button>
  </div>
</form>

<p class="muted" style="font-size:12px;">Значения паролей, API-ключей и тексты запросов/файлов в этом журнале не сохраняются никогда.</p>

<table class="data-table">
  <thead><tr>
    <th></th>
    <th><?php echo sortLink('created_at', 'Дата', $sort, $dir, 'diagnostics', $diagnosticFilters); ?></th>
    <th><?php echo sortLink('level', 'Уровень', $sort, $dir, 'diagnostics', $diagnosticFilters); ?></th>
    <th><?php echo sortLink('source', 'Источник', $sort, $dir, 'diagnostics', $diagnosticFilters); ?></th>
    <th><?php echo sortLink('event_code', 'Код', $sort, $dir, 'diagnostics', $diagnosticFilters); ?></th>
    <th>Сообщение</th>
    <th>Контекст</th>
    <th>IP</th>
  </tr></thead>
  <tbody>
<?php foreach ($pageResult['rows'] as $row): ?>
    <tr>
      <td><?php echo outcomeIcon($row['level'] === 'error' ? 'failure' : ($row['level'] === 'warning' ? 'warning' : 'success')); ?></td>
      <td class="mono"><?php echo View::e($row['created_at']); ?></td>
      <td>
<?php
$levelClass = match ($row['level']) {
    'error' => 'status-bad',
    'warning' => 'status-warn',
    default => 'muted',
};
?>
        <span class="<?php echo $levelClass; ?>"><?php echo View::e($row['level']); ?></span>
      </td>
      <td><?php echo View::e($row['source']); ?></td>
      <td class="mono"><?php echo View::e($row['event_code']); ?></td>
      <td style="max-width:320px;word-break:break-word;"><?php echo View::e($row['message']); ?></td>
      <td class="mono" style="max-width:220px;word-break:break-all;"><?php echo View::e((string) $row['context']); ?></td>
      <td class="mono"><?php echo View::e((string) $row['ip']); ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($pageResult['rows'] === []): ?>
    <tr><td colspan="8" class="muted">Записей не найдено.</td></tr>
<?php endif; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
<div style="margin-top:16px;display:flex;gap:8px;align-items:center;justify-content:center;">
<?php
$pageQuery = array_merge($diagnosticFilters, ['section' => 'log', 'tab' => $tab, 'sort' => $sort, 'dir' => $dir, 'search' => $search]);
$prevQuery = array_merge($pageQuery, ['page' => max(1, $page - 1)]);
$nextQuery = array_merge($pageQuery, ['page' => min($totalPages, $page + 1)]);
?>
  <a class="btn btn-small<?php echo $page <= 1 ? ' muted' : ''; ?>" href="index.php?<?php echo http_build_query($prevQuery); ?>">← Назад</a>
  <span class="muted" style="font-size:12.5px;">Страница <?php echo $page; ?> из <?php echo $totalPages; ?></span>
  <a class="btn btn-small<?php echo $page >= $totalPages ? ' muted' : ''; ?>" href="index.php?<?php echo http_build_query($nextQuery); ?>">Вперёд →</a>
</div>
<?php endif; ?>
<?php
$sectionHtml = ob_get_clean();

echo View::render(__DIR__ . '/../../../templates/admin/layout_top.php', [
    'activeSection' => 'log',
    'pageTitle' => 'Журналы',
    'adminUsername' => AdminSession::username(),
    'flashSuccess' => null,
    'flashError' => null,
]);
echo $sectionHtml;
echo View::render(__DIR__ . '/../../../templates/admin/layout_bottom.php', []);
