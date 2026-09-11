#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
cookies=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$cookies" "$base_url/metadata" > /tmp/metadata.html
grep -q 'Task metadata' /tmp/metadata.html
grep -q '/assets/metadata.css' /tmp/metadata.html
csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/metadata.html | head -n1)
test -n "$csrf"
admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")

# CSRF still protects the new mutation surface.
rejected=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" --data-urlencode 'name=No CSRF' \
  "$base_url/metadata/customers")
test "$rejected" = "403"

# Status CRUD, color, board visibility, roles and ordering.
create_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'name=CI Review' --data-urlencode 'description=Smoke status' \
  --data-urlencode 'color=#336699' --data-urlencode 'show_on_board=1' \
  "$base_url/metadata/statuses")
test "$create_status" = "302"
review_id=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND name='CI Review' LIMIT 1")
test -n "$review_id"

db "SELECT color,show_on_board FROM statuses WHERE id=$review_id" | grep -q $'#336699\t1'

curl --fail --silent --output /dev/null --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'name=CI Later' \
  --data-urlencode 'color=#654321' --data-urlencode 'show_on_board=1' \
  "$base_url/metadata/statuses"
later_id=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND name='CI Later' LIMIT 1")
test -n "$later_id"

curl --fail --silent --output /dev/null --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'direction=up' \
  "$base_url/metadata/statuses/$later_id/move"
test "$(db "SELECT sort_order FROM statuses WHERE id=$later_id")" -lt "$(db "SELECT sort_order FROM statuses WHERE id=$review_id")"

curl --fail --silent --output /dev/null --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'name=CI Review Updated' \
  --data-urlencode 'description=Updated smoke status' --data-urlencode 'color=#123456' \
  "$base_url/metadata/statuses/$review_id"
db "SELECT name,color,show_on_board FROM statuses WHERE id=$review_id" | grep -q $'CI Review Updated\t#123456\t0'

curl --fail --silent --output /dev/null --cookie "$cookies" --data-urlencode "_csrf=$csrf" \
  "$base_url/metadata/statuses/$review_id/default"
curl --fail --silent --output /dev/null --cookie "$cookies" --data-urlencode "_csrf=$csrf" \
  "$base_url/metadata/statuses/$review_id/completion"
test "$(db "SELECT COUNT(*) FROM statuses WHERE user_id=$admin_id AND is_default=1")" = "1"
test "$(db "SELECT COUNT(*) FROM statuses WHERE user_id=$admin_id AND is_completion=1")" = "1"
test "$(db "SELECT is_default+is_completion FROM statuses WHERE id=$review_id")" = "2"

# Task type create/edit/order.
curl --fail --silent --output /dev/null --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'name=CI Incident' \
  --data-urlencode 'description=Smoke type' "$base_url/metadata/types"
type_id=$(db "SELECT id FROM task_types WHERE user_id=$admin_id AND name='CI Incident' LIMIT 1")
test -n "$type_id"
curl --fail --silent --output /dev/null --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'name=CI Incident Updated' \
  --data-urlencode 'description=Updated smoke type' "$base_url/metadata/types/$type_id"
test "$(db "SELECT name FROM task_types WHERE id=$type_id")" = "CI Incident Updated"

# Customer create/edit.
curl --fail --silent --output /dev/null --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'name=CI Metadata Client' \
  "$base_url/metadata/customers"
customer_id=$(db "SELECT id FROM customers WHERE user_id=$admin_id AND name='CI Metadata Client' LIMIT 1")
test -n "$customer_id"
curl --fail --silent --output /dev/null --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'name=CI Metadata Client Updated' \
  "$base_url/metadata/customers/$customer_id"
test "$(db "SELECT name FROM customers WHERE id=$customer_id")" = "CI Metadata Client Updated"

# A foreign user's status stays inaccessible even with a valid authenticated CSRF token.
foreign_status=$(db "SELECT s.id FROM statuses s JOIN users u ON u.id=s.user_id WHERE u.username='other' LIMIT 1")
foreign_result=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" --data-urlencode "_csrf=$csrf" --data-urlencode 'name=Stolen' \
  --data-urlencode 'description=No' --data-urlencode 'color=#111111' \
  "$base_url/metadata/statuses/$foreign_status")
test "$foreign_result" = "404"
test "$(db "SELECT COUNT(*) FROM statuses WHERE id=$foreign_status AND name='Stolen'")" = "0"

# Metadata referenced by a task, and protected status roles, cannot be deleted.
db "INSERT INTO tasks (created_by,title,description,status_id,type_id,customer_id) VALUES ($admin_id,'Metadata protected task','',$review_id,$type_id,$customer_id)"
for target in \
  "statuses/$review_id/delete" \
  "types/$type_id/delete" \
  "customers/$customer_id/delete"
do
  code=$(curl --silent --output /dev/null --write-out '%{http_code}' \
    --cookie "$cookies" --data-urlencode "_csrf=$csrf" "$base_url/metadata/$target")
  test "$code" = "409"
done

curl --fail --silent --cookie "$cookies" "$base_url/metadata" > /tmp/metadata-final.html
grep -q 'CI Review Updated' /tmp/metadata-final.html
grep -q 'CI Incident Updated' /tmp/metadata-final.html
grep -q 'CI Metadata Client Updated' /tmp/metadata-final.html
