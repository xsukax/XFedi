#!/bin/sh
# Safe one-command install for a Cloudflare Tunnel already running on the host.
# Usage: ./install.sh social.example.com [local_port]
set -eu
cd "$(dirname "$0")"
umask 077

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    echo 'Docker Engine/Desktop with Compose v2 is required. Install Docker, then retry.' >&2
    exit 1
fi
if ! docker info >/dev/null 2>&1; then
    echo 'Cannot access the Docker daemon. Start Docker Desktop/Engine first.' >&2
    exit 1
fi

DOMAIN=${1:-}
LOCAL_PORT=${2:-80}
if [ -z "$DOMAIN" ]; then
    printf 'Public permanent Cloudflare Tunnel hostname (e.g. social.example.com): '
    IFS= read -r DOMAIN
fi
DOMAIN=$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]')
case "$DOMAIN" in
    ''|*[!a-z0-9.-]*) echo 'Invalid public hostname characters.' >&2; exit 1 ;;
esac

if ! printf '%s\n' "$DOMAIN" | awk -F. '
  length($0)>253 || NF<2 { exit 1 }
  {
    for (i=1;i<=NF;i++) {
      if (length($i)>63 || $i !~ /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/) exit 1
    }
    if ($NF !~ /^[a-z][a-z-]*[a-z]$/ && $NF !~ /^[a-z][a-z]$/ && $NF !~ /^xn--[a-z0-9-]+$/) exit 1
  }
' ; then
    echo 'Invalid hostname. Enter only the public DNS name (no https://, path, or port).' >&2
    exit 1
fi
case "$LOCAL_PORT" in
    ''|*[!0-9]*) echo 'Local port must be numeric.' >&2; exit 1 ;;
esac
if [ "${#LOCAL_PORT}" -gt 5 ] || [ "$LOCAL_PORT" -lt 1 ] || [ "$LOCAL_PORT" -gt 65535 ]; then
    echo 'Choose a local port from 1 to 65535.' >&2
    exit 1
fi

if [ -f .env ]; then
    old_domain=$(sed -n 's/^DOMAIN=//p' .env | head -n 1)
    configured_port=$(sed -n 's/^LOCAL_PORT=//p' .env | head -n 1)
    if [ "$old_domain" != "$DOMAIN" ]; then
        echo "Refusing identity change: existing DOMAIN=$old_domain (ActivityPub actor IDs are permanent)." >&2
        exit 1
    fi
    if [ -n "$configured_port" ] && [ "$configured_port" != "$LOCAL_PORT" ]; then
        printf 'Using existing port %s from .env. Edit .env and restart to change it.\n' "$configured_port"
    fi
    if ! grep -q '^FEDERATION_PATH=' .env; then
        echo 'Preserving original federated actor URL /xsukax_fediverse.php for this existing installation.'
    fi
    echo 'Using existing .env configuration.'
else
    printf 'DOMAIN=%s\nLOCAL_PORT=%s\nFEDERATION_PATH=/index.php\n' "$DOMAIN" "$LOCAL_PORT" > .env
    chmod 600 .env
    echo 'Created private .env configuration.'
fi
LOCAL_PORT=$(sed -n 's/^LOCAL_PORT=//p' .env | head -n 1)
if [ -z "$LOCAL_PORT" ]; then LOCAL_PORT=8080; fi

if ! grep -q '^XFEDI_SETUP_TOKEN=' .env; then
    token=$(docker run --rm --network none --entrypoint php php:8.3-fpm-alpine -r 'echo bin2hex(random_bytes(32));')
    printf '\nXFEDI_SETUP_TOKEN=%s\n' "$token" >> .env
    chmod 600 .env
fi

printf '\nValidating and building Docker Compose...\n'
docker compose config --quiet
docker compose up --detach --build --remove-orphans
printf '\nInstallation launched.\n'
printf 'Cloudflare Tunnel published hostname: https://%s\n' "$DOMAIN"
if [ "$LOCAL_PORT" = 80 ]; then
    printf 'Configure its HTTP origin service as: http://localhost\n'
else
    printf 'Configure its HTTP origin service as: http://localhost:%s\n' "$LOCAL_PORT"
fi
printf 'Open the app over public HTTPS: https://%s/index.php\n' "$DOMAIN"
printf '\nCheck service status: docker compose ps\n'
printf 'Test local web isolation: ./verify.sh\n'
printf 'See logs:            docker compose logs -f web php worker\n'
printf 'Database backup:     ./backup.sh\n'
printf '\nFirst-run setup token: read XFEDI_SETUP_TOKEN in the private .env file.\n'
printf '\nThe Cloudflare Tunnel route must be configured separately in Cloudflare.\n'
