#!/usr/bin/env bash
# update.sh — штатное обновление с GitHub.
#
#   scripts/check-updates.sh     # есть ли обновление (только git fetch, ничего не меняет)
#   scripts/update.sh [--dry-run] [--yes] [--force] [--ref ТЕГ|ВЕТКА] [--recover]
#
# По порядку: блокировка и прерванное обновление → автопочинка git (lib/autorepair.sh: зависшие *.lock, «dubious ownership» только для этого
# каталога, повторы и таймауты fetch, мелкий клон → полная история) → канал и цель: установка с тега (HEAD отсоединён) — самый новый тег vX.Y.Z,
# ветка — перемотка до origin/<ветка>, --ref — явная цель → список новых коммитов, локальные правки, новые ключи .env (только имена) →
# [--dry-run: план и выход] → подтверждение → preflight, verify, место на диске → локальные правки и коммиты СОХРАНЯЮТСЯ
# (патч, архив, git stash, bundle — в ${BACKUP_DIR:-DATA_ROOT/backups}/local-changes-*), обновление продолжается → резервная копия backup.sh →
# прежние образы помечаются тегом прежнего коммита (в т.ч. php 1.x) → переход (merge --ff-only / checkout --detach; reset --hard никогда) →
# новые ключи .env дописываются (существующие значения не меняются) → сборка новых образов (старая версия работает) → запуск, healthcheck,
# verify, smoke → отчёт DATA_ROOT/updater/report.txt и history.jsonl. Сбой сборки — код возвращается; сбой после переключения — автооткат
# на прежние образы. Прерванное обновление обнаруживается при следующем запуске (--recover — довести откат). Тома и данные не удаляются.
# Весь код — в функциях и вызывается последней строкой: git merge заменяет этот файл на диске, а bash уже прочитал его целиком.
set -euo pipefail
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"
# shellcheck source=lib/autorepair.sh
source "$(dirname "$0")/lib/autorepair.sh"

