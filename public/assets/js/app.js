document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.toggle-password').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-target');
      var input = document.getElementById(targetId);
      if (!input) {
        return;
      }
      var isHidden = input.getAttribute('type') === 'password';
      input.setAttribute('type', isHidden ? 'text' : 'password');
      btn.textContent = isHidden ? 'Скрыть' : 'Показать';
    });
  });

  initConfirmModal();
  initUpdateTerminal();
  // «Все на странице» в ленте аудита: отмечает галочки записей своей формы
  document.querySelectorAll('[data-check-all]').forEach(function (box) {
    box.addEventListener('change', function () {
      box.form.querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = box.checked; });
    });
  });
  initDraftAutosave();
  initThemeToggle();
  initCommandCopy();
  initRevealButtons();
  initAddRowButtons();
  initProviderTypeDefaults();
  initLimitCategoryToggle();
  initSettingsSearch();
  highlightSettingsAnchor();
});

/*
 * Глобальный поиск настроек в шапке админки (как в Moodle): индекс
 * (section/anchor/code/label) отрисован сервером в #settingsSearchIndex,
 * фильтрация — на клиенте по подстроке в коде/названии/разделе. Выбор
 * результата переходит на нужный раздел с #anchor — там же
 * highlightSettingsAnchor() (см. ниже) подсвечивает конкретное поле.
 */
function initSettingsSearch() {
  var input = document.getElementById('settingsSearchInput');
  var results = document.getElementById('settingsSearchResults');
  var indexEl = document.getElementById('settingsSearchIndex');
  if (!input || !results || !indexEl) return;

  var entries = [];
  try {
    entries = JSON.parse(indexEl.textContent || '[]');
  } catch (e) {
    return;
  }

  function render(matches) {
    results.textContent = '';
    if (matches.length === 0) {
      results.hidden = true;
      return;
    }
    matches.slice(0, 12).forEach(function (entry) {
      var a = document.createElement('a');
      a.className = 'settings-search-item';
      a.href = entry.url + '#' + entry.anchor;
      var code = document.createElement('span');
      code.className = 'settings-search-item-code';
      code.textContent = entry.code;
      var label = document.createElement('span');
      label.className = 'settings-search-item-label';
      label.textContent = entry.label;
      var section = document.createElement('span');
      section.className = 'settings-search-item-section';
      section.textContent = entry.sectionLabel;
      a.appendChild(code);
      a.appendChild(label);
      a.appendChild(section);
      results.appendChild(a);
    });
    results.hidden = false;
  }

  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    if (q === '') {
      render([]);
      return;
    }
    var matches = entries.filter(function (entry) {
      return entry.code.toLowerCase().indexOf(q) !== -1
        || entry.label.toLowerCase().indexOf(q) !== -1
        || entry.sectionLabel.toLowerCase().indexOf(q) !== -1;
    });
    render(matches);
  });

  input.addEventListener('focus', function () {
    if (input.value.trim() !== '') input.dispatchEvent(new Event('input'));
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('#settingsSearch')) {
      results.hidden = true;
    }
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      results.hidden = true;
      input.blur();
    }
  });
}

/*
 * После перехода из поиска (URL с #anchor) — прокрутка к полю и краткая
 * подсветка обёртки .field, чтобы администратор сразу увидел, куда
 * попал, а не искал глазами среди похожих полей формы.
 */
function highlightSettingsAnchor() {
  var hash = window.location.hash.replace('#', '');
  if (!hash) return;
  var target = document.getElementById(hash);
  if (!target) return;
  var wrap = target.closest('.field') || target;
  wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
  wrap.setAttribute('data-settings-highlight', '1');
  setTimeout(function () { wrap.removeAttribute('data-settings-highlight'); }, 2200);
}

