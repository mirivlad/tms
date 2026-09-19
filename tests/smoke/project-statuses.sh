#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects" > /tmp/ps-projects.html
csrf=$(grep -m1 -o 'name="_csrf" value="[^"]*"' /tmp/ps-projects.html | sed 's/.*value="//;s/"$//')
test -n "$csrf"
admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'name=Workflow Smoke Project' \
  --data-urlencode 'description=workflow smoke' \
  --data-urlencode 'lifecycle_status=active' \
  "$BASE_URL/projects")
test "$code" = "302"

project_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='Workflow Smoke Project' LIMIT 1")
test -n "$project_id"
personal_count=$(db "SELECT COUNT(*) FROM statuses WHERE user_id=$admin_id AND project_id IS NULL")
project_count=$(db "SELECT COUNT(*) FROM statuses WHERE user_id IS NULL AND project_id=$project_id")
test "$personal_count" = "$project_count"

personal_default=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND project_id IS NULL AND is_default=1 LIMIT 1")
project_default=$(db "SELECT id FROM statuses WHERE project_id=$project_id AND is_default=1 LIMIT 1")
test "$(db "SELECT source_status_id FROM statuses WHERE id=$project_default")" = "$personal_default"

response=$(curl --fail --silent -H 'Accept: application/json' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'title=Workflow clone task' \
  --data-urlencode "project_id=$project_id" "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
clone_task=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Workflow clone task' LIMIT 1")
test "$(db "SELECT status_id FROM tasks WHERE id=$clone_task")" = "$project_default"

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'name=Review' \
  --data-urlencode 'color=#445566' --data-urlencode 'show_on_board=1' \
  "$BASE_URL/projects/$project_id/statuses")
test "$code" = "302"
review_id=$(db "SELECT id FROM statuses WHERE project_id=$project_id AND name='Review' LIMIT 1")
test -n "$review_id"

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" "$BASE_URL/projects/$project_id/statuses/$review_id/default")
test "$code" = "302"

response=$(curl --fail --silent -H 'Accept: application/json' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'title=Workflow custom task' \
  --data-urlencode "project_id=$project_id" "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
custom_task=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Workflow custom task' LIMIT 1")
test "$(db "SELECT status_id FROM tasks WHERE id=$custom_task")" = "$review_id"

code=$(curl --silent -o /tmp/ps-invalid.json -w '%{http_code}' -H 'Accept: application/json' \
  --cookie "$COOKIE_JAR" --data-urlencode "_csrf=$csrf" \
  --data-urlencode "status_id=$personal_default" "$BASE_URL/tasks/$custom_task/status")
test "$code" = "422"
test "$(db "SELECT status_id FROM tasks WHERE id=$custom_task")" = "$review_id"

curl --fail --silent -H 'Accept: application/json' --cookie "$COOKIE_JAR" \
  "$BASE_URL/api/task-statuses?project_id=$project_id" > /tmp/ps-options.json
grep -q '"name":"Review"' /tmp/ps-options.json

personal_completion=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND project_id IS NULL AND is_completion=1 LIMIT 1")
project_completion=$(db "SELECT id FROM statuses WHERE project_id=$project_id AND source_status_id=$personal_completion LIMIT 1")
code=$(curl --silent -o /tmp/ps-move.json -w '%{http_code}' -H 'Accept: application/json' \
  --cookie "$COOKIE_JAR" --data-urlencode "_csrf=$csrf" \
  --data-urlencode "status_id=$project_completion" "$BASE_URL/tasks/$clone_task/status")
test "$code" = "200"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id/statuses" > /tmp/ps-detail.html
grep -q 'Project workflow' /tmp/ps-detail.html
grep -q 'Review' /tmp/ps-detail.html

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" "$BASE_URL/projects/$project_id/delete")
test "$code" = "302"
test "$(db "SELECT project_id IS NULL FROM tasks WHERE id=$clone_task")" = "1"
test "$(db "SELECT status_id FROM tasks WHERE id=$clone_task")" = "$personal_completion"
test "$(db "SELECT project_id IS NULL FROM tasks WHERE id=$custom_task")" = "1"
test "$(db "SELECT status_id FROM tasks WHERE id=$custom_task")" = "$personal_default"
