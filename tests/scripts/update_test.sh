#!/usr/bin/env bash
# Тесты обновления по git без Docker: bash tests/scripts/update_test.sh
# Временный bare-репозиторий «origin» и его клоны (ветка, релизный тег, мелкий клон) — в каталоге mktemp (TMPDIR), удаляется в конце.
# Проверяется: check-updates.sh (нет обновлений / есть — для ветки и для релизных тегов), автопочинка (зависший index.lock,
# локальная правка сохраняется патчем и обновление продолжается, мелкий клон догружается), update.sh --dry-run не меняет HEAD.
set -uo pipefail
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
PASS=0; FAIL=0
t() { local d="$1"; shift; if "$@" >"$T/last.log" 2>&1; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL: $d"; sed 's/^/    /' "$T/last.log"; fi; }
g() { git -C "$@"; }

# origin: копия скриптов проекта + .env.example + VERSION; v1.0.0
W="$T/work"; O="$T/origin.git"; mkdir -p "$W" "$T/data"
cp -r "$SRC/scripts" "$SRC/.env.example" "$W/"; echo 1.0.0 > "$W/VERSION"
g "$W" init -q -b main && g "$W" add -A && g "$W" commit -qm "release 1.0.0" && g "$W" tag v1.0.0
git clone -q --bare "$W" "$O" && g "$W" remote add origin "$O" && g "$W" fetch -q origin
git clone -q "$O" "$T/branch"; git clone -q --branch v1.0.0 "$O" "$T/release" 2>/dev/null; git clone -q --depth 1 "file://$O" "$T/shallow"
sed "s|^COMPOSE_PROJECT_NAME=.*|COMPOSE_PROJECT_NAME=llmptest|; s|^DATA_ROOT=.*|DATA_ROOT=$T/data|" "$SRC/.env.example" > "$T/env"; export ENV_FILE="$T/env"
check() { bash "$1/scripts/check-updates.sh"; }
rc_is() { local want="$1"; shift; "$@"; [ $? -eq "$want" ]; }

t "ветка: обновлений нет → код 0" rc_is 0 check "$T/branch"
t "релиз: обновлений нет → код 0" rc_is 0 check "$T/release"

# новая версия: v1.1.0 с новым ключом .env, затем коммит только в ветке
echo 1.1.0 > "$W/VERSION"; printf 'NEW_TEST_KEY=1\n' >> "$W/.env.example"
g "$W" commit -qam "release 1.1.0" && g "$W" tag v1.1.0
echo x > "$W/extra.txt"; g "$W" add extra.txt && g "$W" commit -qm "branch only commit"
g "$W" push -q origin main --tags

avail() { check "$1" > "$T/out.txt"; [ $? -eq 10 ] || return 1; shift; local p; for p in "$@"; do case "$p" in !*) ! grep -q "${p#!}" "$T/out.txt" || return 1 ;; *) grep -q "$p" "$T/out.txt" || return 1 ;; esac; done; }
t "ветка: доступно обновление → код 10, виден новый коммит и новый ключ .env" avail "$T/branch" "branch only commit" NEW_TEST_KEY
t "релиз: доступно обновление до v1.1.0 (не до коммита ветки)" avail "$T/release" v1.1.0 NEW_TEST_KEY "!branch only commit" "!BACKUP_KEEP"

touch "$T/branch/.git/index.lock"; touch -d '-5 min' "$T/branch/.git/index.lock"
t "автопочинка: зависший .git/index.lock снят" bash -c "bash '$T/branch/scripts/check-updates.sh' >/dev/null; [ ! -e '$T/branch/.git/index.lock' ]"

t "автопочинка: мелкий клон догружен, обновление видно" bash -c "bash '$T/shallow/scripts/check-updates.sh'; [ \$? -eq 10 ] && [ \"\$(git -C '$T/shallow' rev-parse --is-shallow-repository)\" = false ]"

