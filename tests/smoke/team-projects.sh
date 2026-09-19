#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
ADMIN_COOKIES=/tmp/tms-cookies
MEMBER_COOKIES=/tmp/tms-team-project-member-cookies
STORAGE_ROOT=/var/www/html/var/storage/attachments

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  grep -m1 -o 'name="_csrf" value="[^"]*"' "$1" | sed 's/.*value="//;s/"$//'
}

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
test -n "$admin_id"

# Create a dedicated member account.
member_hash=$(docker compose exec -T app php -r 'echo password_hash("team-project-member-password", PASSWORD_DEFAULT);')
member_hash_sql=$(printf '%s' "$member_hash" | sed "s/'/''/g")
db "INSERT INTO users (
      username, email, password_hash, role, is_active, email_verified_at, approved_at
    ) VALUES (
      'teamprojectmember', 'teamprojectmember@example.invalid', '$member_hash_sql',
      'user', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )"
member_id=$(db "SELECT id FROM users WHERE username='teamprojectmember' LIMIT 1")
test -n "$member_id"

# Lead creates a team through the real UI.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams" > /tmp/tp-teams.html
admin_csrf=$(csrf_from /tmp/tp-teams.html)
test -n "$admin_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_csrf"   --data-urlencode 'name=CI Project Team'   --data-urlencode 'description=Team project smoke'   "$BASE_URL/teams")
test "$code" = "302"

team_id=$(db "SELECT id FROM teams WHERE name='CI Project Team' AND created_by=$admin_id LIMIT 1")
test -n "$team_id"
db "INSERT INTO team_members (team_id, user_id, role, joined_at)
    VALUES ($team_id, $member_id, 'member', UTC_TIMESTAMP())"

# Lead creates a team-owned project.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects?owner=team:$team_id" > /tmp/tp-projects.html
project_csrf=$(csrf_from /tmp/tp-projects.html)
test -n "$project_csrf"
grep -q "value=\"team:$team_id\" selected" /tmp/tp-projects.html

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   --data-urlencode "owner_scope=team:$team_id"   --data-urlencode 'name=CI Shared Project'   --data-urlencode 'description=Shared work'   --data-urlencode 'lifecycle_status=active'   "$BASE_URL/projects")
test "$code" = "302"

project_id=$(db "SELECT id FROM projects WHERE owner_team_id=$team_id AND name='CI Shared Project' LIMIT 1")
test -n "$project_id"
test "$(db "SELECT owner_user_id IS NULL FROM projects WHERE id=$project_id")" = "1"
project_status=$(db "SELECT id FROM statuses WHERE project_id=$project_id AND is_default=1 LIMIT 1")
test -n "$project_status"

# Member logs in independently and sees team project, but not Lead settings.
curl --fail --silent --cookie-jar "$MEMBER_COOKIES" "$BASE_URL/login" > /tmp/tp-member-login.html
login_csrf=$(csrf_from /tmp/tp-member-login.html)
test -n "$login_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES" --cookie-jar "$MEMBER_COOKIES"   --data-urlencode "_csrf=$login_csrf"   --data-urlencode 'username=teamprojectmember'   --data-urlencode 'password=team-project-member-password'   "$BASE_URL/login")
test "$code" = "302"

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/projects" > /tmp/tp-member-projects.html
grep -q 'CI Shared Project' /tmp/tp-member-projects.html

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/projects/$project_id" > /tmp/tp-member-project.html
member_csrf=$(csrf_from /tmp/tp-member-project.html)
test -n "$member_csrf"
grep -q 'CI Shared Project' /tmp/tp-member-project.html
if grep -q "href=\"/projects/$project_id/settings\"" /tmp/tp-member-project.html; then
  echo 'Member received project settings navigation.' >&2
  exit 1
fi
if grep -q "action=\"/projects/$project_id/statuses\"" /tmp/tp-member-project.html; then
  echo 'Member received project workflow management controls.' >&2
  exit 1
fi

# Forged Member project settings mutation is rejected.
code=$(curl --silent -o /tmp/tp-forbidden-project.txt -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   --data-urlencode 'name=Stolen status'   --data-urlencode 'color=#112233'   --data-urlencode 'show_on_board=1'   "$BASE_URL/projects/$project_id/statuses")
test "$code" = "404"

