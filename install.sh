#!/usr/bin/env bash
# install.sh — установка LLM-провайдера (Docker Compose).
#
#   ./install.sh [--profile standalone|shared-host] [--data-root DIR] [--project NAME] [--port N] [--bind ADDR]
#                [--public-url https://chat.example.com] [--publish port|nginx] [--server-name NAME]
#                [--tls-cert FILE --tls-key FILE] [--models-from DIR] [--no-voice] [--yes] [--dry-run] [--from STAGE]
#
# standalone  — выделенный сервер: при необходимости ставит Docker, Compose и nginx (apt), настраивает свой сайт nginx с TLS.
# shared-host — сервер с другими проектами: ничего не ставит, чужие сайты, контейнеры, сети, тома и файрвол не трогает;
#               порт приложения — только на 127.0.0.1 (или --bind); публикация — свой файл сайта nginx (nginx -t, откат своих
#               изменений) либо внешний reverse proxy. Не хватает инфраструктуры — диагностика и остановка.
# Этапы: preflight → ports → dirs → config → models → build → start → verify → admin → helper → nginx → summary. Повторный запуск безопасен:
# секреты и параметры .env сохраняются, свой порт считается свободным, уже установленный сайт nginx не переписывается без изменений.
set -euo pipefail
cd "$(dirname "$0")"
# shellcheck source=scripts/lib/common.sh
source scripts/lib/common.sh

PROFILE=""; ASSUME_YES=0; FROM_STAGE=""; MODELS_FROM=""; VOICE=1; TLS_CERT=""; TLS_KEY=""
OPT_DATA=""; OPT_PROJECT=""; OPT_PORT=""; OPT_BIND=""; OPT_URL=""; OPT_PUBLISH=""; OPT_SERVER=""
while [ $# -gt 0 ]; do
  case "$1" in
    --profile) PROFILE="$2"; shift 2 ;;
    --data-root) OPT_DATA="$2"; shift 2 ;;
    --project) OPT_PROJECT="$2"; shift 2 ;;
    --port) OPT_PORT="$2"; shift 2 ;;
    --bind) OPT_BIND="$2"; shift 2 ;;
    --public-url) OPT_URL="$2"; shift 2 ;;
    --publish) OPT_PUBLISH="$2"; shift 2 ;;
    --server-name) OPT_SERVER="$2"; shift 2 ;;
    --tls-cert) TLS_CERT="$2"; shift 2 ;;
    --tls-key) TLS_KEY="$2"; shift 2 ;;
    --models-from) MODELS_FROM="$2"; shift 2 ;;
    --no-voice) VOICE=0; shift ;;
    --yes|-y) ASSUME_YES=1; shift ;;
    --dry-run) DRY_RUN=1; export DRY_RUN; shift ;;
    --from) FROM_STAGE="$2"; shift 2 ;;
    -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
    *) die "Неизвестный аргумент: $1 (см. --help)" ;;
  esac
done

STAGES=(preflight ports dirs config models build start verify admin helper nginx summary)
should_run() { # этапы до --from пропускаются
  [ -z "$FROM_STAGE" ] && return 0
  local i want=-1 cur=-1
  for i in "${!STAGES[@]}"; do [ "${STAGES[$i]}" = "$FROM_STAGE" ] && want=$i; [ "${STAGES[$i]}" = "$1" ] && cur=$i; done
  [ "$want" -ge 0 ] || die "Неизвестный этап --from $FROM_STAGE (${STAGES[*]})"
  [ "$cur" -ge "$want" ]
}
fail_stage() { fail "Этап «$1»: $2"; log "Повтор с этого места: ./install.sh --from $1 $( [ "$DRY_RUN" = 1 ] && echo --dry-run)"; exit 1; }
ask() { # ask ПЕРЕМЕННАЯ "вопрос" "по умолчанию"
  local var="$1" q="$2" def="$3" ans=""
  if [ "$ASSUME_YES" = 1 ] || [ ! -r /dev/tty ]; then printf -v "$var" '%s' "$def"; return 0; fi
  read -r -p "$q [$def]: " ans </dev/tty || true
  printf -v "$var" '%s' "${ans:-$def}"
}

