#!/usr/bin/env bash
# ctl.sh — обслуживание экземпляра: только объекты своего Compose-проекта, чужие контейнеры и образы не затрагиваются.
#
#   scripts/ctl.sh status [--json]   состояние служб, версии, ресурсы
#   scripts/ctl.sh preflight         проверка окружения (Docker, диск, память, порт, каталоги)
#   scripts/ctl.sh verify            все службы healthy + внутренние проверки (обезличивание, ASR, БД, почта)
#   scripts/ctl.sh smoke             сквозная проверка: страница, API, обезличивание, ASR, изоляция сети
#   scripts/ctl.sh logs [служба] [N] журналы (секреты маскируются)
#   scripts/ctl.sh diag              архив диагностики с маскированием секретов
#   scripts/ctl.sh repair            безопасное восстановление: права каталогов, перезапуск нездоровых служб своего проекта
#   scripts/ctl.sh start|stop|restart
#   scripts/ctl.sh models            скачать веса распознавания речи, если их нет
#   scripts/ctl.sh admin-reset       новый пароль аварийного администратора (печатается один раз)
#   scripts/ctl.sh backup|restore|update|rollback ...   → scripts/backup.sh, restore.sh, update.sh, rollback.sh
set -euo pipefail
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"
sanitize_project_env; load_env "$ENV_FILE"; validate_project_name

cmd="${1:-status}"; shift || true

svc_table() { dc ps -a --format '{{.Service}}|{{.State}}|{{.Health}}|{{.Status}}' 2>/dev/null; }

status_json() {
  local disk mem
  disk="$(df -Pk "$DATA_ROOT" | awk 'NR==2 {printf "{\"free_gb\":%.1f,\"used_pct\":%d}", $4/1048576, $5}')"
  mem="$(awk '/MemTotal/ {t=$2} /MemAvailable/ {a=$2} END {printf "{\"total_gb\":%.1f,\"available_gb\":%.1f}", t/1048576, a/1048576}' /proc/meminfo)"
  printf '{"project":"%s","version":"%s","commit":"%s","at":"%s","disk":%s,"memory":%s,"load":"%s","services":[' \
    "$COMPOSE_PROJECT_NAME" "$(app_version)" "$(app_commit)" "$(date -u +%FT%TZ)" "$disk" "$mem" "$(cut -d' ' -f1-3 /proc/loadavg)"
  dc ps -a --format '{{json .}}' 2>/dev/null | python3 -c '
import json,sys
rows=[json.loads(l) for l in sys.stdin if l.strip()]
print(",".join(json.dumps({"service":r.get("Service"),"state":r.get("State"),"health":r.get("Health"),"status":r.get("Status"),"image":r.get("Image")},ensure_ascii=False) for r in rows),end="")'
  printf '],"stats":'
  docker stats --no-stream --format '{{json .}}' $(dc ps -q 2>/dev/null) 2>/dev/null | python3 -c '
import json,sys
print(json.dumps([{"name":r.get("Name"),"cpu":r.get("CPUPerc"),"mem":r.get("MemUsage")} for r in (json.loads(l) for l in sys.stdin if l.strip())],ensure_ascii=False),end="")' || printf '[]'
  printf '}\n'
}

case "$cmd" in
  status)
    if [ "${1:-}" = --json ]; then status_json; exit 0; fi
    log "Проект: $COMPOSE_PROJECT_NAME  версия $(app_version) ($(app_commit))  профиль ${INSTALL_PROFILE:-?}"
    svc_table | column -t -s '|' || true
    df -h "$DATA_ROOT" | awk 'NR==2 {print "Диск данных: свободно " $4 " из " $2}'
    ;;
  preflight)
    rc=0
    docker info >/dev/null 2>&1 && ok "Docker доступен" || { fail "Docker недоступен"; rc=1; }
    docker compose version >/dev/null 2>&1 && ok "Compose $(docker compose version --short)" || { fail "Нет docker compose"; rc=1; }
    why="$(validate_data_root "$DATA_ROOT")" && ok "Каталог данных $DATA_ROOT вне кода" || { fail "$why"; rc=1; }
    free_gb=$(( $(df -Pk "$DATA_ROOT" | awk 'NR==2 {print $4}') / 1048576 )); [ "$free_gb" -ge 5 ] && ok "Свободно ${free_gb} ГБ" || { fail "Мало места: ${free_gb} ГБ"; rc=1; }
    if port_owned_by_project "$APP_PORT" || ! port_busy tcp "$APP_PORT"; then ok "Порт $APP_PORT свободен или занят этим проектом"; else fail "Порт $APP_PORT занят другим процессом"; rc=1; fi
    [ "$(stat -c '%a' "$ENV_FILE")" = 600 ] && ok ".env с правами 600" || { warn ".env доступен не только владельцу — chmod 600 $ENV_FILE"; }
    exit $rc
    ;;
  verify)
    rc=0
    # нет весов речи — без них проверка ASR не пройдёт (а обновление откатится): сначала дозагрузка и перезапуск ASR
    if [ "${ASR_ENABLED:-0}" = 1 ] && [ ! -f "$DATA_ROOT/models/gigaam/${ASR_MODEL_NAME:-v3_e2e_rnnt}.ckpt" ]; then
      warn "Нет весов распознавания речи — загружаю"; "$(dirname "$0")/models.sh" --restart || true
    fi
    while IFS='|' read -r s st h _; do
      if [ "$st" = running ] && { [ "$h" = healthy ] || [ -z "$h" ]; }; then ok "$s: работает${h:+ ($h)}"; else fail "$s: $st ${h:-}"; rc=1; fi
    done < <(svc_table)
    dc exec -T app python -m llmp.cli verify || rc=1
    exit $rc
    ;;
  smoke)
    rc=0; base="http://${APP_BIND:-127.0.0.1}:${APP_PORT}"; base="${base/0.0.0.0/127.0.0.1}"
    code="$(curl -s -o /dev/null -w '%{http_code}' "$base/")"; [ "$code" = 200 ] && ok "Главная страница: 200" || { fail "Главная страница: $code"; rc=1; }
    code="$(curl -s -o /dev/null -w '%{http_code}' "$base/api.php?action=bootstrap")"; [ "$code" = 401 ] && ok "API без входа: 401" || { fail "API без входа: $code (ожидалось 401)"; rc=1; }
    code="$(curl -s -o /dev/null -w '%{http_code}' "$base/llmp/cli.py")"; [ "$code" = 404 ] && ok "Исходники недоступны: $code" || { fail "Исходники отдаются: $code"; rc=1; }
    out="$(dc exec -T app python -m llmp.cli smoke-anon || true)"
    [ "$out" = CLEAN ] && ok "Обезличивание: ФИО, телефон и пароль заменены" || { fail "Обезличивание: $out"; rc=1; }
    net="$(dc exec -T anonymizer python -c 'import socket
