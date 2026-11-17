/*
 * Корпоративный AI-чат (интерфейс в духе ChatGPT): боковая панель с новым
 * чатом, поиском, закреплёнными чатами, списком по последнему использованию
 * и проектами. Вся бизнес-логика (права, лимиты, обезличивание, LLM) — на
 * сервере. Данные с сервера выводятся только через textContent.
 */
(function () {
  'use strict';

  var app = document.getElementById('chatApp');
  if (!app) {
    return;
  }
  // «Голосовой ввод — только по HTTPS» решает браузер: за reverse proxy без доверенного адреса сервер видит http,
  // а у пользователя https и микрофон доступен
  if (window.isSecureContext) {
    app.removeAttribute('data-insecure');
  }

  var API = 'api.php';
  var csrf = app.getAttribute('data-csrf') || '';
  var ready = app.getAttribute('data-ready') === '1';
  var maxUploadBytes = (parseInt(app.getAttribute('data-max-upload-mb'), 10) || 10) * 1024 * 1024;
  var extensions = (app.getAttribute('data-extensions') || '').split(',').filter(Boolean);
  var MAX_FILES = 5; // держать синхронно с ChatService::MAX_UPLOADS на сервере

  function $(id) { return document.getElementById(id); }

  var els = {
    sidebar: $('gptSidebar'), backdrop: $('sidebarBackdrop'),
    search: $('chatSearch'), searchSection: $('searchSection'), searchResults: $('searchResults'),
    pinnedSection: $('pinnedSection'), pinnedList: $('pinnedList'), chatsSection: $('chatsSection'), chatList: $('chatList'),
    projectList: $('projectList'), projectView: $('projectView'), projectName: $('projectName'),
    sourceList: $('sourceList'), sourcesMeta: $('sourcesMeta'), sourceTitle: $('sourceTitle'), sourceText: $('sourceText'),
    sourceFile: $('sourceFile'), sourceFileName: $('sourceFileName'), projectChats: $('projectChats'),
    projectChatsTabCount: $('projectChatsTabCount'), projectSourcesTabCount: $('projectSourcesTabCount'),
    main: $('gptMain'), composerWrap: $('composerWrap'), chatDropzone: $('chatDropzone'), sourcesPanel: $('sourcesPanel'), sourceDropzone: $('sourceDropzone'),
    messages: $('chatMessages'), empty: $('chatEmpty'), emptyTitle: $('emptyTitle'), title: $('chatTitle'), crumb: $('crumbProject'),
    scrollBottom: $('scrollToBottom'), jumpDots: $('jumpDots'),
    model: $('chatModel'), form: $('chatForm'), input: $('chatInput'), file: $('chatFile'),
    attachmentList: $('chatAttachmentList'), send: $('chatSend'), skipAnonToggle: $('skipAnonToggle'),
    usageBox: $('usageBox'), usageText: $('usageText'), usageBar: $('usageBar'), menu: $('ctxMenu'),
    storageBox: $('storageBox'), storageText: $('storageText'), storageBar: $('storageBar'),
    dataManagerView: $('dataManagerView'), dataStorageMeta: $('dataStorageMeta'), dataStorageBar: $('dataStorageBar'),
    dataChatsCount: $('dataChatsCount'), dataChatsList: $('dataChatsList'),
    dataSelectAll: $('dataSelectAll'), dataDeleteSelected: $('dataDeleteSelected'), dataDeleteAll: $('dataDeleteAll')
  };

  var state = {
    conversations: [], projects: [], providers: {},
    currentId: null, currentProjectId: null, projectData: null,
    sending: false, files: [], searchTimer: null,
    expandedProjectId: null, storage: null
  };

  /* ---------- сеть ---------- */

  function request(method, action, body, query) {
    var options = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (method === 'POST') {
      options.headers['X-CSRF-Token'] = csrf;
      options.body = body;
    }
    return fetch(API + '?action=' + encodeURIComponent(action) + (query || ''), options).then(function (response) {
      if (response.status === 401) {
        window.location.href = 'index.php';
        throw new Error('Сеанс завершён.');
      }
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) {
          var error = new Error(data.error || 'Ошибка сервера (' + response.status + ').');
          error.code = data.code || 'http_' + response.status;
          if (error.code === 'csrf') {
            showStaleSessionNotice();
          }
          throw error;
        }
        return data;
      });
    });
  }

  function form(fields) {
    var body = new FormData();
    Object.keys(fields).forEach(function (key) {
      var value = fields[key];
      if (value === null || value === undefined) return;
      if (Array.isArray(value)) {
        // PHP разбирает "ids[]=1&ids[]=2" в массив $_POST['ids'] — обычный
        // append(key, arr) вместо этого превращает массив в одну строку
        // через toString() ("1,2"), и на сервере остаётся только один id.
        value.forEach(function (item) { body.append(key + '[]', item); });
      } else {
        body.append(key, value);
      }
    });
    return body;
  }

  /* ---------- утилиты ---------- */

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' Б';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' КБ';
    return (bytes / 1024 / 1024).toFixed(1) + ' МБ';
  }

  function formatNumber(n) {
    return String(Math.round(n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function formatDate(ymd) {
    var p = String(ymd || '').split('-');
    return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : ymd;
  }

  function toast(message, code) {
    addMessage({ role: 'error', content: message, code: code });
  }

  /*
   * Независимое от текущего экрана уведомление (в отличие от toast()
   * выше, который пишет в ленту #chatMessages — невидимую, пока открыта
   * любая другая панель вроде "Управление данными"). Крепится к body,
   * само исчезает через 4 секунды.
   */
  function showStatusToast(message, kind) {
    var bar = el('div', 'gpt-status-toast' + (kind === 'error' ? ' gpt-status-toast-error' : ''), message);
    document.body.appendChild(bar);
    setTimeout(function () {
      bar.classList.add('gpt-status-toast-out');
      setTimeout(function () { bar.remove(); }, 200);
    }, 4000);
  }

  /*
   * CSRF-токен в сессии сервера мог смениться, пока вкладка была открыта
   * (например, страница провисела открытой очень долго) — любое действие
   * (не только отправка сообщения) в этом случае вернёт code: 'csrf'.
   * Вместо тихого текста показываем один явный баннер с кнопкой обновления.
   */
  var staleSessionShown = false;
  function showStaleSessionNotice() {
    if (staleSessionShown) return;
    staleSessionShown = true;
    var bar = el('div', 'gpt-stale-session');
    bar.appendChild(el('span', null, 'Сеанс устарел — часть действий может не сохраниться.'));
    var button = el('button', 'btn btn-small btn-primary', 'Обновить страницу');
    button.type = 'button';
    button.addEventListener('click', function () { window.location.reload(); });
    bar.appendChild(button);
    document.body.appendChild(bar);
  }

  function projectById(id) {
    return state.projects.filter(function (p) { return p.id === id; })[0] || null;
  }

  function conversationById(id) {
    return state.conversations.filter(function (c) { return c.id === id; })[0] || null;
  }

  /* ---------- боковая панель ---------- */

  var mobile = window.matchMedia('(max-width: 900px)');

  function setSidebar(open) {
    app.classList.toggle('sidebar-closed', !open);
    els.backdrop.hidden = !(open && mobile.matches);
    try { if (!mobile.matches) localStorage.setItem('chatSidebar', open ? 'open' : 'closed'); } catch (e) { /* нет storage */ }
  }

  $('sidebarClose').addEventListener('click', function () { setSidebar(false); });
  $('sidebarOpen').addEventListener('click', function () { setSidebar(true); });
  els.backdrop.addEventListener('click', function () { setSidebar(false); });
  (function initSidebar() {
    var saved = null;
    try { saved = localStorage.getItem('chatSidebar'); } catch (e) { /* нет storage */ }
    setSidebar(!mobile.matches && saved !== 'closed');
  })();

  function closeSidebarOnMobile() {
    if (mobile.matches) setSidebar(false);
  }

  /* ---------- контекстное меню ---------- */

  var menuAnchor = null;
  function closeMenu(restoreFocus) {
    if (els.menu.hidden) return;
    els.menu.hidden = true;
    els.menu.textContent = '';
    if (restoreFocus === true && menuAnchor && document.contains(menuAnchor)) menuAnchor.focus();
    menuAnchor = null;
  }

  function openMenu(anchor, items) {
    els.menu.textContent = '';
    items.forEach(function (item) {
      if (item.separator) {
        els.menu.appendChild(el('div', 'gpt-menu-sep'));
        return;
      }
      var button = el('button', 'gpt-menu-item' + (item.danger ? ' danger' : '') + (item.active ? ' active' : ''), item.label);
      button.type = 'button';
      button.setAttribute('role', 'menuitem');
      button.addEventListener('click', function (e) {
        e.stopPropagation();
        item.action(button);
      });
      els.menu.appendChild(button);
    });
    var rect = anchor.getBoundingClientRect();
    els.menu.hidden = false;
    var top = Math.min(rect.bottom + 4, window.innerHeight - els.menu.offsetHeight - 8);
    var left = Math.min(rect.left, window.innerWidth - els.menu.offsetWidth - 8);
    els.menu.style.top = Math.max(8, top) + 'px';
    els.menu.style.left = Math.max(8, left) + 'px';
    menuAnchor = anchor;
    var first = els.menu.querySelector('.gpt-menu-item');
    if (first) first.focus({ preventScroll: true });
  }

  // Клавиатура в меню: стрелки, Home/End, Tab закрывает меню.
  els.menu.addEventListener('keydown', function (e) {
    var items = Array.prototype.slice.call(els.menu.querySelectorAll('.gpt-menu-item'));
    var i = items.indexOf(document.activeElement);
    var next = null;
    if (e.key === 'ArrowDown') next = items[(i + 1) % items.length];
    else if (e.key === 'ArrowUp') next = items[(i - 1 + items.length) % items.length];
    else if (e.key === 'Home') next = items[0];
    else if (e.key === 'End') next = items[items.length - 1];
    else if (e.key === 'Tab') { closeMenu(true); return; }
    if (next) { e.preventDefault(); next.focus(); }
  });

  document.addEventListener('click', function (e) {
    if (!els.menu.hidden && !els.menu.contains(e.target)) closeMenu();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (!els.menu.hidden) { closeMenu(true); return; }
    // мобильная боковая панель закрывается так же, как меню
    if (mobile.matches && !app.classList.contains('sidebar-closed')) { setSidebar(false); $('sidebarOpen').focus(); }
  });

  function confirmThen(button, label, action) {
    if (button.getAttribute('data-confirm') === '1') {
      closeMenu();
      action();
      return;
    }
    button.setAttribute('data-confirm', '1');
    button.textContent = label;
  }

  function conversationMenu(anchor, conversation) {
    var items = [
      { label: conversation.pinned ? 'Открепить' : 'Закрепить', action: function () { closeMenu(); updateConversation(conversation.id, { pinned: conversation.pinned ? '0' : '1' }); } },
      { label: 'Переименовать', action: function () { closeMenu(); startRename(conversation); } },
      { label: 'Переместить в проект…', action: function () { moveMenu(anchor, conversation); } },
      { separator: true },
      { label: 'Удалить', danger: true, action: function (button) { confirmThen(button, 'Удалить без возврата?', function () { deleteConversation(conversation.id); }); } }
    ];
    openMenu(anchor, items);
  }

  function moveMenu(anchor, conversation) {
    var items = state.projects.map(function (project) {
      return {
        label: project.name, active: conversation.project_id === project.id,
        action: function () { closeMenu(); updateConversation(conversation.id, { project_id: project.id }); }
      };
    });
    if (conversation.project_id) {
      items.push({ separator: true });
      items.push({ label: 'Убрать из проекта', action: function () { closeMenu(); updateConversation(conversation.id, { project_id: 0 }); } });
    }
    if (items.length === 0) {
      items.push({ label: 'Проектов пока нет — создайте в разделе «Проекты»', action: closeMenu });
    }
    openMenu(anchor, items);
  }

  /* ---------- списки ---------- */

  function groupLabel(iso) {
    var date = new Date(iso);
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var diff = Math.floor((today - new Date(date.getFullYear(), date.getMonth(), date.getDate())) / 86400000);
    if (diff <= 0) return 'Сегодня';
    if (diff === 1) return 'Вчера';
    if (diff < 7) return 'Последние 7 дней';
    if (diff < 30) return 'Последние 30 дней';
    return 'Ранее';
  }

  function conversationItem(conversation, snippet) {
    var item = el('div', 'gpt-item' + (conversation.id === state.currentId ? ' active' : ''));
    item.setAttribute('data-id', String(conversation.id));
    var open = el('button', 'gpt-item-open');
    open.type = 'button';
    open.appendChild(el('span', 'gpt-item-title', conversation.title));
    if (snippet) open.appendChild(el('span', 'gpt-item-snippet', snippet));
    open.title = conversation.title;
    open.addEventListener('click', function () { openConversation(conversation.id); });
    var more = el('button', 'gpt-item-more');
    more.appendChild(moreIcon());
    more.type = 'button';
    more.title = 'Действия';
    more.setAttribute('aria-label', 'Действия с чатом');
    more.addEventListener('click', function (e) {
      e.stopPropagation();
      conversationMenu(more, conversation);
    });
    item.appendChild(open);
    item.appendChild(more);
    return item;
  }

  function renderLists() {
    renderBudget();
    var pinned = state.conversations.filter(function (c) { return c.pinned; });
    var regular = state.conversations.filter(function (c) { return !c.pinned && !c.project_id; });

    els.pinnedList.textContent = '';
    pinned.forEach(function (c) { els.pinnedList.appendChild(conversationItem(c)); });
    els.pinnedSection.hidden = pinned.length === 0;

    els.chatList.textContent = '';
    if (regular.length === 0) {
      els.chatList.appendChild(el('p', 'gpt-side-empty', 'Чатов пока нет'));
    }
    var lastGroup = null;
    regular.forEach(function (c) {
      var group = groupLabel(c.updated_at);
      if (group !== lastGroup) {
        els.chatList.appendChild(el('p', 'gpt-side-title', group));
        lastGroup = group;
      }
      els.chatList.appendChild(conversationItem(c));
    });

    renderProjects();
    if (state.projectData) renderProjectChats();
  }

  // Три точки «Действия» — рисованная иконка вместо символа ⋯ (одна толщина линий с остальным набором).
  function moreIcon() {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    [5, 12, 19].forEach(function (x) {
      var c = document.createElementNS(ns, 'circle');
      c.setAttribute('cx', x); c.setAttribute('cy', '12'); c.setAttribute('r', '1.5');
      svg.appendChild(c);
    });
    return svg;
  }

  function folderIcon() {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'gpt-folder');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS(ns, 'path');
    path.setAttribute('d', 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z');
    svg.appendChild(path);
    return svg;
  }

  function projectMenu(anchor, project) {
    var items = [
      { label: project.pinned ? 'Открепить' : 'Закрепить', action: function () { closeMenu(); updateProject(project.id, { pinned: project.pinned ? '0' : '1' }); } },
      { label: 'Переименовать', action: function () { closeMenu(); startProjectRename(project); } },
      { label: 'Открыть проект', action: function () { closeMenu(); openProject(project.id); } },
      { separator: true },
      { label: 'Удалить', danger: true, action: function (button) { confirmThen(button, 'Удалить без возврата?', function () { deleteProject(project.id); }); } }
    ];
    openMenu(anchor, items);
  }

  function updateProject(id, fields) {
    fields.id = id;
    request('POST', 'project_update', form(fields)).then(function (data) {
      state.projects = data.projects;
      renderLists();
    }).catch(function (error) { toast(error.message); });
  }

  function deleteProject(id) {
    request('POST', 'project_delete', form({ id: id })).then(function (data) {
      state.projects = data.projects;
      state.conversations = data.conversations;
      if (state.currentProjectId === id) newConversation(null);
      renderLists();
    }).catch(function (error) { toast(error.message); });
  }

  function startProjectRename(project) {
    var input = el('input', 'gpt-rename');
    input.value = project.name;
    input.maxLength = 80;
    var target = els.projectList.querySelector('.gpt-item[data-project-id="' + project.id + '"] .gpt-item-open');
    if (!target) return;
    target.replaceWith(input);
    input.focus();
    input.select();
    var done = false;
    function finish(save) {
      if (done) return;
      done = true;
      var name = input.value.trim();
      if (save && name !== '' && name !== project.name) {
        updateProject(project.id, { name: name });
      } else {
        renderLists();
      }
    }
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') finish(true);
      if (e.key === 'Escape') finish(false);
    });
    input.addEventListener('blur', function () { finish(true); });
  }

  function chevronIcon() {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'gpt-chevron');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS(ns, 'path');
    path.setAttribute('d', 'm9 6 6 6-6 6');
    svg.appendChild(path);
    return svg;
  }

  function toggleProjectExpanded(id) {
    var wasExpanded = state.expandedProjectId === id;
    state.expandedProjectId = wasExpanded ? null : id;
    // Не перестраиваем весь список — иначе узел .gpt-project-sub рождается
    // уже с классом "expanded", и transition (0fr → 1fr) нечего анимировать.
    // Переключаем класс на существующих узлах, чтобы браузер увидел переход.
    var groups = els.projectList.querySelectorAll('.gpt-project-group');
    for (var i = 0; i < groups.length; i++) {
      var groupId = Number(groups[i].getAttribute('data-project-group-id'));
      groups[i].classList.toggle('expanded', groupId === state.expandedProjectId);
    }
  }

  function renderProjects() {
    els.projectList.textContent = '';
    if (state.projects.length === 0) {
      els.projectList.appendChild(el('p', 'gpt-side-empty', 'Нет проектов'));
      return;
    }
    state.projects.forEach(function (project) {
      var expanded = state.expandedProjectId === project.id;
      var wrap = el('div', 'gpt-project-group' + (expanded ? ' expanded' : ''));
      wrap.setAttribute('data-project-group-id', String(project.id));

      var item = el('div', 'gpt-item' + (project.id === state.currentProjectId && !state.currentId ? ' active' : ''));
      item.setAttribute('data-project-id', String(project.id));
      var open = el('button', 'gpt-item-open');
      open.type = 'button';
      open.title = 'Показать чаты проекта';
      var title = el('span', 'gpt-item-title gpt-item-project');
      title.appendChild(chevronIcon());
      title.appendChild(folderIcon());
      title.appendChild(document.createTextNode(project.name));
      open.appendChild(title);
      open.addEventListener('click', function () { toggleProjectExpanded(project.id); });
      var projectChats = state.conversations.filter(function (c) { return c.project_id === project.id; });
      if (projectChats.length > 0) item.appendChild(el('span', 'gpt-item-count', String(projectChats.length)));
      var more = el('button', 'gpt-item-more');
      more.appendChild(moreIcon());
      more.type = 'button';
      more.title = 'Действия';
      more.setAttribute('aria-label', 'Действия с проектом');
      more.addEventListener('click', function (e) {
        e.stopPropagation();
        projectMenu(more, project);
      });
      item.appendChild(more);
      item.insertBefore(open, item.firstChild);
      wrap.appendChild(item);

      var sub = el('nav', 'gpt-project-sub');
      var subInner = el('div', 'gpt-project-sub-inner');
      if (projectChats.length === 0) {
        subInner.appendChild(el('p', 'gpt-side-empty', 'Чатов в проекте пока нет'));
      } else {
        projectChats.forEach(function (c) { subInner.appendChild(conversationItem(c)); });
      }
      sub.appendChild(subInner);
      wrap.appendChild(sub);

      els.projectList.appendChild(wrap);
    });
  }

  function startRename(conversation) {
    var input = el('input', 'gpt-rename');
    input.value = conversation.title;
    input.maxLength = 120;
    var selector = '.gpt-item[data-id="' + conversation.id + '"] .gpt-item-open';
    var target = els.sidebar.querySelector(selector) || els.projectChats.querySelector(selector);
    if (!target) return;
    target.replaceWith(input);
    input.focus();
    input.select();
    var done = false;
    function finish(save) {
      if (done) return;
      done = true;
      if (save && input.value.trim() !== '' && input.value.trim() !== conversation.title) {
        updateConversation(conversation.id, { title: input.value.trim() });
      } else {
        renderLists();
      }
    }
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') finish(true);
      if (e.key === 'Escape') finish(false);
    });
    input.addEventListener('blur', function () { finish(true); });
  }

  function updateConversation(id, fields) {
    fields.id = id;
    request('POST', 'conversation_update', form(fields)).then(function (data) {
      state.conversations = data.conversations;
      var current = conversationById(state.currentId);
      if (current) {
        setHeader(current.title, current.project_id);
        state.currentProjectId = current.project_id;
      }
      renderLists();
    }).catch(function (error) { toast(error.message); });
  }

  function deleteConversation(id) {
    request('POST', 'delete_conversation', form({ id: id })).then(function () {
      state.conversations = state.conversations.filter(function (c) { return c.id !== id; });
      if (state.currentId === id) newConversation(state.currentProjectId);
      renderLists();
    }).catch(function (error) { toast(error.message); });
  }

  /* ---------- поиск ---------- */

  els.search.addEventListener('input', function () {
    clearTimeout(state.searchTimer);
    var q = els.search.value.trim();
    if (q.length < 2) {
      els.searchSection.hidden = true;
      els.chatsSection.hidden = false;
      els.pinnedSection.hidden = !state.conversations.some(function (c) { return c.pinned; });
      return;
    }
    state.searchTimer = setTimeout(function () {
      request('GET', 'search', null, '&q=' + encodeURIComponent(q)).then(function (data) {
        els.searchResults.textContent = '';
        (data.results || []).forEach(function (c) { els.searchResults.appendChild(conversationItem(c, c.snippet)); });
        if (!data.results || data.results.length === 0) {
          els.searchResults.appendChild(el('p', 'gpt-side-empty', 'Ничего не найдено'));
        }
        els.searchSection.hidden = false;
        els.chatsSection.hidden = true;
        els.pinnedSection.hidden = true;
      }).catch(function (error) { toast(error.message); });
    }, 250);
  });

  /* ---------- сообщения ---------- */

  function copyIcon() {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    var rect = document.createElementNS(ns, 'rect');
    rect.setAttribute('x', '9'); rect.setAttribute('y', '9'); rect.setAttribute('width', '11'); rect.setAttribute('height', '11'); rect.setAttribute('rx', '2');
    var path = document.createElementNS(ns, 'path');
    path.setAttribute('d', 'M5 15V5a2 2 0 0 1 2-2h10');
    svg.appendChild(rect);
    svg.appendChild(path);
    return svg;
  }

  function checkIcon() {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS(ns, 'path');
    path.setAttribute('d', 'M20 6 9 17l-5-5');
    svg.appendChild(path);
    return svg;
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    // Резервный путь для сред без Clipboard API (не HTTPS и т.п.).
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try { document.execCommand('copy'); } catch (err) { /* ignore */ }
    document.body.removeChild(textarea);
    return Promise.resolve();
  }

  function buildMessageActions(content, label, messageId) {
    var actions = el('div', 'gpt-msg-actions');
    var copyButton = el('button', 'gpt-msg-action', null);
    copyButton.type = 'button';
    copyButton.title = 'Копировать';
    copyButton.setAttribute('aria-label', label);
    copyButton.appendChild(copyIcon());
    copyButton.addEventListener('click', function () {
      copyToClipboard(String(content || '')).then(function () {
        copyButton.classList.add('copied');
        copyButton.title = 'Скопировано';
        copyButton.textContent = '';
        copyButton.appendChild(checkIcon());
        setTimeout(function () {
          copyButton.classList.remove('copied');
          copyButton.title = 'Копировать';
          copyButton.textContent = '';
          copyButton.appendChild(copyIcon());
        }, 1500);
      }).catch(function () { toast('Не удалось скопировать текст.'); });
    });
    actions.appendChild(copyButton);
    if (messageId) {
      // ответ файлом Word: сервер собирает .docx из этого же ответа (обезличенный текст, метки остаются как в чате)
      var word = el('button', 'gpt-msg-action', null);
      word.type = 'button';
      word.title = 'Скачать ответ файлом Word';
      word.setAttribute('aria-label', 'Скачать ответ файлом Word');
      var ns = 'http://www.w3.org/2000/svg';
      var svg = document.createElementNS(ns, 'svg');
      svg.setAttribute('viewBox', '0 0 24 24');
      svg.setAttribute('aria-hidden', 'true');
      var path = document.createElementNS(ns, 'path');
      path.setAttribute('d', 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5M12 11v6M9 14l3 3 3-3');
      svg.appendChild(path);
      word.appendChild(svg);
      word.addEventListener('click', function () {
        location.href = API + '?action=message_docx&conversation_id=' + encodeURIComponent(state.currentId) + '&id=' + encodeURIComponent(messageId);
      });
      actions.appendChild(word);
    }
    return actions;
  }

  /*
   * Минимальный markdown-рендерер для ответов нейросети. Строит DOM
   * напрямую через createElement/textContent (никогда innerHTML) — весь
   * контент нейросети остаётся небезопасным по происхождению, поэтому
   * так же, как обычный текст, не может внедрить разметку/скрипты, даже
   * без отдельной санитизации. Поддержаны: заголовки, жирный/курсив,
   * инлайн-код, ссылки, списки (в т.ч. вложенные по отступу), цитаты,
   * таблицы, горизонтальная линия — этого достаточно для типичных
   * ответов LLM; полный CommonMark не требуется.
   */
  function renderInline(container, text) {
    // Метки обезличивания — только оформление, текст не меняется: «[телефон 1]», «[адрес]», «[IP 2]» и обозначения без скобок,
    // которые ставит движок: «Виктор П.» вместо ФИО (в любом падеже), «Организация 2», «Проект 1», «Лицо 3».
    var pattern = /\[([^\]\n]+)\]\((https?:\/\/[^\s()]+)\)|(\*\*|__)([^\n]+?)\3|(`+)([^\n]+?)\5|(\*|_)([^\n]+?)\7|\[((?:IP|[а-яё][а-яё-]*)(?: [А-ЯЁа-яё-]+)?(?: \d{1,4})?)\](?!\()|(?<![А-ЯЁа-яё])((?:[А-ЯЁ][а-яё]{1,20} [А-ЯЁ][а-яё]{0,2}\.(?![А-ЯЁа-яё]))|(?:Организаци(?:я|и|ю|ей)|Мест(?:о|а|у|ом|е)|Проект(?:а|у|ом|е)?|Лиц(?:о|а|у|ом|е)|Участник(?:а|у|ом|е)?) \d{1,4}(?!\d))/;
    var rest = String(text || '');
    while (rest !== '') {
      var m = pattern.exec(rest);
      if (!m) {
        container.appendChild(document.createTextNode(rest));
        break;
      }
      if (m.index > 0) container.appendChild(document.createTextNode(rest.slice(0, m.index)));
      if (m[1] !== undefined) {
        var a = el('a', null, m[1]);
        a.href = m[2];
        a.target = '_blank';
        a.rel = 'noopener noreferrer nofollow';
        container.appendChild(a);
      } else if (m[4] !== undefined) {
        var strong = el('strong');
        renderInline(strong, m[4]);
        container.appendChild(strong);
      } else if (m[6] !== undefined) {
        container.appendChild(el('code', 'gpt-inline-code', m[6]));
      } else if (m[8] !== undefined) {
        var em = el('em');
        renderInline(em, m[8]);
        container.appendChild(em);
      } else if (m[9] !== undefined) {
        var mask = el('span', 'gpt-mask', m[0]);
        mask.title = 'Обезличенные данные: исходное значение не покидало сервер компании';
        container.appendChild(mask);      } else if (m[10] !== undefined) {
        var alias = el('span', 'gpt-mask gpt-mask-alias', m[10]);
        alias.title = 'Обозначение вместо настоящего имени или названия: исходное значение не покидало сервер компании';
        container.appendChild(alias);
      }
      rest = rest.slice(m.index + m[0].length);
    }
  }

  function isTableSeparatorRow(line) {
    return /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(line);
  }

  function splitTableRow(line) {
    var trimmed = line.trim().replace(/^\|/, '').replace(/\|$/, '');
    return trimmed.split('|').map(function (cell) { return cell.trim(); });
  }

  function renderMarkdownBlock(container, block) {
    var lines = block.split('\n');
    var i = 0;
    while (i < lines.length) {
      var line = lines[i];

      if (/^\s*$/.test(line)) { i++; continue; }

      var heading = /^(#{1,6})\s+(.*)$/.exec(line);
      if (heading) {
        var h = el('h' + heading[1].length, 'gpt-heading');
        renderInline(h, heading[2]);
        container.appendChild(h);
        i++;
        continue;
      }

      if (/^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
        container.appendChild(el('hr', 'gpt-hr'));
        i++;
        continue;
      }

      // Таблица: строка с "|", следующая строка — разделитель "---|---".
      if (line.indexOf('|') !== -1 && i + 1 < lines.length && isTableSeparatorRow(lines[i + 1])) {
        var headCells = splitTableRow(line);
        var table = el('table', 'gpt-table');
        var thead = el('thead');
        var headRow = el('tr');
        headCells.forEach(function (cellText) {
          var th = el('th');
          renderInline(th, cellText);
          headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);
        i += 2;
        var tbody = el('tbody');
        while (i < lines.length && lines[i].indexOf('|') !== -1 && !/^\s*$/.test(lines[i])) {
          var rowCells = splitTableRow(lines[i]);
          var tr = el('tr');
          rowCells.forEach(function (cellText) {
            var td = el('td');
            renderInline(td, cellText);
            tr.appendChild(td);
          });
          tbody.appendChild(tr);
          i++;
        }
        table.appendChild(tbody);
        var wrap = el('div', 'gpt-table-wrap');
        wrap.appendChild(table);
        container.appendChild(wrap);
        continue;
      }

      if (/^\s*>/.test(line)) {
        var quoteLines = [];
        while (i < lines.length && /^\s*>/.test(lines[i])) {
          quoteLines.push(lines[i].replace(/^\s*>\s?/, ''));
          i++;
        }
        var blockquote = el('blockquote', 'gpt-quote');
        renderMarkdownBlock(blockquote, quoteLines.join('\n'));
        container.appendChild(blockquote);
        continue;
      }

      var listItem = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/.exec(line);
      if (listItem) {
        var ordered = /\d/.test(listItem[2]);
        var list = el(ordered ? 'ol' : 'ul', 'gpt-list');
        while (i < lines.length) {
          var itemMatch = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/.exec(lines[i]);
          if (!itemMatch) break;
          var li = el('li');
          renderInline(li, itemMatch[3]);
          list.appendChild(li);
          i++;
        }
        container.appendChild(list);
        continue;
      }

      var paraLines = [];
      while (i < lines.length && !/^\s*$/.test(lines[i]) && !/^(#{1,6})\s+/.test(lines[i])
             && !/^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(lines[i]) && !/^\s*>/.test(lines[i])
             && !/^(\s*)([-*+]|\d+[.)])\s+/.test(lines[i])) {
        paraLines.push(lines[i]);
        i++;
      }
      var p = el('p', 'gpt-text');
      renderInline(p, paraLines.join('\n'));
      container.appendChild(p);
    }
  }

  function renderText(container, text) {
    String(text || '').split(/```/).forEach(function (part, index) {
      if (index % 2 === 1) {
        var firstBreak = part.indexOf('\n');
        var code = firstBreak >= 0 && /^[\w+-]*$/.test(part.slice(0, firstBreak)) ? part.slice(firstBreak + 1) : part;
        var pre = el('pre', 'gpt-code');
        pre.appendChild(el('code', null, code.replace(/\n$/, '')));
        container.appendChild(pre);
      } else if (part !== '') {
        renderMarkdownBlock(container, part.replace(/^\n+|\n+$/g, ''));
      }
    });
  }

  function addMessage(message, options) {
    options = options || {};
    els.empty.hidden = true;
    var role = message.role === 'user' ? 'user' : (message.role === 'error' ? 'error' : 'assistant');
    var wrap = el('div', 'gpt-msg gpt-msg-' + role + (options.pending ? ' is-pending' : ''));

    var saved = message.context_report;
    if (typeof saved === 'string') { try { saved = JSON.parse(saved); } catch (e) { saved = null; } }
    if (saved && !message.fallback_from && saved.fallback_from) message.fallback_from = saved.fallback_from;
    if (saved && !message.truncated && saved.truncated) message.truncated = true;
    if (role !== 'user') {
      var meta = role === 'error'
        ? 'Запрос не выполнен'
        : [message.provider || (state.providers[message.provider_id] || {}).name, message.model].filter(Boolean).join(' · ') || 'Нейросеть';
      wrap.appendChild(el('div', 'gpt-msg-meta', meta));
      // Резервирование и обрезанный ответ — видны пользователю явно, а не только администратору.
      if (message.fallback_from) {
        wrap.appendChild(el('div', 'gpt-msg-note', 'Модель «' + message.fallback_from + '» не ответила — ответила резервная.'));
      }
      if (message.truncated) {
        wrap.appendChild(el('div', 'gpt-msg-note', 'Ответ упёрся в лимит длины и может быть неполным — попросите продолжить.'));
      }
    }
    var report = message.context_report;
    if (typeof report === 'string') { try { report = JSON.parse(report); } catch (e) { report = null; } }
    var tokenLine = role === 'assistant' && report ? contextLine(report) : null;
    if (message.attachment_type) {
      wrap.appendChild(el('div', 'gpt-chip', 'Вложение: ' + String(message.attachment_type).toUpperCase() + (message.attachment_size ? ' · ' + formatSize(message.attachment_size) : '')));
    }
    var body = el('div', 'gpt-msg-body');
    renderText(body, message.content);
    if (message.unanonymized) {   // отправлено без обезличивания — «Иван П.» написал сам пользователь, это не замена
      Array.prototype.forEach.call(body.querySelectorAll('.gpt-mask-alias'), function (a) { a.replaceWith(a.textContent); });
    }
    wrap.appendChild(body);
    if (tokenLine) wrap.appendChild(tokenLine);
    if (message.code === 'storage_quota_exceeded') {
      var goToCleanup = el('button', 'btn btn-small btn-primary', 'Управление данными');
      goToCleanup.type = 'button';
      goToCleanup.addEventListener('click', openDataManager);
      wrap.appendChild(goToCleanup);
    }
    if (role === 'assistant') {
      wrap.appendChild(buildMessageActions(message.content, 'Копировать ответ', message.id));
    }
    if (role === 'user') {
      var noteText = options.pending
        ? (options.skipAnonymization ? 'Отправка без обезличивания…' : 'Отправка…')
        : (message.unanonymized ? 'Отправлено без обезличивания' : 'Отправлено в обезличенном виде');
      var note = el('div', 'gpt-msg-note' + (message.unanonymized || options.skipAnonymization ? ' gpt-msg-note-danger' : ''), noteText);
      wrap.appendChild(note);
      wrap.appendChild(buildMessageActions(message.content, 'Копировать сообщение'));
      wrap.setAttribute('data-user-msg', '1');
      wrap.setAttribute('data-preview', String(message.content || '').slice(0, 120));
    }
    els.messages.appendChild(wrap);
    els.messages.scrollTop = els.messages.scrollHeight;
    renderJumpDots();
    return wrap;
  }

  function clearMessages() {
    Array.prototype.slice.call(els.messages.querySelectorAll('.gpt-msg')).forEach(function (node) { node.remove(); });
    els.empty.hidden = false;
    renderJumpDots();
  }

  /* ---------- прокрутка: кнопка "вниз" и точки быстрого перехода ---------- */

  function isNearBottom() {
    return els.messages.scrollHeight - els.messages.scrollTop - els.messages.clientHeight < 80;
  }

  function updateScrollButton() {
    var hasOverflow = els.messages.scrollHeight > els.messages.clientHeight + 40;
    var show = hasOverflow && !isNearBottom();
    els.scrollBottom.hidden = false;
    els.scrollBottom.classList.toggle('visible', show);
  }

  function scrollMessagesToBottom(smooth) {
    if (smooth === false) {
      els.messages.scrollTop = els.messages.scrollHeight;
    } else {
      els.messages.scrollTo({ top: els.messages.scrollHeight, behavior: 'smooth' });
    }
  }

  els.scrollBottom.addEventListener('click', function () { scrollMessagesToBottom(true); });
  els.messages.addEventListener('scroll', updateScrollButton);

  function renderJumpDots() {
    var userMessages = Array.prototype.slice.call(els.messages.querySelectorAll('[data-user-msg]'));
    els.jumpDots.textContent = '';
    if (userMessages.length < 2) {
      els.jumpDots.hidden = true;
      updateScrollButton();
      return;
    }
    els.jumpDots.hidden = false;
    // Не более 12 точек — иначе список своих вопросов в длинном чате не поместится.
    var recent = userMessages.slice(-12);
    recent.forEach(function (node) {
      var dot = el('button', 'gpt-jumpdot');
      dot.type = 'button';
      dot.setAttribute('data-label', node.getAttribute('data-preview') || 'Сообщение');
      dot.addEventListener('click', function () {
        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      els.jumpDots.appendChild(dot);
    });
    updateScrollButton();
  }

  function setHeader(title, projectId) {
    els.title.textContent = title || '';
    var project = projectId ? projectById(projectId) : null;
    els.crumb.hidden = !project;
    if (project) {
      els.crumb.textContent = project.name + ' /';
      els.crumb.onclick = function () { openProject(project.id); };
    }
  }

  function showChatView() {
    els.projectView.hidden = true;
    els.dataManagerView.hidden = true;
    els.messages.hidden = false;
  }

  /* Советы на экране нового чата: с каждым новым чатом — следующий, пока экран открыт — смена раз в 12 с.
     Главный совет (первый: «одна тема — один чат») показывается через раз. Номер — в localStorage, без него — с начала. */
  var tips = [], tipStep = 0, tipTimer = null;
  try { tips = JSON.parse(els.empty.getAttribute('data-tips') || '[]'); } catch (e) { tips = []; }
  try { tipStep = parseInt(localStorage.getItem('llm:tip'), 10) || 0; } catch (e) { tipStep = 0; }
  function tipAt(step) { return step % 2 === 0 || tips.length < 2 ? tips[0] : tips[1 + ((step - 1) / 2) % (tips.length - 1)]; }
  function showTip(advance) {
    var el = $('emptyTip');
    if (!el || !tips.length) return;
    if (advance) {
      tipStep += 1;
      try { localStorage.setItem('llm:tip', String(tipStep)); } catch (e) { /* не запомнится */ }
    }
    el.textContent = tipAt(tipStep);
    el.classList.remove('is-new'); void el.offsetWidth; el.classList.add('is-new');
    clearInterval(tipTimer);
    tipTimer = setInterval(function () { if (!els.empty.hidden && els.empty.offsetParent) showTip(true); }, 12000);
  }
  showTip(true);

  /* Расход токенов чата: каждое сообщение заново отправляет историю, поэтому длинный чат дорожает. Плашка — от 80% лимита. */
  function renderBudget() {
    var box = $('chatBudget'), b = state.currentId && state.budget;
    if (!box) return;
    var pct = b && b.limit > 0 ? b.spent / b.limit : 0;
    box.hidden = pct < 0.8;
    if (box.hidden) return;
    box.setAttribute('data-state', pct >= 1 ? 'full' : 'warn');
    $('chatBudgetText').textContent = pct >= 1
      ? 'Лимит токенов этого чата исчерпан (' + formatNumber(b.spent) + ' из ' + formatNumber(b.limit) + '). Начните новый чат: так дешевле и ответы точнее.'
      : 'Чат уже израсходовал ' + formatNumber(b.spent) + ' из ' + formatNumber(b.limit) + ' токенов: каждое сообщение заново отправляет всю историю и стоит дороже. Для новой темы лучше начать новый чат.';
  }
  $('chatBudgetNew').addEventListener('click', function () { newConversation(state.currentProjectId || null); });

  function newConversation(projectId) {
    convPrefs = { style: null, instructions: '' };
    state.currentId = null;
    state.currentProjectId = projectId || null;
    state.projectData = null;
    showChatView();
    clearMessages();
    els.emptyTitle.textContent = projectId ? 'Новый чат в проекте «' + ((projectById(projectId) || {}).name || '') + '»' : 'Чем помочь?';
    els.input.placeholder = projectId ? 'Новый чат в этом проекте' : 'Спросите что-нибудь';
    showTip(true);
    setHeader('', projectId);
    selectProjectModel(projectId);
    renderLists();
    els.input.focus();
    closeSidebarOnMobile();
  }

  // Новый чат в проекте — модель из настроек проекта (если она есть в списке).
  function selectProjectModel(projectId) {
    var id = projectId && (projectById(projectId) || {}).model_id;
    var option = id && els.model && els.model.tagName === 'SELECT' && els.model.querySelector('option[value$=":' + id + '"]');
    if (option) { els.model.value = option.value; return; }
    // новый чат без модели проекта начинается с базовой модели (её помечает сервер), а не с той, что выбрали в прошлом чате
    if (els.model && els.model.tagName === 'SELECT') {
      for (var i = 0; i < els.model.options.length; i++) {
        if (els.model.options[i].defaultSelected) { els.model.selectedIndex = i; return; }
      }
      els.model.selectedIndex = 0;
    }
  }

  // Значение списка — «провайдер:модель». Диалог восстанавливает свою модель (или модель провайдера по умолчанию).
  function selectProvider(providerId, modelId) {
    if (!els.model || els.model.tagName !== 'SELECT' || !providerId) return;
    var exact = modelId && els.model.querySelector('option[value="' + providerId + ':' + modelId + '"]');
    var any = els.model.querySelector('option[value^="' + providerId + ':"]');
    if (exact || any) els.model.value = (exact || any).value;
  }

  function openConversation(id) {
    if (state.sending) return;
    closeMenu();
    request('GET', 'conversation', null, '&id=' + encodeURIComponent(id)).then(function (data) {
      state.currentId = data.conversation.id;
      state.budget = { spent: data.conversation.tokens_spent || 0, limit: data.conversation.token_limit || 0 };
      state.currentProjectId = data.conversation.project_id;
      state.projectData = null;
      showChatView();
      clearMessages();
      setHeader(data.conversation.title, data.conversation.project_id);
      selectProvider(data.conversation.provider_id, data.conversation.model_id);
      convPrefs = { style: data.conversation.style || null, instructions: data.conversation.instructions || '' };
      (data.messages || []).forEach(function (m) { addMessage(m); });
      renderLists();
      closeSidebarOnMobile();
    }).catch(function (error) { toast(error.message); });
  }

  /* ---------- проекты ---------- */

  $('projectNew').addEventListener('click', function () {
    var input = el('input', 'gpt-rename');
    input.placeholder = 'Название проекта';
    input.maxLength = 80;
    els.projectList.insertBefore(input, els.projectList.firstChild);
    input.focus();
    var done = false;
    function finish(save) {
      if (done) return;
      done = true;
      var name = input.value.trim();
      input.remove();
      if (!save || name === '') return;
      request('POST', 'project_create', form({ name: name })).then(function (data) {
        state.projects = data.projects;
        openProject(data.id);
      }).catch(function (error) { toast(error.message); });
    }
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') finish(true);
      if (e.key === 'Escape') finish(false);
    });
    input.addEventListener('blur', function () { finish(true); });
  });

  function openProject(id) {
    closeMenu();
    request('GET', 'project', null, '&id=' + encodeURIComponent(id)).then(function (data) {
      state.currentId = null;
      state.currentProjectId = id;
      state.projectData = data;
      els.dataManagerView.hidden = true;
      els.projectView.hidden = false;
      clearMessages();
      els.messages.hidden = true;
      els.projectName.textContent = data.project.name;
      $('instructionAddForm').hidden = true;
      $('instructionAddToggle').hidden = false;
      setHeader('', null);
      els.title.textContent = data.project.name;
      renderInstructions();
      renderSources();
      renderProjectForms();
      selectProjectModel(id);
      renderLists();
      switchProjectTab('chats');
      els.input.placeholder = 'Новый чат в этом проекте';
      closeSidebarOnMobile();
    }).catch(function (error) { toast(error.message); });
  }

  function renderSources() {
    var data = state.projectData;
    els.sourceList.textContent = '';
    var total = 0;
    (data.sources || []).forEach(function (source) {
      total += source.chars;
      var wrap = el('div', 'gpt-source-wrap');
      var row = el('div', 'gpt-source');
      var toggle = el('button', 'gpt-source-toggle');
      toggle.type = 'button';
      toggle.title = 'Показать обезличенный текст';
      toggle.setAttribute('aria-label', 'Показать обезличенный текст материала');
      toggle.appendChild(chevronIcon());
      row.appendChild(toggle);
      row.appendChild(el('span', 'gpt-source-type', source.file_type ? source.file_type.toUpperCase() : 'ТЕКСТ'));
      row.appendChild(el('span', 'gpt-source-title', source.title));
      row.appendChild(el('span', 'gpt-source-size', formatNumber(source.chars) + ' симв.'));
      var remove = el('button', 'gpt-item-more', '×');
      remove.type = 'button';
      remove.title = 'Удалить материал';
      remove.addEventListener('click', function (e) {
        e.stopPropagation();
        if (remove.getAttribute('data-confirm') !== '1') {
          remove.setAttribute('data-confirm', '1');
          remove.textContent = 'Удалить?';
          return;
        }
        request('POST', 'project_source_delete', form({ id: source.id })).then(function () {
          data.sources = data.sources.filter(function (s) { return s.id !== source.id; });
          renderSources();
        }).catch(function (error) { toast(error.message); });
      });
      row.appendChild(remove);
      wrap.appendChild(row);

      var preview = el('pre', 'gpt-source-preview');
      preview.hidden = true;
      wrap.appendChild(preview);

      var loaded = false;
      var expandSource = function () {
        var expanded = wrap.classList.toggle('expanded');
        preview.hidden = !expanded;
        if (expanded && !loaded) {
          preview.textContent = 'Загрузка…';
          request('GET', 'project_source_text', null, '&id=' + encodeURIComponent(source.id)).then(function (result) {
            loaded = true;
            preview.textContent = result.text || '(пусто)';
          }).catch(function (error) {
            preview.textContent = 'Не удалось загрузить текст: ' + error.message;
          });
        }
      };
      toggle.addEventListener('click', expandSource);
      row.addEventListener('click', function (e) {
        if (e.target === remove) return;
        expandSource();
      });

      els.sourceList.appendChild(wrap);
    });
    if (!data.sources || data.sources.length === 0) {
      els.sourceList.appendChild(el('p', 'gpt-side-empty', 'Материалов пока нет'));
    }
    var fileCount = data.sources ? data.sources.length : 0;
    var maxFiles = data.sources_max_files || 0;
    els.sourcesMeta.textContent = (maxFiles ? fileCount + ' / ' + maxFiles + ' файлов · ' : '') + formatNumber(total) + ' / ' + formatNumber(data.sources_limit) + ' симв.';
    if (els.projectSourcesTabCount) els.projectSourcesTabCount.textContent = data.sources ? String(data.sources.length) : '';
  }

  function renderProjectChats() {
    var id = state.currentProjectId;
    els.projectChats.textContent = '';
    var chats = state.conversations.filter(function (c) { return c.project_id === id; });
    chats.forEach(function (c) { els.projectChats.appendChild(conversationItem(c)); });
    if (chats.length === 0) {
      els.projectChats.appendChild(el('p', 'gpt-side-empty', 'Чатов в проекте пока нет — напишите сообщение ниже'));
    }
    if (els.projectChatsTabCount) els.projectChatsTabCount.textContent = chats.length > 0 ? String(chats.length) : '';
  }

  /*
   * Вкладки вида проекта (Чаты / Инструкции / Материалы / Память / Настройки).
   * Все панели загружены разом (openProject()), переключение — чисто
   * визуальное, без новых запросов.
   */
  function switchProjectTab(name) {
    document.querySelectorAll('.gpt-project-tab').forEach(function (tab) {
      var active = tab.getAttribute('data-project-tab') === name;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('.gpt-project-tabpanel').forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-project-panel') !== name;
    });
  }

  document.querySelectorAll('.gpt-project-tab').forEach(function (tab) {
    tab.addEventListener('click', function () { switchProjectTab(tab.getAttribute('data-project-tab')); });
  });

  $('instructionAddToggle').addEventListener('click', function () {
    $('instructionAddForm').hidden = false;
    $('instructionAddToggle').hidden = true;
    $('instructionTitle').value = '';
    $('instructionText').value = '';
    $('instructionText').focus();
  });

  $('instructionCancel').addEventListener('click', function () {
    $('instructionAddForm').hidden = true;
    $('instructionAddToggle').hidden = false;
  });

  $('instructionSave').addEventListener('click', function () {
    var text = $('instructionText').value.trim();
    if (text === '') {
      showStatusToast('Введите текст инструкции.', 'error');
      return;
    }
    var button = $('instructionSave');
    button.disabled = true;
    $('instructionStatus').textContent = 'Обезличивание…';
    request('POST', 'project_instruction_add', form({
      project_id: state.currentProjectId, title: $('instructionTitle').value, text: text,
    })).then(function (data) {
      state.projectData.instructions.push(data.instruction);
      renderInstructions();
      $('instructionAddForm').hidden = true;
      $('instructionAddToggle').hidden = false;
      $('instructionStatus').textContent = '';
    }).catch(function (error) {
      $('instructionStatus').textContent = '';
      showStatusToast(error.message, 'error');
    }).finally(function () { button.disabled = false; });
  });

  function renderInstructions() {
    var data = state.projectData;
    var list = $('instructionList');
    list.textContent = '';
    var total = 0;
    (data.instructions || []).forEach(function (instruction) {
      total += instruction.chars;
      var wrap = el('div', 'gpt-source-wrap');
      var row = el('div', 'gpt-source');
      var toggle = el('button', 'gpt-source-toggle');
      toggle.type = 'button';
      toggle.title = 'Показать обезличенный текст';
      toggle.setAttribute('aria-label', 'Показать обезличенный текст инструкции');
      toggle.appendChild(chevronIcon());
      row.appendChild(toggle);
      row.appendChild(el('span', 'gpt-source-title', instruction.title));
      row.appendChild(el('span', 'gpt-source-size', formatNumber(instruction.chars) + ' симв.'));
      var remove = el('button', 'gpt-item-more', '×');
      remove.type = 'button';
      remove.title = 'Удалить блок';
      remove.addEventListener('click', function (e) {
        e.stopPropagation();
        if (remove.getAttribute('data-confirm') !== '1') {
          remove.setAttribute('data-confirm', '1');
          remove.textContent = 'Удалить?';
          return;
        }
        request('POST', 'project_source_delete', form({ id: instruction.id })).then(function () {
          data.instructions = data.instructions.filter(function (s) { return s.id !== instruction.id; });
          renderInstructions();
        }).catch(function (error) { showStatusToast(error.message, 'error'); });
      });
      row.appendChild(remove);
      wrap.appendChild(row);

      var preview = el('pre', 'gpt-source-preview');
      preview.hidden = true;
      wrap.appendChild(preview);

      var loaded = false;
      var expandInstruction = function () {
        var expanded = wrap.classList.toggle('expanded');
        preview.hidden = !expanded;
        if (expanded && !loaded) {
          preview.textContent = 'Загрузка…';
          request('GET', 'project_source_text', null, '&id=' + encodeURIComponent(instruction.id)).then(function (result) {
            loaded = true;
            preview.textContent = result.text || '(пусто)';
          }).catch(function (error) {
            preview.textContent = 'Не удалось загрузить текст: ' + error.message;
          });
        }
      };
      toggle.addEventListener('click', expandInstruction);
      row.addEventListener('click', function (e) {
        if (e.target === remove) return;
        expandInstruction();
      });

      list.appendChild(wrap);
    });
    if (!data.instructions || data.instructions.length === 0) {
      list.appendChild(el('p', 'gpt-side-empty', 'Инструкций пока нет'));
    }
    var maxBlocks = data.instructions_max_blocks || 0;
    var blockCount = data.instructions ? data.instructions.length : 0;
    $('instructionsMeta').textContent = (maxBlocks ? blockCount + ' / ' + maxBlocks + ' блоков · ' : '') + formatNumber(total) + ' симв.';
    if ($('projectInstructionsTabCount')) $('projectInstructionsTabCount').textContent = blockCount > 0 ? String(blockCount) : '';
    $('instructionAddToggle').hidden = maxBlocks > 0 && blockCount >= maxBlocks;
  }

  $('projectRename').addEventListener('click', function () {
    var input = el('input', 'text-input gpt-project-rename');
    input.value = els.projectName.textContent;
    input.maxLength = 80;
    els.projectName.replaceWith(input);
    input.focus();
    var done = false;
    function finish(save) {
      if (done) return;
      done = true;
      input.replaceWith(els.projectName);
      var name = input.value.trim();
      if (!save || name === '' || name === els.projectName.textContent) return;
      request('POST', 'project_update', form({ id: state.currentProjectId, name: name })).then(function (data) {
        state.projects = data.projects;
        els.projectName.textContent = name;
        els.title.textContent = name;
        renderProjects();
      }).catch(function (error) { toast(error.message); });
    }
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') finish(true);
      if (e.key === 'Escape') finish(false);
    });
    input.addEventListener('blur', function () { finish(true); });
  });

  $('projectDelete').addEventListener('click', function (e) {
    var button = e.currentTarget;
    if (button.getAttribute('data-confirm') !== '1') {
      button.setAttribute('data-confirm', '1');
      button.textContent = 'Точно удалить? Чаты останутся';
      return;
    }
    request('POST', 'project_delete', form({ id: state.currentProjectId })).then(function (data) {
      state.projects = data.projects;
      state.conversations = data.conversations;
      button.removeAttribute('data-confirm');
      button.textContent = 'Удалить проект';
      newConversation(null);
    }).catch(function (error) { toast(error.message); });
  });

  els.sourceFile.addEventListener('change', function () {
    var file = els.sourceFile.files && els.sourceFile.files[0];
    els.sourceFileName.textContent = file ? file.name + ' · ' + formatSize(file.size) : '';
  });

  $('sourceAdd').addEventListener('click', function () {
    var file = els.sourceFile.files && els.sourceFile.files[0];
    if (!file && els.sourceText.value.trim() === '') {
      toast('Вставьте текст материала или приложите файл.');
      return;
    }
    if (file && file.size > maxUploadBytes) {
      toast('Файл больше ' + formatSize(maxUploadBytes) + '.');
      return;
    }
    var body = form({ project_id: state.currentProjectId, title: els.sourceTitle.value, text: file ? '' : els.sourceText.value });
    if (file) body.append('file', file, file.name);
    var button = $('sourceAdd');
    button.disabled = true;
    button.textContent = 'Обработка и обезличивание…';
    request('POST', 'project_source_add', body).then(function (data) {
      state.projectData.sources.push(data.source);
      els.sourceTitle.value = '';
      els.sourceText.value = '';
      els.sourceFile.value = '';
      els.sourceFileName.textContent = '';
      renderSources();
    }).catch(function (error) { toast(error.message); }).finally(function () {
      button.disabled = false;
      button.textContent = 'Добавить материал';
    });
  });

  /* ---------- лимит ---------- */

  function renderUsage(usage) {
    if (!usage) return;
    els.usageBox.hidden = false;
    var periodLabel = { day: 'сегодня', week: 'за неделю', month: 'за месяц', none: 'за месяц' }[usage.period] || '';
    if (usage.limit === null) {
      els.usageText.textContent = 'Израсходовано ' + periodLabel + ': ' + formatNumber(usage.used) + ' токенов';
      els.usageBar.parentNode.hidden = true;
      return;
    }
    var percent = usage.limit > 0 ? Math.min(100, usage.used / usage.limit * 100) : 100;
    els.usageText.textContent = 'Лимит ' + periodLabel + ': ' + formatNumber(usage.used) + ' из ' + formatNumber(usage.limit) + ' · обновится ' + formatDate(usage.resets_at);
    els.usageBar.parentNode.hidden = false;
    els.usageBar.style.width = percent + '%';
    els.usageBox.classList.toggle('danger', percent >= 90);
  }

  function formatMb(bytes) {
    return (bytes / 1024 / 1024).toFixed(1).replace('.', ',') + ' МБ';
  }

  function renderStorage(storage) {
    if (!storage) return;
    state.storage = storage;
    els.storageBox.hidden = false;
    if (storage.limit_bytes === null) {
      els.storageText.textContent = 'Хранилище: ' + formatMb(storage.used_bytes) + ' (без ограничения)';
      els.storageBar.parentNode.hidden = true;
    } else {
      var percent = storage.percent || 0;
      els.storageText.textContent = 'Хранилище: ' + formatMb(storage.used_bytes) + ' из ' + formatMb(storage.limit_bytes);
      els.storageBar.parentNode.hidden = false;
      els.storageBar.style.width = percent + '%';
      els.storageBox.classList.toggle('danger', percent >= 90);
    }
    if (els.dataManagerView && !els.dataManagerView.hidden) {
      renderDataManagerStorage();
    }
  }

  function renderDataManagerStorage() {
    var storage = state.storage;
    if (!storage) return;
    els.dataStorageMeta.textContent = storage.limit_bytes === null
      ? formatMb(storage.used_bytes) + ' (без ограничения)'
      : formatMb(storage.used_bytes) + ' из ' + formatMb(storage.limit_bytes);
    els.dataStorageBar.style.width = (storage.percent || (storage.limit_bytes === null ? 0 : 100)) + '%';
  }

  /* ---------- управление данными (самостоятельная очистка) ---------- */

  function openDataManager() {
    closeMenu();
    state.currentId = null;
    els.projectView.hidden = true;
    els.messages.hidden = true;
    els.dataManagerView.hidden = false;
    els.title.textContent = 'Управление данными';
    els.crumb.hidden = true;
    renderDataManagerStorage();
    renderDataChatsList();
    closeSidebarOnMobile();
  }

  function renderDataChatsList() {
    els.dataChatsList.textContent = '';
    els.dataChatsCount.textContent = String(state.conversations.length);
    if (state.conversations.length === 0) {
      els.dataChatsList.appendChild(el('p', 'gpt-side-empty', 'Чатов пока нет'));
    }
    state.conversations.forEach(function (c) {
      var row = el('label', 'gpt-data-row');
      var checkbox = el('input');
      checkbox.type = 'checkbox';
      checkbox.setAttribute('data-chat-checkbox', '');
      checkbox.value = String(c.id);
      checkbox.addEventListener('change', updateDeleteSelectedState);
      row.appendChild(checkbox);
      row.appendChild(el('span', 'gpt-data-row-title', c.title + (c.pinned ? ' · закреплён' : '')));
      row.appendChild(el('span', 'gpt-data-row-meta', formatDate(c.updated_at.slice(0, 10))));
      els.dataChatsList.appendChild(row);
    });
    updateDeleteSelectedState();
  }

  function selectedChatIds() {
    return Array.prototype.slice.call(els.dataChatsList.querySelectorAll('[data-chat-checkbox]:checked')).map(function (cb) { return cb.value; });
  }

  function updateDeleteSelectedState() {
    els.dataDeleteSelected.disabled = selectedChatIds().length === 0;
  }

  els.storageBox.addEventListener('click', openDataManager);
  $('dataManagerClose').addEventListener('click', function () { newConversation(null); });
  $('projectViewClose').addEventListener('click', function () { newConversation(null); });

  els.dataSelectAll.addEventListener('click', function () {
    var boxes = els.dataChatsList.querySelectorAll('[data-chat-checkbox]');
    var allChecked = selectedChatIds().length === boxes.length && boxes.length > 0;
    boxes.forEach(function (cb) { cb.checked = !allChecked; });
    updateDeleteSelectedState();
  });

  // Автосброс "заряженной" кнопки подтверждения, если передумали и не
  // кликнули второй раз — иначе состояние "точно удалить?" остаётся
  // навсегда, и следующий (несвязанный) клик удаляет без предупреждения.
  var CONFIRM_RESET_MS = 4000;
  var deleteSelectedResetTimer = null;
  var deleteAllResetTimer = null;

  els.dataDeleteSelected.addEventListener('click', function (e) {
    var button = e.currentTarget;
    var ids = selectedChatIds();
    if (ids.length === 0) return;
    if (button.getAttribute('data-confirm') !== '1') {
      button.setAttribute('data-confirm', '1');
      button.textContent = 'Точно удалить (' + ids.length + ')?';
      clearTimeout(deleteSelectedResetTimer);
      deleteSelectedResetTimer = setTimeout(function () {
        button.removeAttribute('data-confirm');
        button.textContent = 'Удалить выбранные';
      }, CONFIRM_RESET_MS);
      return;
    }
    clearTimeout(deleteSelectedResetTimer);
    button.disabled = true;
    request('POST', 'bulk_delete_conversations', form({ ids: ids })).then(function (data) {
      state.conversations = data.conversations;
      renderStorage(data.storage);
      renderDataChatsList();
      renderLists();
      showStatusToast(data.deleted > 0 ? 'Удалено чатов: ' + data.deleted + '.' : 'Ничего не удалено — чаты уже отсутствуют.');
    }).catch(function (error) { showStatusToast(error.message, 'error'); }).finally(function () {
      button.removeAttribute('data-confirm');
      button.textContent = 'Удалить выбранные';
      updateDeleteSelectedState();
    });
  });

  els.dataDeleteAll.addEventListener('click', function (e) {
    var button = e.currentTarget;
    if (button.getAttribute('data-confirm') !== '1') {
      button.setAttribute('data-confirm', '1');
      button.textContent = 'Точно удалить всю историю?';
      clearTimeout(deleteAllResetTimer);
      deleteAllResetTimer = setTimeout(function () {
        button.removeAttribute('data-confirm');
        button.textContent = 'Удалить всю историю';
      }, CONFIRM_RESET_MS);
      return;
    }
    clearTimeout(deleteAllResetTimer);
    button.disabled = true;
    request('POST', 'delete_all_conversations', form({})).then(function (data) {
      state.conversations = data.conversations;
      renderStorage(data.storage);
      renderDataChatsList();
      renderLists();
      showStatusToast(data.deleted > 0 ? 'Удалена вся история (' + data.deleted + ').' : 'Ничего не удалено — история уже пуста.');
    }).catch(function (error) { showStatusToast(error.message, 'error'); }).finally(function () {
      button.disabled = false;
      button.removeAttribute('data-confirm');
      button.textContent = 'Удалить всю историю';
    });
  });

  /* ---------- отправка ---------- */

  function renderAttachments() {
    els.attachmentList.textContent = '';
    els.attachmentList.hidden = state.files.length === 0;
    state.files.forEach(function (file, index) {
      var row = el('div', 'gpt-attachment');
      var name = el('span', 'gpt-attachment-name', file.name + ' · ' + formatSize(file.size));
      var remove = el('button', 'gpt-attachment-remove', '×');
      remove.type = 'button';
      remove.setAttribute('aria-label', 'Убрать файл ' + file.name);
      remove.addEventListener('click', function () { removeFile(index); });
      row.appendChild(name);
      row.appendChild(remove);
      els.attachmentList.appendChild(row);
    });
  }

  function removeFile(index) {
    state.files.splice(index, 1);
    els.file.value = '';
    renderAttachments();
  }

  function clearFiles() {
    state.files = [];
    els.file.value = '';
    renderAttachments();
  }

  /*
   * Принимает FileList/массив (drag-and-drop нескольких файлов разом или
   * выбор по одному через input). Отбрасывает неподходящие по типу/размеру
   * файлы с уведомлением, и обрезает до MAX_FILES с отдельным предупреждением,
   * не блокируя приложение остальных.
   */
  function addFiles(fileList) {
    var incoming = Array.prototype.slice.call(fileList || []);
    var rejected = [];
    var accepted = [];
    incoming.forEach(function (file) {
      var ext = (file.name.split('.').pop() || '').toLowerCase();
      if (extensions.indexOf(ext) === -1) {
        rejected.push(file.name + ' — тип .' + ext + ' не поддерживается');
        return;
      }
      if (file.size > maxUploadBytes) {
        rejected.push(file.name + ' — больше ' + formatSize(maxUploadBytes));
        return;
      }
      accepted.push(file);
    });

    var total = state.files.length + accepted.length;
    var skippedByLimit = 0;
    if (total > MAX_FILES) {
      skippedByLimit = total - MAX_FILES;
      accepted = accepted.slice(0, Math.max(0, MAX_FILES - state.files.length));
    }

    state.files = state.files.concat(accepted);
    renderAttachments();

    if (rejected.length) toast(rejected.join('; '));
    if (skippedByLimit > 0) {
      toast('Можно приложить не более ' + MAX_FILES + ' файлов за раз — остальные ' + skippedByLimit + ' пропущены.');
    }
  }

  function setSending(sending) {
    state.sending = sending;
    els.send.disabled = sending || !ready;
    els.send.classList.toggle('busy', sending);
    els.input.disabled = sending || !ready;
  }

  function autosize() {
    els.input.style.height = 'auto';
    els.input.style.height = Math.min(els.input.scrollHeight, window.innerHeight * 0.4) + 'px';
  }

  /*
   * Этапы отправки в строке под сообщением (место под неё уже есть — без сдвига вёрстки). Сервер отвечает одним JSON,
   * поэтому этап оценивается по времени; быстрый путь (< 300 мс) ничего не показывает и не мигает.
   */
  function pendingStages(node, chars, files, skipAnon) {
    var note = node.querySelector('.gpt-msg-note');
    if (!note) return function () {};
    note.setAttribute('role', 'status');
    // ≈ 13 000 знаков/с у сервиса обезличивания (docs/ANONYMIZATION.md, «Скорость»); объём вложения заранее неизвестен — с запасом.
    var sent = Math.max(900, 300 + chars / 13 + files * 1500);
    var stages = [[300, skipAnon ? 'Проверка на пароли и ключи…' : 'Обезличивание…'], [sent, 'Отправлено в нейросеть…'], [sent + 1500, 'Модель отвечает…']];
    var started = Date.now();
    var seconds = null;
    var timers = stages.map(function (stage) {
      return setTimeout(function () {
        note.textContent = '';
        note.appendChild(el('span', 'gpt-stage', stage[1]));
        seconds = el('span', 'gpt-stage-time');
        seconds.setAttribute('aria-hidden', 'true');
        note.appendChild(seconds);
      }, stage[0]);
    });
    var tick = setInterval(function () {
      var s = Math.floor((Date.now() - started) / 1000);
      if (seconds && s >= 5) seconds.textContent = s + ' с';
    }, 1000);
    return function () {
      timers.forEach(clearTimeout);
      clearInterval(tick);
    };
  }

  function send() {
    if (state.sending || !ready) return;
    if (dictation && ['starting', 'listening', 'finishing'].indexOf(dictation.phase) >= 0) {
      // «Отправить» во время записи: запись останавливается, последняя фраза дораспознаётся — и сообщение уходит само
      if (dictation.phase === 'starting' || dictation.sendAfter) return;
      dictation.sendAfter = true;
      (dictation.phase === 'listening' ? dictation.stop() : dictation.settled()).then(function () {
        dictation.sendAfter = false;
        send();
      });
      return;
    }
    var text = els.input.value.trim();
    if (text === '' && state.files.length === 0) {
      els.input.focus();
      return;
    }
    var route = (els.model ? els.model.value : '').split(':');
    var providerId = route[0] || '';
    var modelId = route[1] || '';
    if (!providerId) {
      toast('Нет доступной нейросети.');
      return;
    }

    var fromProjectView = !els.projectView.hidden;
    if (fromProjectView) showChatView();

    var skipAnonymization = !!(els.skipAnonToggle && els.skipAnonToggle.checked);
    var body = form({
      text: text, provider_id: providerId, model_id: modelId || null,
      conversation_id: state.currentId || null,
      project_id: !state.currentId && state.currentProjectId ? state.currentProjectId : null,
      skip_anonymization: skipAnonymization ? '1' : null
    });
    state.files.forEach(function (file) { body.append('files[]', file, file.name); });

    // До ответа сервера исходный текст виден только локально, в этом окне.
    var pending = addMessage({
      role: 'user', content: text,
      attachment_type: state.files.length ? state.files.map(function (f) { return f.name.split('.').pop() || ''; }).join(', ') : null,
      attachment_size: state.files.length ? state.files.reduce(function (sum, f) { return sum + f.size; }, 0) : null
    }, { pending: true, skipAnonymization: skipAnonymization });
    setSending(true);
    var stopStages = pendingStages(pending, text.length, state.files.length, skipAnonymization);

    request('POST', 'send', body).then(function (data) {
      pending.remove();
      els.input.value = '';
      autosize();
      clearFiles();
      if (els.skipAnonToggle) els.skipAnonToggle.checked = false;
      addMessage(data.user_message);
      addMessage(data.assistant_message);
      renderUsage(data.usage);
      renderStorage(data.storage);

      var c = data.conversation;
      state.currentId = c.id;
      state.budget = { spent: c.tokens_spent || 0, limit: c.token_limit || 0 };
      state.currentProjectId = c.project_id;
      state.projectData = null;
      setHeader(c.title, c.project_id);
      var existing = conversationById(c.id);
      state.conversations = state.conversations.filter(function (x) { return x.id !== c.id; });
      state.conversations.unshift({
        id: c.id, title: existing ? existing.title : c.title, provider_id: c.provider_id, project_id: c.project_id,
        pinned: existing ? existing.pinned : false, updated_at: new Date().toISOString()
      });
      renderLists();
    }).catch(function (error) {
      // Исходный текст остаётся в поле ввода — его можно отправить повторно.
      pending.remove();
      if (fromProjectView) setHeader('', state.currentProjectId);
      if (error.code === 'chat_limit' && state.currentId && state.budget) { state.budget.spent = Math.max(state.budget.spent, state.budget.limit); renderBudget(); }
      toast(error.message, error.code);
    }).finally(function () {
      stopStages();
      setSending(false);
      els.input.placeholder = 'Спросите что-нибудь';
      els.input.focus();
    });
  }

  /* ---------- перетаскивание файлов ---------- */

  /*
   * zone — где ловим drag-события (может быть широкой, например вся область
   * чата), highlightEl — что именно подсвечивается рамкой при наведении
   * файла (узкая зона ввода, а не весь экран).
   */
  function initDropzone(zone, highlightEl, onFiles, stopPropagation) {
    if (!zone || !highlightEl) return;
    var depth = 0;
    zone.addEventListener('dragenter', function (e) {
      var types = e.dataTransfer ? e.dataTransfer.types : null;
      if (!types || Array.prototype.indexOf.call(types, 'Files') === -1) return;
      e.preventDefault();
      if (stopPropagation) e.stopPropagation();
      depth++;
      highlightEl.classList.add('drag-over');
    });
    zone.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (stopPropagation) e.stopPropagation();
    });
    zone.addEventListener('dragleave', function (e) {
      e.preventDefault();
      if (stopPropagation) e.stopPropagation();
      depth = Math.max(0, depth - 1);
      if (depth === 0) highlightEl.classList.remove('drag-over');
    });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      if (stopPropagation) e.stopPropagation();
      depth = 0;
      highlightEl.classList.remove('drag-over');
      var files = e.dataTransfer.files;
      if (files && files.length) onFiles(files);
    });
  }

  /*
   * Перетаскивание нескольких файлов в источники проекта разом — каждый
   * файл становится отдельным источником, запросы идут последовательно
   * (не параллельно), чтобы ошибка на одном файле не мешала остальным
   * и не перегружать сервис обезличивания разом несколькими файлами.
   */
  function addSourceFiles(fileList) {
    var files = Array.prototype.slice.call(fileList || []);
    if (files.length === 0) return;
    if (files.length > MAX_FILES) {
      showStatusToast('Можно добавить не более ' + MAX_FILES + ' материалов за раз — остальные ' + (files.length - MAX_FILES) + ' пропущены.');
      files = files.slice(0, MAX_FILES);
    }

    var addButton = $('sourceAdd');
    var total = files.length;
    var done = 0;
    var failed = [];

    function addNext() {
      if (files.length === 0) {
        if (failed.length > 0) {
          showStatusToast('Добавлено материалов: ' + done + ' из ' + total + '. Не удалось: ' + failed.join(', ') + '.', 'error');
        } else if (total > 1) {
          showStatusToast('Добавлено материалов: ' + done + '.');
        }
        addButton.disabled = false;
        addButton.textContent = 'Добавить материал';
        return;
      }
      var file = files.shift();
      if (file.size > maxUploadBytes) {
        failed.push(file.name + ' (больше ' + formatSize(maxUploadBytes) + ')');
        addNext();
        return;
      }
      addButton.disabled = true;
      addButton.textContent = 'Обработка и обезличивание… (' + (done + failed.length + 1) + '/' + total + ')';
      var body = form({ project_id: state.currentProjectId, title: '', text: '' });
      body.append('file', file, file.name);
      request('POST', 'project_source_add', body).then(function (data) {
        state.projectData.sources.push(data.source);
        done++;
        renderSources();
      }).catch(function (error) {
        failed.push(file.name + ' (' + error.message + ')');
      }).finally(addNext);
    }
    addNext();
  }

  initDropzone(els.main, els.composerWrap, function (files) { addFiles(files); });
  // sourcesPanel физически вложена в gptMain — без остановки события
  // drop/dragenter здесь оно всплывает и срабатывает ЕЩЁ РАЗ на внешней
  // зоне выше, добавляя перетащенные файлы одновременно и в источники
  // проекта, и в композер чата.
  initDropzone(els.sourcesPanel, els.sourcesPanel, function (files) {
    addSourceFiles(files);
  }, true);

  /*
   * Вставка изображения из буфера (скриншот Win+Shift+S, PrintScreen, «Копировать изображение»).
   * Браузер отдаёт все такие картинки под именем image.png — даём уникальные имена, иначе вложения неразличимы.
   * Если в буфере есть и текст (копия из Excel, Word), оставляем обычную вставку текста.
   */
  function pastedImages(e) {
    var data = e.clipboardData;
    if (!data || Array.prototype.indexOf.call(data.types || [], 'text/plain') !== -1) return [];
    var stamp = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 19).replace('T', ' ').replace(/:/g, '-');
    var taken = state.files.map(function (f) { return f.name; });
    return Array.prototype.filter.call(data.files || [], function (f) { return /^image\//.test(f.type); }).map(function (f) {
      var ext = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg').replace(/[^a-z0-9]/g, '') || 'png';
      var name = 'Скриншот ' + stamp + '.' + ext;
      for (var n = 2; taken.indexOf(name) !== -1; n++) name = 'Скриншот ' + stamp + ' (' + n + ').' + ext;
      taken.push(name);
      return new File([f], name, { type: f.type });
    });
  }
  document.addEventListener('paste', function (e) {
    var t = e.target;
    // чужие поля (материалы проекта, поиск, формы) не трогаем; в строку ввода чата и на «пустую» страницу — можно
    if (t !== els.input && t && (t.closest && t.closest('input, textarea, select, [contenteditable]') || (els.sourcesPanel && els.sourcesPanel.contains(t)))) return;
    var images = pastedImages(e);
    if (!images.length || state.sending) return;
    e.preventDefault();
    addFiles(images);
  });

  $('chatNew').addEventListener('click', function () { newConversation(null); });
  els.file.addEventListener('change', function () { addFiles(els.file.files); });
  els.form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
  els.input.addEventListener('input', autosize);
  els.input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      send();
    }
  });

  /* ---------- расход токенов: из чего состоял контекст ответа ---------- */

  function contextLine(r) {
    var details = el('details', 'gpt-msg-tokens');
    var used = (r.input_tokens || r.total || 0);
    details.appendChild(el('summary', null, 'Контекст ' + formatNumber(used) + ' из ' + formatNumber(r.window || 0) + ' токенов' + (r.output_tokens ? ' · ответ ' + formatNumber(r.output_tokens) : '')));
    var parts = [
      ['Инструкции', r.instructions], ['Сводка диалога', r.summary], ['Память проекта', r.memory],
      ['Материалы проекта', r.sources, r.sources_total && r.sources < r.sources_total ? 'из ' + formatNumber(r.sources_total) + ' — переданы подходящие фрагменты' : ''],
      ['История', r.history, (r.history_messages || 0) + ' сообщ.' + (r.history_dropped ? ', ранние ' + r.history_dropped + ' — в сводке или не переданы' : '')],
      ['Текущее сообщение', r.current], ['Резерв под ответ', r.reserve]
    ];
    var list = el('dl', 'gpt-tokens-list');
    parts.forEach(function (p) {
      if (!p[1] && p[0] !== 'История') return;
      list.appendChild(el('dt', null, p[0]));
      list.appendChild(el('dd', null, '≈ ' + formatNumber(p[1] || 0) + (p[2] ? ' · ' + p[2] : '')));
    });
    details.appendChild(list);
    if (r.input_tokens) details.appendChild(el('p', 'gpt-tokens-note', 'По данным провайдера: вход ' + formatNumber(r.input_tokens) + ', ответ ' + formatNumber(r.output_tokens || 0) + ' токенов. Остальные цифры — оценка.'));
    return details;
  }

  /* ---------- настройки ответа: стиль, инструкция чата, личные инструкции ---------- */

  var styles = {}, styleDefault = 'balanced', prefs = { style: null, instructions: '' }, convPrefs = { style: null, instructions: '' };
  function openAnswerSettings() {
    var dialog = $('answerSettings');
    var select = $('prefStyle');
    select.textContent = '';
    var def = el('option', null, 'По умолчанию — ' + (styles[styleDefault] || 'сбалансированный'));
    def.value = '';
    select.appendChild(def);
    Object.keys(styles).forEach(function (k) { var o = el('option', null, styles[k]); o.value = k; select.appendChild(o); });
    select.value = (state.currentId ? convPrefs.style : null) || prefs.style || '';
    $('prefPersonal').value = prefs.instructions || '';
    $('prefDialog').value = state.currentId ? (convPrefs.instructions || '') : '';
    $('prefDialog').disabled = !state.currentId;
    $('prefDialogHint').hidden = !!state.currentId;
    $('answerSettingsStatus').textContent = '';
    if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
  }
  function saveAnswerSettings() {
    var status = $('answerSettingsStatus');
    var style = $('prefStyle').value;
    status.textContent = 'Обезличиваем и сохраняем…';
    var jobs = [request('POST', 'user_prefs', form({ style: state.currentId ? (prefs.style || '') : style, instructions: $('prefPersonal').value })).then(function (r) { prefs = r; })];
    if (state.currentId) {
      jobs.push(request('POST', 'conversation_prefs', form({ id: state.currentId, style: style, instructions: $('prefDialog').value })).then(function (r) { convPrefs = r; }));
    }
    Promise.all(jobs).then(function () {
      $('answerSettings').close();
      toast('Настройки ответа сохранены.');
    }).catch(function (error) { status.textContent = error.message; });
  }
  $('answerSettingsOpen').addEventListener('click', openAnswerSettings);
  $('answerSettingsSave').addEventListener('click', saveAnswerSettings);
  /* ---------- проект: инструкции, память, настройки ---------- */

  function projectStatus(id, text) { $(id).textContent = text; }

  function renderProjectForms() {
    var data = state.projectData;
    var project = data.project;
    var settings = project.settings || {};
    $('projectDescription').value = project.description || '';
    $('projectInstructions').value = project.instructions || '';
    $('projectInstructionsMeta').textContent = project.instructions ? formatNumber(project.instructions.length) + ' симв.' : '';
    $('projectMemory').value = project.state || '';
    $('projectMemoryMeta').textContent = formatNumber((project.state || '').length) + ' / ' + formatNumber(data.memory_limit) + ' симв.' +
      (project.state_updated_at ? ' · обновлена ' + new Date(project.state_updated_at).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }) : '');
    $('projectMemoryOff').hidden = settings.use_memory !== false;
    ['projectInstructionsStatus', 'projectMemoryStatus', 'projectSettingsStatus'].forEach(function (id) { projectStatus(id, ''); });

    var model = $('projectModel');
    model.textContent = '';
    var same = el('option', null, 'Как выбрано в чате');
    same.value = '0';
    model.appendChild(same);
    if (els.model && els.model.tagName === 'SELECT') {
      Array.prototype.forEach.call(els.model.options, function (o) {
        var option = el('option', null, o.textContent);
        option.value = o.value.split(':')[1];
        model.appendChild(option);
      });
    }
    model.value = String(settings.model_id || 0);
    if (model.selectedIndex < 0) model.value = '0';
    var style = $('projectStyle');
    style.textContent = '';
    var def = el('option', null, 'По умолчанию');
    def.value = '';
    style.appendChild(def);
    Object.keys(styles).forEach(function (k) { var o = el('option', null, styles[k]); o.value = k; style.appendChild(o); });
    style.value = settings.style || '';
    $('projectShare').value = String(settings.sources_share || 40);
    $('projectUseSources').checked = settings.use_sources !== false;
    $('projectUseMemory').checked = settings.use_memory !== false;
    renderProjectBudget();
  }

  // Оценка токенов проекта в каждом запросе относительно окна модели (выбранной в проекте или в чате).
  function renderProjectBudget() {
    var data = state.projectData;
    var t = data.context_tokens || {};
    var modelId = $('projectModel').value;
    var option = els.model && els.model.tagName === 'SELECT'
      ? (modelId !== '0' && els.model.querySelector('option[value$=":' + modelId + '"]')) || els.model.options[els.model.selectedIndex]
      : null;
    var windowTokens = option ? Number(option.getAttribute('data-window')) || 0 : 0;
    var useMemory = $('projectUseMemory').checked, useSources = $('projectUseSources').checked;
    var total = (t.instructions || 0) + (useMemory ? t.memory || 0 : 0) + (useSources ? t.sources || 0 : 0);
    var list = $('projectBudget');
    list.textContent = '';
    [['Инструкции и описание', t.instructions, ''], ['Память проекта', t.memory, useMemory ? '' : 'выключена'],
      ['Материалы', t.sources, useSources ? '' : 'выключены']].forEach(function (row) {
      list.appendChild(el('dt', null, row[0]));
      list.appendChild(el('dd', null, '≈ ' + formatNumber(row[1] || 0) + (row[2] ? ' · ' + row[2] : '')));
    });
    list.appendChild(el('dt', 'gpt-project-budget-total', 'Всего в каждом запросе'));
    list.appendChild(el('dd', 'gpt-project-budget-total', '≈ ' + formatNumber(total) + (windowTokens ? ' · ' + Math.max(1, Math.round(total * 100 / windowTokens)) + ' % окна' : '')));
    var share = Number($('projectShare').value) || 40;
    var hint = windowTokens ? 'Окно модели «' + option.textContent.trim() + '» — ' + formatNumber(windowTokens) + ' токенов. ' : '';
    if (useSources && windowTokens && (t.sources || 0) > windowTokens * share / 100) {
      hint += 'Материалы больше гарантированной доли: в длинных чатах модель получит только фрагменты, подходящие к вопросу.';
    } else {
      hint += 'Когда история чата перерастает окно, ранняя часть сворачивается в сводку, а факты и решения переходят в память проекта.';
    }
    $('projectBudgetHint').textContent = hint;
  }
  ['projectModel', 'projectShare', 'projectUseSources', 'projectUseMemory'].forEach(function (id) {
    $(id).addEventListener('change', renderProjectBudget);
  });

  function saveProject(fields, statusId, done) {
    projectStatus(statusId, 'Обезличиваем и сохраняем…');
    fields.id = state.currentProjectId;
    return request('POST', 'project_update', form(fields)).then(function (r) {
      done(r);
      projectStatus(statusId, 'Сохранено');
    }).catch(function (error) { projectStatus(statusId, error.message); });
  }
  $('projectInstructionsSave').addEventListener('click', function () {
    saveProject({ description: $('projectDescription').value, instructions: $('projectInstructions').value }, 'projectInstructionsStatus', function (r) {
      state.projectData.project.description = r.description || '';
      state.projectData.project.instructions = r.instructions || '';
      $('projectDescription').value = r.description || '';
      $('projectInstructions').value = r.instructions || '';
      $('projectInstructionsMeta').textContent = r.instructions ? formatNumber(r.instructions.length) + ' симв.' : '';
    });
  });
  function saveMemory(text) {
    saveProject({ memory: text }, 'projectMemoryStatus', function (r) {
      state.projectData.project.state = r.memory || '';
      state.projectData.project.state_updated_at = r.memory ? new Date().toISOString() : null;
      var status = $('projectMemoryStatus').textContent;
      renderProjectForms();
      projectStatus('projectMemoryStatus', status);
    });
  }
  $('projectMemorySave').addEventListener('click', function () { saveMemory($('projectMemory').value); });
  $('projectMemoryClear').addEventListener('click', function () {
    var button = $('projectMemoryClear');
    confirmThen(button, 'Очистить без возврата?', function () {
      button.removeAttribute('data-confirm');
      button.textContent = 'Очистить';
      saveMemory('');
    });
  });
  $('projectSettingsSave').addEventListener('click', function () {
    var settings = {
      model_id: Number($('projectModel').value) || 0, style: $('projectStyle').value, sources_share: Number($('projectShare').value) || 40,
      use_sources: $('projectUseSources').checked, use_memory: $('projectUseMemory').checked
    };
    saveProject({ settings: JSON.stringify(settings) }, 'projectSettingsStatus', function (r) {
      state.projectData.project.settings = r.settings;
      state.projects = r.projects;
      $('projectMemoryOff').hidden = r.settings.use_memory !== false;
      selectProjectModel(state.currentProjectId);
    });
  });

  /* ---------- голосовой ввод ---------- */

  // Состояния кнопки: idle (ожидание) → starting (разрешение микрофона, билет) → listening (запись)
  // → finishing (распознаём хвост) → result (текст в поле) | error. Текст только вставляется в поле:
  // автоматической отправки нет. Отправка во время записи заблокирована.
  var dictation = null;
  function initDictation(cfg) {
    var mic = $('chatMic');
    if (!mic || !cfg || !cfg.enabled || !window.Dictation || !window.Dictation.supported()) return;
    var bar = $('dictationBar'), barText = $('dictationText'), barTime = $('dictationTime');
    var labels = {
      idle: 'Голосовой ввод: начать запись', starting: 'Подключаем микрофон…', listening: 'Остановить запись',
      finishing: 'Распознаём речь…', result: 'Голосовой ввод: записать ещё', error: 'Голосовой ввод: повторить'
    };
    var texts = { starting: 'Подключаем микрофон…', listening: 'Говорите — текст появится в поле. Отправить — сразу остановит запись и отправит', finishing: 'Распознаём последнюю фразу…', result: 'Текст добавлен в поле — проверьте и отправьте' };
    var seen = {}, timer = null, startedAt = 0, hideTimer = null;
    function tick() {
      var s = Math.floor((Date.now() - startedAt) / 1000);
      barTime.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2) + ' / ' + Math.floor(cfg.max_seconds / 60) + ':' + ('0' + (cfg.max_seconds % 60)).slice(-2);
    }
    function render(phase, message) {
      mic.setAttribute('data-state', phase);
      mic.setAttribute('aria-label', labels[phase] || labels.idle);
      mic.title = labels[phase] || labels.idle;
      mic.setAttribute('aria-pressed', phase === 'listening' ? 'true' : 'false');
      mic.disabled = phase === 'starting' || phase === 'finishing';
      bar.setAttribute('data-state', phase);
      clearTimeout(hideTimer);
      bar.hidden = phase === 'idle' && !message;
      barText.textContent = message || texts[phase] || '';
      $('dictationCancel').hidden = !(phase === 'listening' || phase === 'starting' || phase === 'finishing');
      if (phase === 'listening' && !timer) { startedAt = Date.now(); tick(); timer = setInterval(tick, 500); }
      if (phase !== 'listening' && phase !== 'finishing') { clearInterval(timer); timer = null; barTime.textContent = ''; }
      if (phase === 'result') hideTimer = setTimeout(function () { bar.hidden = true; }, 6000);
      // во время записи «Отправить» доступна: она останавливает запись и отправляет надиктованное (send)
      els.send.disabled = state.sending || !ready || phase === 'starting' || phase === 'finishing';
    }
    dictation = new window.Dictation({
      ticket: function () { return request('POST', 'dictation_ticket', form({})); },
      onPhase: render,
      onLevel: function (level) { mic.style.setProperty('--level', Math.min(1, level * 6).toFixed(2)); },
      onFinal: function (seq, text) {
        var input = els.input;
        var r = window.insertDictated(input.value, input.selectionStart == null ? input.value.length : input.selectionStart, text, seq, seen);
        input.value = r.value;
        input.setSelectionRange(r.caret, r.caret);
        autosize();
      }
    });
    mic.hidden = false;
    mic.addEventListener('click', function () {
      if (dictation.phase === 'listening') { dictation.stop(); return; }
      seen = {};
      els.input.focus();
      dictation.start();
    });
    $('dictationCancel').addEventListener('click', function () { dictation.cancel(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && (dictation.phase === 'listening' || dictation.phase === 'starting')) dictation.cancel();
    });
    window.addEventListener('pagehide', function () { if (dictation.phase !== 'idle') dictation.cancel(); });
  }

  setSending(false);

  request('GET', 'bootstrap').then(function (data) {
    initDictation(data.dictation);
    styles = data.styles || {};
    styleDefault = data.style_default || 'balanced';
    prefs = data.prefs || prefs;
    (data.providers || []).forEach(function (p) { state.providers[p.id] = p; });
    state.conversations = data.conversations || [];
    state.projects = data.projects || [];
    renderLists();
    renderUsage(data.usage);
    renderStorage(data.storage);
  }).catch(function (error) {
    els.chatList.textContent = '';
    toast(error.message);
  });
})();
