#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIES=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  grep -m1 -o 'name="_csrf" value="[^"]*"' "$1" | sed 's/.*value="//;s/"$//'
}

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
test -n "$admin_id"

curl --fail --silent --cookie "$COOKIES" "$BASE_URL/teams" > /tmp/transfer-teams.html
csrf=$(csrf_from /tmp/transfer-teams.html)

for name in "Transfer Team A" "Transfer Team B"; do
  code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
    --data-urlencode "_csrf=$csrf" --data-urlencode "name=$name" "$BASE_URL/teams")
  test "$code" = "302"
done
team_a=$(db "SELECT id FROM teams WHERE created_by=$admin_id AND name='Transfer Team A' LIMIT 1")
team_b=$(db "SELECT id FROM teams WHERE created_by=$admin_id AND name='Transfer Team B' LIMIT 1")
test -n "$team_a"
test -n "$team_b"

curl --fail --silent --cookie "$COOKIES" "$BASE_URL/projects" > /tmp/transfer-projects.html
project_csrf=$(csrf_from /tmp/transfer-projects.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$project_csrf" --data-urlencode 'owner_scope=personal' \
  --data-urlencode 'name=Transfer Project' --data-urlencode 'description=ownership smoke' \
  --data-urlencode 'lifecycle_status=active' "$BASE_URL/projects")
test "$code" = "302"
project_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='Transfer Project' LIMIT 1")
test -n "$project_id"

response=$(curl --fail --silent -H 'Accept: application/json' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$project_csrf" --data-urlencode 'title=Transfer task' \
  --data-urlencode "project_id=$project_id" "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
task_id=$(db "SELECT id FROM tasks WHERE project_id=$project_id AND title='Transfer task' LIMIT 1")
test -n "$task_id"

type_id=$(db "SELECT id FROM task_types WHERE user_id=$admin_id ORDER BY id LIMIT 1")
customer_id=$(db "SELECT id FROM customers WHERE user_id=$admin_id ORDER BY id LIMIT 1")
if [ -n "$type_id" ]; then
  db "UPDATE tasks SET type_id=$type_id WHERE id=$task_id"
fi
if [ -n "$customer_id" ]; then
  db "UPDATE tasks SET customer_id=$customer_id WHERE id=$task_id"
fi

curl --fail --silent --cookie "$COOKIES" "$BASE_URL/projects/$project_id/settings" > /tmp/transfer-settings.html
settings_csrf=$(csrf_from /tmp/transfer-settings.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$settings_csrf" --data-urlencode "owner_scope=team:$team_a" \
  --data-urlencode 'name=Transfer Project' --data-urlencode 'description=ownership smoke' \
  --data-urlencode 'lifecycle_status=active' "$BASE_URL/projects/$project_id")
test "$code" = "302"
test "$(db "SELECT owner_team_id FROM projects WHERE id=$project_id")" = "$team_a"
test "$(db "SELECT owner_user_id IS NULL FROM projects WHERE id=$project_id")" = "1"
test "$(db "SELECT type_id IS NULL AND customer_id IS NULL FROM tasks WHERE id=$task_id")" = "1"

db "UPDATE tasks SET assignee_user_id=$admin_id WHERE id=$task_id"

curl --fail --silent --cookie "$COOKIES" "$BASE_URL/projects/$project_id/discussion" > /tmp/transfer-discussion-a.html
discussion_csrf=$(csrf_from /tmp/transfer-discussion-a.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$discussion_csrf" --data-urlencode 'body=<p>Team A secret history</p>' \
  "$BASE_URL/projects/$project_id/discussion")
test "$code" = "302"
comment_a=$(db "SELECT id FROM discussion_comments WHERE project_id=$project_id AND body_html LIKE '%Team A secret history%' LIMIT 1")
test -n "$comment_a"
test "$(db "SELECT team_id FROM discussion_comments WHERE id=$comment_a")" = "$team_a"

curl --fail --silent --cookie "$COOKIES" "$BASE_URL/projects/$project_id/settings" > /tmp/transfer-settings-a.html
settings_csrf=$(csrf_from /tmp/transfer-settings-a.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$settings_csrf" --data-urlencode "owner_scope=team:$team_b" \
  --data-urlencode 'name=Transfer Project' --data-urlencode 'description=ownership smoke' \
  --data-urlencode 'lifecycle_status=active' "$BASE_URL/projects/$project_id")
test "$code" = "302"
test "$(db "SELECT owner_team_id FROM projects WHERE id=$project_id")" = "$team_b"
test "$(db "SELECT assignee_user_id IS NULL FROM tasks WHERE id=$task_id")" = "1"

curl --fail --silent --cookie "$COOKIES" "$BASE_URL/projects/$project_id/discussion" > /tmp/transfer-discussion-b.html
if grep -q 'Team A secret history' /tmp/transfer-discussion-b.html; then
  echo 'Historical Team A discussion leaked after moving the project to Team B.' >&2
  exit 1
fi
discussion_csrf=$(csrf_from /tmp/transfer-discussion-b.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$discussion_csrf" --data-urlencode 'body=<p>Team B current history</p>' \
  "$BASE_URL/projects/$project_id/discussion")
test "$code" = "302"
comment_b=$(db "SELECT id FROM discussion_comments WHERE project_id=$project_id AND body_html LIKE '%Team B current history%' LIMIT 1")
test "$(db "SELECT team_id FROM discussion_comments WHERE id=$comment_b")" = "$team_b"

curl --fail --silent --cookie "$COOKIES" "$BASE_URL/projects/$project_id/settings" > /tmp/transfer-settings-b.html
settings_csrf=$(csrf_from /tmp/transfer-settings-b.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$settings_csrf" --data-urlencode 'owner_scope=personal' \
  --data-urlencode 'name=Transfer Project' --data-urlencode 'description=ownership smoke' \
  --data-urlencode 'lifecycle_status=active' "$BASE_URL/projects/$project_id")
test "$code" = "302"
test "$(db "SELECT owner_user_id FROM projects WHERE id=$project_id")" = "$admin_id"
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" "$BASE_URL/projects/$project_id/discussion")" = "404"

# Returning to Team A reveals only Team A's original history, never Team B's.
curl --fail --silent --cookie "$COOKIES" "$BASE_URL/projects/$project_id/settings" > /tmp/transfer-settings-personal.html
settings_csrf=$(csrf_from /tmp/transfer-settings-personal.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$settings_csrf" --data-urlencode "owner_scope=team:$team_a" \
  --data-urlencode 'name=Transfer Project' --data-urlencode 'description=ownership smoke' \
  --data-urlencode 'lifecycle_status=active' "$BASE_URL/projects/$project_id")
test "$code" = "302"
curl --fail --silent --cookie "$COOKIES" "$BASE_URL/projects/$project_id/discussion" > /tmp/transfer-discussion-a-return.html
grep -q 'Team A secret history' /tmp/transfer-discussion-a-return.html
if grep -q 'Team B current history' /tmp/transfer-discussion-a-return.html; then
  echo 'Team B discussion leaked after returning the project to Team A.' >&2
  exit 1
fi

# Cleanup.
curl --fail --silent --cookie "$COOKIES" "$BASE_URL/tasks/$task_id/edit" > /tmp/transfer-task.html
task_csrf=$(csrf_from /tmp/transfer-task.html)
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$task_csrf" "$BASE_URL/tasks/$task_id/delete")" = "302"

curl --fail --silent --cookie "$COOKIES" "$BASE_URL/projects/$project_id/settings" > /tmp/transfer-settings-final.html
settings_csrf=$(csrf_from /tmp/transfer-settings-final.html)
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
  --data-urlencode "_csrf=$settings_csrf" "$BASE_URL/projects/$project_id/delete")" = "302"

for team_id in "$team_a" "$team_b"; do
  curl --fail --silent --cookie "$COOKIES" "$BASE_URL/teams/$team_id/settings" > /tmp/transfer-team-settings.html
  team_csrf=$(csrf_from /tmp/transfer-team-settings.html)
  test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIES" \
    --data-urlencode "_csrf=$team_csrf" "$BASE_URL/teams/$team_id/delete")" = "302"
done