# Существующая конфигурация — основа повторного запуска.
if [ -f "$ENV_FILE" ]; then
  sanitize_project_env; load_env "$ENV_FILE"; info "Найдена конфигурация $ENV_FILE — повторный запуск, секреты сохраняются."
fi

# ------------------------------------------------------------------ профиль
detect_profile() { # чужие compose-проекты или веб-сервер на хосте → shared-host
  local others=""
  command -v docker >/dev/null 2>&1 && others="$(docker ps --format '{{.Label "com.docker.compose.project"}}' 2>/dev/null | grep -v "^${COMPOSE_PROJECT_NAME:-llmprov}$" | grep -c . || true)"
  if [ "${others:-0}" -gt 0 ] || systemctl is-active --quiet nginx 2>/dev/null || systemctl is-active --quiet apache2 2>/dev/null; then echo shared-host; else echo standalone; fi
}
[ -n "$PROFILE" ] || PROFILE="${INSTALL_PROFILE:-}"
if [ -z "$PROFILE" ]; then
  REC="$(detect_profile)"
  info "Рекомендуемый профиль: $REC (на сервере $( [ "$REC" = shared-host ] && echo 'уже работают другие проекты или веб-сервер' || echo 'других проектов не найдено'))"
  ask PROFILE "Профиль установки (standalone/shared-host)" "$REC"
fi
[ "$PROFILE" = standalone ] || [ "$PROFILE" = shared-host ] || die "Профиль: standalone или shared-host"
ok "Профиль: $PROFILE$( [ "$DRY_RUN" = 1 ] && echo ' (dry-run: изменения только показываются)')"

# ------------------------------------------------------------------ preflight
stage_preflight() {
  local arch os mem_gb disk_gb need_mem=3 need_disk=12   # ОЗУ: голосовой ввод ~2 ГБ на всё вместе (замер), без него — 2
  arch="$(uname -m)"; [ "$arch" = x86_64 ] || [ "$arch" = aarch64 ] || fail_stage preflight "архитектура $arch не поддерживается (нужна x86_64 или aarch64)"
  os="$(. /etc/os-release 2>/dev/null && echo "${ID:-?} ${VERSION_ID:-?}")"
  case "$os" in ubuntu*|debian*) ok "ОС: $os, $arch" ;; *) warn "ОС $os не проверялась (поддерживаются Ubuntu 22.04+ и Debian 12+)" ;; esac
  [ "$VOICE" = 1 ] || need_mem=2
  mem_gb=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 / 1024 ))
  [ "$mem_gb" -ge "$need_mem" ] || fail_stage preflight "ОЗУ ${mem_gb} ГБ, нужно не меньше ${need_mem} ГБ (голосовой ввод можно выключить: --no-voice)"
  ok "ОЗУ: ${mem_gb} ГБ"
  local probe="${OPT_DATA:-${DATA_ROOT:-/srv}}"; while [ ! -d "$probe" ]; do probe="$(dirname "$probe")"; done
  disk_gb=$(( $(df -Pk "$probe" | awk 'NR==2 {print $4}') / 1024 / 1024 ))
  [ "$disk_gb" -ge "$need_disk" ] || fail_stage preflight "свободно ${disk_gb} ГБ на $probe, нужно не меньше ${need_disk} ГБ (образы ~4 ГБ, модели ASR ~0,5 ГБ, данные, резервные копии)"
  ok "Диск: свободно ${disk_gb} ГБ ($probe)"
  for t in openssl curl ss; do command -v "$t" >/dev/null 2>&1 || MISSING+=("$t"); done
  if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then MISSING+=(docker-compose); fi
  if [ "${#MISSING[@]}" -gt 0 ]; then
    if [ "$PROFILE" = shared-host ]; then
      fail_stage preflight "не хватает: ${MISSING[*]}. Профиль shared-host ничего не устанавливает на общий сервер — установите сами (Docker Engine 24+ с плагином compose) и повторите."
    fi
    [ "$(id -u)" -eq 0 ] || fail_stage preflight "для установки ${MISSING[*]} нужен root (sudo ./install.sh)"
    info "Установка: ${MISSING[*]} (apt, без обновления системы)"
    run env DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 update
    run env DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y --no-install-recommends ca-certificates curl openssl iproute2 docker.io docker-compose-v2
    run systemctl enable --now docker
  fi
  if [ "$DRY_RUN" != 1 ] || command -v docker >/dev/null 2>&1; then
    docker info >/dev/null 2>&1 || fail_stage preflight "нет доступа к Docker (пользователь не в группе docker или служба не запущена)"
    ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null), $(docker compose version --short 2>/dev/null | sed 's/^/Compose /')"
  fi
}
MISSING=()