echo "local edit" >> "$T/branch/VERSION"
save_and_continue() {
  # shellcheck source=/dev/null
  source "$T/branch/scripts/lib/common.sh"; source "$T/branch/scripts/lib/autorepair.sh"; DATA_ROOT="$T/data"
  local d; d="$(upd_save_local_changes)" || return 1
  [ -s "$d/changes.patch" ] && grep -q "local edit" "$d/changes.patch" && [ -s "$d/files.tar.gz" ] || return 1
  [ -z "$(git -C "$T/branch" status --porcelain --untracked-files=no)" ] && git -C "$T/branch" stash list | grep -q llmp-update || return 1
  git -C "$T/branch" merge -q --ff-only origin/main
}
t "автопочинка: локальная правка сохранена (патч, архив, stash), затем перемотка вперёд проходит" save_and_continue

echo "local edit" >> "$T/release/VERSION"; before="$(g "$T/release" rev-parse HEAD)"
t "update.sh --dry-run: печатает план, код 0, HEAD и правки не тронуты" bash -c "set -o pipefail; bash '$T/release/scripts/update.sh' --dry-run | tee '$T/dry.txt' && grep -q 'dry-run' '$T/dry.txt' && grep -q 'v1.1.0' '$T/dry.txt' \
  && [ \"\$(git -C '$T/release' rev-parse HEAD)\" = '$before' ] && grep -q 'local edit' '$T/release/VERSION' && [ ! -e '$T/data/updater/update.state' ]"

# Полный прогон update.sh --yes без Docker: docker — заглушка в PATH, ctl.sh и backup.sh в «origin» — заглушки. Новая версия сдвигает
# update.sh на диске (300 строк в начале): старый процесс не должен прочитать новый файл посреди работы. Канал — релизные теги.
F="$T/full"; mkdir -p "$F/bin" "$F/data"
printf '#!/bin/sh\n[ "$1" = info ] && echo /\nexit 0\n' > "$F/bin/docker"; chmod +x "$F/bin/docker"
cp -r "$W" "$F/w"; g "$F/w" remote remove origin; g "$F/w" checkout -q v1.1.0 -B rel
printf '#!/bin/sh\necho ok\n' > "$F/w/scripts/ctl.sh"; printf '#!/bin/sh\necho %s/fake.bak.gpg\n' "$F" > "$F/w/scripts/backup.sh"
g "$F/w" commit -qam "stubs" && g "$F/w" tag v1.2.0 && git clone -q --bare "$F/w" "$F/o.git" && git clone -q --branch v1.2.0 "$F/o.git" "$F/c" 2>/dev/null
{ printf '#!/usr/bin/env bash\n'; for _ in $(seq 300); do echo "# сдвиг"; done; tail -n +2 "$F/w/scripts/update.sh"; } > "$F/u" && mv "$F/u" "$F/w/scripts/update.sh"
echo 1.3.0 > "$F/w/VERSION"; printf 'FULL_KEY=7\n' >> "$F/w/.env.example"; g "$F/w" commit -qam "release 1.3.0" && g "$F/w" tag v1.3.0 && g "$F/w" push -q "$F/o.git" v1.3.0
sed "s|^COMPOSE_PROJECT_NAME=.*|COMPOSE_PROJECT_NAME=llmptest|; s|^DATA_ROOT=.*|DATA_ROOT=$F/data|" "$SRC/.env.example" > "$F/env"
echo "local edit" >> "$F/c/VERSION"
full_run() {
  PATH="$F/bin:$PATH" ENV_FILE="$F/env" bash "$F/c/scripts/update.sh" --yes || return 1
  [ "$(git -C "$F/c" rev-parse HEAD)" = "$(git -C "$F/c" rev-parse 'v1.3.0^{commit}')" ] && [ "$(cat "$F/c/VERSION")" = 1.3.0 ] || return 1
  grep -qx FULL_KEY=7 "$F/env" && grep -q '"result":"ok"' "$F/data/updater/history.jsonl" && [ ! -e "$F/data/updater/update.state" ] || return 1
  grep -q "local edit" "$F"/data/backups/local-changes-*/changes.patch
}
t "update.sh --yes: релиз v1.2.0 → v1.3.0 (checkout --detach), правка сохранена, новый ключ .env дописан, файл скрипта заменён на ходу" full_run

echo "update_test: пройдено $PASS, ошибок $FAIL"
[ "$FAIL" -eq 0 ]
