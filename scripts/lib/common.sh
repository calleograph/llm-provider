#!/usr/bin/env bash
# Общие функции scripts/*.sh (перенос scripts/lib/common.sh «Переговорки»). Подключается через source.
#  - работаем ТОЛЬКО с объектами своего compose-проекта (-p COMPOSE_PROJECT_NAME), чужие контейнеры, сети, тома и образы не трогаем;
#  - .env разбирается как данные (не выполняется как shell): спецсимволы в паролях безопасны;
#  - любая неопределённость — отказ (die) с понятной причиной, а не «попытка исправить».

if [ -n "${_LLMP_COMMON:-}" ]; then return 0; fi
_LLMP_COMMON=1

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$REPO_ROOT/.env}"
DRY_RUN="${DRY_RUN:-0}"

if [ -t 1 ]; then C_RED=$'\033[31m'; C_GRN=$'\033[32m'; C_YEL=$'\033[33m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'; else C_RED=''; C_GRN=''; C_YEL=''; C_DIM=''; C_OFF=''; fi
log()  { printf '%s\n' "$*"; }
info() { printf '%s[i]%s %s\n' "$C_DIM" "$C_OFF" "$*"; }
ok()   { printf '%s[ok]%s %s\n' "$C_GRN" "$C_OFF" "$*"; }
warn() { printf '%s[warn]%s %s\n' "$C_YEL" "$C_OFF" "$*" >&2; }
fail() { printf '%s[FAIL]%s %s\n' "$C_RED" "$C_OFF" "$*" >&2; }
die()  { fail "$*"; exit 1; }

# shellcheck source=mask.sh
source "$(dirname "${BASH_SOURCE[0]}")/mask.sh"