# Адрес, по которому приложение открывается с других машин: при --bind 0.0.0.0 — первый адрес сервера.
reach_host() { if [ "$APP_BIND" = 0.0.0.0 ]; then hostname -I 2>/dev/null | awk '{print $1}'; else echo "$APP_BIND"; fi; }

# Процессы и лимиты памяти — по объёму ОЗУ сервера (замер: ASR с GigaAM ~1,5 ГБ, обезличивание ~0,2 ГБ, процесс приложения ~0,1 ГБ).
# Значение меняется, только если в .env осталось значение по умолчанию из .env.example: ручная настройка администратора сохраняется.
size_resources() {
  local mb workers app anon asr k v cur def
  mb=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 ))
  if [ "$mb" -ge 7500 ]; then return 0
  elif [ "$mb" -ge 5000 ]; then workers=2; app=1g; anon=1g; asr=2g
  else workers=2; app=640m; anon=768m; asr=1800m
  fi
  for kv in "APP_WORKERS=$workers" "APP_MEM_LIMIT=$app" "ANON_MEM_LIMIT=$anon" "ASR_MEM_LIMIT=$asr"; do
    k="${kv%%=*}"; v="${kv#*=}"
    cur="$(grep -E "^$k=" "$ENV_FILE" | cut -d= -f2-)"; def="$(grep -E "^$k=" .env.example | cut -d= -f2-)"
    if [ -z "$cur" ] || [ "$cur" = "$def" ]; then set_value "$k" "$v"; fi
  done
  ok "Ресурсы под ${mb} МБ ОЗУ: процессов приложения $workers, лимиты app $app, обезличивание $anon, ASR $asr"
}

# ------------------------------------------------------------------ параметры
collect() {
  ask COMPOSE_PROJECT_NAME "Имя Compose-проекта (уникальное на сервере)" "${OPT_PROJECT:-${COMPOSE_PROJECT_NAME:-llmprov}}"
  validate_project_name
  if [ ! -f "$ENV_FILE" ] && docker ps -a --format '{{.Label "com.docker.compose.project"}}' 2>/dev/null | grep -qx "$COMPOSE_PROJECT_NAME"; then
    die "Compose-проект «$COMPOSE_PROJECT_NAME» уже существует на этом сервере, а конфигурации в $REPO_ROOT нет — выберите другое имя (--project)."
  fi
  ask DATA_ROOT "Каталог данных (БД, журналы, модели, резервные копии)" "${OPT_DATA:-${DATA_ROOT:-/srv/${COMPOSE_PROJECT_NAME}-data}}"
  local why; why="$(validate_data_root "$DATA_ROOT")" || die "DATA_ROOT: $why"
  APP_BIND="${OPT_BIND:-${APP_BIND:-127.0.0.1}}"
  PUBLISH="${OPT_PUBLISH:-${PUBLISH:-$( [ "$PROFILE" = standalone ] && echo nginx || echo port)}}"   # повторный запуск — выбор из .env
  ask PUBLISH "Публикация: nginx (свой сайт в системном nginx) или port (порт для внешнего прокси)" "$PUBLISH"
  ask APP_PUBLIC_URL "Публичный адрес чата (https://…; пусто — позже)" "${OPT_URL:-${APP_PUBLIC_URL:-}}"
  SERVER_NAME="${OPT_SERVER:-$(printf '%s' "$APP_PUBLIC_URL" | sed -E 's#^[a-z]+://##; s#[:/].*$##')}"
}

