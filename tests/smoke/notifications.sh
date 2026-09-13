#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
webhook_secret="${TELEGRAM_WEBHOOK_SECRET:?TELEGRAM_WEBHOOK_SECRET is required for notification smoke}"
admin_cookies=/tmp/tms-notify-admin-cookies
other_cookies=/tmp/tms-notify-other-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

login() {
  local username="$1"
  local password="$2"
  local cookie_file="$3"
  curl --fail --silent --cookie-jar "$cookie_file" "$base_url/login" > /tmp/tms-notify-login.html
  local csrf
  csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/tms-notify-login.html | head -n1)
  test -n "$csrf"
  local status
  status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
    --cookie "$cookie_file" --cookie-jar "$cookie_file" \
    --data-urlencode "_csrf=$csrf" \
    --data-urlencode "username=$username" \
    --data-urlencode "password=$password" \
    "$base_url/login")
  test "$status" = "302"
}

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
test -n "$admin_id"
test -n "$other_id"
test "$(db "SELECT COUNT(*) FROM schema_migrations WHERE version='005_notifications.sql'")" = "1"

login ciadmin ci-admin-password-12345 "$admin_cookies"

curl --fail --silent --cookie "$admin_cookies" "$base_url/settings/notifications" > /tmp/notify-settings.html
grep -q '>Notifications<' /tmp/notify-settings.html
grep -q 'name="notify_upcoming"' /tmp/notify-settings.html
csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/notify-settings.html | head -n1)
test -n "$csrf"

missing_csrf=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$admin_cookies" \
  --data-urlencode 'email_enabled=1' \
  "$base_url/settings/notifications")
test "$missing_csrf" = "403"

save_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$admin_cookies" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'email_enabled=1' \
  --data-urlencode 'email_address=alerts@example.com' \
  --data-urlencode 'notify_tomorrow=1' \
  --data-urlencode 'tomorrow_time=23:59' \
  --data-urlencode 'urgent_minutes=15' \
  --data-urlencode 'high_minutes=60' \
  --data-urlencode 'medium_minutes=240' \
  --data-urlencode 'low_minutes=1440' \
  --data-urlencode 'overdue_time=09:00' \
  --data-urlencode 'digest_time=19:30' \
  "$base_url/settings/notifications")
test "$save_status" = "302"
test "$(db "SELECT email_enabled FROM notification_settings WHERE user_id=$admin_id")" = "1"
test "$(db "SELECT email_address FROM notification_settings WHERE user_id=$admin_id")" = "alerts@example.com"
test "$(db "SELECT notify_tomorrow FROM notification_settings WHERE user_id=$admin_id")" = "1"
test "$(db "SELECT COUNT(*) FROM notification_settings WHERE user_id=$other_id")" = "0"

# Configure SMTP metadata through the admin route but leave the transport disabled,
# so the smoke never attempts an external connection.
curl --fail --silent --cookie "$admin_cookies" "$base_url/admin/notifications" > /tmp/notify-admin.html
admin_csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/notify-admin.html | head -n1)
test -n "$admin_csrf"
smtp_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$admin_cookies" \
  --data-urlencode "_csrf=$admin_csrf" \
  --data-urlencode 'host=smtp.example.com' \
  --data-urlencode 'port=587' \
  --data-urlencode 'encryption=tls' \
  --data-urlencode 'smtp_username=ci-smtp-user' \
  --data-urlencode 'password=ci-smtp-secret' \
  --data-urlencode 'from_email=tms@example.com' \
  --data-urlencode 'from_name=TMS CI' \
  "$base_url/admin/notifications/smtp")
test "$smtp_status" = "302"
ciphertext=$(db "SELECT password_ciphertext FROM smtp_settings WHERE id=1")
test -n "$ciphertext"
test "$ciphertext" != "ci-smtp-secret"
if db "SELECT password_ciphertext FROM smtp_settings WHERE id=1" | grep -q 'ci-smtp-secret'; then
  echo 'SMTP password was stored in plaintext.' >&2
  exit 1
fi
test "$(db "SELECT enabled FROM smtp_settings WHERE id=1")" = "0"

