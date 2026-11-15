#!/usr/bin/env bash
# Запуск тестового набора локально. На большинстве Linux-дистрибутивов ext-ldap
# и pdo_sqlite уже включены в php.ini по умолчанию (эти флаги ничего не
# ломают, если расширения уже загружены штатно). На Windows-сборках PHP,
# где сборки .dll присутствуют физически, но не включены в php.ini,
# эти -d флаги включают их только для этого процесса, не трогая php.ini.
set -euo pipefail
cd "$(dirname "$0")/.."

PHPUNIT_BIN="${PHPUNIT_BIN:-vendor/bin/phpunit}"
if [ ! -f "$PHPUNIT_BIN" ]; then
    PHPUNIT_BIN="/tmp/phpunit.phar"
fi

php -d extension=pdo_sqlite -d extension=ldap "$PHPUNIT_BIN" --configuration phpunit.xml "$@"
