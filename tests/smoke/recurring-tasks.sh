#!/usr/bin/env bash
set -Eeuo pipefail

on_error() {
  rc=$?
  echo "Recurring smoke failed at line $1 (exit $rc)" >&2
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e     "SELECT id,owner_user_id,current_task_id,mode,interval_value,spawn_status_id,timezone,next_deadline,next_run_at,sequence,is_active,last_error FROM task_recurrences ORDER BY id" >&2 || true
  for file in /tmp/recurring-calendar.out /tmp/recurring-calendar-second.out /tmp/recurring-completion.out /tmp/recurring-completion-second.out; do
    if [ -f "$file" ]; then
      echo "--- $file ---" >&2
      cat "$file" >&2 || true
    fi
  done
  exit "$rc"
}
trap 'on_error $LINENO' ERR

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
test -n "$admin_id"
test -n "$other_id"

spawn_status=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND project_id IS NULL AND is_completion=0 ORDER BY is_default DESC, sort_order ASC, id ASC LIMIT 1")
completion_status=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND project_id IS NULL AND is_completion=1 ORDER BY id ASC LIMIT 1")
other_status=$(db "SELECT id FROM statuses WHERE user_id=$other_id AND project_id IS NULL AND is_completion=0 ORDER BY is_default DESC, sort_order ASC, id ASC LIMIT 1")
test -n "$spawn_status"
test -n "$completion_status"
test -n "$other_status"

seed_deadline=$(TZ="$APP_TIMEZONE" date -d '25 hours ago' '+%Y-%m-%d %H:%M:00')
expected_next=$(TZ="$APP_TIMEZONE" date -d '1 hour ago' '+%Y-%m-%d %H:%M:00')

db "INSERT INTO tasks (created_by,title,description,deadline,status_id,priority,created_at,updated_at)
    VALUES ($admin_id,'Recurring calendar smoke','calendar recurrence','$seed_deadline',$spawn_status,2,NOW(),NOW())"
seed_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Recurring calendar smoke' ORDER BY id DESC LIMIT 1")
test -n "$seed_id"

db "INSERT INTO task_checklist_items (task_id,item_text,is_completed,sort_order)
    VALUES ($seed_id,'First recurring step',1,10),($seed_id,'Second recurring step',0,20)"
db "INSERT INTO custom_fields (user_id,project_id,name,field_type,options_json,is_required,sort_order)
    VALUES ($admin_id,NULL,'Recurring smoke field','text',NULL,0,999)"
field_id=$(db "SELECT id FROM custom_fields WHERE user_id=$admin_id AND name='Recurring smoke field' LIMIT 1")
test -n "$field_id"
db "INSERT INTO task_custom_field_values (task_id,field_id,user_id,value)
    VALUES ($seed_id,$field_id,$admin_id,'copied value')"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/$seed_id/edit" > /tmp/recurrence-seed.html
csrf=$(grep -m1 -o 'name="_csrf" value="[^"]*"' /tmp/recurrence-seed.html | sed 's/.*value="//;s/"$//')
test -n "$csrf"
grep -q 'id="recurrence"' /tmp/recurrence-seed.html

code=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode "mode=daily"   --data-urlencode "interval_value=1"   --data-urlencode "spawn_status_id=$spawn_status"   "$BASE_URL/tasks/$seed_id/recurrence")
test "$code" = "302"

recurrence_id=$(db "SELECT id FROM task_recurrences WHERE owner_user_id=$admin_id AND current_task_id=$seed_id LIMIT 1")
test -n "$recurrence_id"

docker compose exec -T app php /var/www/html/bin/recurring.php > /tmp/recurring-calendar.out
cat /tmp/recurring-calendar.out
grep -q 'generated=1' /tmp/recurring-calendar.out

generated_id=$(db "SELECT current_task_id FROM task_recurrences WHERE id=$recurrence_id")
test -n "$generated_id"
test "$generated_id" != "$seed_id"
test "$(db "SELECT sequence FROM task_recurrences WHERE id=$recurrence_id")" = "1"
test "$(db "SELECT COUNT(*) FROM task_recurrence_occurrences WHERE recurrence_id=$recurrence_id")" = "1"
test "$(db "SELECT title FROM tasks WHERE id=$generated_id")" = "Recurring calendar smoke"
test "$(db "SELECT status_id FROM tasks WHERE id=$generated_id")" = "$spawn_status"
test "$(db "SELECT DATE_FORMAT(deadline,'%Y-%m-%d %H:%i:%s') FROM tasks WHERE id=$generated_id")" = "$expected_next"
test "$(db "SELECT COUNT(*) FROM task_checklist_items WHERE task_id=$generated_id")" = "2"
test "$(db "SELECT COUNT(*) FROM task_checklist_items WHERE task_id=$generated_id AND is_completed=1")" = "0"
test "$(db "SELECT value FROM task_custom_field_values WHERE task_id=$generated_id AND field_id=$field_id")" = "copied value"

