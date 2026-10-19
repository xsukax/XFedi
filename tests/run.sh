#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
find app bin tests -name '*.php' -exec php -l {} \;
php -l index.php
python3 -m py_compile worker.py bin/restore.py tests/integration.py
for script in install.sh upgrade.sh backup.sh restore.sh verify.sh tests/run.sh; do sh -n "$script"; done
php tests/core.php
php tests/social.php
python3 tests/integration.py
python3 tests/operations.py
