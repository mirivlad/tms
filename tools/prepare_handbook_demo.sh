#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
PROJECT=${HANDBOOK_COMPOSE_PROJECT:-tms-handbook}
PORT=${HANDBOOK_PORT:-18082}
BASE_URL=${HANDBOOK_BASE_URL:-http://127.0.0.1:${PORT}}
PASSWORD=${HANDBOOK_DEMO_PASSWORD:-documentation12}
DB_PASSWORD=${HANDBOOK_DB_PASSWORD:-tmsdocs123}
ENV_FILE=$(mktemp)
trap 'rm -f "$ENV_FILE"' EXIT

cat >"$ENV_FILE" <<EOF
APP_URL=${BASE_URL}
APP_TIMEZONE=Europe/Moscow
APP_LOCALE=en
DB_PASS=${DB_PASSWORD}
SESSION_SECURE=false
TMS_PORT=${PORT}
REGISTRATION_ENABLED=true
REGISTRATION_AUTO_APPROVE_AFTER_EMAIL=false
NOTIFICATION_INTERVAL_SECONDS=3600
WEBHOOK_INTERVAL_SECONDS=3600
TELEGRAM_BOT_NAME=@handbook_tms_bot
EOF
cd "$ROOT"
echo "Resetting isolated Compose project: $PROJECT"
docker compose --env-file "$ENV_FILE" -p "$PROJECT" down -v --remove-orphans >/dev/null 2>&1 || true
docker compose --env-file "$ENV_FILE" -p "$PROJECT" up -d db app

for attempt in $(seq 1 60); do
    if curl --fail --silent "$BASE_URL/health" | grep -q '"status":"ok"'; then
        break
    fi
    if [ "$attempt" -eq 60 ]; then
        docker compose --env-file "$ENV_FILE" -p "$PROJECT" ps
        exit 1
    fi
    sleep 2
done

create_admin() {
    local username=$1 email=$2
    docker compose --env-file "$ENV_FILE" -p "$PROJECT" exec -T \
        -e TMS_ADMIN_PASSWORD="$PASSWORD" app \
        php bin/create-admin.php "$username" "$email"
}
create_admin docsru docsru@example.invalid
create_admin docsen docsen@example.invalid

docker compose --env-file "$ENV_FILE" -p "$PROJECT" exec -T db \
    mariadb -utms -p"$DB_PASSWORD" tms \
    < docs/handbooks/demo/seed.sql

echo
echo "Handbook demo is ready at $BASE_URL"
echo "RU login: docsru"
echo "EN login: docsen"
echo "Password: set by HANDBOOK_DEMO_PASSWORD (default: documentation12)"
echo
echo "Capture screenshots with:"
echo "  python3 tools/capture_handbook_screenshots.py --base-url $BASE_URL"