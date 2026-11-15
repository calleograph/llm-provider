<?php
/** @var string $activeSection */
/** @var string $pageTitle */
/** @var ?string $adminUsername */
/** @var ?string $flashSuccess */
/** @var ?string $flashError */

use App\Http\View;

$sectionGroups = [
    'Обзор' => [
        'status' => ['label' => 'Состояние', 'url' => 'index.php?section=status'],
        'services' => ['label' => 'Состояние сервисов', 'url' => 'index.php?section=services'],
    ],
    'LDAP' => [
        'ldap' => ['label' => 'LDAP-подключения', 'url' => 'index.php?section=ldap'],
        'access' => ['label' => 'Доступ к админ-панели', 'url' => 'index.php?section=access'],
    ],
    'Доступ к чату' => [
        'chat_access' => ['label' => 'Доступ к чату', 'url' => 'index.php?section=chat_access'],
    ],
    'Нейросети' => [
        'llm_providers' => ['label' => 'Подключения LLM API', 'url' => 'index.php?section=llm_providers'],
        'llm_models' => ['label' => 'Модели и резервирование', 'url' => 'index.php?section=llm_models'],
        'llm_limits' => ['label' => 'Категории лимитов', 'url' => 'index.php?section=llm_limits'],
    ],
    'Пользователи' => [
        'llm_users' => ['label' => 'Пользователи чата', 'url' => 'index.php?section=llm_users'],
    ],
    'Система' => [
        'anonymization' => ['label' => 'Обезличивание', 'url' => 'index.php?section=anonymization'],
        'mail' => ['label' => 'Почта и уведомления', 'url' => 'index.php?section=mail'],
        'maintenance' => ['label' => 'Обслуживание и состояние', 'url' => 'index.php?section=maintenance'],
        'log' => ['label' => 'Журналы', 'url' => 'index.php?section=log'],
        'system' => ['label' => 'Настройки', 'url' => 'index.php?section=system'],
    ],
];

// Плоская карта section => {label, url} для обогащения индекса настроек
// (см. settings_index.php) и глобального поиска в шапке.
$sectionByKey = [];
foreach ($sectionGroups as $sections) {
    foreach ($sections as $key => $section) {
        $sectionByKey[$key] = $section;
    }
}
$settingsIndex = array_values(array_filter(
    array_map(
        static function (array $entry) use ($sectionByKey): ?array {
            if (!isset($sectionByKey[$entry['section']])) {
                return null;
            }

            return $entry + [
                'sectionLabel' => $sectionByKey[$entry['section']]['label'],
                'url' => $sectionByKey[$entry['section']]['url'],
            ];
        },
        require __DIR__ . '/settings_index.php',
    ),
    static fn (?array $e): bool => $e !== null,
));
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo View::e($pageTitle); ?> — Администрирование</title>
<link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
<link rel="icon" type="image/png" href="../assets/favicon.png">
<link rel="stylesheet" href="<?php echo \App\Http\View::asset('../assets/css/app.css'); ?>">
</head>
<body>
<div class="admin-shell">
  <aside class="admin-sidebar">
    <a class="brand" href="../index.php">Корпоративный AI-чат<span>Панель управления</span></a>
    <nav class="admin-nav">
<?php foreach ($sectionGroups as $groupLabel => $sections): ?>
      <p class="admin-nav-group"><?php echo View::e($groupLabel); ?></p>
<?php foreach ($sections as $key => $section): ?>
      <a href="<?php echo View::e($section['url']); ?>" class="<?php echo $activeSection === $key ? 'active' : ''; ?>"><?php echo View::e($section['label']); ?></a>
<?php endforeach; ?>
<?php endforeach; ?>
    </nav>
  </aside>
  <main class="admin-main">
    <div class="admin-topbar">
      <h1><?php echo View::e($pageTitle); ?></h1>
      <div class="settings-search" id="settingsSearch">
        <input type="search" id="settingsSearchInput" class="text-input" placeholder="Поиск настройки… (код или название)" autocomplete="off" aria-label="Поиск настройки">
        <div class="settings-search-results" id="settingsSearchResults" hidden></div>
      </div>
      <div class="user-info">
        <span class="user-info-label">Сеанс администратора</span>
        <strong><?php echo View::e($adminUsername); ?></strong>
        <a href="index.php?action=logout">Выйти</a>
      </div>
    </div>
    <script type="application/json" id="settingsSearchIndex"><?php echo json_encode($settingsIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<?php if (!empty($flashSuccess)): ?>
    <div class="alert alert-success"><?php echo View::e($flashSuccess); ?></div>
<?php endif; ?>
<?php if (!empty($flashError)): ?>
    <div class="alert alert-error"><?php echo View::e($flashError); ?></div>
<?php endif; ?>
