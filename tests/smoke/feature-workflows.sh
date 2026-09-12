#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
admin_cookies=/tmp/tms-cookies
user_cookies=/tmp/tms-feature-user-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$1" | head -n1
}

# Public feature/help pages remain available without authentication.
curl --fail --silent "$base_url/first_steps" > /tmp/tms-first-steps.html
grep -q 'TMS' /tmp/tms-first-steps.html
curl --fail --silent "$base_url/privacy" > /tmp/tms-privacy.html
grep -q 'TMS' /tmp/tms-privacy.html

# Optional registration creates an isolated user with defaults but does not bypass manual approval.
curl --fail --silent --cookie-jar "$user_cookies" "$base_url/register" > /tmp/tms-register.html
register_csrf=$(csrf_from /tmp/tms-register.html)
test -n "$register_csrf"
register_status=$(curl --silent --output /tmp/tms-register-post.html --write-out '%{http_code}' \
  --cookie "$user_cookies" --cookie-jar "$user_cookies" \
  --data-urlencode "_csrf=$register_csrf" \
  --data-urlencode 'username=featureuser' \
  --data-urlencode 'email=featureuser@example.invalid' \
  --data-urlencode 'password=feature-user-password-12345' \
  --data-urlencode 'password_confirm=feature-user-password-12345' \
  "$base_url/register")
test "$register_status" = "302"
user_id=$(db "SELECT id FROM users WHERE username='featureuser' LIMIT 1")
test -n "$user_id"
test "$(db "SELECT COUNT(*) FROM statuses WHERE user_id=$user_id")" = "3"
test "$(db "SELECT COUNT(*) FROM task_types WHERE user_id=$user_id")" = "1"
test "$(db "SELECT COUNT(*) FROM email_verification_tokens WHERE user_id=$user_id")" = "1"
test "$(db "SELECT COUNT(*) FROM users WHERE id=$user_id AND email_verified_at IS NULL AND approved_at IS NULL")" = "1"

# Administrator can inspect pending users, verify email and approve an account.
curl --fail --silent --cookie "$admin_cookies" "$base_url/admin/pending-users" > /tmp/tms-pending.html
grep -q 'featureuser' /tmp/tms-pending.html
admin_csrf=$(csrf_from /tmp/tms-pending.html)
test -n "$admin_csrf"
verify_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$admin_cookies" \
  --data-urlencode "_csrf=$admin_csrf" \
  --data-urlencode 'return_to=/admin/pending-users' \
  "$base_url/admin/users/$user_id/verify-email")
test "$verify_status" = "302"
test "$(db "SELECT COUNT(*) FROM email_verification_tokens WHERE user_id=$user_id")" = "0"
approve_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$admin_cookies" \
  --data-urlencode "_csrf=$admin_csrf" \
  --data-urlencode 'return_to=/admin/pending-users' \
  "$base_url/admin/users/$user_id/approve")
test "$approve_status" = "302"
test "$(db "SELECT COUNT(*) FROM users WHERE id=$user_id AND email_verified_at IS NOT NULL AND approved_at IS NOT NULL")" = "1"

# The approved user can sign in and update real profile preferences/password.
rm -f "$user_cookies"
curl --fail --silent --cookie-jar "$user_cookies" "$base_url/login" > /tmp/tms-feature-login.html
login_csrf=$(csrf_from /tmp/tms-feature-login.html)
test -n "$login_csrf"
login_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$user_cookies" --cookie-jar "$user_cookies" \
  --data-urlencode "_csrf=$login_csrf" \
  --data-urlencode 'username=featureuser' \
  --data-urlencode 'password=feature-user-password-12345' \
  "$base_url/login")
test "$login_status" = "302"
curl --fail --silent --cookie "$user_cookies" "$base_url/settings/profile" > /tmp/tms-profile.html
profile_csrf=$(csrf_from /tmp/tms-profile.html)
test -n "$profile_csrf"
grep -q '/assets/profile.js' /tmp/tms-profile.html
profile_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$user_cookies" --cookie-jar "$user_cookies" \
  --data-urlencode "_csrf=$profile_csrf" \
  --data-urlencode 'username=featureuser2' \
  --data-urlencode 'timezone=Europe/Helsinki' \
  --data-urlencode 'theme=light' \
  --data-urlencode 'current_password=feature-user-password-12345' \
  --data-urlencode 'new_password=feature-user-password-67890' \
  --data-urlencode 'confirm_new_password=feature-user-password-67890' \
  "$base_url/settings/profile")