# Member creates a task in the shared project.
response=$(curl --fail --silent -H 'Accept: application/json'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   --data-urlencode 'title=Member shared task'   --data-urlencode "project_id=$project_id"   "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
task_id=$(db "SELECT id FROM tasks WHERE created_by=$member_id AND project_id=$project_id AND title='Member shared task' LIMIT 1")
test -n "$task_id"

# Team task form hides personal account metadata.
curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/tasks/$task_id/edit" > /tmp/tp-member-task.html
task_csrf=$(csrf_from /tmp/tp-member-task.html)
test -n "$task_csrf"
grep -q "data-team-owned=\"1\"" /tmp/tp-member-task.html
grep -q 'data-personal-metadata hidden' /tmp/tp-member-task.html
grep -q 'name="assignee_user_id"' /tmp/tp-member-task.html
grep -q "value=\"$member_id\"" /tmp/tp-member-task.html

curl --fail --silent --cookie "$MEMBER_COOKIES"   "$BASE_URL/api/task-assignees?project_id=$project_id" > /tmp/tp-assignees.json
grep -q '"username":"ciadmin"' /tmp/tp-assignees.json
grep -q '"username":"teamprojectmember"' /tmp/tp-assignees.json

# Lead can edit a task created by another member; project scope is locked.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/tasks/$task_id/edit" > /tmp/tp-admin-task.html
admin_task_csrf=$(csrf_from /tmp/tp-admin-task.html)
test -n "$admin_task_csrf"
grep -q 'name="project_id" data-project-select disabled' /tmp/tp-admin-task.html
grep -q "type=\"hidden\" name=\"project_id\" value=\"$project_id\"" /tmp/tp-admin-task.html

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_task_csrf"   --data-urlencode 'title=Lead edited shared task'   --data-urlencode "project_id=$project_id"   --data-urlencode "status_id=$project_status"   --data-urlencode "assignee_user_id=$member_id"   --data-urlencode 'priority=high'   "$BASE_URL/tasks/$task_id")
test "$code" = "302"
test "$(db "SELECT title FROM tasks WHERE id=$task_id")" = "Lead edited shared task"
test "$(db "SELECT created_by FROM tasks WHERE id=$task_id")" = "$member_id"
test "$(db "SELECT assignee_user_id FROM tasks WHERE id=$task_id")" = "$member_id"

# Lead uploads an attachment to the member-authored task; DB keeps task author identity.
printf 'shared attachment\n' > /tmp/tp-shared.txt
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   -F "_csrf=$admin_task_csrf"   -F "attachments[]=@/tmp/tp-shared.txt;type=text/plain"   "$BASE_URL/tasks/$task_id/attachments")
test "$code" = "302"
attachment_id=$(db "SELECT id FROM attachments WHERE task_id=$task_id AND original_name='tp-shared.txt' LIMIT 1")
test -n "$attachment_id"
test "$(db "SELECT user_id FROM attachments WHERE id=$attachment_id")" = "$member_id"

curl --fail --silent --cookie "$MEMBER_COOKIES"   "$BASE_URL/tasks/$task_id/attachments/$attachment_id" > /tmp/tp-member-downloaded.txt
cmp /tmp/tp-shared.txt /tmp/tp-member-downloaded.txt

# A project with tasks and its owning team cannot be deleted.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_id/settings" > /tmp/tp-admin-project.html
admin_project_csrf=$(csrf_from /tmp/tp-admin-project.html)
code=$(curl --silent -o /tmp/tp-project-delete-blocked.html -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_project_csrf"   "$BASE_URL/projects/$project_id/delete")
test "$code" = "409"
test "$(db "SELECT COUNT(*) FROM projects WHERE id=$project_id")" = "1"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams/$team_id/settings" > /tmp/tp-admin-team.html
team_csrf=$(csrf_from /tmp/tp-admin-team.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$team_csrf"   "$BASE_URL/teams/$team_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM teams WHERE id=$team_id")" = "1"

# Removing membership immediately revokes project/task access.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams/$team_id/members" > /tmp/tp-admin-members.html
team_csrf=$(csrf_from /tmp/tp-admin-members.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$team_csrf"   "$BASE_URL/teams/$team_id/members/$member_id/remove")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM team_members WHERE team_id=$team_id AND user_id=$member_id")" = "0"
test "$(db "SELECT assignee_user_id IS NULL FROM tasks WHERE id=$task_id")" = "1"

code=$(curl --silent -o /tmp/tp-member-no-project.txt -w '%{http_code}'   --cookie "$MEMBER_COOKIES" "$BASE_URL/projects/$project_id")
test "$code" = "404"
code=$(curl --silent -o /tmp/tp-member-no-task.txt -w '%{http_code}'   --cookie "$MEMBER_COOKIES" "$BASE_URL/tasks/$task_id/edit")
test "$code" = "404"

# Lead still sees and can remove the member-authored task and physical attachment.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/tasks/$task_id/edit" > /tmp/tp-admin-task-final.html
admin_task_csrf=$(csrf_from /tmp/tp-admin-task-final.html)
storage_name=$(db "SELECT storage_name FROM attachments WHERE id=$attachment_id")
test -n "$storage_name"
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_task_csrf"   "$BASE_URL/tasks/$task_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM tasks WHERE id=$task_id")" = "0"
if docker compose exec -T app test -f "$STORAGE_ROOT/${storage_name:0:2}/$storage_name"; then
  echo 'Collaborative task deletion left attachment content on disk.' >&2
  exit 1
fi

# Empty team project can now be deleted, then the team can be deleted.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_id/settings" > /tmp/tp-admin-project-final.html
admin_project_csrf=$(csrf_from /tmp/tp-admin-project-final.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_project_csrf"   "$BASE_URL/projects/$project_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM projects WHERE id=$project_id")" = "0"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams/$team_id/settings" > /tmp/tp-admin-team-final.html
team_csrf=$(csrf_from /tmp/tp-admin-team-final.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$team_csrf"   "$BASE_URL/teams/$team_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM teams WHERE id=$team_id")" = "0"
