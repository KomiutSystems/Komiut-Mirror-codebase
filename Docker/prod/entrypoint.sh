#!/bin/sh
set -e

# Runs on every container start (env is present from SSM/compose). Caches config,
# routes and views for speed. Migrations are NOT run here — a container may be one
# of several replicas; migrations run once as a dedicated deploy step (see the
# GitHub Actions workflow) to avoid races.

# Firebase service-account keys from SSM, so rotating one is a parameter change
# and a container recreate -- not a JSON file copied onto every host by hand.
# The key that shipped in the storage volume (cce411b4e8) was revoked at Google
# on 2026-09-12 and every push has failed since; the replacement must be able
# to land without a deploy.
#
# Set `<BRAND>_FCM_CREDENTIALS_B64` in SSM to the base64 of the JSON (base64
# because a multi-line value cannot survive the KEY=VALUE .env that
# render-env.sh writes), and `<BRAND>_FCM_CREDENTIALS` to the storage-relative
# path config/services.php reads. Written atomically, mode 600, on every start;
# unset means "leave whatever file is already there".
write_fcm_key() {
  b64="$1"; rel="$2"
  [ -n "$b64" ] || return 0
  [ -n "$rel" ] || { echo "entrypoint: FCM key given but no credentials path -- skipping" >&2; return 0; }
  target="/var/www/storage/app/$rel"
  mkdir -p "$(dirname "$target")"
  if printf '%s' "$b64" | base64 -d > "$target.tmp" 2>/dev/null && grep -q '"private_key"' "$target.tmp"; then
    chmod 600 "$target.tmp" && mv -f "$target.tmp" "$target"
    echo "entrypoint: FCM key written to $rel"
  else
    rm -f "$target.tmp"
    echo "entrypoint: FCM key for $rel is not valid base64 JSON -- left untouched" >&2
  fi
}
write_fcm_key "${KOMIUT_FCM_CREDENTIALS_B64:-}" "${KOMIUT_FCM_CREDENTIALS:-}"
write_fcm_key "${SAFIRI_FCM_CREDENTIALS_B64:-}" "${SAFIRI_FCM_CREDENTIALS:-}"

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
