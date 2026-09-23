#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
cookies=/tmp/tms-webhook-admin-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

login() {
  curl --fail --silent --cookie-jar "$cookies" "$base_url/login" > /tmp/webhook-login.html
  local csrf
  csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/webhook-login.html | head -n1)
  test -n "$csrf"
  local status
  status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
    --cookie "$cookies" --cookie-jar "$cookies" \
    --data-urlencode "_csrf=$csrf" \
    --data-urlencode 'username=ciadmin' \
    --data-urlencode 'password=ci-admin-password-12345' \
    "$base_url/login")
  test "$status" = "302"
}

test "$(db "SELECT COUNT(*) FROM schema_migrations WHERE version='20260923_004_webhooks.sql'")" = "1"
docker compose ps webhook-worker --status running | grep -q webhook-worker

login
curl --fail --silent --cookie "$cookies" "$base_url/admin/webhooks" > /tmp/webhooks-admin.html
grep -q '>Webhooks<' /tmp/webhooks-admin.html
grep -q 'task.created' /tmp/webhooks-admin.html
csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/webhooks-admin.html | head -n1)
test -n "$csrf"

docker compose exec -T app sh -c 'cat > /tmp/tms-webhook-receiver.php' <<'PHP'
<?php
$headers = function_exists('getallheaders') ? getallheaders() : [];
$body = file_get_contents('php://input');
file_put_contents(
    '/var/www/html/var/secrets/webhook-receiver.log',
    json_encode(['headers' => $headers, 'body' => $body], JSON_UNESCAPED_SLASHES) . PHP_EOL,
    FILE_APPEND,
);
http_response_code(204);
PHP
docker compose exec -T app rm -f /var/www/html/var/secrets/webhook-receiver.log
docker compose exec -d app php -S 0.0.0.0:19090 /tmp/tms-webhook-receiver.php
sleep 1

create_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'name=CI task webhook' \
  --data-urlencode 'endpoint_url=http://app:19090/tms' \
  --data-urlencode 'is_active=1' \
  --data-urlencode 'event_types[]=task.created' \
  "$base_url/admin/webhooks")
test "$create_status" = "302"

subscription_id=$(db "SELECT id FROM webhook_subscriptions WHERE name='CI task webhook' ORDER BY id DESC LIMIT 1")
test -n "$subscription_id"

curl --fail --silent --cookie "$cookies" "$base_url/admin/webhooks" > /tmp/webhooks-secret.html
secret=$(sed -n 's/.*<div class="link-command"><code>\([^<]*\)<\/code><\/div>.*/\1/p' /tmp/webhooks-secret.html | head -n1)
test -n "$secret"
curl --fail --silent --cookie "$cookies" "$base_url/admin/webhooks" > /tmp/webhooks-after-secret.html
if grep -Fq "$secret" /tmp/webhooks-after-secret.html; then
  echo 'Webhook signing secret was shown more than once.' >&2
  exit 1
fi

curl --fail --silent --cookie "$cookies" "$base_url/tasks/new" > /tmp/webhook-task-new.html
task_csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/webhook-task-new.html | head -n1)
test -n "$task_csrf"
response=$(curl --fail --silent --header 'Accept: application/json' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  --data-urlencode 'title=Webhook smoke task' \
  "$base_url/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
task_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Webhook smoke task' ORDER BY id DESC LIMIT 1")
event_id=$(db "SELECT event_id FROM domain_events WHERE task_id=$task_id AND event_type='task.created' ORDER BY occurred_at DESC LIMIT 1")
test -n "$task_id"
test -n "$event_id"
test "$(db "SELECT COUNT(*) FROM webhook_deliveries WHERE subscription_id=$subscription_id AND event_id='$event_id'")" = "1"

worker_output=$(docker compose exec -T webhook-worker php /var/www/html/bin/webhooks.php)
printf '%s\n' "$worker_output" | grep -q '^Webhook run:'
test "$(db "SELECT status FROM webhook_deliveries WHERE subscription_id=$subscription_id AND event_id='$event_id'")" = "succeeded"
test "$(db "SELECT attempt_count FROM webhook_deliveries WHERE subscription_id=$subscription_id AND event_id='$event_id'")" = "1"
test "$(db "SELECT response_status FROM webhook_deliveries WHERE subscription_id=$subscription_id AND event_id='$event_id'")" = "204"

docker compose exec -T \
  -e WEBHOOK_SECRET="$secret" \
  -e WEBHOOK_EVENT_ID="$event_id" \
  app php -r '
$lines = file("/var/www/html/var/secrets/webhook-receiver.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$lines) { exit(1); }
$row = json_decode($lines[count($lines)-1], true);
$headers = array_change_key_case($row["headers"] ?? [], CASE_LOWER);
$body = (string)($row["body"] ?? "");
if (($headers["x-tms-event-id"] ?? "") !== getenv("WEBHOOK_EVENT_ID")) { exit(2); }
if (($headers["x-tms-event-type"] ?? "") !== "task.created") { exit(3); }
$timestamp = (string)($headers["x-tms-timestamp"] ?? "");
$expected = "v1=" . hash_hmac("sha256", $timestamp . "." . $body, (string)getenv("WEBHOOK_SECRET"));
if (!hash_equals($expected, (string)($headers["x-tms-signature"] ?? ""))) { exit(4); }
$payload = json_decode($body, true);
if (($payload["id"] ?? "") !== getenv("WEBHOOK_EVENT_ID") || ($payload["type"] ?? "") !== "task.created") { exit(5); }
'

curl --fail --silent --cookie "$cookies" "$base_url/admin/webhooks" > /tmp/webhooks-update.html
update_csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/webhooks-update.html | head -n1)
update_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$update_csrf" \
  --data-urlencode 'name=CI task webhook' \
  --data-urlencode 'endpoint_url=http://127.0.0.1:9/unreachable' \
  --data-urlencode 'is_active=1' \
  --data-urlencode 'event_types[]=task.updated' \
  "$base_url/admin/webhooks/$subscription_id")
test "$update_status" = "302"

status_id=$(db "SELECT status_id FROM tasks WHERE id=$task_id")
response=$(curl --fail --silent --header 'Accept: application/json' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  --data-urlencode "status_id=$status_id" \
  --data-urlencode 'description=Trigger webhook retry' \
  "$base_url/api/tasks/$task_id/quick-edit")
printf '%s' "$response" | grep -q '"success":true'
updated_event_id=$(db "SELECT event_id FROM domain_events WHERE task_id=$task_id AND event_type='task.updated' ORDER BY occurred_at DESC LIMIT 1")
test -n "$updated_event_id"

docker compose exec -T webhook-worker php /var/www/html/bin/webhooks.php >/tmp/webhook-failure-run.txt
test "$(db "SELECT status FROM webhook_deliveries WHERE subscription_id=$subscription_id AND event_id='$updated_event_id'")" = "retry"
test "$(db "SELECT attempt_count FROM webhook_deliveries WHERE subscription_id=$subscription_id AND event_id='$updated_event_id'")" = "1"
test -n "$(db "SELECT last_error FROM webhook_deliveries WHERE subscription_id=$subscription_id AND event_id='$updated_event_id'")"

curl --fail --silent --cookie "$cookies" "$base_url/admin/webhooks" > /tmp/webhooks-history.html
grep -q 'CI task webhook' /tmp/webhooks-history.html
grep -q 'Retry scheduled' /tmp/webhooks-history.html
