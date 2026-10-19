#!/bin/sh
# Run from the SAME installation directory and Compose project used by v1.2.
set -eu
cd "$(dirname "$0")"
[ -f .env ] || { echo 'Missing existing .env. Use install.sh for a fresh installation.' >&2; exit 1; }
docker compose config --quiet
# Build before downtime. Keep all volume names and the old federation URL.
docker compose build php
docker compose stop worker
# The old image may not contain bin/backup.php; run the new image against the SAME volume.
# Stop web writes for the upgrade snapshot and migration.
docker compose stop php
umask 077
mkdir -p backups
OUT="backups/pre-xfedi-$(date -u +%Y%m%d-%H%M%S).tar.gz"
if ! docker compose run --rm --no-deps -T php php /srv/app/bin/backup.php > "$OUT"; then
    rm -f "$OUT"
    echo 'Backup failed; services remain stopped. Original data has not been migrated.' >&2
    exit 1
fi
docker compose run --rm --no-deps -T php php /srv/app/index.php migrate
docker compose up -d --remove-orphans
./verify.sh
printf 'Upgrade complete. Snapshot: %s\n' "$OUT"
