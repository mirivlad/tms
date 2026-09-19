#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
ADMIN_COOKIES=/tmp/tms-cookies
MEMBER_COOKIES=/tmp/tms-discussion-member-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$1" | head -n1
}

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
test -n "$admin_id"

member_hash=$(docker compose exec -T app php -r 'echo password_hash("discussion-member-password", PASSWORD_DEFAULT);')
member_hash_sql=$(printf '%s' "$member_hash" | sed "s/'/''/g")
db "INSERT INTO users (
      username, email, password_hash, role, is_active, email_verified_at, approved_at
    ) VALUES (
      'discussionmember', 'discussionmember@example.invalid', '$member_hash_sql',
      'user', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )"
member_id=$(db "SELECT id FROM users WHERE username='discussionmember' LIMIT 1")
test -n "$member_id"

# Lead creates a team and member joins directly for an isolated discussion smoke.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams" > /tmp/discussion-teams.html
admin_csrf=$(csrf_from /tmp/discussion-teams.html)
test -n "$admin_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_csrf"   --data-urlencode 'name=CI Discussion Team'   --data-urlencode 'description=Discussion smoke'   "$BASE_URL/teams")
test "$code" = "302"
team_id=$(db "SELECT id FROM teams WHERE name='CI Discussion Team' AND created_by=$admin_id LIMIT 1")
test -n "$team_id"
db "INSERT INTO team_members (team_id,user_id,role,joined_at)
    VALUES ($team_id,$member_id,'member',UTC_TIMESTAMP())"

# Create team project and one task.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects?owner=team:$team_id" > /tmp/discussion-projects.html
project_csrf=$(csrf_from /tmp/discussion-projects.html)
test -n "$project_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   --data-urlencode "owner_scope=team:$team_id"   --data-urlencode 'name=CI Discussion Project'   --data-urlencode 'description=Shared discussion'   --data-urlencode 'lifecycle_status=active'   "$BASE_URL/projects")
test "$code" = "302"
project_id=$(db "SELECT id FROM projects WHERE owner_team_id=$team_id AND name='CI Discussion Project' LIMIT 1")
test -n "$project_id"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_id" > /tmp/discussion-project-admin.html
project_csrf=$(csrf_from /tmp/discussion-project-admin.html)
test -n "$project_csrf"
grep -q 'id="discussion"' /tmp/discussion-project-admin.html

response=$(curl --fail --silent -H 'Accept: application/json'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   --data-urlencode 'title=Discussion task'   --data-urlencode "project_id=$project_id"   "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
task_id=$(db "SELECT id FROM tasks WHERE project_id=$project_id AND title='Discussion task' LIMIT 1")
test -n "$task_id"

# Personal projects do not expose discussions.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   --data-urlencode 'owner_scope=personal'   --data-urlencode 'name=CI Personal No Discussion'   --data-urlencode 'lifecycle_status=active'   "$BASE_URL/projects")
test "$code" = "302"
personal_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='CI Personal No Discussion' LIMIT 1")
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$personal_id" > /tmp/discussion-personal.html
if grep -q 'id="discussion"' /tmp/discussion-personal.html; then
  echo 'Personal project unexpectedly exposed discussion UI.' >&2
  exit 1
fi

# Independent member session.
curl --fail --silent --cookie-jar "$MEMBER_COOKIES" "$BASE_URL/login" > /tmp/discussion-login.html
login_csrf=$(csrf_from /tmp/discussion-login.html)
test -n "$login_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES" --cookie-jar "$MEMBER_COOKIES"   --data-urlencode "_csrf=$login_csrf"   --data-urlencode 'username=discussionmember'   --data-urlencode 'password=discussion-member-password'   "$BASE_URL/login")
test "$code" = "302"

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/projects/$project_id" > /tmp/discussion-project-member.html
member_csrf=$(csrf_from /tmp/discussion-project-member.html)
test -n "$member_csrf"
grep -q 'id="discussion"' /tmp/discussion-project-member.html

