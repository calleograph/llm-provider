<?php
/** @var string $csrfToken */
/** @var array<int,array{id:int,name:string,fqdn:string}> $domains */
/** @var int $selectedDomainId */
/** @var ?string $errorMessage */
/** @var string $prefillLogin */

use App\Http\View;
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Вход — корпоративный AI-чат</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="pwd-page admin-login-page">
  <div class="admin-login-shell">
    <div class="admin-login-intro">
      <span class="admin-login-brand">Корпоративный AI-чат</span>
      <p>Работа с внешними нейросетями: перед отправкой запросы и файлы обезличиваются на стороне компании.</p>
      <span class="admin-login-mark" aria-hidden="true">AI</span>
    </div>
    <div class="card pwd-card admin-login-card">
      <div class="admin-login-heading">
        <span class="admin-login-eyebrow">Доменная учётная запись</span>
        <h1>Вход в чат</h1>
        <p class="lead">Используйте логин и пароль Windows. Доступ определяется членством в доменных группах.</p>
      </div>

<?php if ($errorMessage !== null): ?>
      <div class="alert alert-error"><?php echo View::e($errorMessage); ?></div>
<?php endif; ?>

<?php if ($domains === []): ?>
      <div class="alert alert-warn">Вход в сервис ещё не настроен администратором.</div>
<?php else: ?>
      <form method="post" action="index.php?action=login" autocomplete="on">
        <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
<?php if (count($domains) > 1): ?>
        <div class="pwd-field">
          <label class="field-label" for="domain_id">Домен</label>
          <select class="text-input" id="domain_id" name="domain_id">
<?php foreach ($domains as $domain): ?>
            <option value="<?php echo (int) $domain['id']; ?>" <?php echo $domain['id'] === $selectedDomainId ? 'selected' : ''; ?>><?php echo View::e($domain['name'] . ' (' . $domain['fqdn'] . ')'); ?></option>
<?php endforeach; ?>
          </select>
        </div>
<?php else: ?>
        <input type="hidden" name="domain_id" value="<?php echo (int) $domains[0]['id']; ?>">
<?php endif; ?>
        <div class="pwd-field">
          <label class="field-label" for="username">Логин</label>
          <input class="text-input" type="text" id="username" name="username" required autofocus autocomplete="username"
                 value="<?php echo View::e($prefillLogin); ?>" placeholder="ivanov или DOMAIN\ivanov">
        </div>
        <div class="pwd-field">
          <label class="field-label" for="password">Пароль</label>
          <div class="password-field-wrap">
            <input class="text-input" type="password" id="password" name="password" required autocomplete="current-password">
            <button type="button" class="toggle-password" data-target="password">Показать</button>
          </div>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Войти</button>
      </form>
<?php endif; ?>
    </div>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
