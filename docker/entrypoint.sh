#!/usr/bin/env bash
# Starts PHP-FPM and nginx, and exits as soon as either one stops so the
# platform (Cloud Run, Docker restart policy) replaces the container.
set -euo pipefail

export PORT="${PORT:-8080}"
sed "s/\${PORT}/${PORT}/g" /etc/nginx/templates/app.conf.template > /etc/nginx/conf.d/app.conf
nginx -t -q

php-fpm --nodaemonize &
fpm=$!

# Don't accept traffic until PHP-FPM is listening, or the first requests
# after a cold start would get 502s.
for _ in $(seq 1 100); do
  (exec 3<>/dev/tcp/127.0.0.1/9000) 2>/dev/null && break
  sleep 0.1
done

nginx -g 'daemon off;' &
web=$!

trap 'kill -TERM "$fpm" "$web" 2>/dev/null || true' TERM INT

set +e
wait -n "$fpm" "$web"
status=$?
kill -TERM "$fpm" "$web" 2>/dev/null
wait
exit "$status"