docker compose exec -T app php /var/www/html/bin/recurring.php > /tmp/recurring-calendar-second.out
test "$(db "SELECT sequence FROM task_recurrences WHERE id=$recurrence_id")" = "1"
test "$(db "SELECT COUNT(*) FROM task_recurrence_occurrences WHERE recurrence_id=$recurrence_id")" = "1"

db "INSERT INTO tasks (created_by,title,description,deadline,status_id,priority,created_at,updated_at)
    VALUES ($admin_id,'Recurring completion smoke','completion recurrence',NULL,$completion_status,1,NOW(),DATE_SUB(NOW(), INTERVAL 5 MINUTE))"
completion_seed=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Recurring completion smoke' ORDER BY id DESC LIMIT 1")
test -n "$completion_seed"
expected_completion_deadline=$(db "SELECT DATE_FORMAT(DATE_ADD(updated_at, INTERVAL 2 DAY),'%Y-%m-%d %H:%i:%s') FROM tasks WHERE id=$completion_seed")

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks/$completion_seed/edit" > /tmp/recurrence-completion.html
completion_csrf=$(grep -m1 -o 'name="_csrf" value="[^"]*"' /tmp/recurrence-completion.html | sed 's/.*value="//;s/"$//')
test -n "$completion_csrf"

code=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$completion_csrf"   --data-urlencode "mode=after_completion"   --data-urlencode "interval_value=2"   --data-urlencode "spawn_status_id=$spawn_status"   "$BASE_URL/tasks/$completion_seed/recurrence")
test "$code" = "302"

completion_recurrence=$(db "SELECT id FROM task_recurrences WHERE current_task_id=$completion_seed LIMIT 1")
test -n "$completion_recurrence"

docker compose exec -T app php /var/www/html/bin/recurring.php > /tmp/recurring-completion.out
cat /tmp/recurring-completion.out
grep -q 'generated=1' /tmp/recurring-completion.out
completion_generated=$(db "SELECT current_task_id FROM task_recurrences WHERE id=$completion_recurrence")
test "$completion_generated" != "$completion_seed"
test "$(db "SELECT status_id FROM tasks WHERE id=$completion_generated")" = "$spawn_status"
test "$(db "SELECT DATE_FORMAT(deadline,'%Y-%m-%d %H:%i:%s') FROM tasks WHERE id=$completion_generated")" = "$expected_completion_deadline"

docker compose exec -T app php /var/www/html/bin/recurring.php > /tmp/recurring-completion-second.out
test "$(db "SELECT sequence FROM task_recurrences WHERE id=$completion_recurrence")" = "1"

db "INSERT INTO tasks (created_by,title,description,status_id,priority) VALUES ($other_id,'Foreign recurrence smoke','',$other_status,1)"
foreign_task=$(db "SELECT id FROM tasks WHERE created_by=$other_id AND title='Foreign recurrence smoke' ORDER BY id DESC LIMIT 1")
test -n "$foreign_task"
code=$(curl --silent --output /dev/null --write-out '%{http_code}'   --cookie "$COOKIE_JAR"   --data-urlencode "_csrf=$csrf"   --data-urlencode "mode=after_completion"   --data-urlencode "interval_value=1"   --data-urlencode "spawn_status_id=$other_status"   "$BASE_URL/tasks/$foreign_task/recurrence")
test "$code" = "404"
test "$(db "SELECT COUNT(*) FROM task_recurrences WHERE current_task_id=$foreign_task")" = "0"

db "DELETE FROM task_recurrences WHERE id IN ($recurrence_id,$completion_recurrence)"
db "DELETE FROM tasks WHERE id IN ($seed_id,$generated_id,$completion_seed,$completion_generated,$foreign_task)"
db "DELETE FROM custom_fields WHERE id=$field_id"
