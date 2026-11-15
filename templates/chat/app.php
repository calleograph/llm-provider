<?php
/** @var string $csrfToken */
/** @var \App\Llm\Chat\ChatUser $user */
/** @var array<int,array{id:int,name:string,model:string,provider_type:string}> $providers */
/** @var bool $anonymizationReady */
/** @var string[] $extensions */
/** @var int $maxUploadMb */
/** @var bool $canSkipAnonymization */

use App\Http\View;

$accept = implode(',', array_map(static fn (string $ext): string => '.' . $ext, $extensions));
$icon = static fn (string $path): string => '<svg viewBox="0 0 24 24" aria-hidden="true">' . $path . '</svg>';
$iconPanel = $icon('<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16"/>');
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Корпоративный AI-чат</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="<?php echo \App\Http\View::asset('assets/css/app.css'); ?>">
</head>
<body class="chat-body">
<div class="gpt-shell" id="chatApp"
     data-csrf="<?php echo View::e($csrfToken); ?>"
     data-max-upload-mb="<?php echo (int) $maxUploadMb; ?>"
     data-extensions="<?php echo View::e(implode(',', $extensions)); ?>"
     data-ready="<?php echo $anonymizationReady && $providers !== [] ? '1' : '0'; ?>"
     data-can-skip-anonymization="<?php echo $canSkipAnonymization ? '1' : '0'; ?>">

  <aside class="gpt-sidebar" id="gptSidebar" aria-label="Боковая панель">
    <div class="gpt-side-top">
      <span class="gpt-brand">AI-чат</span>
      <button type="button" class="gpt-icon-btn" id="sidebarClose" title="Закрыть боковую панель" aria-label="Закрыть боковую панель"><?php echo $iconPanel; ?></button>
    </div>

    <button type="button" class="gpt-side-item gpt-side-strong" id="chatNew">
      <?php echo $icon('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>'); ?>
      Новый чат
    </button>
    <label class="gpt-search">
      <?php echo $icon('<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'); ?>
      <input type="search" id="chatSearch" placeholder="Поиск по чатам" autocomplete="off" aria-label="Поиск по чатам">
    </label>

    <div class="gpt-side-scroll">
      <section id="searchSection" hidden>
        <p class="gpt-side-title">Результаты поиска</p>
        <nav id="searchResults"></nav>
      </section>
      <section id="pinnedSection" hidden>
        <p class="gpt-side-title">Закреплённые</p>
        <nav id="pinnedList"></nav>
      </section>
      <section id="chatsSection">
        <nav id="chatList"><p class="gpt-side-empty">Загрузка…</p></nav>
      </section>
    </div>

    <div class="gpt-projects">
      <div class="gpt-side-title-row">
        <p class="gpt-side-title">Проекты</p>
        <button type="button" class="gpt-icon-btn gpt-icon-small" id="projectNew" title="Новый проект" aria-label="Новый проект"><?php echo $icon('<path d="M12 5v14M5 12h14"/>'); ?></button>
      </div>
      <nav id="projectList"></nav>
    </div>

    <div class="gpt-user">
      <div class="gpt-usage" id="usageBox" hidden>
        <div class="gpt-usage-text" id="usageText"></div>
        <div class="gpt-usage-bar"><span id="usageBar"></span></div>
      </div>
      <button type="button" class="gpt-usage gpt-usage-storage" id="storageBox" hidden>
        <div class="gpt-usage-text" id="storageText"></div>
        <div class="gpt-usage-bar"><span id="storageBar"></span></div>
      </button>
      <div class="gpt-user-row">
        <span class="gpt-avatar" aria-hidden="true"><?php echo View::e(mb_strtoupper(mb_substr($user->username, 0, 1))); ?></span>
        <span class="gpt-user-name" title="<?php echo View::e(implode(', ', $user->groupNames)); ?>"><?php echo View::e($user->logName()); ?></span>
      </div>
      <div class="gpt-user-actions">
        <div class="theme-toggle" role="group" aria-label="Тема оформления">
          <button type="button" class="active" data-theme-btn="light" aria-label="Светлая тема" title="Светлая тема">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
          </button>
          <button type="button" data-theme-btn="dark" aria-label="Тёмная тема" title="Тёмная тема">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.2A8 8 0 0 1 8.8 4 8 8 0 1 0 20 15.2Z"/></svg>
          </button>
        </div>
        <form method="post" action="index.php?action=logout">
          <input type="hidden" name="csrf" value="<?php echo View::e($csrfToken); ?>">
          <button type="submit" class="link-button gpt-logout">Выйти</button>
        </form>
      </div>
    </div>
  </aside>
  <div class="gpt-backdrop" id="sidebarBackdrop" hidden></div>

  <main class="gpt-main" id="gptMain">
    <header class="gpt-header">
      <button type="button" class="gpt-icon-btn" id="sidebarOpen" title="Открыть боковую панель" aria-label="Открыть боковую панель"><?php echo $iconPanel; ?></button>
