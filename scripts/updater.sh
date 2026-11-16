#!/usr/bin/env bash
# updater.sh — помощник обслуживания на хосте для действий из веб-админки.
#
#   sudo scripts/updater.sh install     установить службу systemd llmprov-updater-<проект>
#   sudo scripts/updater.sh uninstall
#   scripts/updater.sh run              цикл службы (вызывает systemd)
#
# Служба работает от имени владельца репозитория (группа docker), не root. Контейнер app пишет $DATA_ROOT/updater/inbox/request.txt,
# остальной $DATA_ROOT/updater у него только на чтение; Docker-сокета у контейнера нет. Помощник:
#  - принимает только обычный файл (не symlink) до 1024 байт, строки key=value, неизвестный ключ — отказ;
#  - action из белого списка: check | update | backup | backup_verify | verify | diag; значения сверяются с шаблонами;
#  - атомарно забирает запрос (rename), выполняет те же CLI-скрипты в стерильном окружении (env -i), пишет status.json и history.jsonl;
#  - раз в минуту пишет host-status.json (состояние контейнеров, ресурсы) для раздела «Обслуживание»;
#  - при старте помечает прерванную операцию как failed/interrupted. Произвольных команд нет.
set -euo pipefail
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"
sanitize_project_env; load_env "$ENV_FILE"; validate_project_name
X="$DATA_ROOT/updater"; UNIT="llmprov-updater-${COMPOSE_PROJECT_NAME}"
STOP_EARLY=0; trap 'STOP_EARLY=1' INT TERM

json_status() { printf '{"state":"%s","action":"%s","at":"%s","message":"%s"}\n' "$1" "$2" "$(date -u +%FT%TZ)" "$(printf '%s' "$3" | tr '"\n' "' " | head -c 300)" > "$X/status.json.tmp" && mv -f "$X/status.json.tmp" "$X/status.json"; chmod 644 "$X/status.json"; }

parse_request() { # → ACTION, FILE_ARG; отказ при любом отклонении
  local f="$1" line k v
  [ -f "$f" ] && [ ! -L "$f" ] || return 1
  [ "$(stat -c %s "$f")" -le 1024 ] || return 1
  ACTION=""; FILE_ARG=""
  while IFS= read -r line || [ -n "$line" ]; do
    [ -z "$line" ] && continue
    [[ "$line" =~ ^([a-z_]+)=(.*)$ ]] || return 1
    k="${BASH_REMATCH[1]}"; v="${BASH_REMATCH[2]}"
    case "$k" in
      action) [[ "$v" =~ ^(check|update|backup|backup_verify|verify|diag)$ ]] || return 1; ACTION="$v" ;;
      file) [[ "$v" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.bak\.gpg$ ]] || return 1; FILE_ARG="$v" ;;
      id) [[ "$v" =~ ^[a-f0-9]{8,64}$ ]] || return 1 ;;
      *) return 1 ;;
    esac
  done < "$f"
  [ -n "$ACTION" ]
}

run_action() {
  local sc="$REPO_ROOT/scripts" out rc=0
  local -a cmd
  case "$ACTION" in
    check) cmd=("$sc/check-updates.sh") ;;
    update) cmd=("$sc/update.sh" --yes) ;;
    backup) cmd=("$sc/backup.sh") ;;
    backup_verify) [ -n "$FILE_ARG" ] || { json_status failed "$ACTION" "не указан файл"; return; }; cmd=("$sc/backup.sh" --restore-test "${BACKUP_DIR:-$DATA_ROOT/backups}/$FILE_ARG") ;;
    verify) cmd=("$sc/ctl.sh" verify) ;;
    diag) cmd=("$sc/ctl.sh" diag) ;;
  esac
  json_status running "$ACTION" "выполняется"
  # ход работы — построчно в live-output.txt (раздел «Обновление» показывает его, пока идёт действие); секреты скрыты
  : > "$X/live-output.txt"; chmod 644 "$X/live-output.txt"
  # HOME — домашний каталог пользователя службы (root → /root); не каталог обмена: он доступен на запись всем
  # без set -e вокруг конвейера: код 10 («есть обновление») и сбои действий не должны завершать самого помощника
  set +e
  env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME="$(getent passwd "$(id -u)" | cut -d: -f6)" ENV_FILE="$ENV_FILE" ${COMPOSE_EXTRA_FILE:+COMPOSE_EXTRA_FILE="$COMPOSE_EXTRA_FILE"} "${cmd[@]}" 2>&1 \
    | mask_stream >> "$X/live-output.txt"
  rc=${PIPESTATUS[0]}
  set -e
  [ "$ACTION" = check ] && [ "$rc" -eq 10 ] && rc=0   # check-updates.sh: 10 — «доступно обновление», это успешная проверка
  cp -f "$X/live-output.txt" "$X/last-output.txt"; chmod 644 "$X/last-output.txt"
  out="$(cat "$X/live-output.txt")"
  json_status "$([ $rc -eq 0 ] && echo done || echo failed)" "$ACTION" "$(printf '%s' "$out" | tail -3 | tr '\n' ' ')"
  printf '{"at":"%s","action":"%s","result":"%s"}\n' "$(date -u +%FT%TZ)" "$ACTION" "$([ $rc -eq 0 ] && echo ok || echo failed)" >> "$X/history.jsonl"
}