# KEY=VALUE из файла в окружение (без eval/source); уже заданные переменные не перезаписываются.
load_env() {
  local file="${1:-$ENV_FILE}" line key val
  [ -f "$file" ] || die "Файл конфигурации не найден: $file (запустите install.sh)"
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    case "$line" in ''|'#'*) continue ;; esac
    [[ "$line" == *=* ]] || continue
    key="${line%%=*}"; val="${line#*=}"; key="${key//[[:space:]]/}"
    [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
    if [[ "$val" == \"*\" && ${#val} -ge 2 ]]; then val="${val:1:${#val}-2}"; elif [[ "$val" == \'*\' && ${#val} -ge 2 ]]; then val="${val:1:${#val}-2}"; fi
    if [ -z "${!key+x}" ]; then export "$key=$val"; fi
  done < "$file"
}

# Унаследованные переменные (профиль, sudo -E, systemd) не должны тихо перебить .env: Compose отдаёт окружению приоритет.
sanitize_project_env() {
  [ "${LLMP_KEEP_ENV:-}" = all ] && return 0
  local n
  for n in $(grep -oE '^[A-Za-z_][A-Za-z0-9_]*=' "$REPO_ROOT/.env.example" 2>/dev/null | tr -d '='); do
    case " ENV_FILE DRY_RUN IMAGE_TAG ${LLMP_KEEP_ENV:-} " in *" $n "*) continue ;; esac
    unset "$n"
  done
}

require_vars() { local v m=0; for v in "$@"; do [ -n "${!v:-}" ] || { fail "Не задана переменная $v в $ENV_FILE"; m=1; }; done; [ "$m" -eq 0 ] || exit 1; }

validate_project_name() {
  [[ "${COMPOSE_PROJECT_NAME:-}" =~ ^[a-z][a-z0-9_-]{2,40}$ ]] || die "COMPOSE_PROJECT_NAME: a-z0-9_- , с буквы, 3–41 символ"
  case "$COMPOSE_PROJECT_NAME" in
    redis|php|nginx|web|app|asr|docker|default|anonymizer|scheduler) die "COMPOSE_PROJECT_NAME='$COMPOSE_PROJECT_NAME' — слишком общее имя, возможен конфликт с другими проектами" ;;
  esac
}

# DATA_ROOT: локальный абсолютный путь вне каталога кода (урок «Переговорки»: UNC-путь создал каталог внутри репозитория).
validate_data_root() {
  local d="$1"
  [[ "$d" == /* && "$d" != *'\'* && "$d" != *' '* ]] || { echo "Нужен абсолютный путь Linux без пробелов"; return 1; }
  case "$d/" in "$REPO_ROOT"/*) echo "Каталог данных не должен быть внутри каталога кода ($REPO_ROOT)"; return 1 ;; esac
  case "$REPO_ROOT/" in "$d"/*) echo "Каталог кода не должен быть внутри каталога данных"; return 1 ;; esac
  return 0
}

compose_args() {
  COMPOSE_ARGS=(compose -p "$COMPOSE_PROJECT_NAME" --project-directory "$REPO_ROOT" --env-file "$ENV_FILE" -f "$REPO_ROOT/compose.yaml")
  [ -n "${COMPOSE_EXTRA_FILE:-}" ] && COMPOSE_ARGS+=(-f "$COMPOSE_EXTRA_FILE")
  return 0
}
dc() { compose_args; docker "${COMPOSE_ARGS[@]}" "$@"; }

# Выполнить команду или только напечатать её (DRY_RUN=1).
run() { if [ "$DRY_RUN" = "1" ]; then printf '%s[dry-run]%s %s\n' "$C_YEL" "$C_OFF" "$*"; else "$@"; fi; }

as_root() { if [ "$(id -u)" -eq 0 ]; then "$@"; else sudo -n "$@"; fi; }

port_busy() { # tcp|udp порт → 0, если занят
  command -v ss >/dev/null 2>&1 || return 2
  if [ "$1" = tcp ]; then ss -H -ltn "sport = :$2" 2>/dev/null | grep -q .; else ss -H -lun "sport = :$2" 2>/dev/null | grep -q .; fi
}

port_owned_by_project() {
  command -v docker >/dev/null 2>&1 || return 1
  docker ps --filter "label=com.docker.compose.project=${COMPOSE_PROJECT_NAME}" --format '{{.Ports}}' 2>/dev/null | grep -Eq "(:|^)$1->"
}

# Первый свободный порт начиная с $1 (не занят ни слушающим сокетом, ни опубликованным портом контейнера).
find_port() {
  local p="$1"
  while [ "$p" -lt 65000 ]; do
    if ! port_busy tcp "$p" && ! docker ps --format '{{.Ports}}' 2>/dev/null | grep -Eq "(:|^)$p->"; then echo "$p"; return 0; fi
    p=$((p + 1))
  done
  return 1
}

rand_hex() { openssl rand -hex "${1:-32}"; }

# set_default КЛЮЧ ЗНАЧЕНИЕ — записать в .env, только если значения ещё нет (повторный запуск секреты не меняет; ArkLock).
set_default() {
  local key="$1" val="$2" cur
  cur="$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2-)"
  [ -n "$cur" ] && return 0
  if grep -qE "^${key}=" "$ENV_FILE"; then
    local tmp; tmp="$(mktemp "${ENV_FILE}.XXXX")"
    awk -v k="$key" -v v="$val" 'BEGIN{FS=OFS="="} $1==k {print k"="v; next} {print}' "$ENV_FILE" > "$tmp" && cat "$tmp" > "$ENV_FILE" && rm -f "$tmp"
  else
    printf '%s=%s\n' "$key" "$val" >> "$ENV_FILE"
  fi
}

# set_value КЛЮЧ ЗНАЧЕНИЕ — записать всегда (параметры мастера установки, не секреты).
set_value() {
  local key="$1" val="$2" tmp
  if grep -qE "^${key}=" "$ENV_FILE"; then
    tmp="$(mktemp "${ENV_FILE}.XXXX")"
    awk -v k="$key" -v v="$val" 'BEGIN{FS=OFS="="} $1==k {print k"="v; next} {print}' "$ENV_FILE" > "$tmp" && cat "$tmp" > "$ENV_FILE" && rm -f "$tmp"
  else
    printf '%s=%s\n' "$key" "$val" >> "$ENV_FILE"
  fi
}

app_version() { cat "$REPO_ROOT/VERSION" 2>/dev/null || echo 0.0.0; }
app_commit() { git -C "$REPO_ROOT" rev-parse --short=12 HEAD 2>/dev/null || echo unknown; }

# Каталоги данных и права для пользователей контейнеров (www-data 33 — php; 10001 — сервисы Python; 999 — redis).
# chown делается одноразовым контейнером: работает и от root, и от пользователя группы docker.
prepare_data_dirs() {
  run mkdir -p "$DATA_ROOT"/{app,updater,models/gigaam,backups}
  run docker run --rm -v "$DATA_ROOT:/d" "${HELPER_IMAGE:-alpine:3.21}" sh -c 'chown -R 33:33 /d/app /d/updater && chmod 750 /d/app && chmod 1777 /d/updater && chmod -R a+rX /d/models'
}

wait_healthy() { # ждать, пока все сервисы проекта станут healthy
  local t="${1:-300}" waited=0 bad
  while [ "$waited" -lt "$t" ]; do
    bad="$(dc ps --format '{{.Service}} {{.Health}}' 2>/dev/null | awk '$2!="healthy" && $2!="" {print $1}')"
    [ -z "$bad" ] && return 0
    sleep 5; waited=$((waited + 5))
  done
  fail "Не стали healthy за ${t} с: $bad"; return 1
}
