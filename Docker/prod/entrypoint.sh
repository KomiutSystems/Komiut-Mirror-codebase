#!/bin/sh
set -e

# Runs on every container start (env is present from SSM/compose). Caches config,
# routes and views for speed. Migrations are NOT run here — a container may be one
# of several replicas; migrations run once as a dedicated deploy step (see the
# GitHub Actions workflow) to avoid races.

php artisan config:cache
php artisan route:cache
php artisan view:cache

# nginx runs from a bare image with no app code, so it can't serve static files
# (the API docs, any public asset) on its own. Publish this container's web root
# to the shared `webroot` volume nginx mounts. Re-synced on every start, so each
# redeploy refreshes the static assets.
if [ -d /webroot ]; then
  rm -rf /webroot/* 2>/dev/null || true
  cp -a /var/www/public/. /webroot/ 2>/dev/null || true
fi

exec "$@"
