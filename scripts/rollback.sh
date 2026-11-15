#!/usr/bin/env bash
# rollback.sh — откат на прежнюю версию без пересборки: прежние образы сохранены update.sh под тегом коммита.
#   scripts/rollback.sh [КОММИТ]   (по умолчанию — версия до последнего успешного обновления)
# Схема БД только расширяется, поэтому прежняя версия работает с текущей БД; для полного возврата данных — scripts/restore.sh.
set -euo pipefail
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"
sanitize_project_env; load_env "$ENV_FILE"; validate_project_name
target="${1:-$(grep '"result":"ok"' "$DATA_ROOT/updater/history.jsonl" 2>/dev/null | tail -1 | python3 -c 'import json,sys; print(json.loads(sys.stdin.read() or "{}").get("from",""))')}"
[ -n "$target" ] || die "Нет сведений о прежней версии (history.jsonl) — укажите коммит"
docker image inspect "${COMPOSE_PROJECT_NAME}-php:$target" >/dev/null 2>&1 || die "Образов версии $target нет — откат без пересборки невозможен"
[ -z "$(git -C "$REPO_ROOT" status --porcelain --untracked-files=no)" ] || die "В коде есть локальные правки — откат остановлен"
git -C "$REPO_ROOT" checkout -q --detach "$target"
set_value IMAGE_TAG "$target"; export IMAGE_TAG="$target"
dc up -d --remove-orphans && wait_healthy 300 && "$(dirname "$0")/ctl.sh" verify || die "После отката службы не в порядке — scripts/ctl.sh status"
printf '{"at":"%s","action":"rollback","result":"ok","to":"%s"}\n' "$(date -u +%FT%TZ)" "$target" >> "$DATA_ROOT/updater/history.jsonl"
ok "Откат выполнен: $target"
