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
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql pgsql bcmath gd zip

# Redis client extension (phpredis)
RUN pecl install redis && docker-php-ext-enable redis

# Set working directory
WORKDIR /var/www

# Copy Composer
COPY --from=composer:2.3.7 /usr/bin/composer /usr/bin/composer

# Copy app source code
COPY ./  .
COPY ./Docker/2safiri/.env.2safiri  ./.env

# Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Make entrypoint executable
RUN chmod +x ./Docker/2safiri/entrypoint.sh

ENV PORT 8000

ENTRYPOINT ["./Docker/2safiri/entrypoint.sh"]
