#!/usr/bin/env bash
#
# Nightly logical backup: pg_dump the running Postgres container, gzip, and push
# to S3 (server-side encrypted). The EC2 instance profile authorizes the upload
# via IMDS, so no keys live on disk. A 30-day S3 lifecycle rule on the backups/
# prefix expires old dumps; EBS snapshots of /mnt/pgdata are the second layer.
#
# Restore:  aws s3 cp s3://<bucket>/backups/<file> - | gunzip | \
#           docker compose -f docker-compose.uae.yml exec -T db psql -U komiut -d komiut
set -euo pipefail

REGION="me-central-1"
BUCKET="komiut-prod-571727472768"
cd /opt/komiut
TS="$(date +%Y%m%d-%H%M%S)"
FILE="/tmp/komiut-${TS}.sql.gz"
KEY="backups/komiut-${TS}.sql.gz"

# Dump from the running db container (plain SQL, gzipped).
docker compose -f docker-compose.uae.yml exec -T db pg_dump -U komiut -d komiut | gzip > "$FILE"

# Upload via the aws-cli container, which inherits the instance-profile creds.
docker run --rm -v /tmp:/tmp amazon/aws-cli \
  s3 cp "$FILE" "s3://${BUCKET}/${KEY}" --region "$REGION" --sse AES256

rm -f "$FILE"
echo "$(date -Iseconds) backup uploaded: s3://${BUCKET}/${KEY}"
