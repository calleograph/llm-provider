#!/usr/bin/env bash
# update.sh — штатное обновление (приёмы update.sh/updtxn «Переговорки» и ArkLock).
#
#   scripts/update.sh [--check] [--yes] [--force] [--ref TAG|BRANCH] [--recover]
#
# Перед обновлением: есть ли обновление, текущее состояние, конфигурация, место на диске, резервная копия, совместимость
# (миграции схемы только добавляют таблицы и колонки — прежняя версия работает с новой схемой, поэтому откат кода не требует
# отката БД; копия всё равно создаётся). Затем: git merge --ff-only (никогда reset --hard; локальные правки — отказ),
# дописывание новых ключей в .env (существующие не меняются), сборка новых образов (старая версия продолжает работать),
# запуск, ожидание healthcheck, verify, smoke-test, отчёт. Сбой после переключения — автоматический откат на прежние образы.
# Прерванное обновление обнаруживается при следующем запуске (--recover — довести откат). Тома и данные не удаляются.
set -euo pipefail
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"
sanitize_project_env; load_env "$ENV_FILE"; validate_project_name

CHECK=0; YES=0; FORCE=0; REF=""; RECOVER=0
while [ $# -gt 0 ]; do case "$1" in --check) CHECK=1 ;; --yes) YES=1 ;; --force) FORCE=1 ;; --ref) REF="$2"; shift ;; --recover) RECOVER=1 ;; *) die "Неизвестный аргумент $1" ;; esac; shift; done

STATE_DIR="$DATA_ROOT/updater"; mkdir -p "$STATE_DIR" 2>/dev/null || true
STATE="$STATE_DIR/update.state"; REPORT="$STATE_DIR/report.txt"; HISTORY="$STATE_DIR/history.jsonl"
exec 9>"$REPO_ROOT/.update.lock"; flock -n 9 || die "Обновление уже выполняется (lock)"

say() { printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*" | tee -a "$REPORT" | mask_stream >/dev/null; log "$*"; }
history() { printf '{"at":"%s","action":"update","result":"%s","from":"%s","to":"%s","detail":"%s"}\n' "$(date -u +%FT%TZ)" "$1" "${FROM:-}" "${TO:-}" "$2" >> "$HISTORY"; }

rollback_to() { # откат на прежний коммит и уже собранные прежние образы (без пересборки)
  local prev="$1"
  warn "Откат на $prev"
  git -C "$REPO_ROOT" checkout -q --detach "$prev" 2>/dev/null || git -C "$REPO_ROOT" reset -q --keep "$prev"
  set_value IMAGE_TAG "$prev"; export IMAGE_TAG="$prev"
  dc up -d --remove-orphans && wait_healthy 300 && "$(dirname "$0")/ctl.sh" verify && { ok "Откат выполнен: работает $prev"; return 0; }
  fail "Откат не восстановил работу — восстановите копию: scripts/restore.sh ${BACKUP:-<файл>}"; return 1
}

# Прерванное обновление
if [ -f "$STATE" ]; then
  read -r phase FROM TO BACKUP < "$STATE" || true
  if [ "$RECOVER" = 1 ]; then rollback_to "$FROM" && rm -f "$STATE"; exit $?; fi
  die "Предыдущее обновление ($FROM → $TO) прервано на этапе «$phase». Выполните scripts/update.sh --recover (откат на $FROM) и повторите."
fi

: > "$REPORT"; chmod 600 "$REPORT" 2>/dev/null || true
say "Обновление $COMPOSE_PROJECT_NAME: проверка"
git -C "$REPO_ROOT" rev-parse --git-dir >/dev/null 2>&1 || die "Каталог $REPO_ROOT не git-репозиторий — обновление по git невозможно"
[ -z "$(git -C "$REPO_ROOT" status --porcelain --untracked-files=no)" ] || die "В коде есть локальные правки — обновление остановлено, чтобы их не потерять (git status)"
git -C "$REPO_ROOT" fetch -q --tags origin || die "Нет доступа к репозиторию обновлений"
FROM="$(git -C "$REPO_ROOT" rev-parse --short=12 HEAD)"
if [ -z "$REF" ]; then REF="origin/$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD)"; fi
TO="$(git -C "$REPO_ROOT" rev-parse --short=12 "$REF^{commit}")" || die "Нет ревизии $REF"
if [ "$FROM" = "$TO" ] && [ "$FORCE" != 1 ]; then ok "Обновлений нет (версия $(app_version), $FROM)"; history none "up-to-date"; exit 0; fi
git -C "$REPO_ROOT" merge-base --is-ancestor "$FROM" "$TO" || die "Ревизия $REF не продолжает текущую ($FROM) — только перемотка вперёд"
say "Доступно обновление: $FROM → $TO ($(git -C "$REPO_ROOT" show -s --format=%s "$TO" | head -c 120))"
[ "$CHECK" = 1 ] && exit 0
if [ "$YES" != 1 ] && [ -r /dev/tty ]; then read -r -p "Обновить? [y/N]: " a </dev/tty; [ "${a:-n}" = y ] || die "Отменено"; fi

"$(dirname "$0")/ctl.sh" preflight >/dev/null || [ "$FORCE" = 1 ] || die "Предварительная проверка не пройдена (scripts/ctl.sh preflight); --force — продолжить"
"$(dirname "$0")/ctl.sh" verify >/dev/null 2>&1 || { warn "Текущая версия работает не полностью — после обновления сравните результат"; }
free_gb=$(( $(df -Pk "$(docker info -f '{{.DockerRootDir}}' 2>/dev/null || echo "$DATA_ROOT")" | awk 'NR==2 {print $4}') / 1048576 ))
[ "$free_gb" -ge 5 ] || die "Мало места для сборки образов: ${free_gb} ГБ (нужно 5)"

say "Резервная копия перед обновлением"
BACKUP="$("$(dirname "$0")/backup.sh" | tail -1)" || die "Резервная копия не создана — обновление остановлено"
say "Копия: $BACKUP"

# Прежние образы сохраняются под тегом прежнего коммита: откат без пересборки.
for s in php nginx anonymizer asr; do
  img="${COMPOSE_PROJECT_NAME}-$s:${IMAGE_TAG:-local}"
  docker image inspect "$img" >/dev/null 2>&1 && [ "${IMAGE_TAG:-local}" != "$FROM" ] && docker tag "$img" "${COMPOSE_PROJECT_NAME}-$s:$FROM"
done
set_value IMAGE_TAG "$FROM"

echo "fetch $FROM $TO $BACKUP" > "$STATE"
git -C "$REPO_ROOT" merge -q --ff-only "$TO" || { rm -f "$STATE"; die "git merge --ff-only не удался"; }
# Новые ключи из .env.example — дописываются со значениями по умолчанию; существующие значения и секреты не меняются.
while IFS= read -r line; do
  k="${line%%=*}"; grep -qE "^${k}=" "$ENV_FILE" || { printf '%s\n' "$line" >> "$ENV_FILE"; say "Новый параметр .env: $k"; }
done < <(grep -E '^[A-Z_][A-Z0-9_]*=' "$REPO_ROOT/.env.example")

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
  docker images --format '{{.Repository}}:{{.Tag}}' | grep -E "^${COMPOSE_PROJECT_NAME}-(php|nginx|anonymizer|asr):" | grep -vE ":(${TO}|${FROM})$" | xargs -r docker rmi >/dev/null 2>&1 || true
else
  say "Новая версия не прошла проверку — откат"
  history failed verify
  rollback_to "$FROM" && rm -f "$STATE"
  exit 1
fi
