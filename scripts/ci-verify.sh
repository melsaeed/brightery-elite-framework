#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php -r 'foreach(["pdo_mysql","fileinfo","sodium","gd"] as $e){if(!extension_loaded($e)){fwrite(STDERR,"Missing required CI extension: $e\n");exit(1);}}'
if [[ "${TEST_DB_DATABASE:-}" != brightery_test_* ]]; then echo "Set TEST_DB_DATABASE to an isolated test schema" >&2; exit 2; fi
if [[ "${TEST_RACE_DATABASE:-}" != brightery_test_* || "$TEST_RACE_DATABASE" == "$TEST_DB_DATABASE" ]]; then echo "Set a DIFFERENT empty TEST_RACE_DATABASE" >&2; exit 2; fi
find core modules app bootstrap bin public tests -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
php -l bin/console >/dev/null
php -l bin/benchmark >/dev/null
php -l bin/build-assets >/dev/null
php tests/run.php
php tests/security.php
php tests/production.php
node --check public/assets/js/form-studio.js
node --check public/assets/js/module-builder.js
node --check public/assets/js/crud-controls.js
node --check public/assets/js/module-evolve.js
php bin/console compat:check
php bin/console module:validate
TEST_DB_DATABASE="$TEST_DB_DATABASE" php tests/integration_mysql.php
TEST_DB_DATABASE="$TEST_RACE_DATABASE" php tests/concurrency_mysql.php
