#!/bin/sh
set -e

# Runs on every container start (env is present from SSM/compose). Caches config,
# routes and views for speed. Migrations are NOT run here — a container may be one
# of several replicas; migrations run once as a dedicated deploy step (see the
# GitHub Actions workflow) to avoid races.

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