# Member posts sanitized project comment.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   --data-urlencode 'body=<p>Hello <script>alert(1)</script><strong>team</strong></p>'   "$BASE_URL/projects/$project_id/discussion")
test "$code" = "302"
root_id=$(db "SELECT id FROM discussion_comments
              WHERE project_id=$project_id AND task_id IS NULL AND author_user_id=$member_id
              ORDER BY id DESC LIMIT 1")
test -n "$root_id"
root_body=$(db "SELECT body_html FROM discussion_comments WHERE id=$root_id")
printf '%s' "$root_body" | grep -q '<strong>team</strong>'
if printf '%s' "$root_body" | grep -qi '<script'; then
  echo 'Discussion sanitizer preserved script content.' >&2
  exit 1
fi

# Lead replies; reply-to-reply is rejected.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   --data-urlencode 'body=<p>Lead reply</p>'   "$BASE_URL/projects/$project_id/discussion/$root_id/reply")
test "$code" = "302"
reply_id=$(db "SELECT id FROM discussion_comments WHERE parent_comment_id=$root_id ORDER BY id DESC LIMIT 1")
test -n "$reply_id"

before=$(db "SELECT COUNT(*) FROM discussion_comments WHERE project_id=$project_id")
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   --data-urlencode 'body=<p>Too deep</p>'   "$BASE_URL/projects/$project_id/discussion/$reply_id/reply")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE project_id=$project_id")" = "$before"

# Member edits own root but cannot delete Lead reply.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   --data-urlencode 'body=<p>Edited root</p>'   "$BASE_URL/projects/$project_id/discussion/$root_id")
test "$code" = "302"
grep -q 'Edited root' < <(db "SELECT body_html FROM discussion_comments WHERE id=$root_id")

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   "$BASE_URL/projects/$project_id/discussion/$reply_id/delete")
test "$code" = "302"
test "$(db "SELECT deleted_at IS NULL FROM discussion_comments WHERE id=$reply_id")" = "1"

# Lead soft-deletes member root; reply survives and UI shows both placeholder and reply.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   "$BASE_URL/projects/$project_id/discussion/$root_id/delete")
test "$code" = "302"
test "$(db "SELECT deleted_at IS NOT NULL FROM discussion_comments WHERE id=$root_id")" = "1"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE id=$reply_id")" = "1"

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/projects/$project_id#discussion" > /tmp/discussion-thread.html
grep -q 'Comment deleted' /tmp/discussion-thread.html
grep -q 'Lead reply' /tmp/discussion-thread.html

# Task discussion has the same team ACL.
curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/tasks/$task_id/edit" > /tmp/discussion-task.html
task_csrf=$(csrf_from /tmp/discussion-task.html)
test -n "$task_csrf"
grep -q 'id="discussion"' /tmp/discussion-task.html

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$task_csrf"   --data-urlencode 'body=<p>Task-specific context</p>'   "$BASE_URL/tasks/$task_id/discussion")
test "$code" = "302"
task_comment=$(db "SELECT id FROM discussion_comments
                   WHERE task_id=$task_id AND project_id IS NULL
                   ORDER BY id DESC LIMIT 1")
test -n "$task_comment"

# Removing membership revokes discussion access immediately.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams/$team_id" > /tmp/discussion-team-admin.html
team_csrf=$(csrf_from /tmp/discussion-team-admin.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$team_csrf"   "$BASE_URL/teams/$team_id/members/$member_id/remove")
test "$code" = "302"

before=$(db "SELECT COUNT(*) FROM discussion_comments WHERE task_id=$task_id")
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$task_csrf"   --data-urlencode 'body=<p>Must not be added</p>'   "$BASE_URL/tasks/$task_id/discussion")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE task_id=$task_id")" = "$before"
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$MEMBER_COOKIES" "$BASE_URL/tasks/$task_id/edit")
test "$code" = "404"

# Context deletion cascades hard cleanup of discussion rows.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/tasks/$task_id/edit" > /tmp/discussion-task-admin.html
task_admin_csrf=$(csrf_from /tmp/discussion-task-admin.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$task_admin_csrf"   "$BASE_URL/tasks/$task_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE task_id=$task_id")" = "0"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_id" > /tmp/discussion-project-final.html
project_csrf=$(csrf_from /tmp/discussion-project-final.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   "$BASE_URL/projects/$project_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE project_id=$project_id")" = "0"
