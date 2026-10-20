FROM php:8.2-fpm

RUN apt-get update && apt-get install -y libpq-dev git unzip && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_pgsql

# PGClient stores its login and connections here (PGCLIENT_CONFIG_DIR); a volume
# mounted here inherits the www-data ownership.
RUN mkdir -p /var/lib/pgclient && chown www-data:www-data /var/lib/pgclient

WORKDIR /var/www/html