<?php
/** @var array<int,array<string,mixed>> $models */
$models = $models ?? [];
$manyProviders = count(array_unique(array_column($models, 'provider_id'))) > 1;
$modelLabel = static fn (array $m): string => (string) $m['display_name'] . ($manyProviders ? ' · ' . (string) $m['provider_name'] : '');
?>
<?php if (count($models) > 1): ?>
      <select class="gpt-model-select" id="chatModel" aria-label="Модель">
<?php foreach ($models as $m): ?>
        <option value="<?php echo (int) $m['provider_id'] . ':' . (int) $m['id']; ?>"<?php echo !empty($m['provider_default']) && !empty($m['is_default']) ? ' selected' : ''; ?>><?php echo View::e($modelLabel($m)); ?></option>
<?php endforeach; ?>
      </select>
<?php elseif (count($models) === 1): ?>
      <span class="gpt-model-single"><?php echo View::e($modelLabel($models[0])); ?></span>
      <input type="hidden" id="chatModel" value="<?php echo (int) $models[0]['provider_id'] . ':' . (int) $models[0]['id']; ?>">
<?php endif; ?>
      <div class="gpt-crumbs">
        <button type="button" class="gpt-crumb-project" id="crumbProject" hidden></button>
        <span class="gpt-title" id="chatTitle"></span>
      </div>
      <button type="button" class="gpt-icon-btn" id="answerSettingsOpen" title="Настройки ответа" aria-label="Настройки ответа" aria-haspopup="dialog">
        <?php echo $icon('<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>'); ?>
      </button>
    </header>

<?php if ($providers === []): ?>
    <div class="alert alert-warn gpt-banner">Вам не назначено ни одной нейросети. Обратитесь к администратору, чтобы получить доступ.</div>
<?php elseif (!$anonymizationReady): ?>
    <div class="alert alert-warn gpt-banner">Сервис обезличивания не настроен — отправка сообщений временно недоступна.</div>
