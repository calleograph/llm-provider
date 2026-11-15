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
  initDraftAutosave();
  initThemeToggle();
  initCommandCopy();
  initRevealButtons();
  initAddRowButtons();
  initProviderTypeDefaults();
  initAnonymizationProfile();
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

/* «Обезличивание»: показывает поля выбранного типа API (DocClean / JSON). */
function initAnonymizationProfile() {
  var select = document.querySelector('select[data-anon-profile]');
  if (!select) {
    return;
  }
  function apply() {
    document.querySelectorAll('[data-profile-section]').forEach(function (section) {
      // Список профилей через пробел; поля скрытого блока выключаются, чтобы required не мешал отправке формы.
      section.hidden = section.getAttribute('data-profile-section').split(' ').indexOf(select.value) < 0;
      section.querySelectorAll('input, select, textarea').forEach(function (el) { el.disabled = section.hidden; });
    });
  }
  select.addEventListener('change', apply);
  apply();
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
 * Переключатель светлой/тёмной темы. Применяется ко всей странице
 * (data-theme на <html>), запоминается в localStorage только на этом
 * устройстве/браузере — не влияет ни на сервер, ни на других пользователей.
 * По умолчанию — светлая тема, независимо от системных настроек.
 */
function initThemeToggle() {
  var buttons = document.querySelectorAll('[data-theme-btn]');
  if (!buttons.length) {
    return;
  }

  var STORAGE_KEY = 'pwd-page-theme';

  function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    buttons.forEach(function (button) {
      var active = button.dataset.themeBtn === theme;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', String(active));
    });
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (err) {
      // localStorage недоступен (приватный режим и т.п.) — тема просто не запомнится.
    }
  }

  var savedTheme = 'light';
  try {
    savedTheme = localStorage.getItem(STORAGE_KEY) || 'light';
  } catch (err) {
    // ignore
  }

  applyTheme(savedTheme === 'dark' ? 'dark' : 'light');
  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      applyTheme(button.dataset.themeBtn);
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
    '<div class="modal-box" role="alertdialog" aria-modal="true">' +
    '  <h2 class="modal-title"></h2>' +
    '  <p class="modal-text"></p>' +
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

  function close() {
    overlay.classList.remove('open');
    pendingForm = null;
  }

  cancelBtn.addEventListener('click', close);
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) {
      close();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.classList.contains('open')) {
      close();
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
      overlay.classList.add('open');
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
