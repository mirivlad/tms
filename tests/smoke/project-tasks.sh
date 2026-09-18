#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects" > /tmp/project-task-projects.html
csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/project-task-projects.html | head -n1)
test -n "$csrf"

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
test -n "$admin_id"
db "INSERT INTO projects (owner_user_id, owner_team_id, created_by, name, description, lifecycle_status) VALUES ($admin_id, NULL, $admin_id, 'Task Integration Project', 'Smoke project', 'active')"
project_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='Task Integration Project' LIMIT 1")
test -n "$project_id"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/new?project_id=$project_id" > /tmp/project-task-new.html
grep -Eq "option value=\"$project_id\" selected" /tmp/project-task-new.html

# Quick add can assign directly to an owned project.
response=$(curl --fail --silent --header 'Accept: application/json'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'title=Project smoke task'   --data-urlencode 'description=Created in a project'   --data-urlencode "project_id=$project_id"   "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
task_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Project smoke task' LIMIT 1")
test -n "$task_id"
test "$(db "SELECT project_id FROM tasks WHERE id=$task_id")" = "$project_id"

# A normal unassigned task stays in the No project pool.
response=$(curl --fail --silent --header 'Accept: application/json'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'title=Unassigned smoke task'   "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
loose_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Unassigned smoke task' LIMIT 1")
test -n "$loose_id"
test "$(db "SELECT project_id IS NULL FROM tasks WHERE id=$loose_id")" = "1"

curl --fail --silent --get --cookie "$COOKIE_JAR" --data-urlencode "project=$project_id" "$BASE_URL/tasks" > /tmp/project-task-filtered.html
grep -q 'Project smoke task' /tmp/project-task-filtered.html
if grep -q 'Unassigned smoke task' /tmp/project-task-filtered.html; then
  echo 'Project filter leaked an unassigned task.' >&2
  exit 1
fi

curl --fail --silent --get --cookie "$COOKIE_JAR" --data-urlencode 'project=none' "$BASE_URL/tasks" > /tmp/project-task-none.html
grep -q 'Unassigned smoke task' /tmp/project-task-none.html
if grep -q 'Project smoke task' /tmp/project-task-none.html; then
  echo 'No-project filter leaked an assigned task.' >&2
  exit 1
fi

curl --fail --silent --get --cookie "$COOKIE_JAR" --data-urlencode "project=$project_id" "$BASE_URL/board" > /tmp/project-task-board.html
grep -q 'Project smoke task' /tmp/project-task-board.html
if grep -q 'Unassigned smoke task' /tmp/project-task-board.html; then
  echo 'Board project filter leaked an unassigned task.' >&2
  exit 1
fi

month=$(date +%Y-%m)
curl --fail --silent --get --cookie "$COOKIE_JAR"   --data-urlencode "month=$month"   --data-urlencode 'mode=all'   --data-urlencode "project=$project_id"   "$BASE_URL/calendar" > /tmp/project-task-calendar.html
grep -q 'Project smoke task' /tmp/project-task-calendar.html
if grep -q 'Unassigned smoke task' /tmp/project-task-calendar.html; then
  echo 'Calendar project filter leaked an unassigned task.' >&2
  exit 1
fi

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id" > /tmp/project-task-detail.html
grep -q 'Task Integration Project' /tmp/project-task-detail.html
grep -q 'Project smoke task' /tmp/project-task-detail.html

# A forged project id from another owner is rejected.
other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
foreign_project=$(db "SELECT id FROM projects WHERE owner_user_id=$other_id ORDER BY id LIMIT 1")
test -n "$foreign_project"
foreign_response=$(curl --silent --output /tmp/project-task-foreign.json --write-out '%{http_code}'   --header 'Accept: application/json'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'title=Must not be assigned'   --data-urlencode "project_id=$foreign_project"   "$BASE_URL/tasks/quick-add")
test "$foreign_response" = "400"
test "$(db "SELECT COUNT(*) FROM tasks WHERE created_by=$admin_id AND title='Must not be assigned'")" = "0"

# Deleting a project preserves its tasks by returning them to No project.
delete_status=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   "$BASE_URL/projects/$project_id/delete")
test "$delete_status" = "302"
test "$(db "SELECT project_id IS NULL FROM tasks WHERE id=$task_id")" = "1"
