#!/usr/bin/env bash
# backup.sh — зашифрованная резервная копия (для SQLite).
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

# Проверка распакованной копии: состав, суммы, целостность и счётчики БД (sqlite3 из стандартной библиотеки python3 хоста).
check_unpacked() {
  local d="$1"
  [ -f "$d/manifest.json" ] && [ -f "$d/app.sqlite" ] && [ -f "$d/env" ] || { fail "В копии нет обязательных частей (manifest.json, app.sqlite, env)"; return 1; }
  python3 - "$d" <<'PY' || return 1
import hashlib, json, os, sys
d = sys.argv[1]; m = json.load(open(os.path.join(d, "manifest.json")))
for name, want in m["sha256"].items():
    got = hashlib.sha256(open(os.path.join(d, name), "rb").read()).hexdigest()
    if got != want: print(f"[FAIL] сумма {name} не совпала"); sys.exit(1)
print(f"[ok] суммы частей совпадают (версия {m['version']}, {m['created']})")
PY
  python3 - "$d" <<'PY'
import json, os, sqlite3, sys
d = sys.argv[1]; m = json.load(open(os.path.join(d, "manifest.json")))
c = sqlite3.connect(f"file:{os.path.join(d, 'app.sqlite')}?mode=ro", uri=True)
if c.execute("PRAGMA integrity_check").fetchone()[0] != "ok": print("[FAIL] БД: integrity_check"); sys.exit(1)
for t, n in m["counts"].items():
    got = c.execute(f'SELECT COUNT(*) FROM "{t}"').fetchone()[0]
    if got != n: print(f"[FAIL] {t}: {got}, в манифесте {n}"); sys.exit(1)
print(f"[ok] БД: целостность в порядке, счётчики совпадают ({len(m['counts'])} таблиц)")
PY
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
# Согласованный снимок БД внутри контейнера app (VACUUM INTO), затем поток tar наружу — без доступа хоста к файлам uid 33.
# Имя снимка уникально: параллельная копия (cron и кнопка в админке) не затрёт чужой снимок.
snap=".snapshot-$$-$RANDOM.sqlite"
dc exec -T app python -m llmp.cli snapshot "/data/app/$snap"
dc exec -T app sh -c "cd /data/app && mkdir -p certs && tar -cf - '$snap' certs" | tar -xf - -C "$tmp"
dc exec -T app rm -f "/data/app/$snap"
mv "$tmp/$snap" "$tmp/app.sqlite"
cp "$ENV_FILE" "$tmp/env"
# shellcheck disable=SC2086
counts="$(python3 -c 'import json,sqlite3,sys; c=sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True); o={}
for t in sys.argv[2:]:
    try: o[t]=c.execute(f"SELECT COUNT(*) FROM \"{t}\"").fetchone()[0]
    except sqlite3.Error: pass
print(json.dumps(o))' "$tmp/app.sqlite" $TABLES)"
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
# Проверка сразу после создания: непроверенная копия — не копия. Неудача — копия удаляется.
if ! verify "$DIR/$name"; then rm -f "$DIR/$name" "$DIR/$name.json"; die "Созданная копия не прошла проверку и удалена"; fi
# ротация: последние KEEP копий; цифра после имени — копии проекта «llm-test» не совпадут с «llm»
ls -1t "$DIR"/"${COMPOSE_PROJECT_NAME}"-[0-9]*.bak.gpg 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do rm -f "$old" "$old.json"; info "Удалена старая копия $(basename "$old")"; done
echo "$DIR/$name"