test "$profile_status" = "302"
test "$(db "SELECT username FROM users WHERE id=$user_id")" = "featureuser2"
test "$(db "SELECT CONCAT(timezone,'|',theme) FROM user_preferences WHERE user_id=$user_id")" = "Europe/Helsinki|light"
curl --fail --silent --cookie "$user_cookies" "$base_url/dashboard" > /tmp/tms-feature-dashboard.html
grep -q 'class="theme-light"' /tmp/tms-feature-dashboard.html
grep -q 'featureuser2' /tmp/tms-feature-dashboard.html

# Global quick-add exists away from the dashboard and creates tasks through JSON.
curl --fail --silent --cookie "$user_cookies" "$base_url/tasks" > /tmp/tms-feature-tasks.html
grep -q 'data-quick-add-trigger' /tmp/tms-feature-tasks.html
grep -q 'id="quick-add-dialog"' /tmp/tms-feature-tasks.html
grep -q '/assets/task-preview.js' /tmp/tms-feature-tasks.html
task_csrf=$(csrf_from /tmp/tms-feature-tasks.html)
test -n "$task_csrf"
for title in 'Feature bulk alpha' 'Feature bulk beta'; do
  response=$(curl --fail --silent --header 'Accept: application/json' \
    --cookie "$user_cookies" \
    --data-urlencode "_csrf=$task_csrf" \
    --data-urlencode "title=$title" \
    --data-urlencode 'description=feature workflow smoke' \
    "$base_url/tasks/quick-add")
  printf '%s' "$response" | grep -q '"success":true'
done
alpha_id=$(db "SELECT id FROM tasks WHERE created_by=$user_id AND title='Feature bulk alpha' LIMIT 1")
beta_id=$(db "SELECT id FROM tasks WHERE created_by=$user_id AND title='Feature bulk beta' LIMIT 1")
test -n "$alpha_id"
test -n "$beta_id"
grep -q 'data-task-preview-delete-form' /tmp/tms-feature-tasks.html
grep -q 'data-task-preview-attachments' /tmp/tms-feature-tasks.html

# Quick view exposes owner-scoped attachments and the task delete action.
printf 'preview attachment content\n' > /tmp/tms-feature-preview.txt
upload_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$user_cookies" \
  -F "_csrf=$task_csrf" \
  -F 'attachments[]=@/tmp/tms-feature-preview.txt;filename=preview.txt;type=text/plain' \
  "$base_url/tasks/$alpha_id/attachments")
test "$upload_status" = "302"
preview_attachment_id=$(db "SELECT id FROM attachments WHERE task_id=$alpha_id AND user_id=$user_id AND original_name='preview.txt' LIMIT 1")
preview_storage=$(db "SELECT storage_name FROM attachments WHERE id=$preview_attachment_id")
test -n "$preview_attachment_id"
test -n "$preview_storage"
preview_storage_path="/var/www/html/var/storage/attachments/${preview_storage:0:2}/$preview_storage"
docker compose exec -T app test -f "$preview_storage_path"

# Quick view is owner-scoped.
curl --fail --silent --cookie "$user_cookies" "$base_url/api/tasks/$alpha_id" > /tmp/tms-task-preview.json
grep -q 'Feature bulk alpha' /tmp/tms-task-preview.json
grep -q '"name":"preview.txt"' /tmp/tms-task-preview.json
grep -q "/tasks/$alpha_id/attachments/$preview_attachment_id" /tmp/tms-task-preview.json
grep -q "/tasks/$alpha_id/delete" /tmp/tms-task-preview.json
curl --fail --silent --cookie "$user_cookies" \
  "$base_url/tasks/$alpha_id/attachments/$preview_attachment_id" > /tmp/tms-feature-preview-download.txt
cmp /tmp/tms-feature-preview.txt /tmp/tms-feature-preview-download.txt
admin_task_id=$(db "SELECT id FROM tasks WHERE created_by != $user_id ORDER BY id LIMIT 1")
if [ -n "$admin_task_id" ]; then
  preview_status=$(curl --silent --output /dev/null --write-out '%{http_code}' --cookie "$user_cookies" "$base_url/api/tasks/$admin_task_id")
  test "$preview_status" = "404"
