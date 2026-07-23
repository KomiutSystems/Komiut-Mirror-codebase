#!/usr/bin/env bash
#
# Runs ON the staging EC2 box, invoked by the deploy workflow via SSM with
# IMAGE set to the freshly-built ECR tag. Pulls that image, migrates once, and
# rolls the stack. Config (this script, compose, nginx) is already refreshed by
# the `git reset --hard origin/staging` the workflow runs before calling us.
set -euo pipefail

cd /opt/komiut
: "${IMAGE:?IMAGE env var required}"
REGION="${AWS_REGION:-eu-west-1}"
REGISTRY="${IMAGE%%/*}"
export IMAGE
COMPOSE="docker compose -f docker-compose.staging.yml"

# Ensure the realtime env exists on the box (staging placeholders; prod pulls
# these from SSM instead). Idempotent — only appends what's missing.
ensure_env() { grep -q "^$1=" .env 2>/dev/null || echo "$1=$2" >> .env; }
ensure_env BROADCAST_DRIVER reverb
ensure_env REVERB_APP_ID komiut-staging
ensure_env REVERB_APP_KEY komiut-staging-key
ensure_env REVERB_APP_SECRET komiut-staging-secret
ensure_env REVERB_HOST 52.213.203.84
ensure_env REVERB_PORT 80
ensure_env REVERB_SCHEME http
ensure_env REVERB_SERVER_HOST 0.0.0.0
ensure_env REVERB_SERVER_PORT 8080

echo "=== ECR login ($REGISTRY) ==="
aws ecr get-login-password --region "$REGION" | docker login --username AWS --password-stdin "$REGISTRY"

echo "=== pull image $IMAGE ==="
$COMPOSE pull app worker scheduler reverb

echo "=== ensure infra is up ==="
$COMPOSE up -d db redis

echo "=== migrate (one-off) ==="
$COMPOSE run --rm worker migrate --force

echo "=== roll services ==="
$COMPOSE up -d --remove-orphans

echo "=== prune dangling images ==="
docker image prune -f

echo DEPLOY_DONE
