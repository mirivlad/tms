#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
cookies=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
default_status=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND is_default=1 LIMIT 1")
completion_status=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND is_completion=1 LIMIT 1")
reference_id=$(db "SELECT id FROM custom_fields WHERE user_id=$admin_id AND name='Reference' LIMIT 1")
test -n "$admin_id"
test -n "$default_status"
test -n "$completion_status"
test -n "$reference_id"

db "INSERT INTO customers (user_id,name) VALUES ($admin_id,'Filter 100% Acme'),($admin_id,'Filter Beta') ON DUPLICATE KEY UPDATE name=VALUES(name)"
acme_id=$(db "SELECT id FROM customers WHERE user_id=$admin_id AND name='Filter 100% Acme' LIMIT 1")
beta_id=$(db "SELECT id FROM customers WHERE user_id=$admin_id AND name='Filter Beta' LIMIT 1")

db "INSERT INTO tasks (created_by,title,description,deadline,status_id,priority,customer_id,created_at,updated_at) VALUES
($admin_id,'Filter open match','', '2026-06-15 12:00:00',$default_status,1,$acme_id,'2026-05-05 09:00:00','2026-05-05 09:00:00'),
($admin_id,'Filter done match','', '2026-06-16 12:00:00',$completion_status,1,$acme_id,'2026-05-06 09:00:00','2026-05-06 09:00:00'),
($admin_id,'Filter outside deadline','', '2026-07-10 12:00:00',$default_status,1,$beta_id,'2026-05-07 09:00:00','2026-05-07 09:00:00')"

curl --fail --silent --get --cookie "$cookies" \
  --data-urlencode 'q=Filter' \
  --data-urlencode "status_id=$completion_status" \
  --data-urlencode 'status_invert=1' \
  "$base_url/tasks" > /tmp/filter-invert.html
grep -q 'Filter open match' /tmp/filter-invert.html
if grep -q 'Filter done match' /tmp/filter-invert.html; then
  echo 'Status inversion returned the excluded completion status.' >&2
  exit 1
fi

a=$(curl --fail --silent --get --cookie "$cookies" --data-urlencode 'customer=100%' "$base_url/tasks")
printf '%s' "$a" | grep -q 'Filter open match'
printf '%s' "$a" | grep -q 'Filter done match'
if printf '%s' "$a" | grep -q 'Filter outside deadline'; then
  echo 'Customer substring filter treated % as a wildcard or leaked a different customer.' >&2
  exit 1
fi

curl --fail --silent --get --cookie "$cookies" \
  --data-urlencode 'q=Filter' \
  --data-urlencode 'deadline_from=2026-06-01' \
  --data-urlencode 'deadline_to=2026-06-30' \
  --data-urlencode 'created_from=2026-05-01' \
  --data-urlencode 'created_to=2026-05-31' \
  "$base_url/tasks" > /tmp/filter-dates.html
grep -q 'Filter open match' /tmp/filter-dates.html
grep -q 'Filter done match' /tmp/filter-dates.html
if grep -q 'Filter outside deadline' /tmp/filter-dates.html; then
  echo 'Date-range filter returned a task outside the requested deadline range.' >&2
  exit 1
fi
grep -q 'filter-chip' /tmp/filter-dates.html
grep -q 'deadline_from=2026-06-01' /tmp/filter-dates.html

values=''
for i in $(seq -w 1 14); do
  values+="($admin_id,'Filter page $i','',$default_status,1,'2026-05-10 09:00:00','2026-05-10 09:00:00'),"
done
values=${values%,}
db "INSERT INTO tasks (created_by,title,description,status_id,priority,created_at,updated_at) VALUES $values"
db "INSERT INTO task_custom_field_values (task_id,field_id,user_id,value)
    SELECT id,$reference_id,$admin_id,'PAGE' FROM tasks
    WHERE created_by=$admin_id AND title LIKE 'Filter page %'"

curl --fail --silent --get --cookie "$cookies" \
  --data-urlencode "custom[$reference_id]=PAGE" \
  --data-urlencode 'sort=title' \
  --data-urlencode 'order=asc' \
  --data-urlencode 'per_page=10' \
  --data-urlencode 'page=1' \
  "$base_url/tasks" > /tmp/filter-page1.html
test "$(grep -o 'Filter page [0-9][0-9]' /tmp/filter-page1.html | sort -u | wc -l)" = "10"
grep -q 'Filter page 01' /tmp/filter-page1.html
if grep -q 'Filter page 11' /tmp/filter-page1.html; then
  echo 'Pagination ran before custom filtering/sorting.' >&2
  exit 1
fi
grep -q 'custom%5B' /tmp/filter-page1.html
grep -q 'per_page=10' /tmp/filter-page1.html

curl --fail --silent --get --cookie "$cookies" \
  --data-urlencode "custom[$reference_id]=PAGE" \
  --data-urlencode 'sort=title' \
  --data-urlencode 'order=asc' \
  --data-urlencode 'per_page=10' \
  --data-urlencode 'page=2' \
  "$base_url/tasks" > /tmp/filter-page2.html
test "$(grep -o 'Filter page [0-9][0-9]' /tmp/filter-page2.html | sort -u | wc -l)" = "4"
grep -q 'Filter page 11' /tmp/filter-page2.html
grep -q 'Filter page 14' /tmp/filter-page2.html
if grep -q 'Filter page 01' /tmp/filter-page2.html; then
  echo 'Second page repeated first-page rows.' >&2
  exit 1
fi
