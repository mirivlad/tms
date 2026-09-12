#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
cookies=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$cookies" "$base_url/custom-fields" > /tmp/custom-fields.html
grep -q 'Custom fields' /tmp/custom-fields.html
csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/custom-fields.html | head -n1)
test -n "$csrf"

create_field() {
  local name="$1"
  local type="$2"
  local options="${3:-}"
  local required="${4:-0}"
  local args=(
    --silent --output /dev/null --write-out '%{http_code}'
    --cookie "$cookies"
    --data-urlencode "_csrf=$csrf"
    --data-urlencode "name=$name"
    --data-urlencode "field_type=$type"
    --data-urlencode "options=$options"
  )
  if [ "$required" = "1" ]; then
    args+=(--data-urlencode 'is_required=1')
  fi
  local status
  status=$(curl "${args[@]}" "$base_url/custom-fields")
  test "$status" = "302"
}

create_field 'Reference' 'text' '' 1
create_field 'Notes field' 'textarea'
create_field 'Environment' 'select' $'Prod\nStaging'
create_field 'Budget' 'money'
create_field 'Billable' 'checkbox'
create_field 'Tags' 'checkbox_list' $'red\nblue\ngreen'

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
test -n "$admin_id"
test -n "$other_id"
test "$(db "SELECT COUNT(*) FROM custom_fields WHERE user_id=$admin_id")" = "6"

reference_id=$(db "SELECT id FROM custom_fields WHERE user_id=$admin_id AND name='Reference' LIMIT 1")
notes_id=$(db "SELECT id FROM custom_fields WHERE user_id=$admin_id AND name='Notes field' LIMIT 1")
environment_id=$(db "SELECT id FROM custom_fields WHERE user_id=$admin_id AND name='Environment' LIMIT 1")
budget_id=$(db "SELECT id FROM custom_fields WHERE user_id=$admin_id AND name='Budget' LIMIT 1")
billable_id=$(db "SELECT id FROM custom_fields WHERE user_id=$admin_id AND name='Billable' LIMIT 1")
tags_id=$(db "SELECT id FROM custom_fields WHERE user_id=$admin_id AND name='Tags' LIMIT 1")
for id in "$reference_id" "$notes_id" "$environment_id" "$budget_id" "$billable_id" "$tags_id"; do test -n "$id"; done

grep -q '"Prod","Staging"' < <(db "SELECT options_json FROM custom_fields WHERE id=$environment_id")

db "INSERT INTO custom_fields (user_id,name,field_type,sort_order) VALUES ($other_id,'Foreign field','text',1)"
foreign_field=$(db "SELECT id FROM custom_fields WHERE user_id=$other_id AND name='Foreign field' LIMIT 1")
test -n "$foreign_field"

foreign_update=$(curl --silent --output /tmp/foreign-field.html --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'name=Stolen' \
  --data-urlencode 'field_type=text' \
  "$base_url/custom-fields/$foreign_field")
test "$foreign_update" = "404"
test "$(db "SELECT name FROM custom_fields WHERE id=$foreign_field")" = "Foreign field"

curl --fail --silent --cookie "$cookies" "$base_url/tasks/new" > /tmp/custom-task-form.html
grep -q "custom_fields\[$reference_id\]" /tmp/custom-task-form.html
grep -q "custom_fields\[$tags_id\]\[\]" /tmp/custom-task-form.html
task_csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/custom-task-form.html | head -n1)
default_status=$(sed -n 's/.*<option value="\([0-9][0-9]*\)" selected>.*/\1/p' /tmp/custom-task-form.html | head -n1)
test -n "$task_csrf"
test -n "$default_status"

create_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  --data-urlencode 'title=CI custom field task' \
  --data-urlencode "status_id=$default_status" \
  --data-urlencode 'priority=medium' \
  --data-urlencode "custom_fields[$reference_id]=REF-42" \
  --data-urlencode "custom_fields[$notes_id]=Line one" \
  --data-urlencode "custom_fields[$environment_id]=Prod" \
  --data-urlencode "custom_fields[$budget_id]=1 200,5" \
  --data-urlencode "custom_fields[$billable_id]=1" \
  --data-urlencode "custom_fields[$tags_id][]=red" \
  --data-urlencode "custom_fields[$tags_id][]=blue" \
  "$base_url/tasks")
test "$create_status" = "302"

custom_task=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='CI custom field task' LIMIT 1")
test -n "$custom_task"
test "$(db "SELECT value FROM task_custom_field_values WHERE task_id=$custom_task AND field_id=$reference_id")" = "REF-42"
test "$(db "SELECT value FROM task_custom_field_values WHERE task_id=$custom_task AND field_id=$budget_id")" = "1200.50"
test "$(db "SELECT value FROM task_custom_field_values WHERE task_id=$custom_task AND field_id=$billable_id")" = "1"
test "$(db "SELECT value FROM task_custom_field_values WHERE task_id=$custom_task AND field_id=$tags_id")" = '["red","blue"]'

