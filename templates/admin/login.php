<?php
/** @var string $csrfToken */
/** @var ?string $errorMessage */
/** @var ?int $retryAfter */

use App\Http\View;
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Вход — Администрирование</title>
<link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
<link rel="icon" type="image/png" href="../assets/favicon.png">
<link rel="stylesheet" href="<?php echo \App\Http\View::asset('../assets/css/app.css'); ?>">
</head>
<body>
<div class="pwd-page admin-login-page">
  <div class="admin-login-shell">
    <div class="admin-login-intro">
      <a class="admin-login-brand" href="../index.php">Корпоративный AI-чат</a>
      <p>Защищённая панель управления сервисом</p>
      <span class="admin-login-mark" aria-hidden="true">AD</span>
    </div>
    <div class="card pwd-card admin-login-card">
      <div class="admin-login-heading">
        <span class="admin-login-eyebrow">Служебный доступ</span>
        <h1>Вход в администрирование</h1>
        <p class="lead">Используйте доменную учётную запись из группы администраторов или локальный аварийный доступ.</p>
      </div>

<?php if ($errorMessage !== null): ?>
    <div class="alert alert-error"><?php echo View::e($errorMessage); ?><?php if ($retryAfter): ?> (повтор через <?php echo (int) $retryAfter; ?> сек.)<?php endif; ?></div>
<?php endif; ?>

    <form method="post" action="index.php?action=login">
      <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">

      <div class="pwd-field">
        <label class="field-label" for="method">Способ входа</label>
        <select class="text-input" id="method" name="method">
          <option value="domain">Доменная учётная запись (группа администраторов)</option>
          <option value="local">Локальный аварийный администратор</option>
        </select>
      </div>

      <div class="pwd-field">
        <label class="field-label" for="username">Логин</label>
        <input class="text-input" type="text" id="username" name="username" required autofocus autocomplete="username">
      </div>

      <div class="pwd-field">
        <label class="field-label" for="password">Пароль</label>
        <input class="text-input" type="password" id="password" name="password" required autocomplete="current-password">
      </div>

      <button class="btn btn-primary btn-block" type="submit">Войти</button>
    </form>
      <p class="pwd-footer"><a href="../index.php">← Перейти в чат</a></p>
    </div>
  </div>
</div>
<script src="<?php echo \App\Http\View::asset('../assets/js/app.js'); ?>"></script>
</body>
</html>
