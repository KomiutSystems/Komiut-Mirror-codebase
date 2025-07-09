FROM php:8.2-fpm as php


RUN apt-get update -y && apt-get install -y \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libpq-dev \
    libcurl4-gnutls-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql bcmath gd zip

# Set working directory
WORKDIR /var/www

# Copy Composer
COPY --from=composer:2.3.7 /usr/bin/composer /usr/bin/composer

# Copy app source code
COPY . .

# Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Make entrypoint executable
RUN chmod +x ./Docker/entrypoint.sh

ENV PORT 8000

ENTRYPOINT ["./Docker/entrypoint.sh"]
