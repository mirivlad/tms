#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/new" > /tmp/activity-task-new.html
csrf=$(grep -m1 -o 'name="_csrf" value="[^"]*"' /tmp/activity-task-new.html | sed 's/.*value="//;s/"$//')
default_status=$(sed -n 's/.*<option value="\([0-9][0-9]*\)" selected>.*/\1/p' /tmp/activity-task-new.html | head -n1)
test -n "$csrf"
test -n "$default_status"

response=$(curl --fail --silent --header 'Accept: application/json'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'title=Activity smoke task'   "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
task_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Activity smoke task' ORDER BY id DESC LIMIT 1")
test -n "$task_id"
test "$(db "SELECT COUNT(*) FROM activity_events WHERE task_id=$task_id AND event_type='task.created'")" = "1"
test "$(db "SELECT visibility_user_id FROM activity_events WHERE task_id=$task_id AND event_type='task.created' LIMIT 1")" = "$admin_id"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/$task_id/history" > /tmp/activity-task-history.html
grep -q 'Task created' /tmp/activity-task-history.html
grep -q 'Activity smoke task' /tmp/activity-task-history.html

response=$(curl --fail --silent --header 'Accept: application/json'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode "status_id=$default_status"   --data-urlencode 'description=<p>Secret body must not be copied into activity JSON</p>'   --data-urlencode 'deadline=2026-09-30T12:00'   "$BASE_URL/api/tasks/$task_id/quick-edit")
printf '%s' "$response" | grep -q '"success":true'

test "$(db "SELECT COUNT(*) FROM activity_events WHERE task_id=$task_id")" = "2"
payload=$(db "SELECT payload_json FROM activity_events WHERE task_id=$task_id AND event_type='task.updated' ORDER BY id DESC LIMIT 1")
printf '%s' "$payload" | grep -q '"description"'
if printf '%s' "$payload" | grep -q 'Secret body'; then
  echo 'Activity payload leaked task description content.' >&2
  exit 1
fi

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/$task_id/history" > /tmp/activity-task-history-updated.html
grep -q 'Task updated' /tmp/activity-task-history-updated.html
grep -q 'Description' /tmp/activity-task-history-updated.html
grep -q 'Changed' /tmp/activity-task-history-updated.html

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects" > /tmp/activity-projects.html
project_csrf=$(grep -m1 -o 'name="_csrf" value="[^"]*"' /tmp/activity-projects.html | sed 's/.*value="//;s/"$//')
test -n "$project_csrf"

status=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$project_csrf"   --data-urlencode 'name=Activity smoke project'   --data-urlencode 'description=History project'   --data-urlencode 'lifecycle_status=active'   "$BASE_URL/projects")
test "$status" = "302"

project_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='Activity smoke project' ORDER BY id DESC LIMIT 1")
test -n "$project_id"
test "$(db "SELECT COUNT(*) FROM activity_events WHERE project_id=$project_id AND task_id IS NULL AND event_type='project.created'")" = "1"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id/activity" > /tmp/activity-project-history.html
grep -q 'Project created' /tmp/activity-project-history.html
grep -q 'Activity smoke project' /tmp/activity-project-history.html
