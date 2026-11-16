#!/usr/bin/env bash
# build_images (scripts/lib/common.sh) без настоящего Docker: подставной docker.
# Сбой BuildKit «failed to open writer … locked» → один повтор классическим сборщиком; прочие сбои не повторяются.
set -u
cd "$(dirname "$0")/../.."
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin"; : > "$T/env"
cat > "$T/bin/docker" <<'D'
#!/usr/bin/env bash
echo "call BUILDKIT=${DOCKER_BUILDKIT:-} BAKE=${COMPOSE_BAKE:-}" >> "$CALLS"
case "$MODE" in
  locked) [ "${DOCKER_BUILDKIT:-}" = 0 ] && exit 0; echo "ERROR: failed to open writer: ref moby/1/abc locked for 8.6s: unavailable"; exit 1 ;;
  other)  echo "ERROR: network unreachable"; exit 1 ;;
  *)      exit 0 ;;
esac
D
chmod +x "$T/bin/docker"
export PATH="$T/bin:$PATH" CALLS="$T/calls" ENV_FILE="$T/env" COMPOSE_PROJECT_NAME=bt
# shellcheck source=../../scripts/lib/common.sh
source scripts/lib/common.sh
fails=0
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: ожидалось '$2', получено '$3'"; fails=$((fails+1)); fi; }
run_mode() { : > "$CALLS"; MODE="$1" build_images >/dev/null 2>&1; echo $?; }

check "успешная сборка — один вызов" "0 1" "$(rc=$(run_mode ok); echo "$rc $(wc -l < "$CALLS")")"
check "сбой BuildKit — повтор классическим сборщиком, успех" "0 2" "$(rc=$(run_mode locked); echo "$rc $(wc -l < "$CALLS")")"
check "повтор идёт с DOCKER_BUILDKIT=0 и COMPOSE_BAKE=false" "call BUILDKIT=0 BAKE=false" "$(tail -1 "$CALLS")"
check "прочий сбой — без повтора, код ошибки сохранён" "1 1" "$(rc=$(run_mode other); echo "$rc $(wc -l < "$CALLS")")"
exit $fails