/* «Пользователи чата»: поля периода/токенов вручную показываются только при выборе «Вручную…». */
function initLimitCategoryToggle() {
  document.querySelectorAll('[data-limit-category]').forEach(function (select) {
    var fields = select.closest('form').querySelector('[data-limit-manual-fields]');
    if (!fields) return;
    select.addEventListener('change', function () {
      fields.hidden = select.value !== 'manual';
    });
  });

  document.querySelectorAll('[data-storage-unlimited]').forEach(function (checkbox) {
    var input = checkbox.closest('.field').querySelector('[data-storage-input]');
    if (!input) return;
    checkbox.addEventListener('change', function () {
      input.disabled = checkbox.checked;
    });
  });
}

/* Кнопки «+» в админке: раскрывают скрытый блок новой записи (домен,
   нейросеть) и прячутся сами. */
function initRevealButtons() {
  document.querySelectorAll('[data-reveal]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-reveal'));
      if (!target) {
        return;
      }
      target.hidden = false;
      button.hidden = true;
      var first = target.querySelector('input:not([type="hidden"]), select, textarea');
      if (first) {
        first.focus();
      }
    });
  });
}

/* «+ Добавить группу»: клонирует строку из <template>, подставляя уникальный
   индекс в имена полей (new_groups[__INDEX__][...]). */
function initAddRowButtons() {
  var counter = 0;
  document.querySelectorAll('[data-add-row]').forEach(function (button) {
    button.addEventListener('click', function () {
      var template = document.getElementById(button.getAttribute('data-add-row'));
      var target = document.getElementById(button.getAttribute('data-add-row-target'));
      if (!template || !target) {
        return;
      }
      counter++;
      var fragment = template.content.cloneNode(true);
      fragment.querySelectorAll('[name]').forEach(function (field) {
        field.setAttribute('name', field.getAttribute('name').replace('__INDEX__', 'n' + counter));
      });
      var row = fragment.firstElementChild;
      target.appendChild(fragment);
      if (row) {
        var first = row.querySelector('input:not([type="checkbox"])');
        if (first) {
          first.focus();
        }
      }
    });
  });
  document.addEventListener('click', function (e) {
    var remove = e.target.closest ? e.target.closest('[data-remove-row]') : null;
    if (remove) {
      var row = remove.closest('tr');
      if (row) {
        row.remove();
      }
    }
  });
}

/* Раздел «Нейросети»: при смене типа провайдера подставляет Base URL,
   endpoint и версию API по умолчанию — только в поля, которые пусты или
   ещё содержат значение по умолчанию прежнего типа. */
function initProviderTypeDefaults() {
  document.querySelectorAll('select[data-provider-type]').forEach(function (select) {
    var defaults;
    try {
      defaults = JSON.parse(select.getAttribute('data-defaults') || '{}');
    } catch (e) {
      return;
    }
    var form = select.closest('form');
    var previous = select.value;
    select.addEventListener('change', function () {
      var oldDefaults = defaults[previous] || {};
      var newDefaults = defaults[select.value] || {};
      form.querySelectorAll('[data-default-field]').forEach(function (field) {
        var key = field.getAttribute('data-default-field');
        if (field.value === '' || field.value === (oldDefaults[key] || '')) {
          field.value = newDefaults[key] || '';
        }
        field.setAttribute('placeholder', newDefaults[key] || '');
      });
      form.querySelectorAll('[data-default-placeholder]').forEach(function (field) {
        field.setAttribute('placeholder', newDefaults[field.getAttribute('data-default-placeholder')] || '');
      });
      var routingRow = form.querySelector('[data-routing-provider-row]');
      if (routingRow) {
        routingRow.hidden = select.value !== 'openai_compatible';
      }
      previous = select.value;
    });
  });
}

/* Команды в административных инструкциях копируются без заголовков и
   пояснений. Fallback нужен для HTTP/старых браузеров без Clipboard API. */