fi

# Bulk actions operate only on selected tasks owned by the current user.
bulk_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$user_cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  --data-urlencode "task_ids[]=$alpha_id" \
  --data-urlencode "task_ids[]=$beta_id" \
  --data-urlencode 'action=update_priority' \
  --data-urlencode 'priority=high' \
  "$base_url/tasks/bulk")
test "$bulk_status" = "302"
test "$(db "SELECT COUNT(*) FROM tasks WHERE id IN ($alpha_id,$beta_id) AND priority=2")" = "2"

# Calendar filters are exercised behaviorally, including multi-select, inversion and literal customer matching.
calendar_month=$(TZ='Europe/Helsinki' date +%Y-%m)
calendar_deadline="${calendar_month}-15T10:30"
default_status=$(db "SELECT id FROM statuses WHERE user_id=$user_id AND is_default=1 LIMIT 1")
test -n "$default_status"
db "INSERT INTO statuses (user_id,name,color,sort_order,show_on_board) VALUES ($user_id,'Calendar alternate','#445566',99,1)"
alternate_status=$(db "SELECT id FROM statuses WHERE user_id=$user_id AND name='Calendar alternate' LIMIT 1")
db "INSERT INTO customers (user_id,name) VALUES ($user_id,'Calendar 100% Client')"
calendar_customer=$(db "SELECT id FROM customers WHERE user_id=$user_id AND name='Calendar 100% Client' LIMIT 1")
test -n "$alternate_status"
test -n "$calendar_customer"

# Put both tasks into the current month, then make their filter dimensions distinct.
deadline_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$user_cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  --data-urlencode "task_ids[]=$alpha_id" \
  --data-urlencode "task_ids[]=$beta_id" \
  --data-urlencode 'action=update_deadline' \
  --data-urlencode 'deadline_type=custom' \
  --data-urlencode "custom_deadline=$calendar_deadline" \
  "$base_url/tasks/bulk")
test "$deadline_status" = "302"
urgent_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$user_cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  --data-urlencode "task_ids[]=$alpha_id" \
  --data-urlencode 'action=update_priority' \
  --data-urlencode 'priority=urgent' \
  "$base_url/tasks/bulk")
test "$urgent_status" = "302"
db "UPDATE tasks SET status_id=$alternate_status WHERE id=$beta_id AND created_by=$user_id"
db "UPDATE tasks SET customer_id=$calendar_customer WHERE id=$alpha_id AND created_by=$user_id"

curl --fail --silent --get --cookie "$user_cookies" \
  --data-urlencode "month=$calendar_month" \
  --data-urlencode 'mode=deadlines_only' \
  --data-urlencode 'priority[]=urgent' \
  --data-urlencode 'priority[]=high' \
  "$base_url/calendar" > /tmp/tms-feature-calendar-multi.html
grep -q 'Feature bulk alpha' /tmp/tms-feature-calendar-multi.html
grep -q 'Feature bulk beta' /tmp/tms-feature-calendar-multi.html
grep -q 'name="status_id\[\]" multiple' /tmp/tms-feature-calendar-multi.html
grep -q 'name="type_id\[\]" multiple' /tmp/tms-feature-calendar-multi.html
grep -q 'name="priority\[\]" multiple' /tmp/tms-feature-calendar-multi.html

curl --fail --silent --get --cookie "$user_cookies" \
  --data-urlencode "month=$calendar_month" \
  --data-urlencode 'mode=deadlines_only' \
  --data-urlencode 'priority[]=urgent' \
  --data-urlencode 'priority_invert=1' \
  "$base_url/calendar" > /tmp/tms-feature-calendar-priority-invert.html
grep -q 'Feature bulk beta' /tmp/tms-feature-calendar-priority-invert.html
if grep -q 'Feature bulk alpha' /tmp/tms-feature-calendar-priority-invert.html; then
  echo 'Calendar priority inversion returned the excluded priority.' >&2
  exit 1
fi

curl --fail --silent --get --cookie "$user_cookies" \
  --data-urlencode "month=$calendar_month" \
  --data-urlencode 'mode=deadlines_only' \
  --data-urlencode "status_id[]=$default_status" \
  --data-urlencode 'status_invert=1' \
  "$base_url/calendar" > /tmp/tms-feature-calendar-status-invert.html