try:
    socket.create_connection(("1.1.1.1", 443), timeout=3); print("OPEN")
except OSError: print("CLOSED")' 2>/dev/null || echo CLOSED)"
    [ "$net" = CLOSED ] && ok "Сервис обезличивания изолирован от интернета" || { fail "У сервиса обезличивания есть выход наружу"; rc=1; }
    if [ "${ASR_ENABLED:-0}" = 1 ]; then
      # healthy = процесс жив; модель GigaAM после старта ещё загружается (на медленном процессоре — минуты): повтор до 3 мин
      r=False
      for _ in $(seq 1 36); do
        r="$(dc exec -T asr python -c 'import urllib.request,json; print(json.loads(urllib.request.urlopen(urllib.request.Request("http://127.0.0.1:8090/selftest", method="POST"), timeout=120).read()).get("ok"))' 2>/dev/null || echo False)"
        [ "$r" = True ] && break; sleep 5
      done
      [ "$r" = True ] && ok "ASR: тестовое распознавание выполнено" || { fail "ASR: самопроверка не прошла"; rc=1; }
    fi
    exit $rc
    ;;
  logs)
    dc logs --no-color --tail "${2:-200}" ${1:+"$1"} 2>&1 | mask_stream
    ;;
  diag)
    out="$DATA_ROOT/backups/diag-$(date -u +%Y%m%d-%H%M%S).tar.gz"; tmp="$(mktemp -d "$DATA_ROOT/backups/.diag.XXXX")"
    ( umask 077
      "$0" status > "$tmp/status.txt" 2>&1 || true
      "$0" verify > "$tmp/verify.txt" 2>&1 || true
      dc config 2>/dev/null | mask_stream > "$tmp/compose-config.txt" || true
      dc logs --no-color --tail 2000 2>&1 | mask_stream > "$tmp/logs.txt" || true
      docker version > "$tmp/docker.txt" 2>&1 || true; df -h > "$tmp/disk.txt"; free -m > "$tmp/memory.txt" 2>&1 || true
      # контроль: значения секретов из .env не должны попасть в архив
      while IFS= read -r v; do [ -n "$v" ] && grep -rlF -- "$v" "$tmp" | while read -r f; do sed -i "s|$(printf '%s' "$v" | sed 's/[|&\]/\\&/g')|***|g" "$f"; done; done < <(env_secret_values "$ENV_FILE")
      tar -czf "$out" -C "$tmp" . )
    rm -rf "$tmp"; ok "Диагностика: $out (секреты замаскированы)"
    ;;
  repair)
    prepare_data_dirs
    while IFS='|' read -r s st h _; do
      if [ "$st" != running ] || [ "$h" = unhealthy ]; then warn "Перезапуск $s ($st ${h:-})"; dc up -d --no-deps "$s"; fi
    done < <(svc_table)
    wait_healthy 300 && ok "Все службы healthy"
    ;;
  start) dc up -d ;;
  stop) dc stop ;;
  restart) dc restart ${1:+"$1"} ;;
  admin-reset) dc exec -T app python -m llmp.cli admin-reset --text ;;
  backup|restore|update|rollback|models) exec "$(dirname "$0")/$cmd.sh" "$@" ;;
  *) sed -n '2,17p' "$0"; exit 2 ;;
esac
