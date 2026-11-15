#!/usr/bin/env bash
# backup.sh — зашифрованная резервная копия (приёмы scripts/backup.sh ArkLock, для SQLite).
#
#   scripts/backup.sh [--keep N]            создать копию и сразу проверить её
#   scripts/backup.sh --verify FILE         сумма SHA-256, расшифровка, состав, целостность БД
#   scripts/backup.sh --restore-test FILE   то же + пробное восстановление во временный каталог и сверка счётчиков с манифестом
#   scripts/backup.sh --list
#
# Состав: снимок БД (VACUUM INTO — согласованный, без остановки), сертификаты, .env (конфигурация и секреты, включая мастер-ключ:
# без него сохранённые ключи провайдеров не расшифровать), manifest.json (версия, суммы, счётчики таблиц). Пользователи, история,
# проекты, настройки моделей, провайдеров, обезличивания и словари — в БД. Модели ASR не копируются (скачиваются заново).
# Шифрование: gpg --symmetric AES256 ключом .backup.key (хранится отдельно от копий; держите его копию вне сервера).
set -euo pipefail
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"
sanitize_project_env; load_env "$ENV_FILE"; validate_project_name

KEY="${BACKUP_KEY_FILE:-$REPO_ROOT/.backup.key}"
DIR="${BACKUP_DIR:-$DATA_ROOT/backups}"
KEEP="${BACKUP_KEEP:-14}"
TABLES="llm_providers llm_models llm_conversations llm_messages llm_projects llm_project_sources llm_users llm_domain_groups app_settings local_admin"
[ -r "$KEY" ] || die "Нет ключа резервных копий $KEY (создаётся install.sh). Без ключа копию нельзя ни создать, ни восстановить."
command -v gpg >/dev/null 2>&1 || die "Нужен gpg (apt install gnupg)"
mkdir -p "$DIR"; chmod 700 "$DIR" 2>/dev/null || true

image() { docker image inspect "${COMPOSE_PROJECT_NAME}-php:${IMAGE_TAG:-local}" >/dev/null 2>&1 && echo "${COMPOSE_PROJECT_NAME}-php:${IMAGE_TAG:-local}" || dc images -q php 2>/dev/null | head -1; }

# Проверка распакованной копии: состав, суммы, целостность и счётчики БД (в контейнере с PHP — на хосте PHP может не быть).
check_unpacked() {
  local d="$1" img
  [ -f "$d/manifest.json" ] && [ -f "$d/app.sqlite" ] && [ -f "$d/env" ] || { fail "В копии нет обязательных частей (manifest.json, app.sqlite, env)"; return 1; }
  python3 - "$d" <<'PY' || return 1
import hashlib, json, os, sys
d = sys.argv[1]; m = json.load(open(os.path.join(d, "manifest.json")))
for name, want in m["sha256"].items():
    got = hashlib.sha256(open(os.path.join(d, name), "rb").read()).hexdigest()
    if got != want: print(f"[FAIL] сумма {name} не совпала"); sys.exit(1)
print(f"[ok] суммы частей совпадают (версия {m['version']}, {m['created']})")
PY
  img="$(image)"; [ -n "$img" ] || { warn "Образ php не найден — проверка БД пропущена"; return 0; }
  docker run --rm --network none --user "$(id -u):$(id -g)" -v "$d:/v:ro" --entrypoint php "$img" -r '
    $m = json_decode(file_get_contents("/v/manifest.json"), true);
    $p = new PDO("sqlite:/v/app.sqlite"); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($p->query("PRAGMA integrity_check")->fetchColumn() !== "ok") { echo "[FAIL] БД: integrity_check\n"; exit(1); }
    foreach ($m["counts"] as $t => $n) { $c = (int) $p->query("SELECT COUNT(*) FROM $t")->fetchColumn(); if ($c !== $n) { echo "[FAIL] $t: $c, в манифесте $n\n"; exit(1); } }
    echo "[ok] БД: целостность в порядке, счётчики совпадают (" . count($m["counts"]) . " таблиц)\n";'
}

decrypt_to() { # FILE DIR
  local f="$1" d="$2" want
  want="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sha256"])' "$f.json" 2>/dev/null || true)"
  [ -n "$want" ] && [ "$(sha256sum "$f" | cut -d' ' -f1)" = "$want" ] || { fail "Сумма SHA-256 файла копии не совпала или нет $f.json"; return 1; }
  ok "Файл копии цел (SHA-256)"
  gpg --batch --quiet --decrypt --passphrase-file "$KEY" "$f" | tar -xf - -C "$d" || { fail "Не расшифровывается этим ключом или повреждена"; return 1; }
}

