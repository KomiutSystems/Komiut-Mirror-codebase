FROM php:8.4-fpm AS php

RUN apt-get update -y && apt-get install -y \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libpq-dev \
    libcurl4-gnutls-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql pgsql bcmath gd zip pcntl

RUN pecl install redis && docker-php-ext-enable redis

WORKDIR /var/www

COPY --from=composer:2.3.7 /usr/bin/composer /usr/bin/composer
COPY . .
COPY ./Docker/local/.env.pg ./.env

RUN mkdir -p storage/framework/cache storage/framework/views storage/framework/sessions storage/logs bootstrap/cache \
    && composer install --no-interaction --prefer-dist --optimize-autoloader

ENV PORT=8001
