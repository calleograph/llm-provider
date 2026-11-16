#!/usr/bin/env bash
# models.sh — веса распознавания речи GigaAM в ${DATA_ROOT}/models/gigaam. Модели в Git не хранятся.
#
#   scripts/models.sh [--from-dir КАТАЛОГ] [--restart]
#
# Нужны два файла: <ASR_MODEL_NAME>.ckpt и <ASR_MODEL_NAME>_tokenizer.model. Если оба на месте и целы — ничего не делает.
# Источники по порядку: зеркало проекта на GitHub (Releases, тег models-gigaam-v3), свой GIGAAM_MODEL_BASE_URL из .env,
# CDN разработчика модели. Для модели по умолчанию сверяется SHA-256; HTML-страницы прокси и обрывы не принимаются.
# --from-dir — закрытая сеть: файлы берутся из каталога. --restart — после загрузки перезапустить службу ASR.
# Вызывают install.sh (этап models), update.sh и помощник обновлений; вручную: scripts/ctl.sh models.
set -euo pipefail
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"

FROM_DIR=""; RESTART=0
while [ $# -gt 0 ]; do
  case "$1" in
    --from-dir) FROM_DIR="$2"; shift 2 ;;
    --restart) RESTART=1; shift ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) die "Неизвестный аргумент: $1" ;;
  esac
done
sanitize_project_env; load_env "$ENV_FILE"; validate_project_name
[ "${ASR_ENABLED:-1}" = 1 ] || { info "Голосовой ввод выключен — модели ASR не нужны"; exit 0; }

NAME="${ASR_MODEL_NAME:-v3_e2e_rnnt}"
[[ "$NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "Недопустимое имя модели: $NAME"
DEST="$DATA_ROOT/models/gigaam"; STAGE="$DATA_ROOT/updater/models-stage"
SOURCES=("https://github.com/calleograph/llm-provider/releases/download/models-gigaam-v3")
[ -z "${GIGAAM_MODEL_BASE_URL:-}" ] || SOURCES+=("$GIGAAM_MODEL_BASE_URL")
SOURCES+=("https://cdn.chatwm.opensmodel.sberdevices.ru/GigaAM")
CKPT_SHA="${GIGAAM_CKPT_SHA256:-}"; TOK_SHA="${GIGAAM_TOKENIZER_SHA256:-}"
if [ "$NAME" = v3_e2e_rnnt ]; then   # проверенные значения модели по умолчанию
  CKPT_SHA="${CKPT_SHA:-f60c62fe45902d967000770a12f260de7c5ac4d4b8e5e852f1df8c548e3958b5}"
  TOK_SHA="${TOK_SHA:-828c12c991019eef952a960661f25a92d6ad279591e2ea466b4aeddf1d20a18a}"
fi

# файл цел: размер не меньше нижней границы (чекпойнт ~450 МБ, токенайзер ~250 КБ) и SHA-256, если известна
good() { # файл мин_размер sha
  [ -f "$1" ] && [ "$(stat -c %s "$1")" -ge "$2" ] && { [ -z "$3" ] || [ "$(sha256sum "$1" | cut -d' ' -f1)" = "$3" ]; }
}

# в каталог моделей (принадлежит root): напрямую, а если нет прав — вспомогательным контейнером
place() { # имя
  if [ -w "$DEST" ]; then mv -f "$STAGE/$1" "$DEST/$1" && chmod a+r "$DEST/$1"
  else docker run --rm -v "$DATA_ROOT:/d" "${HELPER_IMAGE:-alpine:3.21}" sh -c "mv -f '/d/updater/models-stage/$1' '/d/models/gigaam/$1' && chmod a+r '/d/models/gigaam/$1'"; fi
}

fetch() { # имя мин_размер sha
  local f="$1" min="$2" want="$3" src why="" rc
  if good "$DEST/$f" "$min" "$want"; then ok "Модель ASR: $f на месте"; return 0; fi
  mkdir -p "$STAGE"; rm -f "$STAGE/$f" "$STAGE/$f.part"
  if [ -n "$FROM_DIR" ]; then
    [ -f "$FROM_DIR/$f" ] || die "в каталоге $FROM_DIR нет файла $f"
    cp -f "$FROM_DIR/$f" "$STAGE/$f.part"
  else
    for src in "${SOURCES[@]}"; do
      info "Загрузка $f: ${src%%/GigaAM*}"
      rm -f "$STAGE/$f.part"; rc=0
      curl -fL --retry 3 --retry-delay 5 --connect-timeout 20 --speed-limit 10240 --speed-time 60 -sS -o "$STAGE/$f.part" "$src/$f" 2>"$STAGE/curl.err" || rc=$?
      if [ "$rc" = 0 ] && good "$STAGE/$f.part" "$min" "$want" && ! head -c 512 "$STAGE/$f.part" | grep -qi '<html\|<!doctype'; then break; fi
      why+="  - $src: $([ "$rc" = 0 ] && echo 'файл не прошёл проверку размера или SHA-256' || echo "код curl $rc: $(head -c 160 "$STAGE/curl.err" | tr '\n' ' ')")"$'\n'
      rm -f "$STAGE/$f.part"
    done
    [ -f "$STAGE/$f.part" ] || die "не удалось скачать $f ни из одного источника:"$'\n'"$why""Закрытая сеть: scripts/models.sh --from-dir КАТАЛОГ (нужны $NAME.ckpt и ${NAME}_tokenizer.model)."
  fi
  good "$STAGE/$f.part" "$min" "$want" || die "$f не прошёл проверку (размер или SHA-256)"
  mv -f "$STAGE/$f.part" "$STAGE/$f"; place "$f"; CHANGED=1
  ok "Модель ASR: $f ($(du -h "$DEST/$f" | cut -f1)${want:+, SHA-256 совпала})"
}

CHANGED=0
if [ "$DRY_RUN" = 1 ]; then info "[dry-run] загрузка $NAME.ckpt и ${NAME}_tokenizer.model в $DEST"; exit 0; fi
mkdir -p "$DEST" 2>/dev/null || true
fetch "$NAME.ckpt" 100000000 "$CKPT_SHA"
fetch "${NAME}_tokenizer.model" 100000 "$TOK_SHA"
rm -rf "$STAGE"
if [ "$CHANGED" = 1 ] && [ "$RESTART" = 1 ]; then dc restart asr >/dev/null && ok "Служба ASR перезапущена — голосовой ввод готов"; fi
