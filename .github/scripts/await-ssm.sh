#!/usr/bin/env bash
# Wait for one SSM command invocation and FAIL the job if it failed.
#
# send-command returns as soon as the command is accepted, not when it finishes.
# Without this the deploy reports success the moment SSM takes the request, so a
# migration that blew up or a container that never started still shows green.
#
# Usage: await-ssm.sh <command-id> <instance-id>
set -euo pipefail

CMD="${1:?command id required}"
INSTANCE="${2:?instance id required}"
DEADLINE=$((SECONDS + 900))

while :; do
  STATUS=$(aws ssm get-command-invocation \
             --command-id "$CMD" --instance-id "$INSTANCE" \
             --query Status --output text 2>/dev/null || echo Pending)

  case "$STATUS" in
    Success)
      echo "--- SSM $CMD on $INSTANCE: Success ---"
      aws ssm get-command-invocation --command-id "$CMD" --instance-id "$INSTANCE" \
        --query StandardOutputContent --output text | tail -20
      exit 0
      ;;
    Failed|Cancelled|TimedOut)
      echo "--- SSM $CMD on $INSTANCE: $STATUS ---" >&2
      echo "stdout:" >&2
      aws ssm get-command-invocation --command-id "$CMD" --instance-id "$INSTANCE" \
        --query StandardOutputContent --output text >&2 || true
      echo "stderr:" >&2
      aws ssm get-command-invocation --command-id "$CMD" --instance-id "$INSTANCE" \
        --query StandardErrorContent --output text >&2 || true
      exit 1
      ;;
  esac

  if (( SECONDS > DEADLINE )); then
    echo "Timed out waiting for SSM $CMD on $INSTANCE (last status: $STATUS)" >&2
    exit 1
  fi
  sleep 10
done