function initCommandCopy() {
  document.querySelectorAll('[data-copy-command]').forEach(function (button) {
    button.addEventListener('click', function () {
      var value = button.getAttribute('data-copy-command') || '';
      if (!value) return;

      function done() {
        var original = button.textContent;
        button.textContent = 'Скопировано';
        button.classList.add('copied');
        window.setTimeout(function () {
          button.textContent = original;
          button.classList.remove('copied');
        }, 1600);
      }

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(done).catch(function () { fallbackCopy(value, done); });
      } else {
        fallbackCopy(value, done);
      }
    });
  });
}

function fallbackCopy(value, done) {
  var area = document.createElement('textarea');
  area.value = value;
  area.setAttribute('readonly', '');
  area.style.position = 'fixed';
  area.style.opacity = '0';
  document.body.appendChild(area);
  area.select();
  var copied = false;
  try { copied = document.execCommand('copy'); } catch (err) { copied = false; }
  document.body.removeChild(area);
  if (copied) {
    done();
    return;
  }
  /* Последний fallback для строгих браузерных политик: команда появляется в
     нативном поле браузера, где её можно скопировать Ctrl+C без искажений. */
  window.prompt('Браузер запретил доступ к буферу. Скопируйте команду вручную:', value);
}

/**
 * Тема оформления: одна кнопка солнце/луна (светлая «Стекло» / «Тёмная»). Применяется ко всей странице (data-theme на <html>),
 * запоминается в localStorage 'llm:theme' только в этом браузере. Пока пользователь не выбрал сам, действует тема
 * администратора или «Стекло» — см. theme.js.
 */
function initThemeToggle() {
  var buttons = document.querySelectorAll('[data-theme-switch]');
  var root = document.documentElement;
  function render() {
    var dark = root.getAttribute('data-theme') === 'dark';
    buttons.forEach(function (b) { b.setAttribute('aria-pressed', String(dark)); });
  }
  render();
  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      var theme = root.getAttribute('data-theme') === 'dark' ? 'glass' : 'dark';
      root.setAttribute('data-theme', theme);
      try { localStorage.setItem('llm:theme', theme); } catch (err) { /* приватный режим — тема просто не запомнится */ }
      render();
    });
  });
}

/**
 * Модальное подтверждение опасных действий (удаление конфигурации/сертификата
 * и т.п.) вместо стандартного confirm() — единообразный стиль, текст берётся
 * из data-confirm-title/data-confirm-text формы или кнопки.
 */
function initConfirmModal() {
  var overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.innerHTML =
    '<div class="modal-box" role="alertdialog" aria-modal="true" aria-labelledby="confirmModalTitle" aria-describedby="confirmModalText">' +
    '  <h2 class="modal-title" id="confirmModalTitle"></h2>' +
    '  <p class="modal-text" id="confirmModalText"></p>' +
    '  <div class="modal-actions">' +
    '    <button type="button" class="btn modal-cancel">Отмена</button>' +
    '    <button type="button" class="btn btn-danger modal-confirm">Подтвердить</button>' +
    '  </div>' +
    '</div>';
  document.body.appendChild(overlay);

  var titleEl = overlay.querySelector('.modal-title');
  var textEl = overlay.querySelector('.modal-text');
  var cancelBtn = overlay.querySelector('.modal-cancel');
  var confirmBtn = overlay.querySelector('.modal-confirm');
  var pendingForm = null;
  var returnFocus = null;

  function close() {
    overlay.classList.remove('open');
    pendingForm = null;
    if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
    returnFocus = null;
  }

  cancelBtn.addEventListener('click', close);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) {
      close();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (!overlay.classList.contains('open')) return;
    if (e.key === 'Escape') {
      close();
    } else if (e.key === 'Tab') {
      // фокус остаётся внутри окна: две кнопки, переход по кругу
      e.preventDefault();
      (document.activeElement === cancelBtn ? confirmBtn : cancelBtn).focus();
    }
  });
  confirmBtn.addEventListener('click', function () {
    var form = pendingForm;
    close();
    if (form) {
      form.submit();
    }
  });

  document.querySelectorAll('form[data-confirm-text]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      titleEl.textContent = form.getAttribute('data-confirm-title') || 'Подтвердите действие';
      textEl.textContent = form.getAttribute('data-confirm-text');
      pendingForm = form;
      returnFocus = e.submitter || document.activeElement;
      overlay.classList.add('open');
      cancelBtn.focus();
    });
  });
}