<?php endif; ?>

    <section class="gpt-project" id="projectView" hidden>
      <div class="gpt-project-head">
        <span class="gpt-project-icon" aria-hidden="true"><?php echo $icon('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>'); ?></span>
        <h2 id="projectName"></h2>
        <button type="button" class="btn btn-small" id="projectRename">Переименовать</button>
        <button type="button" class="btn btn-small btn-danger" id="projectDelete">Удалить проект</button>
        <button type="button" class="gpt-icon-btn gpt-panel-close" id="projectViewClose" title="Закрыть и вернуться в чат" aria-label="Закрыть"><?php echo $icon('<path d="M18 6 6 18M6 6l12 12"/>'); ?></button>
      </div>
      <div class="gpt-project-tabs" role="tablist">
        <button type="button" class="gpt-project-tab active" data-project-tab="chats" role="tab" aria-selected="true">Чаты <span class="gpt-panel-meta" id="projectChatsTabCount"></span></button>
        <button type="button" class="gpt-project-tab" data-project-tab="about" role="tab" aria-selected="false">О проекте</button>
        <button type="button" class="gpt-project-tab" data-project-tab="instructions" role="tab" aria-selected="false">Инструкции <span class="gpt-panel-meta" id="projectInstructionsTabCount"></span></button>
        <button type="button" class="gpt-project-tab" data-project-tab="sources" role="tab" aria-selected="false">Источники <span class="gpt-panel-meta" id="projectSourcesTabCount"></span></button>
      </div>

      <div class="gpt-project-tabpanel" data-project-panel="chats">
        <nav id="projectChats" class="gpt-project-chats"></nav>
      </div>

      <div class="gpt-project-tabpanel" data-project-panel="about" hidden>
        <div class="gpt-panel">
          <p class="gpt-panel-title">Описание</p>
          <p class="gpt-panel-hint">Коротко: что это за работа и какой результат нужен. Модель видит описание во всех чатах проекта; перед сохранением текст обезличивается.</p>
          <textarea class="text-input" id="projectDescription" rows="3" maxlength="1000" placeholder="Например: подготовка договора поставки, ответственный — юридический отдел"></textarea>
          <div class="gpt-panel-actions">
            <button type="button" class="btn btn-small btn-primary" id="projectDescriptionSave">Сохранить описание</button>
            <span class="gpt-panel-status" id="projectDescriptionStatus"></span>
          </div>
          <p class="gpt-panel-title">Состояние работы</p>
          <p class="gpt-panel-hint">Резюме, ключевые факты и решения, незавершённые задачи. Обновляется само по мере работы в чатах проекта — так модель помнит, что связывает разные диалоги.</p>
          <div class="gpt-project-state" id="projectState">Пока пусто — появится после нескольких сообщений в чатах проекта.</div>
        </div>
      </div>

      <div class="gpt-project-tabpanel" data-project-panel="instructions" hidden>
        <div class="gpt-panel">
          <p class="gpt-panel-title">Инструкции <span class="gpt-panel-meta" id="instructionsMeta"></span></p>
          <p class="gpt-panel-hint">
            Короткие правила поведения модели в этом проекте — можно добавить несколько отдельных
            блоков (например «Стиль ответа», «Термины компании»). Каждый блок ограничен по длине,
            чтобы не переполнить контекст модели. Перед сохранением текст обезличивается.
          </p>
          <div id="instructionList" class="gpt-sources"></div>
          <div class="gpt-instruction-add" id="instructionAddForm" hidden>
            <input class="text-input" id="instructionTitle" maxlength="120" placeholder="Название блока (необязательно)">
            <textarea class="text-input" id="instructionText" rows="4" placeholder="Например: отвечай кратко, в деловом стиле"></textarea>
            <div class="gpt-panel-actions">
              <button type="button" class="btn btn-small btn-primary" id="instructionSave">Сохранить блок</button>
              <button type="button" class="btn btn-small" id="instructionCancel">Отмена</button>
              <span class="gpt-panel-status" id="instructionStatus"></span>
            </div>
          </div>
          <button type="button" class="btn btn-small" id="instructionAddToggle">+ Добавить блок инструкций</button>
        </div>
      </div>

      <div class="gpt-project-tabpanel" data-project-panel="sources" hidden>
        <div class="gpt-panel" id="sourcesPanel">
          <div class="gpt-dropzone" id="sourceDropzone"><span>Отпустите файлы, чтобы добавить в источники</span></div>
          <p class="gpt-panel-title">Источники <span class="gpt-panel-meta" id="sourcesMeta"></span></p>
          <p class="gpt-panel-hint">
            Документы и тексты, которые модель учитывает во всех чатах проекта. Если источники помещаются
            в контекст модели — передаются целиком; если нет — передаются фрагменты, наиболее подходящие
            к вопросу, без повторов. Хранятся только в обезличенном виде. Можно перетащить сразу
            несколько файлов прямо сюда.
          </p>
          <div id="sourceList" class="gpt-sources"></div>
          <div class="gpt-source-add">
            <input class="text-input" id="sourceTitle" maxlength="120" placeholder="Название (необязательно)">
            <textarea class="text-input" id="sourceText" rows="3" placeholder="Вставьте текст или приложите файл"></textarea>
            <div class="gpt-panel-actions">
              <label class="btn btn-small gpt-file-btn">Файл<input type="file" id="sourceFile" class="gpt-file-input" accept="<?php echo View::e($accept); ?>"></label>
              <span class="gpt-panel-status" id="sourceFileName"></span>
              <button type="button" class="btn btn-small btn-primary" id="sourceAdd">Добавить источник</button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="gpt-project" id="dataManagerView" hidden>
      <div class="gpt-project-head">
        <span class="gpt-project-icon" aria-hidden="true"><?php echo $icon('<path d="M3 7h18M6 7v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>'); ?></span>
        <h2>Управление данными</h2>
        <button type="button" class="gpt-icon-btn gpt-panel-close" id="dataManagerClose" title="Закрыть и вернуться в чат" aria-label="Закрыть"><?php echo $icon('<path d="M18 6 6 18M6 6l12 12"/>'); ?></button>
      </div>
      <div class="gpt-panel">
        <p class="gpt-panel-title">Хранилище <span class="gpt-panel-meta" id="dataStorageMeta"></span></p>
        <p class="gpt-panel-hint">
          Учитывается объём обезличенного текста ваших чатов и источников проектов. Исходные загруженные файлы
          на сервере не хранятся — они удаляются сразу после обработки.
        </p>
        <div class="gpt-usage-bar gpt-usage-bar-wide"><span id="dataStorageBar"></span></div>
      </div>
      <div class="gpt-panel">
        <div class="llm-toolbar">
          <p class="gpt-panel-title">Чаты <span class="gpt-panel-meta" id="dataChatsCount"></span></p>
          <div class="gpt-panel-actions">
            <button type="button" class="btn btn-small" id="dataSelectAll">Выбрать все</button>
            <button type="button" class="btn btn-small btn-danger" id="dataDeleteSelected" disabled>Удалить выбранные</button>
            <button type="button" class="btn btn-small btn-danger" id="dataDeleteAll">Удалить всю историю</button>
          </div>
        </div>
        <div id="dataChatsList" class="gpt-data-list"></div>
      </div>
    </section>

    <div class="gpt-messages-wrap">
      <div class="gpt-messages" id="chatMessages" aria-live="polite">
        <div class="gpt-empty" id="chatEmpty">
          <h2 id="emptyTitle">Чем помочь?</h2>
          <p class="muted">Текст и файлы (<?php echo View::e(strtoupper(implode(', ', array_values(array_intersect(['pdf', 'docx', 'odt', 'xlsx', 'txt', 'csv', 'md'], $extensions)))) . (array_intersect(['png', 'jpg', 'jpeg'], $extensions) !== [] ? ', изображения' : '')); ?>, до <?php echo (int) $maxUploadMb; ?> МБ)
            перед отправкой во внешний сервис обезличиваются. В истории хранится только обезличенный текст.</p>
        </div>
      </div>
      <nav class="gpt-jumpdots" id="jumpDots" aria-label="Быстрый переход к своим сообщениям" hidden></nav>
      <button type="button" class="gpt-scroll-bottom" id="scrollToBottom" title="К последнему сообщению" aria-label="К последнему сообщению" hidden>
        <?php echo $icon('<path d="M12 5v14M5 12l7 7 7-7"/>'); ?>
      </button>
    </div>

    <div class="gpt-composer-wrap" id="composerWrap">
      <div class="gpt-dropzone" id="chatDropzone"><span>Отпустите файлы, чтобы приложить к сообщению</span></div>
      <form class="gpt-composer" id="chatForm" autocomplete="off">
        <div class="gpt-attachment-list" id="chatAttachmentList" hidden></div>
        <textarea class="gpt-input" id="chatInput" rows="1" placeholder="Спросите что-нибудь" aria-label="Сообщение"></textarea>
        <div class="gpt-dictation" id="dictationBar" data-state="idle" role="status" aria-live="polite" hidden>
          <span class="gpt-dictation-dot" aria-hidden="true"></span>
          <span class="gpt-dictation-text" id="dictationText"></span>
          <span class="gpt-dictation-time" id="dictationTime"></span>
          <button type="button" class="btn btn-small" id="dictationStop">Стоп</button>
          <button type="button" class="btn btn-small btn-ghost" id="dictationCancel">Отмена</button>
        </div>
        <div class="gpt-composer-row">
          <label class="gpt-icon-btn gpt-attach" title="Приложить файлы (до 5)">
            <?php echo $icon('<path d="m21 12-8.5 8.5a5 5 0 0 1-7-7L14 5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4L15.5 8"/>'); ?>
            <input type="file" id="chatFile" class="gpt-file-input" accept="<?php echo View::e($accept); ?>" multiple>
          </label>
          <span class="gpt-hint">Enter — отправить, Shift+Enter — новая строка</span>
