#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$COOKIE_JAR"   "$BASE_URL/tasks?project=all&priority=urgent&sort=title&order=asc&per_page=10"   > /tmp/saved-view-tasks.html
csrf=$(grep -m1 -o 'name="_csrf" value="[^"]*"' /tmp/saved-view-tasks.html | sed 's/.*value="//;s/"$//')
test -n "$csrf"

headers=$(mktemp)
curl --silent --output /dev/null --dump-header "$headers"   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'name=Urgent work'   --data-urlencode 'query=project=all&priority=urgent&sort=title&order=asc&per_page=10&page=999&evil=1'   --data-urlencode 'is_default=1'   "$BASE_URL/tasks/views"

grep -q '^HTTP/.* 302' "$headers"
location=$(awk 'BEGIN{IGNORECASE=1} /^Location:/ {gsub("\r",""); print $2}' "$headers" | tail -n1)
case "$location" in
  /tasks/views/*) ;;
  *) echo "Unexpected saved-view redirect: $location" >&2; exit 1 ;;
esac
view_id=${location##*/}
test -n "$view_id"

payload=$(db "SELECT query_json FROM task_saved_views WHERE id=$view_id")
printf '%s' "$payload" | grep -q '"priority":"urgent"'
printf '%s' "$payload" | grep -q '"project":"all"'
if printf '%s' "$payload" | grep -Eq '"(page|evil|view)"'; then
  echo 'Saved view persisted unsupported query state.' >&2
  exit 1
fi
test "$(db "SELECT is_default FROM task_saved_views WHERE id=$view_id")" = "1"

apply_headers=$(mktemp)
curl --silent --output /dev/null --dump-header "$apply_headers"   --cookie "$COOKIE_JAR" "$BASE_URL/tasks/views/$view_id"
grep -q '^HTTP/.* 302' "$apply_headers"
apply_location=$(awk 'BEGIN{IGNORECASE=1} /^Location:/ {gsub("\r",""); print $2}' "$apply_headers" | tail -n1)
printf '%s' "$apply_location" | grep -q 'priority=urgent'
printf '%s' "$apply_location" | grep -q 'project=all'
printf '%s' "$apply_location" | grep -q "view=$view_id"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL$apply_location" > /tmp/saved-view-applied.html
grep -q 'Urgent work' /tmp/saved-view-applied.html
grep -q 'saved-view-item is-active' /tmp/saved-view-applied.html

default_headers=$(mktemp)
curl --silent --output /dev/null --dump-header "$default_headers"   --cookie "$COOKIE_JAR" "$BASE_URL/tasks"
grep -q '^HTTP/.* 302' "$default_headers"
default_location=$(awk 'BEGIN{IGNORECASE=1} /^Location:/ {gsub("\r",""); print $2}' "$default_headers" | tail -n1)
test "$default_location" = "/tasks/views/$view_id"

manual_code=$(curl --silent --output /tmp/saved-view-manual.html --write-out '%{http_code}'   --cookie "$COOKIE_JAR" "$BASE_URL/tasks?project=none")
test "$manual_code" = "200"

headers2=$(mktemp)
curl --silent --output /dev/null --dump-header "$headers2"   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'name=No project'   --data-urlencode 'query=project=none&sort=deadline&order=desc&per_page=25'   "$BASE_URL/tasks/views"
location2=$(awk 'BEGIN{IGNORECASE=1} /^Location:/ {gsub("\r",""); print $2}' "$headers2" | tail -n1)
second_id=${location2##*/}
test -n "$second_id"

code=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   "$BASE_URL/tasks/views/$second_id/default")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM task_saved_views WHERE user_id=(SELECT id FROM users WHERE username='ciadmin') AND is_default=1")" = "1"
test "$(db "SELECT is_default FROM task_saved_views WHERE id=$second_id")" = "1"

other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
test -n "$other_id"
db "INSERT INTO task_saved_views (user_id,name,query_json,is_default) VALUES ($other_id,'Foreign private view','{\"q\":\"secret\"}',0)"
foreign_id=$(db "SELECT id FROM task_saved_views WHERE user_id=$other_id AND name='Foreign private view' LIMIT 1")
test -n "$foreign_id"

code=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR" "$BASE_URL/tasks/views/$foreign_id")
test "$code" = "404"

code=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   "$BASE_URL/tasks/views/$foreign_id/delete")
test "$code" = "404"
test "$(db "SELECT COUNT(*) FROM task_saved_views WHERE id=$foreign_id")" = "1"

code=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   "$BASE_URL/tasks/views/$view_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM task_saved_views WHERE id=$view_id")" = "0"

code=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   "$BASE_URL/tasks/views/$second_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM task_saved_views WHERE id=$second_id")" = "0"
test "$(db "SELECT COUNT(*) FROM task_saved_views WHERE user_id=(SELECT id FROM users WHERE username='ciadmin') AND is_default=1")" = "0"
