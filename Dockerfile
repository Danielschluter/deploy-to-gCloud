# Single-container image (nginx + PHP-FPM) for Cloud Run and local Docker.
# Cloud Run sends traffic to $PORT (default 8080); the entrypoint renders the
# nginx config for it, then supervises both processes.

FROM php:8.3-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx libpq-dev \
 && docker-php-ext-install -j"$(nproc)" opcache pdo_pgsql \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/nginx/sites-enabled/default \
 && ln -sf /dev/stdout /var/log/nginx/access.log \
 && ln -sf /dev/stderr /var/log/nginx/error.log \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/fpm-pool.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/nginx.conf /etc/nginx/templates/app.conf.template
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod 755 /usr/local/bin/entrypoint

WORKDIR /app
COPY src ./src

ENV PORT=8080 \
    FPM_MAX_CHILDREN=8 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD curl -fsS "http://127.0.0.1:${PORT}/healthz" || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
