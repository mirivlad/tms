#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
cookies=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$cookies" "$base_url/tasks/new" > /tmp/task-form-parity.html
grep -q 'data-rich-editor' /tmp/task-form-parity.html
grep -q '/assets/task-form.css' /tmp/task-form-parity.html
grep -q '/assets/task-form.js' /tmp/task-form-parity.html
grep -q 'data-customer-autocomplete' /tmp/task-form-parity.html
csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/task-form-parity.html | head -n1)
default_status=$(sed -n 's/.*<option value="\([0-9][0-9]*\)" selected>.*/\1/p' /tmp/task-form-parity.html | head -n1)
test -n "$csrf"
test -n "$default_status"

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
test -n "$admin_id"
test -n "$other_id"

db "INSERT INTO customers (user_id,name) VALUES ($admin_id,'Shared Lookup Own') ON DUPLICATE KEY UPDATE name=VALUES(name)"
db "INSERT INTO customers (user_id,name) VALUES ($other_id,'Shared Lookup Foreign') ON DUPLICATE KEY UPDATE name=VALUES(name)"

curl --fail --silent --cookie "$cookies" \
  "$base_url/api/customers/search?q=Shared%20Lookup" > /tmp/customer-search.json
grep -q 'Shared Lookup Own' /tmp/customer-search.json
if grep -q 'Shared Lookup Foreign' /tmp/customer-search.json; then
  echo "Customer autocomplete leaked another user's customer." >&2
  exit 1
fi

test "$(curl --fail --silent --cookie "$cookies" "$base_url/api/customers/search?q=x")" = "[]"

malicious='<p>Hello <strong>world</strong><script>alert(1)</script><img src=x onerror="alert(2)"><a href="javascript:alert(3)">bad</a><a href="https://example.com/path">safe</a></p>'
create_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'title=CI rich task' \
  --data-urlencode "description=$malicious" \
  --data-urlencode "status_id=$default_status" \
  --data-urlencode 'priority=medium' \
  --data-urlencode 'customer=Shared Lookup Own' \
  "$base_url/tasks")
test "$create_status" = "302"

rich_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='CI rich task' LIMIT 1")
test -n "$rich_id"
rich_description=$(db "SELECT description FROM tasks WHERE id=$rich_id")
printf '%s' "$rich_description" | grep -q '<strong>world</strong>'
printf '%s' "$rich_description" | grep -q 'href="https://example.com/path"'
for forbidden in '<script' '<img' 'onerror' 'javascript:'; do
  if printf '%s' "$rich_description" | grep -qi "$forbidden"; then
    echo "Unsafe rich-text fragment survived sanitization: $forbidden" >&2
    exit 1
  fi
done

curl --fail --silent --cookie "$cookies" "$base_url/tasks/$rich_id/edit" > /tmp/rich-edit.html
if grep -q '<script>alert(1)</script>' /tmp/rich-edit.html; then
  echo "Stored task description became executable on the edit page." >&2
  exit 1
fi
grep -q '&lt;strong&gt;world&lt;/strong&gt;' /tmp/rich-edit.html

update_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'title=CI rich task updated' \
  --data-urlencode '<description=<p onclick="alert(9)">Edited <em>safe</em></p>' \
  --data-urlencode "status_id=$default_status" \
  --data-urlencode 'priority=high' \
  --data-urlencode 'customer=Shared Lookup Own' \
  "$base_url/tasks/$rich_id")
test "$update_status" = "302"
updated_description=$(db "SELECT description FROM tasks WHERE id=$rich_id")
printf '%s' "$updated_description" | grep -q '<em>safe</em>'
if printf '%s' "$updated_description" | grep -qi 'onclick'; then
  echo "Event handler survived task update sanitization." >&2
  exit 1
fi

curl --fail --silent --cookie "$cookies" "$base_url/dashboard" > /tmp/quick-dashboard.html
grep -q 'action="/tasks/quick-add"' /tmp/quick-dashboard.html
quick_csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/quick-dashboard.html | head -n1)
test -n "$quick_csrf"

quick_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$quick_csrf" \
  --data-urlencode 'title=CI quick task' \
  --data-urlencode 'description=Quick <script>alert(7)</script> note' \
  "$base_url/tasks/quick-add")
test "$quick_status" = "302"
quick_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='CI quick task' LIMIT 1")
test -n "$quick_id"
test "$(db "SELECT priority FROM tasks WHERE id=$quick_id")" = "1"
test "$(db "SELECT status_id FROM tasks WHERE id=$quick_id")" = "$default_status"
quick_description=$(db "SELECT description FROM tasks WHERE id=$quick_id")
if printf '%s' "$quick_description" | grep -q '<script'; then
  echo "Quick-add stored executable markup." >&2
  exit 1
fi
printf '%s' "$quick_description" | grep -q '&lt;script&gt;alert(7)&lt;/script&gt;'

curl --fail --silent --cookie "$cookies" "$base_url/dashboard" > /tmp/quick-dashboard-after.html
grep -q 'Task added.' /tmp/quick-dashboard-after.html
grep -q "/tasks/$quick_id/edit" /tmp/quick-dashboard-after.html