stage_ports() {
  local want="${OPT_PORT:-${APP_PORT:-18480}}"
  if port_owned_by_project "$want"; then ok "Порт $want уже занят этим же проектом (повторный запуск)"; APP_PORT="$want"; return 0; fi
  if port_busy tcp "$want" || docker ps --format '{{.Ports}}' 2>/dev/null | grep -Eq "(:|^)$want->"; then
    local free; free="$(find_port "$want")" || fail_stage ports "не найден свободный порт от $want"
    warn "Порт $want занят — свободный вариант: $free"
    ask want "Порт приложения" "$free"
    port_busy tcp "$want" && fail_stage ports "порт $want занят"
  fi
  APP_PORT="$want"; ok "Порт приложения: ${APP_BIND}:${APP_PORT}"
}

stage_dirs() { prepare_data_dirs; ok "Каталоги данных: $DATA_ROOT"; }

stage_config() {
  if [ "$DRY_RUN" = 1 ]; then info "[dry-run] $ENV_FILE: параметры экземпляра, секреты (если ещё нет), права 600"; return 0; fi
  [ -f "$ENV_FILE" ] || { install -m 0600 /dev/null "$ENV_FILE"; cat .env.example > "$ENV_FILE"; }
  chmod 600 "$ENV_FILE"
  set_value COMPOSE_PROJECT_NAME "$COMPOSE_PROJECT_NAME"; set_value INSTALL_PROFILE "$PROFILE"; set_value DATA_ROOT "$DATA_ROOT"
  set_value APP_BIND "$APP_BIND"; set_value APP_PORT "$APP_PORT"; set_value APP_PUBLIC_URL "$APP_PUBLIC_URL"; set_value PUBLISH "$PUBLISH"
  set_value COMPOSE_PROFILES "$( [ "$VOICE" = 1 ] && echo voice || echo '')"; set_value ASR_ENABLED "$VOICE"
  set_value IMAGE_TAG "$(app_commit)"
  # Прежняя установка 1.x на PHP-хостинге: ключ из var/data/master.key переносится, а не создаётся заново (иначе секреты не расшифровать).
  if [ -f var/data/master.key ] && ! grep -qE '^APP_MASTER_KEY=.+' "$ENV_FILE"; then set_default APP_MASTER_KEY "$(tr -d '\n' < var/data/master.key)"; info "Мастер-ключ перенесён из var/data/master.key"; fi
  set_default APP_MASTER_KEY "$(openssl rand -base64 32)"
  set_default INTERNAL_API_TOKEN "$(rand_hex 32)"; set_default DICTATION_SECRET "$(rand_hex 32)"; set_default REDIS_PASSWORD "$(rand_hex 24)"
  if [ "$PROFILE" = shared-host ]; then   # на общем сервере ASR не забирает все ядра
    local half=$(( $(nproc) / 2 )); [ "$half" -ge 1 ] || half=1; grep -qE '^ASR_CPU_THREADS=[1-9]' "$ENV_FILE" || set_value ASR_CPU_THREADS "$half"
  fi
  size_resources
  [ -f .backup.key ] || { (umask 077; openssl rand -base64 48 > .backup.key); warn "Создан ключ резервных копий $REPO_ROOT/.backup.key — сохраните его копию ВНЕ сервера: без него копии не восстановить."; }
  # Перенос существующих данных PHP-установки (var/data) в каталог данных — однократно, исходник не удаляется.
  if [ -f var/data/app.sqlite ] && [ ! -f "$DATA_ROOT/app/data/app.sqlite" ]; then
    docker run --rm -v "$REPO_ROOT/var:/src:ro" -v "$DATA_ROOT/app:/dst" "${HELPER_IMAGE:-alpine:3.21}" sh -c 'mkdir -p /dst/data /dst/certs && cp -a /src/data/app.sqlite /dst/data/ && (cp -a /src/data/.installed /dst/data/ 2>/dev/null || true) && (cp -a /src/certs/. /dst/certs/ 2>/dev/null || true) && chown -R 33:33 /dst'
    ok "Существующие данные перенесены из var/ в $DATA_ROOT/app (исходник сохранён)"
  fi
  unset COMPOSE_PROFILES APP_PORT APP_BIND DATA_ROOT; sanitize_project_env; load_env "$ENV_FILE"
  ok "Конфигурация: $ENV_FILE (600)"
}

