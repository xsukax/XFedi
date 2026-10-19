#!/bin/sh
# Tests local origin availability and that private files cannot be downloaded.
set -eu
cd "$(dirname "$0")"
[ -f .env ] || { echo 'Missing .env: run ./install.sh first' >&2; exit 1; }
PORT=$(sed -n 's/^LOCAL_PORT=//p' .env | head -n 1)
PORT=${PORT:-8080}
ORIGIN="http://127.0.0.1:$PORT"

pass=0
fail=0
check() {
  label=$1 path=$2 expected=$3
  code=$(curl --silent --max-time 8 --output /dev/null --write-out '%{http_code}' "$ORIGIN$path") || code=connection-error
  if [ "$code" = "$expected" ]; then
    printf 'PASS %-38s HTTP %s\n' "$label" "$code"
    pass=$((pass + 1))
  else
    printf 'FAIL %-38s expected %s, got %s\n' "$label" "$expected" "$code"
    fail=$((fail + 1))
  fi
}

# Automatic origin smoke test; does not require DNS or the Cloudflare tunnel yet.
check 'PHP application (executed, not static)' /index.php 200
check 'Homepage at root' / 200
check 'Legacy PHP URL compatibility' /xsukax_fediverse.php 200
check 'NodeInfo discovery' /.well-known/nodeinfo 200
check 'Federated timeline' /public 200
check 'Local timeline' /public/local 200
check 'Application health' /healthz 200
check 'Stylesheet' /assets/theme.css 200
check 'Browser script' /assets/ui.js 200
check 'XML sitemap' /sitemap.xml 200
check 'Robots and sitemap reference' /robots.txt 200
check 'Private implementation hidden' /app/core.php 404
check 'Background worker hidden' /worker.py 404
check 'Backup implementation hidden' /bin/backup.php 404
check 'PHP source download blocked' /index.php.txt 404
check 'SQLite database hidden' /social.sqlite 404
check 'Private volume path hidden' /var/lib/xsukax-fediverse/social.sqlite 404
check 'Private data alias hidden' /data/social.sqlite 404
check 'SQLite journal hidden' /social.sqlite-wal 404
check 'Docker Compose hidden' /compose.yaml 404
check '.env hidden' /.env 404
check 'Caddyfile hidden' /Caddyfile 404
check 'Dockerfile hidden' /Dockerfile 404
check 'Source file backup hidden' /index.php.bak 404
check 'Other PHP script blocked' /example.php 404
check 'Dot directories hidden' /.git/config 404

body=$(curl --silent --max-time 8 "$ORIGIN/index.php" || true)
case "$body" in
  *'<?php'*|*'PDO::ATTR_ERRMODE'*|*'-----BEGIN PRIVATE KEY-----'*)
    echo 'FAIL PHP source or sensitive data may be exposed!'; fail=$((fail + 1));;
  *) echo 'PASS PHP source not present in response'; pass=$((pass + 1));;
esac
printf '\nCompleted: %s passed; %s failed.\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