/**
 * Черновик формы «Изменить конфигурацию» автосохраняется в sessionStorage
 * (только для текущей вкладки браузера, не localStorage — не должен
 * пережить закрытие браузера) и предлагается восстановить, если страница
 * была случайно закрыта/перезагружена до отправки. Пароли и CSRF-токен
 * никогда не входят в список сохраняемых полей.
 */
function initDraftAutosave() {
  var form = document.getElementById('connectionForm');
  if (!form || !window.sessionStorage) {
    return;
  }

  var storageKey = 'draft:' + form.id + ':' + (form.querySelector('[name="id"]') ? form.querySelector('[name="id"]').value : 'new');
  var fieldNames = ['name', 'ldaps_url', 'host', 'port', 'base_dn', 'domain', 'netbios_domain', 'admin_group_dn', 'network_timeout'];

  try {
    var saved = sessionStorage.getItem(storageKey);
    if (saved) {
      var data = JSON.parse(saved);
      var banner = document.getElementById('draftRestoreBanner');
      if (banner) {
        banner.hidden = false;
        banner.querySelector('.draft-apply').addEventListener('click', function () {
          fieldNames.forEach(function (name) {
            var field = form.querySelector('[name="' + name + '"]');
            if (field && data[name] !== undefined) {
              field.value = data[name];
            }
          });
          banner.hidden = true;
        });
        banner.querySelector('.draft-discard').addEventListener('click', function () {
          sessionStorage.removeItem(storageKey);
          banner.hidden = true;
        });
      }
    }
  } catch (err) {
    // sessionStorage недоступен (приватный режим и т.п.) — просто не автосохраняем.
  }

  form.addEventListener('input', function () {
    try {
      var draft = {};
      fieldNames.forEach(function (name) {
        var field = form.querySelector('[name="' + name + '"]');
        if (field) {
          draft[name] = field.value;
        }
      });
      sessionStorage.setItem(storageKey, JSON.stringify(draft));
    } catch (err) {
      // Недоступно — молча игнорируем, форма продолжает работать без автосохранения.
    }
  });

  form.addEventListener('submit', function () {
    try {
      sessionStorage.removeItem(storageKey);
    } catch (err) {
      // ignore
    }
  });
}

/* Подтверждение и автоотправка форм без встроенных обработчиков (CSP: script-src 'self'):
   <form data-confirm="Текст?"> и <input data-autosubmit>. */
document.addEventListener('submit', function (e) {
  var form = e.target;
  if (form instanceof HTMLFormElement && form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) {
    e.preventDefault();
  }
}, true);
document.addEventListener('change', function (e) {
  var el = e.target;
  if (el && el.hasAttribute && el.hasAttribute('data-autosubmit') && el.form) {
    el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit();
  }
});

/**
 * Окно-терминал раздела «Обновление»: дочитывает журнал помощника частями (action=log, JSON), показывает этап «N из M»
 * и итог. Пока идёт действие — опрос раз в 1,5 с, иначе раз в 8 с. Если приложение перезапускается при обновлении,
 * запросы падают — окно пишет об этом и продолжает опрос, пока сервер не ответит снова.
 */
