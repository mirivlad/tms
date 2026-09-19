#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
ADMIN_COOKIES=/tmp/tms-collab-acl-admin
OTHER_COOKIES=/tmp/tms-collab-acl-other

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$1" | head -n1
}

login() {
  local username="$1"
  local password="$2"
  local cookies="$3"
  local page="/tmp/tms-collab-login-${username}.html"
  curl --fail --silent --cookie-jar "$cookies" "$BASE_URL/login" > "$page"
  local csrf
  csrf=$(csrf_from "$page")
  test -n "$csrf"
  local code
  code=$(curl --silent -o /dev/null -w '%{http_code}' \
    --cookie "$cookies" --cookie-jar "$cookies" \
    --data-urlencode "_csrf=$csrf" \
    --data-urlencode "username=$username" \
    --data-urlencode "password=$password" \
    "$BASE_URL/login")
  test "$code" = "302"
}

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
test -n "$admin_id"
test -n "$other_id"

# Give the existing isolated user a real login and enough personal workflow
# metadata to create a valid team project through the application.
other_hash=$(docker compose exec -T app php -r 'echo password_hash("collab-other-password", PASSWORD_DEFAULT);')
other_hash_sql=$(printf '%s' "$other_hash" | sed "s/'/''/g")
db "UPDATE users
    SET password_hash='$other_hash_sql',
        email_verified_at=UTC_TIMESTAMP(),
        approved_at=UTC_TIMESTAMP()
    WHERE id=$other_id"
if [ "$(db "SELECT COUNT(*) FROM statuses WHERE user_id=$other_id AND is_default=1")" = "0" ]; then
  db "INSERT INTO statuses (
        user_id,name,description,color,sort_order,is_default,is_completion,show_on_board
      ) VALUES (
        $other_id,'Other Inbox','','#6b7280',10,1,0,1
      )"
fi

# Independent sessions for two users who will own separate teams.
login ciadmin ci-admin-password-12345 "$ADMIN_COOKIES"
login other collab-other-password "$OTHER_COOKIES"

# Admin creates Team A.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams" > /tmp/collab-admin-teams.html
admin_csrf=$(csrf_from /tmp/collab-admin-teams.html)
test -n "$admin_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$ADMIN_COOKIES" \
  --data-urlencode "_csrf=$admin_csrf" \
  --data-urlencode 'name=ACL Team A' \
  --data-urlencode 'description=First isolated team' \
  "$BASE_URL/teams")
test "$code" = "302"
team_a=$(db "SELECT id FROM teams WHERE created_by=$admin_id AND name='ACL Team A' ORDER BY id DESC LIMIT 1")
test -n "$team_a"

# Other creates Team B.
curl --fail --silent --cookie "$OTHER_COOKIES" "$BASE_URL/teams" > /tmp/collab-other-teams.html
other_csrf=$(csrf_from /tmp/collab-other-teams.html)
test -n "$other_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$OTHER_COOKIES" \
  --data-urlencode "_csrf=$other_csrf" \
  --data-urlencode 'name=ACL Team B' \
  --data-urlencode 'description=Second isolated team' \
  "$BASE_URL/teams")
test "$code" = "302"
team_b=$(db "SELECT id FROM teams WHERE created_by=$other_id AND name='ACL Team B' ORDER BY id DESC LIMIT 1")
test -n "$team_b"

