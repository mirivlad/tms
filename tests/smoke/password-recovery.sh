#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
cookies=/tmp/tms-recovery-cookies
login_cookies=/tmp/tms-recovery-login-cookies
new_password='ci-admin-recovered-password-12345'

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$1" | head -n1
}

curl --fail --silent --cookie-jar "$cookies" "$base_url/forgot-password" > /tmp/recovery-forgot.html
csrf=$(csrf_from /tmp/recovery-forgot.html)
test -n "$csrf"
for identifier in ciadmin nobody-does-not-exist; do
  status=$(curl --silent --output "/tmp/recovery-${identifier}.html" --write-out '%{http_code}' \
    --cookie "$cookies" --cookie-jar "$cookies" \
    --data-urlencode "_csrf=$csrf" \
    --data-urlencode "identifier=$identifier" \
    "$base_url/forgot-password")
  test "$status" = "200"
  grep -q 'If the account exists and a recovery channel is available' "/tmp/recovery-${identifier}.html"
  csrf=$(csrf_from "/tmp/recovery-${identifier}.html")
  test -n "$csrf"
done

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
test -n "$admin_id"
test "$(db "SELECT COUNT(*) FROM remember_tokens WHERE user_id=$admin_id")" -ge 1

reset_output=$(docker compose exec -T app php bin/issue-password-reset.php ciadmin)
reset_url=$(printf '%s\n' "$reset_output" | grep '/reset-password?token=' | tail -n1 | tr -d '\r')
test -n "$reset_url"
token=${reset_url#*token=}
test "$token" != "$reset_url"

curl --fail --silent --cookie-jar "$cookies" "$reset_url" > /tmp/recovery-reset.html
grep -Fq 'name="token"' /tmp/recovery-reset.html
grep -Fq "value=\"$token\"" /tmp/recovery-reset.html
reset_csrf=$(csrf_from /tmp/recovery-reset.html)
test -n "$reset_csrf"

reset_status=$(curl --silent --output /tmp/recovery-reset-done.html --write-out '%{http_code}' \
  --cookie "$cookies" --cookie-jar "$cookies" \
  --data-urlencode "_csrf=$reset_csrf" \
  --data-urlencode "token=$token" \
  --data-urlencode "password=$new_password" \
  --data-urlencode "password_confirm=$new_password" \
  "$base_url/reset-password")
test "$reset_status" = "200"
grep -q 'Password changed' /tmp/recovery-reset-done.html

test "$(db "SELECT COUNT(*) FROM remember_tokens WHERE user_id=$admin_id")" = "0"
test "$(db "SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=$admin_id")" = "0"
replay_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" "$reset_url")
test "$replay_status" = "400"

rm -f "$login_cookies"
curl --fail --silent --cookie-jar "$login_cookies" "$base_url/login" > /tmp/recovery-login.html
login_csrf=$(csrf_from /tmp/recovery-login.html)
test -n "$login_csrf"
old_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$login_cookies" --cookie-jar "$login_cookies" \
  --data-urlencode "_csrf=$login_csrf" \
  --data-urlencode 'username=ciadmin' \
  --data-urlencode 'password=ci-admin-password-12345' \
  "$base_url/login")
test "$old_status" = "401"

curl --fail --silent --cookie-jar "$login_cookies" "$base_url/login" > /tmp/recovery-login-new.html
login_csrf=$(csrf_from /tmp/recovery-login-new.html)
new_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$login_cookies" --cookie-jar "$login_cookies" \
  --data-urlencode "_csrf=$login_csrf" \
  --data-urlencode 'username=ciadmin' \
  --data-urlencode "password=$new_password" \
  "$base_url/login")
test "$new_status" = "302"
curl --fail --silent --cookie "$login_cookies" "$base_url/dashboard" | grep -q 'Task overview'
