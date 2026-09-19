#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/custom-fields" > /tmp/pcf-personal.html
csrf=$(grep -m1 -o 'name="_csrf" value="[^"]*"' /tmp/pcf-personal.html | sed 's/.*value="//;s/"$//')
test -n "$csrf"
admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'name=Lineage smoke field' \
  --data-urlencode 'field_type=text' \
  "$BASE_URL/custom-fields")
test "$code" = "302"
personal_field=$(db "SELECT id FROM custom_fields WHERE user_id=$admin_id AND project_id IS NULL AND name='Lineage smoke field' LIMIT 1")
test -n "$personal_field"

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'name=Project Field Smoke' \
  --data-urlencode 'description=project field smoke' \
  --data-urlencode 'lifecycle_status=active' \
  "$BASE_URL/projects")
test "$code" = "302"

project_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='Project Field Smoke' LIMIT 1")
test -n "$project_id"
project_field=$(db "SELECT id FROM custom_fields WHERE project_id=$project_id AND source_field_id=$personal_field LIMIT 1")
test -n "$project_field"
test "$(db "SELECT field_type FROM custom_fields WHERE id=$project_field")" = "text"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id/fields" > /tmp/pcf-project.html
grep -q 'Project custom fields' /tmp/pcf-project.html
grep -q 'Lineage smoke field' /tmp/pcf-project.html

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'name=Acceptance' \
  --data-urlencode 'field_type=text' \
  --data-urlencode 'is_required=1' \
  "$BASE_URL/projects/$project_id/fields")
test "$code" = "302"
project_only_field=$(db "SELECT id FROM custom_fields WHERE project_id=$project_id AND name='Acceptance' LIMIT 1")
test -n "$project_only_field"
test "$(db "SELECT source_field_id IS NULL FROM custom_fields WHERE id=$project_only_field")" = "1"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/new?project_id=$project_id" > /tmp/pcf-task-form.html
grep -q "custom_fields\[$project_field\]" /tmp/pcf-task-form.html
grep -q "custom_fields\[$project_only_field\]" /tmp/pcf-task-form.html
if grep -q "custom_fields\[$personal_field\]" /tmp/pcf-task-form.html; then
  echo 'Project task form leaked the personal source field.' >&2
  exit 1
fi

project_status=$(db "SELECT id FROM statuses WHERE project_id=$project_id AND is_default=1 LIMIT 1")
test -n "$project_status"
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'title=Project field task' \
  --data-urlencode "project_id=$project_id" \
  --data-urlencode "status_id=$project_status" \
  --data-urlencode 'priority=medium' \
  --data-urlencode "custom_fields[$project_field]=LINEAGE-VALUE" \
  --data-urlencode "custom_fields[$project_only_field]=accepted" \
  "$BASE_URL/tasks")
test "$code" = "302"

task_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Project field task' LIMIT 1")
test -n "$task_id"
test "$(db "SELECT value FROM task_custom_field_values WHERE task_id=$task_id AND field_id=$project_field")" = "LINEAGE-VALUE"
test "$(db "SELECT value FROM task_custom_field_values WHERE task_id=$task_id AND field_id=$project_only_field")" = "accepted"
test "$(db "SELECT COUNT(*) FROM task_custom_field_values WHERE task_id=$task_id AND field_id=$personal_field")" = "0"

curl --fail --silent --cookie "$COOKIE_JAR" \
  "$BASE_URL/api/task-custom-fields?project_id=$project_id&task_id=$task_id" > /tmp/pcf-fragment.html
grep -q 'LINEAGE-VALUE' /tmp/pcf-fragment.html
grep -q 'Acceptance' /tmp/pcf-fragment.html

curl --fail --silent --get --cookie "$COOKIE_JAR" \
  --data-urlencode "project=$project_id" \
  "$BASE_URL/tasks" > /tmp/pcf-filtered-list.html
grep -q "custom\[$project_field\]" /tmp/pcf-filtered-list.html
if grep -q "custom\[$personal_field\]" /tmp/pcf-filtered-list.html; then
  echo 'Project-filtered task list used personal custom field controls.' >&2
  exit 1
fi

# Deleting the project preserves a lineage-backed value on its personal source,
# while project-only values disappear with the project field.
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" "$BASE_URL/projects/$project_id/delete")
test "$code" = "302"
test "$(db "SELECT project_id IS NULL FROM tasks WHERE id=$task_id")" = "1"
test "$(db "SELECT value FROM task_custom_field_values WHERE task_id=$task_id AND field_id=$personal_field")" = "LINEAGE-VALUE"
test "$(db "SELECT COUNT(*) FROM task_custom_field_values WHERE task_id=$task_id AND field_id=$project_only_field")" = "0"

# Keep this smoke self-contained so the general custom-field workflow starts
# from its expected clean personal field set.
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" "$BASE_URL/tasks/$task_id/delete")
test "$code" = "302"
code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" "$BASE_URL/custom-fields/$personal_field/delete")
test "$code" = "302"
test "$(db "SELECT COUNT(*) FROM custom_fields WHERE id=$personal_field")" = "0"
