#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
ADMIN_COOKIES=/tmp/tms-cookies
MEMBER_COOKIES=/tmp/tms-discussion-member-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  grep -m1 -o 'name="_csrf" value="[^"]*"' "$1" | sed 's/.*value="//;s/"$//'
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

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_id/discussion" > /tmp/discussion-project-admin.html
project_csrf=$(csrf_from /tmp/discussion-project-admin.html)
test -n "$project_csrf"
grep -q 'id="discussion"' /tmp/discussion-project-admin.html
grep -q 'class="discussion-workspace project-discussion-workspace"' /tmp/discussion-project-admin.html
grep -q 'class="project-task-discussion-rail"' /tmp/discussion-project-admin.html
grep -q 'class="discussion-composer discussion-editor"' /tmp/discussion-project-admin.html
grep -q 'src="/assets/discussions.js"' /tmp/discussion-project-admin.html
grep -q 'data-discussion-command="bold"' /tmp/discussion-project-admin.html
grep -q 'data-discussion-command="insertOrderedList"' /tmp/discussion-project-admin.html

response=$(curl --fail --silent -H 'Accept: application/json'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   --data-urlencode 'title=Discussion task'   --data-urlencode "project_id=$project_id"   "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
task_id=$(db "SELECT id FROM tasks WHERE project_id=$project_id AND title='Discussion task' LIMIT 1")
test -n "$task_id"

# Personal projects do not expose discussions.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   --data-urlencode 'owner_scope=personal'   --data-urlencode 'name=CI Personal No Discussion'   --data-urlencode 'lifecycle_status=active'   "$BASE_URL/projects")
test "$code" = "302"
personal_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='CI Personal No Discussion' LIMIT 1")
test "$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$personal_id/discussion")" = "404"

# Independent member session.
curl --fail --silent --cookie-jar "$MEMBER_COOKIES" "$BASE_URL/login" > /tmp/discussion-login.html
login_csrf=$(csrf_from /tmp/discussion-login.html)
test -n "$login_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES" --cookie-jar "$MEMBER_COOKIES"   --data-urlencode "_csrf=$login_csrf"   --data-urlencode 'username=discussionmember'   --data-urlencode 'password=discussion-member-password'   "$BASE_URL/login")
test "$code" = "302"

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/projects/$project_id/discussion" > /tmp/discussion-project-member.html
member_csrf=$(csrf_from /tmp/discussion-project-member.html)
test -n "$member_csrf"
grep -q 'id="discussion"' /tmp/discussion-project-member.html

