<?php
/** @var string $csrfToken */
/** @var ?string $errorMessage */
use App\Http\View;
?><!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Первичная настройка</title>
<link rel="stylesheet" href="../assets/css/app.css">
</head><body>
<div class="pwd-page admin-login-page"><div class="admin-login-shell"><div class="admin-login-intro">
  <span class="admin-login-brand">Корпоративный AI-чат</span><p>Первичный запуск</p><span class="admin-login-mark" aria-hidden="true">AD</span>
</div><div class="card pwd-card admin-login-card"><div class="admin-login-heading">
  <span class="admin-login-eyebrow">Шаг 1 из 3</span><h1>Создайте аварийный доступ</h1>
  <p class="lead">Задайте пароль локального администратора <code>admin</code>. Он нужен, если домен, DNS или LDAPS временно недоступны.</p>
</div>
<div class="alert alert-warn">Открывайте эту страницу только из администраторской сети. После сохранения она станет недоступна, а пароль не отображается и не сохраняется в журналах.</div>
<?php if ($errorMessage !== null): ?><div class="alert alert-error"><?php echo View::e($errorMessage); ?></div><?php endif; ?>
<form method="post" action="setup.php"><input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
  <div class="pwd-field"><label class="field-label" for="password">Новый пароль</label><input class="text-input" id="password" name="password" type="password" autocomplete="new-password" required minlength="14" autofocus></div>
  <div class="pwd-field"><label class="field-label" for="password_confirm">Повторите пароль</label><input class="text-input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required minlength="14"></div>
  <button class="btn btn-primary btn-block" type="submit">Сохранить и продолжить</button>
</form></div></div></div><script src="../assets/js/app.js"></script></body></html>
