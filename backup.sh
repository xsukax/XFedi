#!/bin/sh
# Consistent database plus all uploaded images, outside the served paths.
set -eu
cd "$(dirname "$0")"
umask 077
mkdir -p backups
OUT="backups/xfedi-$(date -u +%Y%m%d-%H%M%S).tar.gz"
if docker compose exec -T php php /srv/app/bin/backup.php > "$OUT"; then
    chmod 600 "$OUT"
    printf 'Backup created: %s\n' "$OUT"
    echo 'Includes private signing keys. Keep it private and retain a separate copy of .env.'
else
    rm -f "$OUT"
    echo 'Backup failed.' >&2
    exit 1
fi
