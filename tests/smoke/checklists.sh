#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/new" > /tmp/checklist-task-new.html
csrf=$(grep -m1 -o 'name="_csrf" value="[^"]*"' /tmp/checklist-task-new.html | sed 's/.*value="//;s/"$//')
test -n "$csrf"

response=$(curl --fail --silent --header 'Accept: application/json'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'title=Checklist smoke task'   "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
task_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Checklist smoke task' ORDER BY id DESC LIMIT 1")
test -n "$task_id"

status=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'text=Backup database'   "$BASE_URL/tasks/$task_id/checklist")
test "$status" = "302"

first_id=$(db "SELECT id FROM task_checklist_items WHERE task_id=$task_id AND item_text='Backup database' LIMIT 1")
test -n "$first_id"
test "$(db "SELECT COUNT(*) FROM activity_events WHERE task_id=$task_id AND event_type='task.checklist_added'")" = "1"

status=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'text=Run smoke tests'   "$BASE_URL/tasks/$task_id/checklist")
test "$status" = "302"
second_id=$(db "SELECT id FROM task_checklist_items WHERE task_id=$task_id AND item_text='Run smoke tests' LIMIT 1")
test -n "$second_id"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/$task_id/edit" > /tmp/checklist-task-edit.html
grep -q 'Backup database' /tmp/checklist-task-edit.html
grep -q 'Run smoke tests' /tmp/checklist-task-edit.html
grep -q '0 of 2 completed' /tmp/checklist-task-edit.html

status=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   "$BASE_URL/tasks/$task_id/checklist/$first_id/toggle")
test "$status" = "302"
test "$(db "SELECT is_completed FROM task_checklist_items WHERE id=$first_id")" = "1"
test "$(db "SELECT COUNT(*) FROM activity_events WHERE task_id=$task_id AND event_type='task.checklist_completed'")" = "1"

status=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'text=Run all smoke tests'   "$BASE_URL/tasks/$task_id/checklist/$second_id")
test "$status" = "302"
test "$(db "SELECT item_text FROM task_checklist_items WHERE id=$second_id")" = "Run all smoke tests"

status=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode 'direction=up'   "$BASE_URL/tasks/$task_id/checklist/$second_id/move")
test "$status" = "302"
test "$(db "SELECT id FROM task_checklist_items WHERE task_id=$task_id ORDER BY sort_order,id LIMIT 1")" = "$second_id"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks" > /tmp/checklist-task-list.html
grep -q 'Checklist smoke task' /tmp/checklist-task-list.html
grep -q '1/2' /tmp/checklist-task-list.html

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/$task_id/history" > /tmp/checklist-history.html
grep -q 'Checklist item added' /tmp/checklist-history.html
grep -q 'Checklist item completed' /tmp/checklist-history.html
grep -q 'Backup database' /tmp/checklist-history.html

other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
foreign_task=$(db "SELECT id FROM tasks WHERE created_by=$other_id ORDER BY id LIMIT 1")
test -n "$foreign_task"
db "INSERT INTO task_checklist_items (task_id,item_text,sort_order) VALUES ($foreign_task,'Foreign private step',10)"
foreign_item=$(db "SELECT id FROM task_checklist_items WHERE task_id=$foreign_task AND item_text='Foreign private step' LIMIT 1")
code=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   "$BASE_URL/tasks/$foreign_task/checklist/$foreign_item/toggle")
test "$code" = "404"
test "$(db "SELECT is_completed FROM task_checklist_items WHERE id=$foreign_item")" = "0"

status=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   "$BASE_URL/tasks/$task_id/checklist/$first_id/delete")
test "$status" = "302"
test "$(db "SELECT COUNT(*) FROM task_checklist_items WHERE id=$first_id")" = "0"
test "$(db "SELECT COUNT(*) FROM activity_events WHERE task_id=$task_id AND event_type='task.checklist_deleted'")" = "1"