say() { log "$*"; [ "$DRY_RUN" = 1 ] || printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*" | mask_stream >> "$REPORT"; }
history() { printf '{"at":"%s","action":"update","result":"%s","from":"%s","to":"%s","detail":"%s"}\n' "$(date -u +%FT%TZ)" "$1" "${FROM:-}" "${TO:-}" "$2" >> "$HISTORY"; }

rollback_to() { # откат на прежний коммит и уже собранные прежние образы (без пересборки); ветка остаётся веткой
  local prev="$1"
  warn "Откат на $prev"
  git -C "$REPO_ROOT" reset -q --keep "$prev" 2>/dev/null || git -C "$REPO_ROOT" checkout -q --detach "$prev"
  set_value IMAGE_TAG "$prev"; export IMAGE_TAG="$prev"
  dc up -d --remove-orphans && wait_healthy 300 && "$(dirname "$0")/ctl.sh" verify && { ok "Откат выполнен: работает $prev"; return 0; }
  fail "Откат не восстановил работу — восстановите копию: scripts/restore.sh ${BACKUP:-<файл>}"; return 1
}

main() {
  local CHECK=0 YES=0 FORCE=0 REF="" RECOVER=0 DIVERGED=0 DIRTY=0 keys saved phase a free_gb s img k line
  while [ $# -gt 0 ]; do
    case "$1" in
      --check) CHECK=1 ;; --yes|-y) YES=1 ;; --force) FORCE=1 ;; --ref) REF="${2:-}"; shift ;; --recover) RECOVER=1 ;; --dry-run) DRY_RUN=1 ;;
      -h|--help) sed -n '2,16p' "$0"; return 0 ;;
      *) die "Неизвестный аргумент $1" ;;
    esac; shift
  done
  [ "$CHECK" = 1 ] && { "$(dirname "$0")/check-updates.sh" ${REF:+--ref "$REF"}; return $?; }   # прежний ключ: теперь проверка — check-updates.sh
  sanitize_project_env; load_env "$ENV_FILE"; validate_project_name
  STATE_DIR="$DATA_ROOT/updater"; mkdir -p "$STATE_DIR" 2>/dev/null || true
  STATE="$STATE_DIR/update.state"; REPORT="$STATE_DIR/report.txt"; HISTORY="$STATE_DIR/history.jsonl"
  exec 9>"$REPO_ROOT/.update.lock"; flock -n 9 || die "Обновление уже выполняется (lock)"

  # Прерванное обновление
  if [ -f "$STATE" ]; then
    read -r phase FROM TO BACKUP < "$STATE" || true
    if [ "$RECOVER" = 1 ]; then rollback_to "$FROM" && rm -f "$STATE"; return $?; fi
    die "Предыдущее обновление ($FROM → $TO) прервано на этапе «$phase». Выполните scripts/update.sh --recover (откат на $FROM) и повторите."
  fi

  [ "$DRY_RUN" = 1 ] || { : > "$REPORT"; chmod 600 "$REPORT" 2>/dev/null || true; }
  say "Обновление $COMPOSE_PROJECT_NAME: проверка"
  git -C "$REPO_ROOT" rev-parse --git-dir >/dev/null 2>&1 || die "Каталог $REPO_ROOT не git-репозиторий — обновление по git невозможно"
  upd_git_fetch_repair || die "Нет доступа к репозиторию обновлений (git fetch после повторов и автопочинки) — проверьте сеть/прокси. Ничего не изменено"
  upd_resolve_target "$REF" || die "Не найдена цель обновления: ${REF:-в origin нет ни ветки этой установки, ни релизных тегов vX.Y.Z}"
  FROM="$(git -C "$REPO_ROOT" rev-parse --short=12 HEAD)"; TO="${TARGET:0:12}"
  say "Канал: $CHANNEL, цель $TARGET_NAME"
  if [ "$FROM" = "$TO" ] && [ "$FORCE" != 1 ]; then ok "Обновлений нет (версия $(app_version), $FROM)"; [ "$DRY_RUN" = 1 ] || history none "up-to-date"; return 0; fi
  git -C "$REPO_ROOT" merge-base --is-ancestor HEAD "$TARGET" || DIVERGED=1
  say "Доступно обновление: $FROM → $TO ($(git -C "$REPO_ROOT" show -s --format=%s "$TARGET" | head -c 120))"
  git -C "$REPO_ROOT" log --oneline --no-decorate -n 30 "HEAD..$TARGET" | sed 's/^/  /'
  [ "$DIVERGED" = 0 ] || warn "История разошлась с origin: локальные коммиты ($(git -C "$REPO_ROOT" rev-list --count "$TARGET..HEAD")) будут сохранены (bundle и refs/llmp/saved/*)"
  [ -z "$(git -C "$REPO_ROOT" status --porcelain --untracked-files=no)" ] || { DIRTY=1; warn "Локальные правки файлов проекта будут сохранены (патч, архив, git stash), обновление продолжится"; }
  keys="$(upd_env_new_keys "$ENV_FILE" "$(git -C "$REPO_ROOT" show "$TARGET:.env.example" 2>/dev/null)" | tr '\n' ' ')"
  [ -z "$keys" ] || say "Новые параметры .env (будут дописаны со значениями по умолчанию): $keys"

  if [ "$DRY_RUN" = 1 ]; then
    info "[dry-run] план: preflight, verify, место на диске → сохранение локальных правок/коммитов → backup.sh → метки образов $FROM →"
    info "[dry-run] переход на $TO ($([ -n "$BRANCH" ] && echo "ветка $BRANCH: merge --ff-only" || echo "checkout --detach")) → новые ключи .env → сборка → up + healthcheck → verify → smoke"
    info "[dry-run] ничего не изменено"; return 0
  fi
  if [ "$YES" != 1 ]; then
    [ -r /dev/tty ] || die "Нет терминала для подтверждения: добавьте --yes"
    read -r -p "Обновить $FROM → $TO? [y/N]: " a </dev/tty; [ "${a:-n}" = y ] || die "Отменено"
  fi

  "$(dirname "$0")/ctl.sh" preflight >/dev/null || [ "$FORCE" = 1 ] || die "Предварительная проверка не пройдена (scripts/ctl.sh preflight); --force — продолжить"
  "$(dirname "$0")/ctl.sh" verify >/dev/null 2>&1 || { warn "Текущая версия работает не полностью — после обновления сравните результат"; }
  free_gb=$(( $(df -Pk "$(docker info -f '{{.DockerRootDir}}' 2>/dev/null || echo "$DATA_ROOT")" | awk 'NR==2 {print $4}') / 1048576 ))
  [ "$free_gb" -ge 5 ] || die "Мало места для сборки образов: ${free_gb} ГБ (нужно 5)"

  if [ "$DIVERGED" = 1 ]; then
    saved="$(upd_save_local_commits HEAD "$TARGET")" || die "Локальные коммиты не сохранены — обновление остановлено, ничего не изменено"
    say "Автопочинка: локальные коммиты сохранены в $saved"
  fi
  if [ "$DIRTY" = 1 ]; then
    saved="$(upd_save_local_changes)" || die "Локальные правки не удалось сохранить и убрать (права на файлы?) — обновление остановлено, ничего не удалено"
    say "Автопочинка: локальные правки сохранены в $saved (и в git stash), рабочая копия чистая"
  fi

  say "Резервная копия перед обновлением"
  BACKUP="$("$(dirname "$0")/backup.sh" | tail -1)" || die "Резервная копия не создана — обновление остановлено"
  say "Копия: $BACKUP"

  # Прежние образы сохраняются под тегом прежнего коммита: откат без пересборки (php — образ приложения версий 1.x).
  for s in app php nginx anonymizer asr; do
    img="${COMPOSE_PROJECT_NAME}-$s:${IMAGE_TAG:-local}"
    docker image inspect "$img" >/dev/null 2>&1 && [ "${IMAGE_TAG:-local}" != "$FROM" ] && docker tag "$img" "${COMPOSE_PROJECT_NAME}-$s:$FROM"
  done
  set_value IMAGE_TAG "$FROM"

  echo "fetch $FROM $TO $BACKUP" > "$STATE"
  upd_git_unlock
  if [ -n "$BRANCH" ] && [ "$DIVERGED" = 1 ]; then git -C "$REPO_ROOT" checkout -q -B "$BRANCH" "$TARGET"
  elif [ -n "$BRANCH" ]; then git -C "$REPO_ROOT" merge -q --ff-only "$TARGET"
  else git -C "$REPO_ROOT" checkout -q --detach "$TARGET"; fi || { rm -f "$STATE"; die "Переход на $TO не удался (git) — код и работающая версия не изменены"; }
  # Новые ключи из .env.example — дописываются со значениями по умолчанию; существующие значения и секреты не меняются.
  [ -z "$(tail -c1 "$ENV_FILE")" ] || echo >> "$ENV_FILE"
  while IFS= read -r line; do
    k="${line%%=*}"; grep -qE "^${k}=" "$ENV_FILE" || { printf '%s\n' "$line" >> "$ENV_FILE"; say "Новый параметр .env: $k"; }
  done < <(grep -E '^[A-Z_][A-Z0-9_]*=' "$REPO_ROOT/.env.example")

  # Права каталогов данных (в том числе обмена с помощником) — как у текущей версии; сбой не останавливает обновление.
  prepare_data_dirs >/dev/null 2>&1 || warn "Права каталогов данных не обновлены — при необходимости: sudo scripts/ctl.sh repair"
  # Веса речи: на установках, где их нет (источник раньше не работал), появляются при обновлении. Сбой загрузки обновление не останавливает.
  "$(dirname "$0")/models.sh" 2>&1 || warn "Модель распознавания речи не загружена: голосовой ввод заработает после scripts/ctl.sh models"

  echo "build $FROM $TO $BACKUP" > "$STATE"
  export APP_VERSION; APP_VERSION="$(app_version)"; export APP_GIT_COMMIT="$TO"; export IMAGE_TAG="$TO"
  say "Сборка образов $TO (прежняя версия продолжает работать)"
  if ! dc build; then
    git -C "$REPO_ROOT" reset -q --keep "$FROM"; rm -f "$STATE"; history failed build; die "Сборка не удалась — код возвращён на $FROM, работающая версия не тронута"
  fi

  echo "switch $FROM $TO $BACKUP" > "$STATE"
  set_value IMAGE_TAG "$TO"
  say "Запуск новой версии (миграции схемы выполняются при старте приложения)"
  if dc up -d --remove-orphans && wait_healthy 420 && "$(dirname "$0")/ctl.sh" verify && "$(dirname "$0")/ctl.sh" smoke; then
    rm -f "$STATE"; history ok "$(app_version)"
    ok "Обновлено: $FROM → $TO, версия $(app_version). Отчёт: $REPORT"
    # Старые теги своих образов: оставляем прежний (для отката), более ранние удаляем. Чужие образы и общий кэш не трогаем.
    docker images --format '{{.Repository}}:{{.Tag}}' | grep -E "^${COMPOSE_PROJECT_NAME}-(app|php|nginx|anonymizer|asr):" | grep -vE ":(${TO}|${FROM})$" | xargs -r docker rmi >/dev/null 2>&1 || true
  else
    say "Новая версия не прошла проверку — откат"
    history failed verify
    rollback_to "$FROM" && rm -f "$STATE"
    return 1
  fi
}

main "$@"; exit $?
