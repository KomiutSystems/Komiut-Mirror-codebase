# syntax=docker/dockerfile:1
#
# Production image — ONE brand-agnostic app (brand is resolved per request, so
# komiut and 2safiri share this image and one fleet). Build for arm64 (Graviton)
# in CI: `docker buildx build --platform linux/arm64 --target prod`.

# ---- Stage 1: composer dependencies (no dev) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: artisan can't boot yet (no app code); autoload is optimised below.
# --ignore-platform-reqs: this minimal composer image lacks gd/zip/etc.; the
# runtime stage installs them. We only download packages here.
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress --ignore-platform-reqs

# ---- Stage 2: runtime ----
FROM php:8.4-fpm-bookworm AS prod

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libpq-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql pgsql bcmath gd zip pcntl sockets \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove && rm -rf /var/lib/apt/lists/*

# Sane php-fpm production settings.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.memory_consumption=192'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'expose_php=Off' > /usr/local/etc/php/conf.d/hardening.ini

WORKDIR /var/www

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Optimise the autoloader now that all app code is present.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts \
    && rm /usr/bin/composer \
    && mkdir -p storage/framework/{cache,views,sessions} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    # /webroot is where entrypoint.sh publishes public/ for nginx to serve. It
    # MUST exist in the image and be owned by www-data. Docker initialises a new
    # named volume from the image's directory at the mount point, inheriting its
    # ownership — but if the path does not exist in the image, the volume is
    # created root:root instead. This container runs as www-data (below), so the
    # publish then fails with EACCES and nginx serves an empty document root.
    && mkdir -p /webroot \
    && chown www-data:www-data /webroot

COPY Docker/prod/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
