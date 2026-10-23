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
# Pinned to the patch release, not the floating 8.4 tag: 8.4.27 fixes
# CVE-2026-109618 and CVE-2026-109619 (unauthenticated RCE, 21 Oct 2026), and a
# floating tag can quietly rebuild on a cached older image. Bump deliberately.
FROM php:8.4.27-fpm-bookworm AS prod

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
    && { \
      echo 'expose_php=Off'; \
      # Unused here, and the confirmed FPM path of CVE-2026-109619; php.net
      # advises keeping it off after patching when nothing needs it.
      echo 'session.upload_progress.enabled=0'; \
      # Form-encoded input only (JSON bodies are not affected); limits how much
      # one request can make PHP build while parsing it (CVE-2026-109618).
      echo 'max_input_nesting_level=32'; \
    } > /usr/local/etc/php/conf.d/hardening.ini \
    # Recycle each php-fpm worker after 500 requests, so state left behind by
    # one request cannot live on in that worker indefinitely (CVE-2026-109619
    # hardening). Loaded after the image's own www.conf, so it overrides it.
    #
    # request_terminate_timeout: nginx gives up on a request at 60s, but php-fpm
    # never did (0 = no limit), so a stuck request held one of the 5 workers
    # forever. That matters more with DB_PERSISTENT: after an RDS failover a kept
    # connection can point at a black-holed address, and a query on it can hang
    # until TCP gives up (~15 min). Kill the worker at 75s instead -- and keep
    # counting after the response is sent (track_finished): the request log and
    # last-active writes run then, in terminate(), and would otherwise escape it.
    #
    # pm.max_children 8, not the image's 5: on 2026-10-23 slow crew-app counts
    # held 4 of 5 workers and everything else queued behind them. The whole
    # pool used ~130 MB of a host's ~1 GB free; 8 workers x 2 hosts is also 16
    # kept DB connections of RDS's 181.
    && printf '[www]\npm.max_children = 8\npm.max_spare_servers = 4\npm.max_requests = 500\nrequest_terminate_timeout = 75s\nrequest_terminate_timeout_track_finished = yes\n' > /usr/local/etc/php-fpm.d/zz-komiut.conf \
    # A pool of its own for the ALB health probe, GET /api/up (nginx sends it
    # to :9001). On 2026-10-23 the probe queued behind those same slow requests
    # in the one shared pool, timed out, and the ASG replaced BOTH servers --
    # busy, not dead -- into new ones facing the same load. Two workers that
    # only ever run the probe answer as long as PHP itself is alive; a dead
    # php-fpm still fails it.
    && printf '[health]\nlisten = 9001\npm = static\npm.max_children = 2\npm.max_requests = 500\nrequest_terminate_timeout = 10s\nrequest_terminate_timeout_track_finished = yes\nclear_env = no\ncatch_workers_output = yes\ndecorate_workers_output = no\n' > /usr/local/etc/php-fpm.d/zz-komiut-health.conf

# OPcache for the worker and scheduler (Docker/prod/cli-entrypoint.sh adds this
# directory to PHP_INI_SCAN_DIR; php-fpm and ad-hoc `artisan` never read it).
# The scheduler starts a fresh PHP process for every task, every minute, and
# each one compiled the framework from source. The file cache keeps compiled
# scripts on disk between those processes, in the container's own /tmp, so a
# new image always starts empty. Timestamps are validated here, unlike php-fpm:
# a file cache outlives any one process, and a cached file rewritten in place
# (config:cache run by hand) must not be served stale to the next command.
RUN mkdir -p /usr/local/etc/php/conf.d-cli /tmp/opcache \
    && chown www-data:www-data /tmp/opcache \
    && { \
      echo 'opcache.enable_cli=1'; \
      echo 'opcache.file_cache=/tmp/opcache'; \
      echo 'opcache.validate_timestamps=1'; \
    } > /usr/local/etc/php/conf.d-cli/opcache-cli.ini

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
COPY Docker/prod/cli-entrypoint.sh /usr/local/bin/cli-entrypoint
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/cli-entrypoint

USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