<?php if ($canSkipAnonymization): ?>
          <label class="gpt-skip-anon" id="skipAnonWrap" title="Сообщение и файлы уйдут в нейросеть напрямую, без обезличивания. Используйте только если уверены, что в них нет персональных или конфиденциальных данных.">
            <input type="checkbox" id="skipAnonToggle">
            <span class="gpt-skip-anon-mark">!</span>
            <span class="gpt-skip-anon-text">не обезличивать</span>
          </label>
<?php endif; ?>
          <button type="button" class="gpt-icon-btn gpt-mic" id="chatMic" data-state="idle" title="Голосовой ввод" aria-label="Голосовой ввод: начать запись" aria-pressed="false" hidden>
            <?php echo $icon('<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>'); ?>
          </button>
          <button class="gpt-send" type="submit" id="chatSend" title="Отправить" aria-label="Отправить"><?php echo $icon('<path d="M12 19V5M5 12l7-7 7 7"/>'); ?></button>
        </div>
      </form>
    </div>
    <p class="gpt-footnote">Персональные и конфиденциальные данные заменяются метками до отправки во внешнюю нейросеть.</p>
  </main>
</div>
<div class="gpt-menu" id="ctxMenu" role="menu" hidden></div>
<dialog class="gpt-dialog" id="answerSettings" aria-labelledby="answerSettingsTitle">
  <form method="dialog" class="gpt-dialog-body" id="answerSettingsForm">
    <h2 class="gpt-dialog-title" id="answerSettingsTitle">Настройки ответа</h2>
    <label class="field-label" for="prefStyle">Стиль ответа</label>
    <select class="text-input" id="prefStyle"></select>
    <label class="field-label" for="prefDialog">Инструкция для этого чата</label>
    <textarea class="text-input" id="prefDialog" rows="3" maxlength="4000" placeholder="Например: отвечай с примерами на Python"></textarea>
    <p class="gpt-panel-hint" id="prefDialogHint">Появится после первого сообщения в чате.</p>
    <label class="field-label" for="prefPersonal">Мои постоянные инструкции (для всех чатов)</label>
    <textarea class="text-input" id="prefPersonal" rows="3" maxlength="4000" placeholder="Например: я юрист, термины можно не объяснять"></textarea>
    <p class="gpt-panel-hint">Инструкции обезличиваются перед сохранением. Правила достоверности и безопасности важнее любых инструкций.</p>
    <div class="gpt-panel-actions">
      <button type="button" class="btn btn-primary" id="answerSettingsSave">Сохранить</button>
      <button type="submit" class="btn btn-ghost" value="cancel">Отмена</button>
      <span class="gpt-panel-status" id="answerSettingsStatus" role="status"></span>
    </div>
  </form>
</dialog>
<script src="<?php echo \App\Http\View::asset('assets/js/app.js'); ?>"></script>
<script src="<?php echo \App\Http\View::asset('assets/js/dictation.js'); ?>"></script>
<script src="<?php echo \App\Http\View::asset('assets/js/chat.js'); ?>"></script>
</body>
</html>
