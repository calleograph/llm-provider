#!/usr/bin/env bash
# Проверки scripts/models.sh без сети: выключенный голос, неполный --from-dir, слишком маленький файл.
set -u
cd "$(dirname "$0")/../.."
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/data" "$T/src"
printf 'COMPOSE_PROJECT_NAME=mtest\nDATA_ROOT=%s/data\nASR_ENABLED=%%s\n' "$T" > "$T/env.tpl"
env_for() { printf "$(cat "$T/env.tpl")" "$1" > "$T/env"; }
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: ожидалось '$2', получено '$3'"; fails=$((fails+1)); fi; }

env_for 0; ENV_FILE="$T/env" scripts/models.sh >/dev/null 2>&1; check "голос выключен — успех без загрузки" 0 $?
env_for 1; ENV_FILE="$T/env" scripts/models.sh --from-dir "$T/src" >/dev/null 2>&1; check "в каталоге нет файла — отказ" 1 $?
echo tiny > "$T/src/v3_e2e_rnnt.ckpt"; echo tiny > "$T/src/v3_e2e_rnnt_tokenizer.model"
ENV_FILE="$T/env" scripts/models.sh --from-dir "$T/src" >/dev/null 2>&1; check "слишком маленький файл не принят" 1 $?
check "в каталог моделей ничего не попало" 0 "$(ls "$T/data/models/gigaam" 2>/dev/null | wc -l)"
exit $fails
