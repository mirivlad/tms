#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
cookies=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$1" | head -n1
}

for path in tasks board calendar; do
  curl --fail --silent --cookie "$cookies" "$base_url/$path" > "/tmp/tms-nav-$path.html"
  grep -q 'class="nav-create-task"' "/tmp/tms-nav-$path.html"
  grep -q 'data-quick-add-trigger' "/tmp/tms-nav-$path.html"
  grep -q 'class="nav-dropdown nav-settings-menu"' "/tmp/tms-nav-$path.html"
  grep -q 'class="nav-dropdown user-menu"' "/tmp/tms-nav-$path.html"
  grep -q 'href="/settings/profile"' "/tmp/tms-nav-$path.html"
  grep -q 'href="/metadata"' "/tmp/tms-nav-$path.html"
  grep -q 'href="/custom-fields"' "/tmp/tms-nav-$path.html"
  grep -q 'href="/settings/notifications"' "/tmp/tms-nav-$path.html"
  grep -q 'href="/admin"' "/tmp/tms-nav-$path.html"
  grep -q 'dropdown-locale-switch' "/tmp/tms-nav-$path.html"
  grep -q '/assets/action-icons.js' "/tmp/tms-nav-$path.html"
done

curl --fail --silent "$base_url/assets/navigation.css" > /tmp/tms-navigation.css
grep -Fq '.compact-page-header > a[href="/tasks/new"]' /tmp/tms-navigation.css
curl --fail --silent "$base_url/assets/action-icons.js" > /tmp/tms-action-icons.js
grep -q 'aria-label' /tmp/tms-action-icons.js
grep -q 'row-actions' /tmp/tms-action-icons.js

curl --fail --silent --cookie "$cookies" "$base_url/settings/profile" > /tmp/tms-profile-autofill.html
profile_csrf=$(csrf_from /tmp/tms-profile-autofill.html)
test -n "$profile_csrf"
grep -q 'name="current_password" autocomplete="off"' /tmp/tms-profile-autofill.html

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
test -n "$admin_id"
before_hash=$(db "SELECT password_hash FROM users WHERE id=$admin_id")
test -n "$before_hash"

profile_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" --cookie-jar "$cookies" \
  --data-urlencode "_csrf=$profile_csrf" \
  --data-urlencode 'username=ciadmin' \
  --data-urlencode 'timezone=UTC' \
  --data-urlencode 'theme=paper' \
  --data-urlencode 'current_password=ci-admin-password-12345' \
  "$base_url/settings/profile")
test "$profile_status" = "302"

after_hash=$(db "SELECT password_hash FROM users WHERE id=$admin_id")
test "$after_hash" = "$before_hash"
test "$(db "SELECT CONCAT(timezone,'|',theme) FROM user_preferences WHERE user_id=$admin_id")" = "UTC|paper"

curl --fail --silent --cookie "$cookies" "$base_url/settings/profile" > /tmp/tms-profile-autofill-result.html
grep -q 'data-theme="paper"' /tmp/tms-profile-autofill-result.html
if grep -q 'Fill in the current password, new password and confirmation' /tmp/tms-profile-autofill-result.html; then
  echo 'Current-password autofill incorrectly triggered password-change validation.' >&2
  exit 1
fi
