#!/usr/bin/env bash
# Lives on the EC2 host at /opt/komiut/deploy.sh. Invoked by the GitHub Actions
# deploy job (via SSM) with IMAGE=<ecr image tag> in the environment.
#
# Pulls the new image, runs migrations ONCE, then rolls the services with zero
# leftover containers. Assumes /opt/komiut holds this repo's compose files +
# the .env rendered from SSM Parameter Store.
set -euo pipefail

cd /opt/komiut

if [ -z "${IMAGE:-}" ]; then
  echo "IMAGE not set" >&2
  exit 1
fi

echo "==> Logging in to ECR"
aws ecr get-login-password --region "${AWS_REGION:-eu-west-1}" \
  | docker login --username AWS --password-stdin "${IMAGE%%/*}"

echo "==> Pulling ${IMAGE}"
IMAGE="$IMAGE" docker compose -f docker-compose.prod.yml pull

echo "==> Running migrations (once)"
IMAGE="$IMAGE" docker compose -f docker-compose.prod.yml run --rm \
  --entrypoint "php artisan migrate --force" app

echo "==> Rolling services"
IMAGE="$IMAGE" docker compose -f docker-compose.prod.yml up -d --remove-orphans

echo "==> Pruning old images"
docker image prune -f

echo "==> Done: ${IMAGE}"