function initUpdateTerminal() {
  var box = document.querySelector('[data-upd-term]');
  if (!box) return;
  var screen = box.querySelector('[data-upd-screen]'), follow = box.querySelector('[data-upd-follow]');
  var badge = box.querySelector('[data-upd-badge]'), at = box.querySelector('[data-upd-at]'), result = box.querySelector('[data-upd-result]');
  var progress = box.querySelector('[data-upd-progress]'), bar = box.querySelector('[data-upd-bar]'), stepEl = box.querySelector('[data-upd-step]');
  var offset = 0, text = '', lost = false, wasRunning = box.getAttribute('data-running') === '1', timer = null;
  var BADGE = { pending: ['запрос передан помощнику', 'badge-inactive'], running: ['выполняется', 'upd-badge-run'],
                done: ['завершено успешно', 'badge-active'], failed: ['остановлено', 'llm-badge-bad'] };

  function render(d) {
    var b = BADGE[d.state] || ['', ''];
    badge.textContent = lost ? 'нет связи с сервером' : b[0];
    badge.className = 'badge ' + (lost ? 'tele-badge-raw' : b[1]);
    badge.hidden = !badge.textContent;
    if (d.at) at.textContent = d.at + ' UTC';
    progress.hidden = !(d.action === 'update' && (d.running || d.step > 0));
    var pct = d.steps ? Math.round(d.step / d.steps * 100) : 0;
    bar.style.width = (d.state === 'done' ? 100 : Math.max(pct, d.running ? 3 : pct)) + '%';
    stepEl.textContent = d.step ? 'Этап ' + d.step + ' из ' + d.steps + ': ' + d.step_name : 'Запуск…';
    result.hidden = true;
    if (lost) {
      result.hidden = false; result.className = 'alert alert-warn';
      result.textContent = 'Связь с сервером прервана — вероятно, приложение перезапускается при обновлении. Окно переподключится само; обновление продолжается на сервере.';
    } else if (!d.running && d.action === 'update' && d.state === 'done') {
      result.hidden = false; result.className = 'alert alert-success';
      result.innerHTML = 'Обновление выполнено: проверки пройдены. <button type="button" class="btn btn-small btn-primary">Обновить страницу</button>';
      result.querySelector('button').addEventListener('click', function () { location.reload(); });
    } else if (!d.running && d.action === 'update' && d.state === 'failed') {
      result.hidden = false; result.className = 'alert alert-error';
      result.textContent = 'Обновление остановилось. Данные, настройки и модели не затронуты; если переключение уже произошло — вернулась прежняя версия. Причина — в последних строках журнала. Исправьте её и нажмите «Обновить» снова (повтор безопасен) или выполните sudo scripts/update.sh на сервере.';
    }
  }

  function poll() {
    fetch('index.php?section=updates&action=log&offset=' + offset, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(function (d) {
        lost = false;
        if (d.reset) { text = ''; }
        if (d.text) { text = (text + d.text).slice(-600000); }
        offset = d.offset;
        screen.textContent = text || 'Ожидание вывода…';
        if (follow.checked) screen.scrollTop = screen.scrollHeight;
        if (d.running || d.state) box.hidden = false;
        render(d);
        if (d.stuck && box.getAttribute('data-stuck') !== '1') { location.reload(); return; }   // страница покажет причину и кнопку отмены
        // действие закончилось, пока окно было открыто, — перечитать страницу: версии, история, кнопки
        if (wasRunning && !d.running && d.action === 'check') { location.reload(); return; }
        wasRunning = d.running;
        schedule(d.running ? 1500 : 8000);
      })
      .catch(function () {
        lost = true;
        render({ state: 'running', action: 'update', running: true, step: 0, steps: 0 });
        schedule(3000);
      });
  }
  function schedule(ms) { clearTimeout(timer); timer = setTimeout(poll, ms); }

  screen.addEventListener('scroll', function () {
    follow.checked = screen.scrollHeight - screen.scrollTop - screen.clientHeight < 40;
  });
  box.querySelector('[data-upd-download]').addEventListener('click', function () {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
    a.download = 'llm-provider-update-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.log';
    document.body.appendChild(a); a.click(); a.remove();
  });
  poll();
}
