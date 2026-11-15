#!/usr/bin/env bash
# check-updates.sh — есть ли обновление. Только git fetch: код, .env, образы и службы не меняются.
#
#   scripts/check-updates.sh [--ref ТЕГ|ВЕТКА]
#
# Печатает канал (релизные теги vX.Y.Z или ветка), текущую и доступную версию, новые коммиты, новые параметры .env (только имена).
# Код возврата (для помощника updater.sh и cron): 0 — обновлений нет, 10 — доступно обновление, 1 — проверить не удалось.
# Обновить: scripts/update.sh (сначала можно scripts/update.sh --dry-run).
set -uo pipefail
# shellcheck source=lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
# shellcheck source=lib/autorepair.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/autorepair.sh"

REF=""
while [ $# -gt 0 ]; do case "$1" in --ref) REF="${2:-}"; shift ;; -h|--help) sed -n '2,8p' "$0"; exit 0 ;; *) die "Неизвестный аргумент: $1" ;; esac; shift; done

upd_git_env
git -C "$REPO_ROOT" rev-parse --git-dir >/dev/null 2>&1 || die "$REPO_ROOT — не git-репозиторий: проверка обновлений по git невозможна"
upd_git_fetch_repair || die "Нет доступа к репозиторию обновлений (git fetch origin) — проверьте сеть или прокси сервера"
upd_resolve_target "$REF" || die "Не найдена цель обновления: ${REF:-в origin нет ни ветки этой установки, ни релизных тегов vX.Y.Z}"

HEAD_SHA="$(git -C "$REPO_ROOT" rev-parse HEAD)"
ver_at() { git -C "$REPO_ROOT" show "$1:VERSION" 2>/dev/null | tr -d '[:space:]'; }
case "$CHANNEL" in
  release) log "Канал: релизы (теги vX.Y.Z)${BRANCH:+; ветка $BRANCH не найдена в origin}" ;;
  branch) log "Канал: ветка $BRANCH (перемотка вперёд до origin/$BRANCH)" ;;
  ref) log "Цель указана явно: $REF" ;;
esac
log "Установлено: $(ver_at HEAD) · ${HEAD_SHA:0:12}$(t="$(upd_head_release_tag)"; [ -n "$t" ] && printf ' (%s)' "$t")"
if [ "$HEAD_SHA" = "$TARGET" ]; then ok "Обновлений нет"; exit 0; fi

log "Доступно:    $(ver_at "$TARGET") · ${TARGET:0:12} ($TARGET_NAME)"
if git -C "$REPO_ROOT" merge-base --is-ancestor HEAD "$TARGET"; then
  n="$(git -C "$REPO_ROOT" rev-list --count "HEAD..$TARGET")"
  log "Новые коммиты ($n):"; git -C "$REPO_ROOT" log --oneline --no-decorate -n 30 "HEAD..$TARGET" | sed 's/^/  /'
  [ "$n" -gt 30 ] && log "  … (показаны последние 30)"
else
  warn "История на сервере разошлась с origin (локальные коммиты: $(git -C "$REPO_ROOT" rev-list --count "$TARGET..HEAD")) — update.sh сохранит их и перейдёт на цель"
fi
[ -z "$(git -C "$REPO_ROOT" status --porcelain --untracked-files=no)" ] || warn "Есть локальные правки файлов проекта — update.sh сохранит их (патч, архив, git stash) и продолжит"
keys="$(upd_env_new_keys "$ENV_FILE" "$(git -C "$REPO_ROOT" show "$TARGET:.env.example" 2>/dev/null)" | tr '\n' ' ')"
[ -z "$keys" ] || log "Новые параметры .env (будут дописаны со значениями по умолчанию): $keys"
info "Обновить: scripts/update.sh   (план без изменений: scripts/update.sh --dry-run)"
exit 10
