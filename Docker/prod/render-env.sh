#!/bin/bash
# Renders /opt/komiut/.env from SSM Parameter Store.
#
# Every key under /komiut/prod/ becomes one env var. DB_HOST and REDIS_HOST there
# point at RDS and ElastiCache, so an app node holds no state of its own — which
# is what makes running several of them behind the ALB safe.
#
# This exists as a file, rather than inline in the launch template's user-data,
# because BOTH paths need it and they used to disagree:
#
#   - user-data ran it once at instance boot;
#   - the deploy pipeline never ran it at all.
#
# So a parameter added or changed in SSM only reached production when an instance
# happened to be replaced. REVERB_SCALING_ENABLED is the example that bit us: set
# it in SSM and the running fleet carried on without it indefinitely. The deploy
# now calls this on every roll, so config changes ship like code changes.
#
# Safe to re-run. Writes atomically, and refuses to leave a half-written .env in
# place if SSM returns nothing.
set -euo pipefail

REGION="${1:-eu-central-1}"
TARGET="${2:-/opt/komiut/.env}"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

aws ssm get-parameters-by-path --region "$REGION" --path /komiut/prod/ --with-decryption \
  --query "Parameters[].[Name,Value]" --output text | while IFS=$'\t' read -r name value; do
    printf '%s=%s\n' "${name##*/}" "$value" >> "$TMP"
done

# A truncated or empty render is worse than a stale one: the app would boot with
# no APP_KEY and no database and then fail in a way that looks like a bad image.
grep -q '^APP_KEY=' "$TMP" || { echo "FATAL: APP_KEY missing from SSM — leaving $TARGET untouched"; exit 1; }
grep -q '^DB_HOST=' "$TMP" || { echo "FATAL: DB_HOST missing from SSM — leaving $TARGET untouched"; exit 1; }

install -m 600 "$TMP" "$TARGET"
echo "rendered $TARGET ($(wc -l < "$TARGET") keys)"
