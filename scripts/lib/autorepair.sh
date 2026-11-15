# shellcheck shell=bash
# Автопочинка и выбор цели обновления по git.
# Подключается из check-updates.sh и update.sh после common.sh (нужны REPO_ROOT, log/ok/warn/info, DATA_ROOT — для сохранения правок).
# Правило: ничего не удаляется безвозвратно. Локальные правки и коммиты СНАЧАЛА сохраняются в ${BACKUP_DIR:-$DATA_ROOT/backups}/local-changes-*/
# (и в git), и только потом рабочая копия приводится к целевой версии. Никаких reset --hard; неотслеживаемые файлы (свой compose-override,
# заметки администратора) не трогаются.

# «detected dubious ownership» (git от root или помощника, а каталог принадлежит другому пользователю): разрешаем ТОЛЬКО этот каталог
# и ТОЛЬКО для процесса обновления — через окружение, глобальный git-конфиг root и владельца не меняется. Плюс таймауты сети и без запроса пароля.
upd_git_env() {
  export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$REPO_ROOT"
  export GIT_TERMINAL_PROMPT=0 GIT_HTTP_LOW_SPEED_LIMIT=1000 GIT_HTTP_LOW_SPEED_TIME=60
}

# Зависшие блокировки git (процесс был убит: перезагрузка, нехватка памяти, прерванное обновление): *.lock в .git старше минуты.
# Не трогаем, если сейчас в этом каталоге работает git (его cwd внутри репозитория — так делает git -C).
upd_git_unlock() {
  local gd f p n=0
  gd="$(git -C "$REPO_ROOT" rev-parse --absolute-git-dir 2>/dev/null)" || return 0
  for p in $(pgrep -x git 2>/dev/null); do
    case "$(readlink "/proc/$p/cwd" 2>/dev/null)/" in "$REPO_ROOT"/*) return 0 ;; esac
  done
  while IFS= read -r f; do
    [ -n "$f" ] && rm -f -- "$f" && n=$((n + 1))
  done < <(find "$gd" -maxdepth 4 -name '*.lock' -type f -mmin +1 2>/dev/null)
  [ "$n" -gt 0 ] && warn "Автопочинка: сняты зависшие блокировки git ($n) — прежний процесс git был прерван"
  return 0
}

upd_git_fetch() { # повторы с паузой и таймаутом: временный сбой сети не останавливает обновление
  local i=1 n="${GIT_RETRIES:-3}"
  while :; do
    timeout "${GIT_FETCH_TIMEOUT:-300}" git -C "$REPO_ROOT" fetch -q --tags "$@" origin && return 0
    [ "$i" -ge "$n" ] && return 1
    warn "git fetch: сбой (попытка $i/$n) — повтор через $((i * 5)) с"; sleep $((i * 5)); i=$((i + 1))
  done
}

# fetch с починкой: блокировки → мелкий клон догружается (иначе не вычислить «новые коммиты» и перемотку) → повторы →
# принудительное обновление тегов (тег переставили в origin). Нет сети — не чинится: код возврата 1.
upd_git_fetch_repair() {
  upd_git_env; upd_git_unlock
  if [ "$(git -C "$REPO_ROOT" rev-parse --is-shallow-repository 2>/dev/null)" = true ]; then
    warn "Автопочинка: мелкий клон — догружаю историю (git fetch --unshallow)"
    upd_git_fetch --unshallow || return 1
  fi
  upd_git_fetch && return 0
  upd_git_unlock
  warn "Автопочинка: git fetch не прошёл — повтор с принудительным обновлением тегов"
  GIT_RETRIES=1 upd_git_fetch --force
}

# Канал релизов: самый новый тег vX.Y.Z (без rc), установленный тег HEAD.
upd_release_tag() { git -C "$REPO_ROOT" tag -l 'v*' --sort=-v:refname 2>/dev/null | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | head -n 1; }
upd_head_release_tag() { git -C "$REPO_ROOT" tag --points-at HEAD 2>/dev/null | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -n 1; }

# upd_resolve_target [REF] → CHANNEL (ref|branch|release), BRANCH, TARGET (полный SHA), TARGET_NAME; 1 — цели нет.
#  ветка с origin/<ветка> → перемотка вперёд по ветке; отсоединённый HEAD (установка с тега), ветка без origin — самый новый релизный тег,
#  без тегов — ветка по умолчанию origin. Установка новее цели (локальные коммиты поверх неё) — TARGET=HEAD, назад не откатываемся.
upd_resolve_target() {
  local ref="${1:-}" head t
  head="$(git -C "$REPO_ROOT" rev-parse HEAD)"
  BRANCH="$(git -C "$REPO_ROOT" symbolic-ref --short -q HEAD || true)"
  if [ -n "$ref" ]; then
    CHANNEL=ref; TARGET_NAME="$ref"
    TARGET="$(git -C "$REPO_ROOT" rev-parse --verify --quiet "origin/$ref^{commit}" || git -C "$REPO_ROOT" rev-parse --verify --quiet "$ref^{commit}")" || return 1
  elif [ -n "$BRANCH" ] && TARGET="$(git -C "$REPO_ROOT" rev-parse --verify --quiet "refs/remotes/origin/$BRANCH^{commit}")"; then
    CHANNEL=branch; TARGET_NAME="origin/$BRANCH"
  else
    CHANNEL=release; TARGET_NAME="$(upd_release_tag)"
    if [ -n "$TARGET_NAME" ]; then TARGET="$(git -C "$REPO_ROOT" rev-parse "$TARGET_NAME^{commit}")"
    else
      for t in origin/HEAD origin/main; do TARGET="$(git -C "$REPO_ROOT" rev-parse --verify --quiet "$t^{commit}")" && { TARGET_NAME="$t"; break; }; done
      [ -n "$TARGET_NAME" ] || return 1
    fi
  fi
  if [ "$head" != "$TARGET" ] && git -C "$REPO_ROOT" merge-base --is-ancestor "$TARGET" "$head"; then TARGET="$head"; fi
  return 0
}

upd_saved_dir() { # новый каталог для сохранения локальных изменений (только владельцу)
  local d; d="${BACKUP_DIR:-$DATA_ROOT/backups}/local-changes-$(date +%Y%m%d-%H%M%S)"
  mkdir -p "$d" && chmod 700 "$d" && printf '%s\n' "$d"
}

# Правки отслеживаемых файлов (раньше — «локальные правки, обновление остановлено»): changes.patch, files.tar.gz (изменённые файлы целиком),
# status.txt, README.txt с командами возврата и копия в git stash. Затем рабочая копия чистая. Печатает каталог; 1 — не удалось (ничего не удалено).
upd_save_local_changes() {
  local d
  d="$(upd_saved_dir)" || return 1
  git -C "$REPO_ROOT" status --porcelain --untracked-files=no > "$d/status.txt"
  git -C "$REPO_ROOT" diff --binary HEAD > "$d/changes.patch" && [ -s "$d/changes.patch" ] || return 1
  git -C "$REPO_ROOT" diff --name-only -z --diff-filter=d HEAD | tar -C "$REPO_ROOT" --null -T - -czf "$d/files.tar.gz" 2>/dev/null || true
  cat > "$d/README.txt" <<EOF
Локальные правки файлов проекта, найденные перед обновлением $(date -Is) на коммите $(git -C "$REPO_ROOT" rev-parse --short=12 HEAD).
Обновление сохранило их здесь и продолжилось на чистой копии. Ничего не удалено.
  status.txt      — что было изменено
  changes.patch   — вернуть: git -C $REPO_ROOT apply --3way $d/changes.patch
  files.tar.gz    — изменённые файлы целиком (как были до обновления)
Та же копия — в git stash (git -C $REPO_ROOT stash list). .env, данные и неотслеживаемые файлы не менялись.
EOF
  git -C "$REPO_ROOT" -c user.name=llmp-update -c user.email=update@localhost stash push -q -m "llmp-update-$(date +%Y%m%d-%H%M%S)" >/dev/null || return 1
  [ -z "$(git -C "$REPO_ROOT" status --porcelain --untracked-files=no)" ] || return 1
  printf '%s\n' "$d"
}

# Коммиты на сервере, которых нет в цели (история разошлась): bundle + список + ссылка refs/llmp/saved/<время>. Печатает каталог.
upd_save_local_commits() { # OLD TARGET
  local d ref
  d="$(upd_saved_dir)" || return 1; ref="refs/llmp/saved/$(date +%Y%m%d-%H%M%S)"
  git -C "$REPO_ROOT" update-ref "$ref" "$1" || return 1
  git -C "$REPO_ROOT" log --oneline "$2..$1" > "$d/local-commits.txt"
  git -C "$REPO_ROOT" bundle create -q "$d/local-commits.bundle" "$ref" "^$2" 2>/dev/null || return 1
  printf 'Локальные коммиты (история разошлась с origin), %s. Вернуть: git -C %s log %s  или  git fetch %s\n' \
    "$(date -Is)" "$REPO_ROOT" "$ref" "$d/local-commits.bundle" > "$d/README.txt"
  printf '%s\n' "$d"
}

# Ключи .env.example цели, которых нет в .env (сравниваются только имена; значения и секреты не читаются).
upd_env_new_keys() { # ENV_FILE EXAMPLE_TEXT
  local k
  for k in $(printf '%s\n' "$2" | grep -oE '^[A-Z_][A-Z0-9_]*='); do grep -q "^$k" "$1" 2>/dev/null || printf '%s\n' "${k%=}"; done
}
