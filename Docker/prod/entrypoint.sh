#!/bin/sh
set -e

# Runs on every container start (env is present from SSM/compose). Caches config,
# routes and views for speed. Migrations are NOT run here — a container may be one
# of several replicas; migrations run once as a dedicated deploy step (see the
# GitHub Actions workflow) to avoid races.

php artisan config:cache
php artisan route:cache

# view:cache ONLY if there are views to cache.
#
# This service is API-only — the Blade dashboard, marketing pages and auth
# scaffolding were removed (see routes/web.php), so `resources/views` does not
# exist in the image. `view:cache` treats that as fatal:
#
#   In Finder.php line 648:
#     The "/var/www/resources/views" directory does not exist.
#
# With `set -e` that killed the entrypoint, so the app container crash-looped and
# nginx served 502 while worker/scheduler/reverb stayed up — they override the
# entrypoint in compose and never ran this script, which is what made the failure
# look like a broken image rather than a broken startup step.
if [ -d /var/www/resources/views ]; then
  php artisan view:cache
else
  echo "entrypoint: no resources/views (API-only build) — skipping view:cache"
fi

# nginx runs from a bare image with no app code, so it can't serve static files
# (the API docs, any public asset) on its own. Publish this container's web root
# to the shared `webroot` volume nginx mounts. Re-synced on every start, so each
# redeploy refreshes the static assets.
#
# This used to be `cp ... 2>/dev/null || true`, which is how it stayed broken so
# long: the volume was created root:root while this container runs as www-data,
# every copy died with EACCES, and the redirect threw the message away. nginx
# served an empty document root and the deploy reported success. Never silence
# this again — if the publish fails the container must fail, because a running
# app with no static assets looks healthy to the load balancer.
if [ -d /webroot ]; then
  if [ ! -w /webroot ]; then
    echo "entrypoint: FATAL /webroot is not writable by $(id -un) — nginx would serve an empty document root" >&2
    ls -ld /webroot >&2
    exit 1
  fi
  rm -rf /webroot/*
  cp -a /var/www/public/. /webroot/
  echo "entrypoint: published $(find /webroot -type f | wc -l) files to the nginx web root"
fi

exec "$@"
