FROM php:8.2-fpm

RUN apt-get update && apt-get install -y libpq-dev git unzip && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_pgsql

WORKDIR /var/www/html
