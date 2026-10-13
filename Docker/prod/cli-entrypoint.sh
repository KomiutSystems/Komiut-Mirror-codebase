#!/bin/sh
# Entrypoint for the queue worker and the scheduler: `cli-entrypoint queue:work ...`.
#
# They skip entrypoint.sh (that one publishes the web root and runs php-fpm), and
# with it the config and route caches -- so every scheduled task, a new PHP
# process each time, re-read the .env and every config and route file. Cache
# them here too, and turn on the CLI OPcache (see the Dockerfile).
#
# A failed cache is reported and the process starts uncached: a worker that runs
# slower beats a worker that is not running, and the app container's own
# entrypoint fails the deploy loudly on the same error anyway.

export PHP_INI_SCAN_DIR="/usr/local/etc/php/conf.d:/usr/local/etc/php/conf.d-cli"

php artisan config:cache >/dev/null || echo "cli-entrypoint: config:cache failed -- starting without it" >&2
php artisan route:cache >/dev/null || echo "cli-entrypoint: route:cache failed -- starting without it" >&2

exec php artisan "$@"