stage_models() {
  [ "$VOICE" = 1 ] || { info "Голосовой ввод выключен — модели ASR не нужны"; return 0; }
  scripts/models.sh ${MODELS_FROM:+--from-dir "$MODELS_FROM"} || fail_stage models "модель распознавания речи не загружена (причины выше)"
}

stage_build() {
  export APP_VERSION; APP_VERSION="$(app_version)"; export APP_GIT_COMMIT; APP_GIT_COMMIT="$(app_commit)"
  info "Сборка образов (первый раз ASR собирается 10–30 минут: torch и GigaAM)"
  run build_images || fail_stage build "сборка не удалась — проверьте доступ к реестрам и pypi; повтор: ./install.sh --from build"
}

stage_start() {
  run dc up -d --remove-orphans || fail_stage start "контейнеры не запустились (./scripts/ctl.sh logs)"
  [ "$DRY_RUN" = 1 ] && return 0
  wait_healthy 420 || fail_stage start "службы не стали healthy (./scripts/ctl.sh status; ./scripts/ctl.sh logs)"
  ok "Все службы healthy"
}

stage_verify() { [ "$DRY_RUN" = 1 ] && { info "[dry-run] scripts/ctl.sh smoke"; return 0; }; scripts/ctl.sh smoke || fail_stage verify "smoke-test не пройден"; }

# Помощник обслуживания (systemd) — кнопки «Проверить обновления», «Обновить», «Резервная копия» в админке работают сразу.
stage_helper() {
  if [ "$DRY_RUN" = 1 ]; then info "[dry-run] служба помощника обслуживания (scripts/updater.sh install)"; return 0; fi
  if [ "$(id -u)" -eq 0 ]; then
    "$REPO_ROOT/scripts/updater.sh" install >/dev/null && ok "Помощник обслуживания установлен: кнопки обслуживания в админке работают"
  else
    warn "Помощник обслуживания не установлен (нужен root): для кнопок обслуживания в админке выполните sudo scripts/updater.sh install"
  fi
}

stage_admin() {
  [ "$DRY_RUN" = 1 ] && { info "[dry-run] создание аварийного администратора (если его ещё нет)"; return 0; }
  if dc exec -T app python -m llmp.cli admin-exists; then
    ADMIN_NOTE="аварийный администратор уже есть (сброс пароля: ./scripts/ctl.sh admin-reset)"
  else
    ADMIN_NOTE="$(dc exec -T app python -m llmp.cli admin-reset --text)"
    # сразу, а не только в итоге: если следующий этап (nginx) упадёт, итог не выведется и пароль потеряется
    ok "Аварийный администратор: $ADMIN_NOTE"
  fi
}

render_site() {
  local redirect="" tls="" listen="80"
  if [ -n "$TLS_CERT" ]; then
    listen="443 ssl"; tls="    ssl_certificate     $TLS_CERT;
    ssl_certificate_key $TLS_KEY;
    ssl_protocols TLSv1.2 TLSv1.3;"
    redirect="server { listen 80; server_name ${SERVER_NAME}; return 301 https://\$host\$request_uri; }"
  fi
  sed -e "s|@@PROJECT@@|$COMPOSE_PROJECT_NAME|g" -e "s|@@APP_PORT@@|$APP_PORT|g" -e "s|@@SERVER_NAME@@|${SERVER_NAME:-_}|g" -e "s|@@LISTEN@@|$listen|g" deploy/nginx/site.conf.tpl \
    | awk -v r="$redirect" -v t="$tls" '{ if ($0=="@@REDIRECT@@") print r; else if ($0=="@@TLS@@") print t; else print }'
}