# Each Lead creates a team-owned project through the real application.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects?owner=team:$team_a" > /tmp/collab-project-a-new.html
csrf_a=$(csrf_from /tmp/collab-project-a-new.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$ADMIN_COOKIES" \
  --data-urlencode "_csrf=$csrf_a" \
  --data-urlencode "owner_scope=team:$team_a" \
  --data-urlencode 'name=ACL Project A' \
  --data-urlencode 'lifecycle_status=active' \
  "$BASE_URL/projects")
test "$code" = "302"
project_a=$(db "SELECT id FROM projects WHERE owner_team_id=$team_a AND name='ACL Project A' LIMIT 1")
test -n "$project_a"

curl --fail --silent --cookie "$OTHER_COOKIES" "$BASE_URL/projects?owner=team:$team_b" > /tmp/collab-project-b-new.html
csrf_b=$(csrf_from /tmp/collab-project-b-new.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$OTHER_COOKIES" \
  --data-urlencode "_csrf=$csrf_b" \
  --data-urlencode "owner_scope=team:$team_b" \
  --data-urlencode 'name=ACL Project B' \
  --data-urlencode 'lifecycle_status=active' \
  "$BASE_URL/projects")
test "$code" = "302"
project_b=$(db "SELECT id FROM projects WHERE owner_team_id=$team_b AND name='ACL Project B' LIMIT 1")
test -n "$project_b"
status_b=$(db "SELECT id FROM statuses WHERE project_id=$project_b AND is_default=1 LIMIT 1")
test -n "$status_b"

# Other creates a task and project attachment inside Team B.
curl --fail --silent --cookie "$OTHER_COOKIES" "$BASE_URL/projects/$project_b" > /tmp/collab-project-b.html
project_b_csrf=$(csrf_from /tmp/collab-project-b.html)
response=$(curl --fail --silent -H 'Accept: application/json' \
  --cookie "$OTHER_COOKIES" \
  --data-urlencode "_csrf=$project_b_csrf" \
  --data-urlencode 'title=ACL Team B task' \
  --data-urlencode "project_id=$project_b" \
  "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
task_b=$(db "SELECT id FROM tasks WHERE project_id=$project_b AND title='ACL Team B task' LIMIT 1")
test -n "$task_b"

printf 'team b private attachment\n' > /tmp/collab-team-b.txt
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$OTHER_COOKIES" \
  -F "_csrf=$project_b_csrf" \
  -F "attachments[]=@/tmp/collab-team-b.txt;type=text/plain" \
  "$BASE_URL/projects/$project_b/attachments")
test "$code" = "302"
attachment_b=$(db "SELECT id FROM project_attachments
                   WHERE project_id=$project_b AND original_name='collab-team-b.txt'
                   LIMIT 1")
test -n "$attachment_b"

# Team A Lead must not discover Team B through ordinary lists.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams" > /tmp/collab-admin-team-list.html
if grep -q 'ACL Team B' /tmp/collab-admin-team-list.html; then
  echo 'Cross-team leak: Team B appeared in Team A user team list.' >&2
  exit 1
fi
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects" > /tmp/collab-admin-project-list.html
if grep -q 'ACL Project B' /tmp/collab-admin-project-list.html; then
  echo 'Cross-team leak: Project B appeared in Team A user project list.' >&2
  exit 1
fi

# Direct reads across team boundary are indistinguishable from missing resources.
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$ADMIN_COOKIES" "$BASE_URL/teams/$team_b")" = "404"
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_b")" = "404"
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$ADMIN_COOKIES" "$BASE_URL/tasks/$task_b/edit")" = "404"
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$ADMIN_COOKIES" "$BASE_URL/api/task-assignees?project_id=$project_b")" = "404"
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_b/attachments/$attachment_b")" = "404"

# Forged mutations must not cross the boundary either.
before_statuses=$(db "SELECT COUNT(*) FROM statuses WHERE project_id=$project_b")
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$ADMIN_COOKIES" \
  --data-urlencode "_csrf=$csrf_a" \
  --data-urlencode 'name=Injected status' \
  --data-urlencode 'color=#112233' \
  "$BASE_URL/projects/$project_b/statuses")
test "$code" = "404"
test "$(db "SELECT COUNT(*) FROM statuses WHERE project_id=$project_b")" = "$before_statuses"

before_fields=$(db "SELECT COUNT(*) FROM custom_fields WHERE project_id=$project_b")
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$ADMIN_COOKIES" \
  --data-urlencode "_csrf=$csrf_a" \
  --data-urlencode 'name=Injected field' \
  --data-urlencode 'field_type=text' \
  "$BASE_URL/projects/$project_b/fields")
test "$code" = "404"
test "$(db "SELECT COUNT(*) FROM custom_fields WHERE project_id=$project_b")" = "$before_fields"

before_comments=$(db "SELECT COUNT(*) FROM discussion_comments WHERE task_id=$task_b")
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$ADMIN_COOKIES" \
  --data-urlencode "_csrf=$csrf_a" \
  --data-urlencode 'body=<p>cross-team injection</p>' \
  "$BASE_URL/tasks/$task_b/discussion")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE task_id=$task_b")" = "$before_comments"

code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$ADMIN_COOKIES" \
  --data-urlencode "_csrf=$csrf_a" \
  --data-urlencode "status_id=$status_b" \
  "$BASE_URL/tasks/$task_b/status")
test "$code" = "404"
test "$(db "SELECT status_id FROM tasks WHERE id=$task_b")" = "$status_b"

# The inverse boundary must hold as well.
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$OTHER_COOKIES" "$BASE_URL/teams/$team_a")" = "404"
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$OTHER_COOKIES" "$BASE_URL/projects/$project_a")" = "404"

# Cleanup through the owning sessions.
curl --fail --silent --cookie "$OTHER_COOKIES" "$BASE_URL/tasks/$task_b/edit" > /tmp/collab-task-b.html
task_b_csrf=$(csrf_from /tmp/collab-task-b.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$OTHER_COOKIES" --data-urlencode "_csrf=$task_b_csrf" \
  "$BASE_URL/tasks/$task_b/delete")
test "$code" = "302"

curl --fail --silent --cookie "$OTHER_COOKIES" "$BASE_URL/projects/$project_b" > /tmp/collab-project-b-final.html
project_b_csrf=$(csrf_from /tmp/collab-project-b-final.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$OTHER_COOKIES" --data-urlencode "_csrf=$project_b_csrf" \
  "$BASE_URL/projects/$project_b/attachments/$attachment_b/delete")
test "$code" = "302"
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$OTHER_COOKIES" --data-urlencode "_csrf=$project_b_csrf" \
  "$BASE_URL/projects/$project_b/delete")
test "$code" = "302"

curl --fail --silent --cookie "$OTHER_COOKIES" "$BASE_URL/teams/$team_b" > /tmp/collab-team-b-final.html
team_b_csrf=$(csrf_from /tmp/collab-team-b-final.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$OTHER_COOKIES" --data-urlencode "_csrf=$team_b_csrf" \
  "$BASE_URL/teams/$team_b/delete")
test "$code" = "302"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_a" > /tmp/collab-project-a-final.html
project_a_csrf=$(csrf_from /tmp/collab-project-a-final.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$ADMIN_COOKIES" --data-urlencode "_csrf=$project_a_csrf" \
  "$BASE_URL/projects/$project_a/delete")
test "$code" = "302"
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams/$team_a" > /tmp/collab-team-a-final.html
team_a_csrf=$(csrf_from /tmp/collab-team-a-final.html)
code=$(curl --silent -o /dev/null -w '%{http_code}' \
  --cookie "$ADMIN_COOKIES" --data-urlencode "_csrf=$team_a_csrf" \
  "$BASE_URL/teams/$team_a/delete")
test "$code" = "302"