# Member posts sanitized project comment.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   --data-urlencode 'body=<p>Hello @ciadmin <script>alert(1)</script><strong>team</strong></p>'   "$BASE_URL/projects/$project_id/discussion")
test "$code" = "302"
root_id=$(db "SELECT id FROM discussion_comments
              WHERE project_id=$project_id AND task_id IS NULL AND author_user_id=$member_id
              ORDER BY id DESC LIMIT 1")
test -n "$root_id"
test "$(db "SELECT COUNT(*) FROM domain_events WHERE comment_id=$root_id AND event_type='discussion.comment.created'")" = "1"
test "$(db "SELECT visibility_team_id FROM domain_events WHERE comment_id=$root_id AND event_type='discussion.comment.created' LIMIT 1")" = "$team_id"
root_body=$(db "SELECT body_html FROM discussion_comments WHERE id=$root_id")
printf '%s' "$root_body" | grep -q '<strong>team</strong>'
if printf '%s' "$root_body" | grep -qi '<script'; then
  echo 'Discussion sanitizer preserved script content.' >&2
  exit 1
fi

# Mention creates one canonical unread inbox item for the current team member.
mention_notification=$(db "SELECT id FROM internal_notifications
                           WHERE user_id=$admin_id
                             AND comment_id=$root_id
                             AND notification_type='discussion_mention'
                           LIMIT 1")
test -n "$mention_notification"
test "$(db "SELECT read_at IS NULL FROM internal_notifications WHERE id=$mention_notification")" = "1"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/notifications" > /tmp/discussion-admin-inbox.html
grep -q 'notification-inbox-item is-unread' /tmp/discussion-admin-inbox.html
grep -q 'discussionmember' /tmp/discussion-admin-inbox.html

code=$(curl --silent -D /tmp/discussion-open.headers -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   "$BASE_URL/notifications/$mention_notification/open")
test "$code" = "302"
grep -qi "location: /projects/$project_id/discussion#comment-$root_id" /tmp/discussion-open.headers
test "$(db "SELECT read_at IS NOT NULL FROM internal_notifications WHERE id=$mention_notification")" = "1"

# Lead replies; reply-to-reply is rejected.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   --data-urlencode 'body=<p>@discussionmember Lead reply</p>'   "$BASE_URL/projects/$project_id/discussion/$root_id/reply")
test "$code" = "302"
reply_id=$(db "SELECT id FROM discussion_comments WHERE parent_comment_id=$root_id ORDER BY id DESC LIMIT 1")
test -n "$reply_id"

# Reply notification wins over duplicate mention to the same parent author.
test "$(db "SELECT COUNT(*) FROM internal_notifications
            WHERE user_id=$member_id AND comment_id=$reply_id")" = "1"
test "$(db "SELECT notification_type FROM internal_notifications
            WHERE user_id=$member_id AND comment_id=$reply_id LIMIT 1")" = "discussion_reply"
reply_notification=$(db "SELECT id FROM internal_notifications
                         WHERE user_id=$member_id AND comment_id=$reply_id
                         LIMIT 1")
test -n "$reply_notification"
curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/notifications" > /tmp/discussion-member-inbox.html
grep -q 'ciadmin' /tmp/discussion-member-inbox.html

before=$(db "SELECT COUNT(*) FROM discussion_comments WHERE project_id=$project_id")
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   --data-urlencode 'body=<p>Too deep</p>'   "$BASE_URL/projects/$project_id/discussion/$reply_id/reply")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE project_id=$project_id")" = "$before"

# Member edits own root but cannot delete Lead reply.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   --data-urlencode 'body=<p>Edited root @ciadmin</p>'   "$BASE_URL/projects/$project_id/discussion/$root_id")
test "$code" = "302"
grep -q 'Edited root' < <(db "SELECT body_html FROM discussion_comments WHERE id=$root_id")
test "$(db "SELECT COUNT(*) FROM domain_events WHERE comment_id=$root_id AND event_type='discussion.comment.updated'")" = "1"
test "$(db "SELECT COUNT(*) FROM internal_notifications
            WHERE user_id=$admin_id AND comment_id=$root_id
              AND notification_type='discussion_mention'")" = "1"

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$member_csrf"   "$BASE_URL/projects/$project_id/discussion/$reply_id/delete")
test "$code" = "302"
test "$(db "SELECT deleted_at IS NULL FROM discussion_comments WHERE id=$reply_id")" = "1"

# Lead soft-deletes member root; reply survives and UI shows both placeholder and reply.
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   "$BASE_URL/projects/$project_id/discussion/$root_id/delete")
test "$code" = "302"
test "$(db "SELECT deleted_at IS NOT NULL FROM discussion_comments WHERE id=$root_id")" = "1"
test "$(db "SELECT COUNT(*) FROM domain_events WHERE comment_id=$root_id AND event_type='discussion.comment.deleted'")" = "1"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE id=$reply_id")" = "1"

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/projects/$project_id/discussion" > /tmp/discussion-thread.html
grep -q 'Comment deleted' /tmp/discussion-thread.html
grep -q 'Lead reply' /tmp/discussion-thread.html

# Task discussion is a standalone workspace, not part of task editing.
curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/tasks/$task_id/edit" > /tmp/discussion-task-edit.html
grep -q "href=\"/tasks/$task_id/discussion\"" /tmp/discussion-task-edit.html
if grep -q 'id="discussion"' /tmp/discussion-task-edit.html; then
  echo 'Task edit unexpectedly embeds the discussion thread.' >&2
  exit 1
fi

curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/tasks/$task_id/discussion" > /tmp/discussion-task.html
task_csrf=$(csrf_from /tmp/discussion-task.html)
test -n "$task_csrf"
grep -q 'id="discussion"' /tmp/discussion-task.html
grep -q 'class="discussion-workspace task-discussion-workspace"' /tmp/discussion-task.html
grep -q 'class="discussion-composer discussion-editor"' /tmp/discussion-task.html
grep -q 'src="/assets/discussions.js"' /tmp/discussion-task.html
grep -q 'data-discussion-command="bold"' /tmp/discussion-task.html
grep -q 'data-discussion-command="insertOrderedList"' /tmp/discussion-task.html

code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$task_csrf"   --data-urlencode 'body=<p>Task-specific context @ciadmin</p>'   "$BASE_URL/tasks/$task_id/discussion")
test "$code" = "302"
task_comment=$(db "SELECT id FROM discussion_comments
                   WHERE task_id=$task_id AND project_id IS NULL
                   ORDER BY id DESC LIMIT 1")
test -n "$task_comment"
test "$(db "SELECT COUNT(*) FROM domain_events WHERE comment_id=$task_comment AND task_id=$task_id AND event_type='discussion.comment.created'")" = "1"
test "$(db "SELECT project_id FROM domain_events WHERE comment_id=$task_comment AND event_type='discussion.comment.created' LIMIT 1")" = "$project_id"
test "$(db "SELECT COUNT(*) FROM internal_notifications
            WHERE user_id=$admin_id AND comment_id=$task_comment
              AND notification_type='discussion_mention'")" = "1"

# A new comment from another team member is unread until the discussion is opened.
curl --fail --silent --get --cookie "$ADMIN_COOKIES" --data-urlencode "project=$project_id" "$BASE_URL/tasks" > /tmp/discussion-task-list-unread.html
grep -q "href=\"/tasks/$task_id/discussion\"" /tmp/discussion-task-list-unread.html
grep -q 'discussion-unread-badge' /tmp/discussion-task-list-unread.html

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/tasks/$task_id/discussion" > /tmp/discussion-task-admin-thread.html
grep -q 'Task-specific context' /tmp/discussion-task-admin-thread.html
grep -q 'data-discussion-command="bold"' /tmp/discussion-task-admin-thread.html
test "$(db "SELECT COUNT(*) FROM discussion_read_markers
            WHERE user_id=$admin_id AND team_id=$team_id
              AND context_type='task' AND context_id=$task_id
              AND last_read_comment_id >= $task_comment")" = "1"

# Project discussion aggregates task threads by task.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_id/discussion" > /tmp/discussion-project-aggregate.html
grep -q 'Discussion task' /tmp/discussion-project-aggregate.html
grep -q 'Task-specific context' /tmp/discussion-project-aggregate.html
grep -q "href=\"/tasks/$task_id/discussion\"" /tmp/discussion-project-aggregate.html

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/notifications" > /tmp/discussion-admin-inbox-final.html
admin_inbox_csrf=$(csrf_from /tmp/discussion-admin-inbox-final.html)
test -n "$admin_inbox_csrf"
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$admin_inbox_csrf"   "$BASE_URL/notifications/read-all")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM internal_notifications WHERE user_id=$admin_id AND read_at IS NULL")" = "0"

# Removing membership revokes discussion access immediately.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/teams/$team_id/members" > /tmp/discussion-team-admin.html
team_csrf=$(csrf_from /tmp/discussion-team-admin.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$team_csrf"   "$BASE_URL/teams/$team_id/members/$member_id/remove")
test "$code" = "302"

before=$(db "SELECT COUNT(*) FROM discussion_comments WHERE task_id=$task_id")
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   --data-urlencode "_csrf=$task_csrf"   --data-urlencode 'body=<p>Must not be added</p>'   "$BASE_URL/tasks/$task_id/discussion")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE task_id=$task_id")" = "$before"
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$MEMBER_COOKIES" "$BASE_URL/tasks/$task_id/edit")
test "$code" = "404"

# Historical inbox entry survives membership loss, but opening it no longer
# bounces the user into a forbidden/404 work context.
code=$(curl --silent -D /tmp/discussion-stale-open.headers -o /dev/null -w '%{http_code}'   --cookie "$MEMBER_COOKIES"   "$BASE_URL/notifications/$reply_notification/open")
test "$code" = "302"
grep -qi 'location: /notifications' /tmp/discussion-stale-open.headers
test "$(db "SELECT read_at IS NOT NULL FROM internal_notifications WHERE id=$reply_notification")" = "1"
curl --fail --silent --cookie "$MEMBER_COOKIES" "$BASE_URL/notifications" > /tmp/discussion-stale-inbox.html
grep -q 'That work context is no longer available to you.' /tmp/discussion-stale-inbox.html

# Context deletion cascades hard cleanup of discussion rows.
curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/tasks/$task_id/edit" > /tmp/discussion-task-admin.html
task_admin_csrf=$(csrf_from /tmp/discussion-task-admin.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$task_admin_csrf"   "$BASE_URL/tasks/$task_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE task_id=$task_id")" = "0"

curl --fail --silent --cookie "$ADMIN_COOKIES" "$BASE_URL/projects/$project_id/settings" > /tmp/discussion-project-final.html
project_csrf=$(csrf_from /tmp/discussion-project-final.html)
code=$(curl --silent -o /dev/null -w '%{http_code}'   --cookie "$ADMIN_COOKIES"   --data-urlencode "_csrf=$project_csrf"   "$BASE_URL/projects/$project_id/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM discussion_comments WHERE project_id=$project_id")" = "0"