case "${1:-}" in
  install)
    [ "$(id -u)" -eq 0 ] || die "Нужен root"
    cat > "/etc/systemd/system/$UNIT.service" <<UNIT
[Unit]
Description=LLM provider maintenance helper ($COMPOSE_PROJECT_NAME)
After=docker.service
Requires=docker.service

[Service]
# от имени владельца репозитория (группа docker): git и файлы кода остаются его, root не нужен
User=$(stat -c %U "$REPO_ROOT")
Environment=ENV_FILE=$ENV_FILE
ExecStart=$REPO_ROOT/scripts/updater.sh run
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload && systemctl enable --now "$UNIT" && ok "Служба $UNIT установлена" ;;
  uninstall) systemctl disable --now "$UNIT" 2>/dev/null || true; rm -f "/etc/systemd/system/$UNIT.service"; systemctl daemon-reload; ok "Служба $UNIT удалена" ;;
  run)
    [ -d "$X/inbox" ] && [ ! -L "$X/inbox" ] || die "Нет каталога $X/inbox — выполните scripts/ctl.sh repair"
    [ "$(stat -c %u "$X")" = "$(id -u)" ] || die "Каталог $X должен принадлежать пользователю помощника ($(id -un)) — scripts/ctl.sh repair"
    if grep -q '"state":"running"' "$X/status.json" 2>/dev/null; then json_status failed interrupted "операция прервана перезапуском помощника — проверьте scripts/ctl.sh verify; прерванное обновление: scripts/update.sh --recover"; fi
    # веса речи отсутствуют (например, источник раньше не работал) — дозагрузка в фоне и перезапуск ASR; при обновлении помощник стартует новым кодом
    if [ "${ASR_ENABLED:-1}" = 1 ] && [ ! -f "$DATA_ROOT/models/gigaam/${ASR_MODEL_NAME:-v3_e2e_rnnt}.ckpt" ]; then
      ( "$REPO_ROOT/scripts/models.sh" --restart > "$X/models-output.txt" 2>&1 ; chmod 644 "$X/models-output.txt" ) &
    fi
    last=0
    while [ "$STOP_EARLY" = 0 ]; do
      printf '{"at":"%s","pid":%d}\n' "$(date -u +%FT%TZ)" $$ > "$X/helper.json"
      if [ $(( $(date +%s) - last )) -ge 60 ]; then "$REPO_ROOT/scripts/ctl.sh" status --json > "$X/host-status.json.tmp" 2>/dev/null && mv -f "$X/host-status.json.tmp" "$X/host-status.json" && chmod 644 "$X/host-status.json"; last=$(date +%s); fi
      if [ -e "$X/inbox/request.txt" ]; then
        rm -f "$X/request.processing"; mv -f "$X/inbox/request.txt" "$X/request.processing" 2>/dev/null || { sleep 2; continue; }
        if parse_request "$X/request.processing"; then run_action; else json_status failed rejected "запрос отклонён: неверный формат"; fi
        rm -f "$X/request.processing"
        # после обновления — перезапуск самого помощника уже из нового кода (иначе он работал бы старой версией до перезагрузки)
        [ "$ACTION" = update ] && exec "$REPO_ROOT/scripts/updater.sh" run
      fi
      sleep 3
    done ;;
  *) sed -n '2,15p' "$0"; exit 2 ;;
esac
