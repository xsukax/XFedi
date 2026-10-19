#!/bin/sh
# Usage: ./restore.sh /absolute/path/backup.tar.gz [--replace]
# Stop services first. Archive is mounted read-only; validation precedes replacement.
set -eu
cd "$(dirname "$0")"
[ "$#" -ge 1 ] || { echo 'Usage: ./restore.sh /absolute/path/backup.tar.gz [--replace]' >&2; exit 2; }
case "$1" in /*) ARCHIVE=$1;; *) echo 'Use an absolute archive path.' >&2; exit 2;; esac
[ -f "$ARCHIVE" ] || { echo 'Backup archive not found.' >&2; exit 1; }
MODE=${2:-}
[ -z "$MODE" ] || [ "$MODE" = --replace ] || { echo 'Only --replace is accepted as the second argument.' >&2; exit 2; }
docker compose stop php worker
if [ "$MODE" = --replace ]; then
    docker compose run --rm --no-deps -T -v "$ARCHIVE:/restore.tar.gz:ro" php python3 /srv/app/bin/restore.py /restore.tar.gz --replace
else
    docker compose run --rm --no-deps -T -v "$ARCHIVE:/restore.tar.gz:ro" php python3 /srv/app/bin/restore.py /restore.tar.gz
fi
echo 'Restore complete. Review .env identity, then run: docker compose up -d'
