#!/usr/bin/env bash
set -u
ROOT="s:/Coding_Tools/Programs/ODOO HACKATHON/dayflow"
AID="$1"
START="$2"
PRIYA=5f2fc57a-9d26-4279-bbdf-054496fd35ea
VIKRAM=c55da0e9-62e4-4f6d-a1c8-0ba0d6d17700

run() {
  MSYS_NO_PATHCONV=1 docker run --rm --network container:dayflow-rev-pg \
    -v "$ROOT:/app" -v "$ROOT/server/shared:/var/www/shared:ro" \
    -e DB_HOST=127.0.0.1 -e DB_PORT=5432 -e DB_NAME=revdb -e DB_USER=revuser -e DB_PASSWORD=revpass -e DB_SCHEMA=employee \
    -e SERVICE_NAME=employee -e EVENTS_ENABLED=false -e APP_TIMEZONE=Asia/Kolkata -e LOG_PATH=/tmp -e STORAGE_PATH=/tmp/storage \
    -e JWT_SECRET=0123456789abcdef0123456789abcdef -e INTERNAL_SIGNING_KEY=0123456789abcdef0123456789abcdef -e ENCRYPTION_KEY=0123456789abcdef0123456789abcdef \
    --entrypoint php dayflow/php-runtime:8.3 /app/.rev/race.php "$@" 2>&1 | grep -v '^\[22-Aug'
}

run holder "$AID" "$START" "$PRIYA" &
run "${MODE:-challenger}" "$AID" "$((START + 1))" "$VIKRAM" &
wait
