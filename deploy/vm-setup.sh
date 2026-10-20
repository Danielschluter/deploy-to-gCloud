#!/usr/bin/env bash
# Runs on the VM as root (called by gcp-deploy.sh). Installs Docker if missing,
# creates .env and the basic-auth file on first run, then (re)starts the stack.
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
BASIC_AUTH_USER=admin
BASIC_AUTH_PASSWORD=$(openssl rand -hex 12)
EOF
  )
fi

set -a
. ./.env
set +a

# Rebuilt every run so editing BASIC_AUTH_* in .env and redeploying takes effect.
printf '%s:%s\n' "$BASIC_AUTH_USER" "$(openssl passwd -apr1 "$BASIC_AUTH_PASSWORD")" > nginx/.htpasswd
chmod 644 nginx/.htpasswd

compose() { docker compose -f docker-compose.prod.yml "$@"; }

echo "==> Starting containers"
compose up -d --build --remove-orphans
# Restart so the containers pick up the freshly uploaded files and config.
compose restart php nginx
compose ps

echo
echo "Login: $BASIC_AUTH_USER / $BASIC_AUTH_PASSWORD  (stored in $(pwd)/.env)"