low_budget_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  --data-urlencode 'title=CI low budget task' \
  --data-urlencode "status_id=$default_status" \
  --data-urlencode 'priority=medium' \
  --data-urlencode "custom_fields[$reference_id]=LOW" \
  --data-urlencode "custom_fields[$environment_id]=Staging" \
  --data-urlencode "custom_fields[$budget_id]=9.50" \
  "$base_url/tasks")
test "$low_budget_status" = "302"

curl --fail --silent --get --cookie "$cookies" \
  --data-urlencode "sort=custom_$budget_id" \
  --data-urlencode 'order=asc' \
  "$base_url/tasks" > /tmp/custom-sort.html
low_line=$(grep -n 'CI low budget task' /tmp/custom-sort.html | head -n1 | cut -d: -f1)
high_line=$(grep -n 'CI custom field task' /tmp/custom-sort.html | head -n1 | cut -d: -f1)
test -n "$low_line"
test -n "$high_line"
test "$low_line" -lt "$high_line"

invalid_status=$(curl --silent --output /tmp/invalid-custom.html --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  --data-urlencode 'title=Invalid custom task' \
  --data-urlencode "status_id=$default_status" \
  --data-urlencode 'priority=medium' \
  --data-urlencode "custom_fields[$reference_id]=REQ" \
  --data-urlencode "custom_fields[$environment_id]=Foreign option" \
  "$base_url/tasks")
test "$invalid_status" = "422"
test "$(db "SELECT COUNT(*) FROM tasks WHERE created_by=$admin_id AND title='Invalid custom task'")" = "0"

extra_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$task_csrf" \
  --data-urlencode 'title=Foreign field ignored' \
  --data-urlencode "status_id=$default_status" \
  --data-urlencode 'priority=medium' \
  --data-urlencode "custom_fields[$reference_id]=SAFE" \
  --data-urlencode "custom_fields[$foreign_field]=MUST-NOT-WRITE" \
  "$base_url/tasks")
test "$extra_status" = "302"
extra_task=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Foreign field ignored' LIMIT 1")
test -n "$extra_task"
test "$(db "SELECT COUNT(*) FROM task_custom_field_values WHERE task_id=$extra_task AND field_id=$foreign_field")" = "0"

curl --fail --silent --get --cookie "$cookies" \
  --data-urlencode "sort=custom_$budget_id" \
  --data-urlencode 'order=desc' \
  "$base_url/tasks" > /tmp/custom-sort-desc.html
high_line=$(grep -n 'CI custom field task' /tmp/custom-sort-desc.html | head -n1 | cut -d: -f1)
low_line=$(grep -n 'CI low budget task' /tmp/custom-sort-desc.html | head -n1 | cut -d: -f1)
missing_line=$(grep -n 'Foreign field ignored' /tmp/custom-sort-desc.html | head -n1 | cut -d: -f1)
test "$high_line" -lt "$low_line"
test "$low_line" -lt "$missing_line"

if db "INSERT INTO task_custom_field_values (task_id,field_id,user_id,value) VALUES ($custom_task,$foreign_field,$admin_id,'forbidden')"; then
  echo 'Cross-user custom field foreign key unexpectedly accepted.' >&2
  exit 1
fi

curl --fail --silent --get --cookie "$cookies" \
  --data-urlencode "custom[$environment_id]=Prod" \
  "$base_url/tasks" > /tmp/custom-filter.html
grep -q 'CI custom field task' /tmp/custom-filter.html
if grep -q 'Foreign field ignored' /tmp/custom-filter.html; then
  echo 'Custom field filter returned a non-matching task.' >&2
  exit 1
fi
grep -q 'REF-42' /tmp/custom-filter.html
grep -q 'red, blue' /tmp/custom-filter.html
grep -q "custom%5B$environment_id%5D=Prod" /tmp/custom-filter.html

curl --fail --silent --cookie "$cookies" "$base_url/tasks/$custom_task/edit" > /tmp/custom-edit.html
grep -q 'value="REF-42"' /tmp/custom-edit.html
grep -q 'value="Prod" selected' /tmp/custom-edit.html
grep -q 'value="red" checked' /tmp/custom-edit.html
grep -q 'value="blue" checked' /tmp/custom-edit.html

change_type=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'name=Budget' \
  --data-urlencode 'field_type=text' \
  "$base_url/custom-fields/$budget_id")
test "$change_type" = "302"
test "$(db "SELECT COUNT(*) FROM task_custom_field_values WHERE field_id=$budget_id AND user_id=$admin_id")" = "0"

move_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'direction=up' \
  "$base_url/custom-fields/$tags_id/move")
test "$move_status" = "302"

foreign_delete=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  --data-urlencode "_csrf=$csrf" \
  "$base_url/custom-fields/$foreign_field/delete")
test "$foreign_delete" = "404"
test "$(db "SELECT COUNT(*) FROM custom_fields WHERE id=$foreign_field AND user_id=$other_id")" = "1"
