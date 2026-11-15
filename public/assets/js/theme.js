/* Тема применяется до первой отрисовки, чтобы страница не мигала другой схемой.
   Порядок: выбор пользователя (localStorage 'llm:theme') > тема, закреплённая администратором (data-site-theme) > «Стекло».
   Темы две — «Стекло» и «Тёмная»; прежний выбор других тем не действует, и страница открывается в «Стекле».
   Отдельный файл, а не встроенный скрипт: CSP разрешает только script-src 'self'. Выбор темы — в app.js. */
(function () {
  var ids = ['glass', 'dark'];   // тот же список — THEMES в llmp/settings.py
  var root = document.documentElement;
  var user = null;
  try {
    user = localStorage.getItem('llm:theme');
    var old = localStorage.getItem('pwd-page-theme');   // ключ прежнего переключателя светлая/тёмная — переносится один раз
    if (old !== null) {
      if (!user && old === 'dark') { user = old; localStorage.setItem('llm:theme', old); }
      localStorage.removeItem('pwd-page-theme');
    }
  } catch (e) { /* хранилище недоступно — тема администратора или «Стекло» */ }
  var site = root.getAttribute('data-site-theme');
  var t = ids.indexOf(user) >= 0 ? user : (ids.indexOf(site) >= 0 ? site : 'glass');
  root.setAttribute('data-theme', t);
}());
