#!/usr/bin/env bash
# restore.sh — восстановление из зашифрованной копии (scripts/backup.sh).
#
#   scripts/restore.sh FILE [--with-env] [--yes]
#
# Порядок: проверка копии (--verify) → страховочная копия текущего состояния → остановка app/scheduler/nginx своего проекта →
# замена БД и сертификатов → запуск → verify. Конфигурация (.env) по умолчанию остаётся текущей; если мастер-ключ в копии
# другой (сохранённые секреты иначе не расшифровать) — восстановление требует --with-env. Другие проекты не затрагиваются.
set -euo pipefail
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"
sanitize_project_env; load_env "$ENV_FILE"; validate_project_name

FILE="${1:?Укажите файл копии}"; shift || true
WITH_ENV=0; YES=0
for a in "$@"; do case "$a" in --with-env) WITH_ENV=1 ;; --yes) YES=1 ;; *) die "Неизвестный аргумент $a" ;; esac; done
KEY="${BACKUP_KEY_FILE:-$REPO_ROOT/.backup.key}"
DIR="${BACKUP_DIR:-$DATA_ROOT/backups}"

"$(dirname "$0")/backup.sh" --verify "$FILE" || die "Копия не прошла проверку — восстановление отменено"
tmp="$(mktemp -d "$DIR/.restore.XXXX")"; trap 'rm -rf "$tmp"' EXIT
gpg --batch --quiet --decrypt --passphrase-file "$KEY" "$FILE" | tar -xf - -C "$tmp"

cur_key="$(grep -E '^APP_MASTER_KEY=' "$ENV_FILE" | cut -d= -f2-)"
bak_key="$(grep -E '^APP_MASTER_KEY=' "$tmp/env" | cut -d= -f2-)"
if [ "$cur_key" != "$bak_key" ] && [ "$WITH_ENV" != 1 ]; then
  die "Мастер-ключ в копии отличается от текущего: без него ключи провайдеров и пароли не расшифровать. Повторите с --with-env (конфигурация будет восстановлена из копии; текущая сохранится рядом)."
fi
if [ "$YES" != 1 ] && [ -r /dev/tty ]; then
  read -r -p "Заменить данные экземпляра «$COMPOSE_PROJECT_NAME» содержимым копии? [y/N]: " ans </dev/tty; [ "${ans:-n}" = y ] || die "Отменено"
fi

info "Страховочная копия текущего состояния"
safety="$("$(dirname "$0")/backup.sh" --keep 1000 | tail -1)" || die "Не удалось создать страховочную копию — восстановление отменено"
ok "Страховочная копия: $safety"

dc stop nginx scheduler app >/dev/null
# Файлы подставляются одноразовым контейнером с правами пользователя приложения (uid 33).
docker run --rm -v "$tmp:/src:ro" -v "$DATA_ROOT/app:/dst" "${HELPER_IMAGE:-alpine:3.21}" sh -c '
  set -e; mkdir -p /dst/data /dst/certs
  cp /src/app.sqlite /dst/data/app.sqlite.restore && mv /dst/data/app.sqlite.restore /dst/data/app.sqlite
  rm -f /dst/data/app.sqlite-wal /dst/data/app.sqlite-shm
  rm -rf /dst/certs/* && cp -a /src/certs/. /dst/certs/ 2>/dev/null || true
  chown -R 33:33 /dst'
if [ "$WITH_ENV" = 1 ]; then
  cp -p "$ENV_FILE" "$ENV_FILE.before-restore-$(date -u +%Y%m%d%H%M%S)"
  cat "$tmp/env" > "$ENV_FILE"; chmod 600 "$ENV_FILE"
  # размещение остаётся от этого сервера (данные уже лежат в его DATA_ROOT), секреты и настройки — из копии
  for k in COMPOSE_PROJECT_NAME DATA_ROOT APP_BIND APP_PORT APP_PUBLIC_URL INSTALL_PROFILE IMAGE_TAG; do set_value "$k" "${!k:-}"; done
  # переменные прежнего .env уже в окружении и перебили бы файл в Compose — перечитываем
  unset $(grep -oE '^[A-Za-z_][A-Za-z0-9_]*=' "$ENV_FILE" | tr -d =); sanitize_project_env; load_env "$ENV_FILE"; validate_project_name
  info "Конфигурация восстановлена из копии (прежняя сохранена рядом; каталог данных, имя проекта и порт — этого сервера)"
fi
dc up -d >/dev/null
wait_healthy 300 || die "После восстановления службы не стали healthy. Вернуть прежнее состояние: scripts/restore.sh $safety --yes"
"$(dirname "$0")/ctl.sh" verify || die "Проверка после восстановления не пройдена. Прежнее состояние: scripts/restore.sh $safety --yes"
ok "Восстановление завершено из $(basename "$FILE")"
