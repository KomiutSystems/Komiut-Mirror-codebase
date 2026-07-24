#!/usr/bin/env bash
#
# Runs ON the UAE prod EC2 box (from /opt/komiut, a clone of the mirror repo).
# Generates .env from SSM Parameter Store, builds the image from source, brings
# up Postgres + Redis (data on the /mnt/pgdata EBS volume), migrates + seeds the
# RBAC catalog, rolls the stack, and installs the nightly pg_dump->S3 backup.
# Idempotent — safe to re-run for a redeploy.
set -euo pipefail

cd /opt/komiut
REGION="${AWS_REGION:-eu-central-1}"
BUCKET="komiut-prod-euc1-571727472768"
COMPOSE="docker compose -f docker-compose.prod-self.yml"

echo "=== 1) build .env from SSM /komiut/prod/* + static prod config ==="
: > .env.ssm
aws ssm get-parameters-by-path --region "$REGION" --path /komiut/prod/ --with-decryption \
  --query "Parameters[].[Name,Value]" --output text | while IFS=$'\t' read -r name value; do
    printf '%s=%s\n' "${name##*/}" "$value" >> .env.ssm
done
DB_PASSWORD="$(grep '^DB_PASSWORD=' .env.ssm | cut -d= -f2-)"
export DB_PASSWORD
[ -n "$DB_PASSWORD" ] || { echo "FATAL: /komiut/prod/DB_PASSWORD missing in SSM"; exit 1; }

cat > .env <<ENV
APP_NAME=Komiut
APP_ENV=production
APP_DEBUG=false
APP_URL=${APP_URL:-https://api.komiut.com}
LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=komiut
DB_USERNAME=komiut
DB_PASSWORD=${DB_PASSWORD}

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=s3
AWS_DEFAULT_REGION=${REGION}
AWS_BUCKET=${BUCKET}
ENV

# Append everything else from SSM (APP_KEY, REVERB_*, KOMIUT_APP_KEY, brand cfg…).
# Later duplicates win in Laravel's env parser, so SSM values override the block
# above where a key repeats — keep DB_PASSWORD out of SSM-with-a-different-name.
cat .env.ssm >> .env
rm -f .env.ssm

echo "=== 2) build the app image from source ==="
$COMPOSE build app

echo "=== 3) datastores up (Postgres on /mnt/pgdata, Redis) ==="
mkdir -p /mnt/pgdata/data
$COMPOSE up -d db redis

echo "=== 4) migrate + seed RBAC (permissions + roles) — no demo data in prod ==="
$COMPOSE run --rm worker migrate --force
$COMPOSE run --rm worker db:seed --class=PermissionSeeder --force
$COMPOSE run --rm worker db:seed --class=RoleSeeder --force

echo "=== 5) roll the full stack ==="
$COMPOSE up -d --remove-orphans

echo "=== 6) install nightly pg_dump->S3 backup (02:00) ==="
command -v crond >/dev/null 2>&1 || dnf install -y cronie
systemctl enable --now crond
install -m 0755 Docker/prod-self/backup.sh /usr/local/bin/komiut-backup.sh
printf '0 2 * * * root /usr/local/bin/komiut-backup.sh >> /var/log/komiut-backup.log 2>&1\n' > /etc/cron.d/komiut-backup
chmod 0644 /etc/cron.d/komiut-backup

echo DEPLOY_DONE
