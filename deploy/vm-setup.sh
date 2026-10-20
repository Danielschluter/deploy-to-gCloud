#!/usr/bin/env bash
# Runs on the VM as root (called by gcp-deploy.sh). Installs Docker if missing,
# creates .env on first run, (re)starts the stack and writes PGClient's login.
set -euo pipefail
cd "$(dirname "$0")/.."

if ! command -v docker >/dev/null 2>&1; then
  echo "==> Installing Docker"
  # Wait for apt locks held by first-boot package updates instead of failing.
  echo 'DPkg::Lock::Timeout "300";' > /etc/apt/apt.conf.d/99lock-timeout
  curl -fsSL https://get.docker.com | sh
fi

if [ ! -f .env ]; then
  echo "==> Generating .env with random credentials"
  (
    umask 077
    cat > .env <<EOF
POSTGRES_DB=appdb
POSTGRES_USER=app
POSTGRES_PASSWORD=$(openssl rand -hex 24)
ADMIN_USER=admin
ADMIN_PASSWORD=$(openssl rand -hex 12)
EOF
  )
fi

if ! grep -q '^ADMIN_PASSWORD=' .env; then
  # .env from a deploy that used nginx basic auth: keep that login for PGClient.
  . ./.env
  printf 'ADMIN_USER=%s\nADMIN_PASSWORD=%s\n' \
    "${BASIC_AUTH_USER:-admin}" "${BASIC_AUTH_PASSWORD:-$(openssl rand -hex 12)}" >> .env
  sed -i '/^BASIC_AUTH_/d' .env
fi
rm -f nginx/.htpasswd

set -a
. ./.env
set +a

compose() { docker compose -f docker-compose.prod.yml "$@"; }

echo "==> Starting containers"
compose up -d --build --remove-orphans
# Restart so the containers pick up the freshly uploaded files and config.
compose restart php nginx

echo "==> Writing PGClient login"
compose exec -T -u www-data -e ADMIN_USER="$ADMIN_USER" -e ADMIN_PASSWORD="$ADMIN_PASSWORD" \
  php php < deploy/pgclient-provision.php
compose ps

echo
echo "PGClient login: $ADMIN_USER / $ADMIN_PASSWORD  (stored in $(pwd)/.env)"