# A Telegram link token is bearer material: only its verifier hash may persist.
link_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$admin_cookies" \
  --data-urlencode "_csrf=$csrf" \
  "$base_url/settings/notifications/telegram-link")
test "$link_status" = "302"
curl --fail --silent --cookie "$admin_cookies" "$base_url/settings/notifications" > /tmp/notify-link.html
command=$(sed -n 's/.*<code>\(\/link_[^<]*\)<\/code>.*/\1/p' /tmp/notify-link.html | head -n1)
test -n "$command"
prefix="/link_${admin_id}_"
payload=${command#"$prefix"}
test "$payload" != "$command"
selector=${payload%%.*}
verifier=${payload#*.}
test ${#selector} -eq 24
test ${#verifier} -eq 64
stored_hash=$(db "SELECT verifier_hash FROM telegram_link_tokens WHERE selector='$selector' AND user_id=$admin_id")
expected_hash=$(printf '%s' "$verifier" | sha256sum | awk '{print $1}')
test "$stored_hash" = "$expected_hash"
test "$stored_hash" != "$verifier"

wrong_secret=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  -H 'Content-Type: application/json' \
  -H 'X-Telegram-Bot-Api-Secret-Token: wrong-secret' \
  --data '{}' "$base_url/telegram/webhook")
test "$wrong_secret" = "403"

valid_empty=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  -H 'Content-Type: application/json' \
  -H "X-Telegram-Bot-Api-Secret-Token: $webhook_secret" \
  --data '{}' "$base_url/telegram/webhook")
test "$valid_empty" = "200"

link_webhook=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  -H 'Content-Type: application/json' \
  -H "X-Telegram-Bot-Api-Secret-Token: $webhook_secret" \
  --data "{\"message\":{\"chat\":{\"id\":123456789,\"username\":\"ciadminbot\"},\"text\":\"$command\"}}" \
  "$base_url/telegram/webhook")
test "$link_webhook" = "200"
test "$(db "SELECT telegram_chat_id FROM notification_settings WHERE user_id=$admin_id")" = "123456789"
test "$(db "SELECT telegram_enabled FROM notification_settings WHERE user_id=$admin_id")" = "1"
test "$(db "SELECT COUNT(*) FROM telegram_link_tokens WHERE selector='$selector' AND consumed_at IS NOT NULL")" = "1"

# New token belonging to admin must not be usable by changing only the user id.
curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$admin_cookies" --data-urlencode "_csrf=$csrf" \
  "$base_url/settings/notifications/telegram-link" | grep -q '^302$'
curl --fail --silent --cookie "$admin_cookies" "$base_url/settings/notifications" > /tmp/notify-link-cross.html
command2=$(sed -n 's/.*<code>\(\/link_[^<]*\)<\/code>.*/\1/p' /tmp/notify-link-cross.html | head -n1)
test -n "$command2"
payload2=${command2#"$prefix"}
cross_command="/link_${other_id}_${payload2}"
cross_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  -H 'Content-Type: application/json' \
  -H "X-Telegram-Bot-Api-Secret-Token: $webhook_secret" \
  --data "{\"message\":{\"chat\":{\"id\":987654321,\"username\":\"foreign\"},\"text\":\"$cross_command\"}}" \
  "$base_url/telegram/webhook")
test "$cross_status" = "200"
test "$(db "SELECT COUNT(*) FROM notification_settings WHERE user_id=$other_id AND telegram_chat_id IS NOT NULL")" = "0"

# The ordinary user must not reach deployment-wide transport administration.
other_hash=$(docker compose exec -T app php -r 'echo password_hash("ci-other-password-12345", PASSWORD_DEFAULT);')
test -n "$other_hash"
db "UPDATE users SET password_hash='$other_hash' WHERE id=$other_id"
login other ci-other-password-12345 "$other_cookies"
forbidden_admin=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$other_cookies" "$base_url/admin/notifications")
test "$forbidden_admin" = "403"

# The notifier is a separate process, starts only after the application is healthy,
# and can be invoked repeatedly without performing migrations itself.
docker compose ps notifier --status running | grep -q notifier
runner_output=$(docker compose exec -T notifier php /var/www/html/bin/notify.php)
printf '%s\n' "$runner_output" | grep -q '^Notification run:'