grep -q 'Feature bulk beta' /tmp/tms-feature-calendar-status-invert.html
if grep -q 'Feature bulk alpha' /tmp/tms-feature-calendar-status-invert.html; then
  echo 'Calendar status inversion returned the excluded status.' >&2
  exit 1
fi

curl --fail --silent --get --cookie "$user_cookies" \
  --data-urlencode "month=$calendar_month" \
  --data-urlencode 'mode=deadlines_only' \
  --data-urlencode 'customer=100%' \
  "$base_url/calendar" > /tmp/tms-feature-calendar-customer.html
grep -q 'Feature bulk alpha' /tmp/tms-feature-calendar-customer.html
if grep -q 'Feature bulk beta' /tmp/tms-feature-calendar-customer.html; then
  echo 'Calendar customer filter treated % as a wildcard.' >&2
  exit 1
fi

# The delete action exposed by quick view uses the ordinary owner-scoped task deletion route
# and must clean up attachment rows and physical files as well.
preview_delete_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$user_cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  "$base_url/tasks/$alpha_id/delete")
test "$preview_delete_status" = "302"
test "$(db "SELECT COUNT(*) FROM tasks WHERE id=$alpha_id")" = "0"
test "$(db "SELECT COUNT(*) FROM attachments WHERE id=$preview_attachment_id")" = "0"
if docker compose exec -T app test -e "$preview_storage_path"; then
  echo 'Quick-view task deletion left the physical attachment behind.' >&2
  exit 1
fi

# New password is effective after logout/login.
logout_csrf=$(csrf_from /tmp/tms-feature-dashboard.html)
curl --silent --output /dev/null --cookie "$user_cookies" --data-urlencode "_csrf=$logout_csrf" "$base_url/logout"
rm -f "$user_cookies"
curl --fail --silent --cookie-jar "$user_cookies" "$base_url/login" > /tmp/tms-feature-login2.html
login2_csrf=$(csrf_from /tmp/tms-feature-login2.html)
new_login_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$user_cookies" --cookie-jar "$user_cookies" \
  --data-urlencode "_csrf=$login2_csrf" \
  --data-urlencode 'username=featureuser2' \
  --data-urlencode 'password=feature-user-password-67890' \
  "$base_url/login")
test "$new_login_status" = "302"

# Administrator impersonation is reversible and keeps the original admin identity isolated.
curl --fail --silent --cookie "$admin_cookies" "$base_url/admin/users" > /tmp/tms-admin-users-before-impersonation.html
impersonate_csrf=$(csrf_from /tmp/tms-admin-users-before-impersonation.html)
test -n "$impersonate_csrf"
impersonate_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$admin_cookies" --cookie-jar "$admin_cookies" \
  --data-urlencode "_csrf=$impersonate_csrf" \
  "$base_url/admin/users/$user_id/impersonate")
test "$impersonate_status" = "302"
curl --fail --silent --cookie "$admin_cookies" "$base_url/dashboard" > /tmp/tms-impersonated-dashboard.html
grep -q 'action="/admin/stop-impersonation"' /tmp/tms-impersonated-dashboard.html
grep -q 'featureuser2' /tmp/tms-impersonated-dashboard.html
curl --fail --silent --cookie "$admin_cookies" "$base_url/tasks" > /tmp/tms-impersonated-tasks.html
grep -q 'Feature bulk beta' /tmp/tms-impersonated-tasks.html
if grep -q 'CI urgent task' /tmp/tms-impersonated-tasks.html; then
  echo 'Impersonation leaked the administrator task scope.' >&2
  exit 1
fi
stop_csrf=$(csrf_from /tmp/tms-impersonated-dashboard.html)
test -n "$stop_csrf"
stop_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$admin_cookies" --cookie-jar "$admin_cookies" \
  --data-urlencode "_csrf=$stop_csrf" \
  "$base_url/admin/stop-impersonation")
test "$stop_status" = "302"
curl --fail --silent --cookie "$admin_cookies" "$base_url/admin/users" > /tmp/tms-admin-users-after-impersonation.html
grep -q 'ciadmin' /tmp/tms-admin-users-after-impersonation.html