stage_nginx() {
  if [ "$PUBLISH" != nginx ]; then
    info "Публикация через внешний прокси: направьте его на http://$(reach_host):${APP_PORT} (WebSocket /ws/dictation; X-Forwarded-For перезаписывать)."
    [ "$DRY_RUN" = 1 ] || { render_site > "$DATA_ROOT/nginx-site.example.conf"; info "Пример сайта для системного nginx: $DATA_ROOT/nginx-site.example.conf"; }
    return 0
  fi
  if ! command -v nginx >/dev/null 2>&1; then
    [ "$PROFILE" = standalone ] || fail_stage nginx "системного nginx нет. В shared-host он не ставится: используйте --publish port и свой прокси."
    run as_root env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends nginx
  fi
  [ -n "$SERVER_NAME" ] || fail_stage nginx "для сайта nginx нужен публичный адрес (--public-url)"
  [ -z "$TLS_CERT" ] || { [ -r "$TLS_CERT" ] && [ -r "$TLS_KEY" ]; } || fail_stage nginx "сертификат или ключ не читается"
  local site="/etc/nginx/sites-available/$COMPOSE_PROJECT_NAME.conf" link="/etc/nginx/sites-enabled/$COMPOSE_PROJECT_NAME.conf" rendered prev="" new_file=0 new_link=0 tlog
  if [ -e "$site" ] && ! grep -qx "# managed-by: llm-provider:${COMPOSE_PROJECT_NAME}" "$site"; then fail_stage nginx "$site принадлежит не этому проекту — отказ"; fi
  rendered="$(render_site)"
  if [ -f "$site" ] && [ "$(cat "$site")" = "$rendered" ] && [ -L "$link" ]; then ok "Сайт nginx уже актуален ($site)"; return 0; fi
  if [ "$DRY_RUN" = 1 ]; then info "[dry-run] будет записан $site и симлинк $link; затем nginx -t и только при успехе reload; при ошибке — откат своих изменений"; printf '%s\n' "$rendered" | sed 's/^/    | /'; return 0; fi
  [ -e "$site" ] || new_file=1; [ -e "$link" ] || new_link=1; [ -f "$site" ] && prev="$(cat "$site")"
  printf '%s\n' "$rendered" | as_root tee "$site" >/dev/null
  [ "$new_link" = 1 ] && as_root ln -s "$site" "$link"
  tlog="$(mktemp)"
  if as_root nginx -t 2>"$tlog"; then
    as_root systemctl reload nginx; ok "Сайт nginx установлен, nginx перезагружен (reload)"
  else
    [ "$new_link" = 1 ] && as_root rm -f "$link"
    if [ "$new_file" = 1 ]; then as_root rm -f "$site"; elif [ -n "$prev" ]; then printf '%s\n' "$prev" | as_root tee "$site" >/dev/null; fi
    fail "nginx -t: $(tail -3 "$tlog" | tr '\n' ' ')"; rm -f "$tlog"
    fail_stage nginx "свои изменения откатаны, reload не выполнялся. Приложение работает на ${APP_BIND}:${APP_PORT}."
  fi
  rm -f "$tlog"
}

stage_summary() {
  log ""
  if [ "$DRY_RUN" = 1 ]; then ok "Предпросмотр завершён: изменений не внесено. Запуск без --dry-run выполнит показанные шаги."; return 0; fi
  ok "Установка завершена: $COMPOSE_PROJECT_NAME ($PROFILE), версия $(app_version) ($(app_commit))"
  log "  Адрес:           ${APP_PUBLIC_URL:-http://$(reach_host):${APP_PORT}} (локально: http://127.0.0.1:${APP_PORT})"
  log "  Администрирование: /admin/  — ${ADMIN_NOTE:-}"
  log "  Данные:          $DATA_ROOT     Конфигурация: $ENV_FILE (600)"
  log "  Ключ копий:      $REPO_ROOT/.backup.key — сохраните вне сервера"
  log "  Дальше:          настройте домен AD и нейросети в /admin/; обслуживание — ./scripts/ctl.sh {status|verify|logs|diag|backup|update}"
}

should_run preflight && stage_preflight
collect
for s in ports dirs config models build start verify admin helper nginx summary; do should_run "$s" && "stage_$s"; done