verify() {
  local f="$1" tmp rc=0
  [ -f "$f" ] || die "Нет файла $f"
  tmp="$(mktemp -d "$DIR/.verify.XXXX")"; trap 'rm -rf "$tmp"' RETURN
  decrypt_to "$f" "$tmp" && check_unpacked "$tmp" || rc=1
  return $rc
}

case "${1:-}" in
  --verify) verify "${2:?файл копии}"; exit $? ;;
  --restore-test)
    verify "${2:?файл копии}" || exit 1
    ok "Пробное восстановление: копия расшифрована, БД открывается, данные совпадают с манифестом"; exit 0 ;;
  --list) ls -1t "$DIR"/*.bak.gpg 2>/dev/null || info "Копий нет"; exit 0 ;;
  --keep) KEEP="${2:?}" ;;
  "") ;;
  *) sed -n '2,13p' "$0"; exit 2 ;;
esac

name="${COMPOSE_PROJECT_NAME}-$(date -u +%Y%m%d-%H%M%S)-$(app_version).bak.gpg"
tmp="$(mktemp -d "$DIR/.make.XXXX")"; trap 'rm -rf "$tmp"; rm -f "$DIR/$name.part"' EXIT
umask 077
# Согласованный снимок БД внутри контейнера php (VACUUM INTO), затем поток tar наружу — без доступа хоста к файлам uid 33.
dc exec -T php php -r '@unlink("/data/app/.snapshot.sqlite"); (new PDO("sqlite:/data/app/data/app.sqlite"))->exec("VACUUM INTO \x27/data/app/.snapshot.sqlite\x27");'
dc exec -T php sh -c 'cd /data/app && mkdir -p certs && tar -cf - .snapshot.sqlite certs' | tar -xf - -C "$tmp"
dc exec -T php rm -f /data/app/.snapshot.sqlite
mv "$tmp/.snapshot.sqlite" "$tmp/app.sqlite"
cp "$ENV_FILE" "$tmp/env"
img="$(image)"
counts="$(docker run --rm --network none --user "$(id -u):$(id -g)" -v "$tmp:/v:ro" --entrypoint php "$img" -r '$p = new PDO("sqlite:/v/app.sqlite"); $o = []; foreach (explode(" ", $argv[1]) as $t) { try { $o[$t] = (int) $p->query("SELECT COUNT(*) FROM $t")->fetchColumn(); } catch (Throwable) {} } echo json_encode($o);' "$TABLES")"
python3 - "$tmp" "$(app_version)" "$(app_commit)" "$counts" <<'PY'
import hashlib, json, os, sys, datetime
d, ver, commit, counts = sys.argv[1], sys.argv[2], sys.argv[3], json.loads(sys.argv[4])
files = ["app.sqlite", "env"] + [os.path.join("certs", f) for f in sorted(os.listdir(os.path.join(d, "certs")))]
json.dump({"version": ver, "commit": commit, "created": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
           "sha256": {f: hashlib.sha256(open(os.path.join(d, f), "rb").read()).hexdigest() for f in files}, "counts": counts},
          open(os.path.join(d, "manifest.json"), "w"), indent=1)
PY
tar -C "$tmp" -cf - . | gpg --batch --quiet --symmetric --cipher-algo AES256 --s2k-mode 3 --s2k-digest-algo SHA512 --s2k-count 65011712 --passphrase-file "$KEY" -o "$DIR/$name.part"
mv "$DIR/$name.part" "$DIR/$name"
printf '{"sha256":"%s","created":"%s","version":"%s"}\n' "$(sha256sum "$DIR/$name" | cut -d' ' -f1)" "$(date -u +%FT%TZ)" "$(app_version)" > "$DIR/$name.json"
ok "Копия создана: $DIR/$name ($(du -h "$DIR/$name" | cut -f1))"
# Проверка сразу после создания: непроверенная копия — не копия (ArkLock). Неудача — копия удаляется.
if ! verify "$DIR/$name"; then rm -f "$DIR/$name" "$DIR/$name.json"; die "Созданная копия не прошла проверку и удалена"; fi
# ротация: последние KEEP копий
ls -1t "$DIR"/"${COMPOSE_PROJECT_NAME}"-*.bak.gpg 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do rm -f "$old" "$old.json"; info "Удалена старая копия $(basename "$old")"; done
echo "$DIR/$name"
