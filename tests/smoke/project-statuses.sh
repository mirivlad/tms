#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

status_filter_values() {
  python3 - "$1" <<'PYCODE'
import sys
from html.parser import HTMLParser

class StatusFilterParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.in_status_filter = False
        self.values = []

    def handle_starttag(self, tag, attrs):
        data = dict(attrs)
        if tag == 'select' and 'data-status-filter-select' in data:
            self.in_status_filter = True
            return
        if self.in_status_filter and tag == 'option':
            self.values.append(data.get('value', ''))

    def handle_endtag(self, tag):
        if tag == 'select' and self.in_status_filter:
            self.in_status_filter = False

parser = StatusFilterParser()
with open(sys.argv[1], encoding='utf-8') as source:
    parser.feed(source.read())
print('\n'.join(parser.values))
PYCODE
}

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects" > /tmp/ps-projects.html
csrf=$(grep -m1 -o 'name="_csrf" value="[^"]*"' /tmp/ps-projects.html | sed 's/.*value="//;s/"$//')
test -n "$csrf"
admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'name=Workflow Smoke Project' \
  --data-urlencode 'description=workflow smoke' \
  --data-urlencode 'lifecycle_status=active' \
  "$BASE_URL/projects")
test "$code" = "302"

project_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='Workflow Smoke Project' LIMIT 1")
test -n "$project_id"
personal_count=$(db "SELECT COUNT(*) FROM statuses WHERE user_id=$admin_id AND project_id IS NULL")
project_count=$(db "SELECT COUNT(*) FROM statuses WHERE user_id IS NULL AND project_id=$project_id")
test "$personal_count" = "$project_count"

personal_default=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND project_id IS NULL AND is_default=1 LIMIT 1")
project_default=$(db "SELECT id FROM statuses WHERE project_id=$project_id AND is_default=1 LIMIT 1")
test "$(db "SELECT source_status_id FROM statuses WHERE id=$project_default")" = "$personal_default"

response=$(curl --fail --silent -H 'Accept: application/json' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'title=Workflow clone task' \
  --data-urlencode "project_id=$project_id" "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
clone_task=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Workflow clone task' LIMIT 1")
test "$(db "SELECT status_id FROM tasks WHERE id=$clone_task")" = "$project_default"

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'name=Review' \
  --data-urlencode 'color=#445566' --data-urlencode 'show_on_board=1' \
  "$BASE_URL/projects/$project_id/statuses")
test "$code" = "302"
review_id=$(db "SELECT id FROM statuses WHERE project_id=$project_id AND name='Review' LIMIT 1")
test -n "$review_id"

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" "$BASE_URL/projects/$project_id/statuses/$review_id/default")
test "$code" = "302"

response=$(curl --fail --silent -H 'Accept: application/json' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode 'title=Workflow custom task' \
  --data-urlencode "project_id=$project_id" "$BASE_URL/tasks/quick-add")
printf '%s' "$response" | grep -q '"success":true'
custom_task=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='Workflow custom task' LIMIT 1")
test "$(db "SELECT status_id FROM tasks WHERE id=$custom_task")" = "$review_id"

code=$(curl --silent -o /tmp/ps-invalid.json -w '%{http_code}' -H 'Accept: application/json' \
  --cookie "$COOKIE_JAR" --data-urlencode "_csrf=$csrf" \
  --data-urlencode "status_id=$personal_default" "$BASE_URL/tasks/$custom_task/status")
test "$code" = "422"
test "$(db "SELECT status_id FROM tasks WHERE id=$custom_task")" = "$review_id"

curl --fail --silent -H 'Accept: application/json' --cookie "$COOKIE_JAR" \
  "$BASE_URL/api/task-statuses?project_id=$project_id" > /tmp/ps-options.json
grep -q '"name":"Review"' /tmp/ps-options.json
grep -q "\"id\":$review_id" /tmp/ps-options.json
if grep -q "\"id\":$personal_default" /tmp/ps-options.json; then
  echo 'Project status API leaked a personal status.' >&2
  exit 1
fi

curl --fail --silent -H 'Accept: application/json' --cookie "$COOKIE_JAR" \
  "$BASE_URL/api/task-statuses?scope=all" > /tmp/ps-options-all.json
grep -q "\"id\":$review_id" /tmp/ps-options-all.json
grep -q "\"id\":$personal_default" /tmp/ps-options-all.json

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/tasks" > /tmp/ps-tasks-default.html
grep -q '/assets/status-filter.js' /tmp/ps-tasks-default.html
grep -q 'data-status-filter-project' /tmp/ps-tasks-default.html
grep -q 'value="none" selected' /tmp/ps-tasks-default.html
status_filter_values /tmp/ps-tasks-default.html > /tmp/ps-task-status-values-default.txt
grep -qx "$personal_default" /tmp/ps-task-status-values-default.txt
if grep -qx "$review_id" /tmp/ps-task-status-values-default.txt; then
  echo 'Default task status filter leaked a project status.' >&2
  exit 1
fi
if grep -q 'Workflow custom task' /tmp/ps-tasks-default.html; then
  echo 'Default task list included a project task.' >&2
  exit 1
fi

curl --fail --silent --get --cookie "$COOKIE_JAR" \
  --data-urlencode "project=$project_id" \
  "$BASE_URL/tasks" > /tmp/ps-tasks-project.html
status_filter_values /tmp/ps-tasks-project.html > /tmp/ps-task-status-values-project.txt
grep -qx "$review_id" /tmp/ps-task-status-values-project.txt
if grep -qx "$personal_default" /tmp/ps-task-status-values-project.txt; then
  echo 'Project task status filter leaked a personal status.' >&2
  exit 1
fi
grep -q 'Workflow custom task' /tmp/ps-tasks-project.html

curl --fail --silent --get --cookie "$COOKIE_JAR" \
  --data-urlencode 'project=all' \
  "$BASE_URL/tasks" > /tmp/ps-tasks-all.html
status_filter_values /tmp/ps-tasks-all.html > /tmp/ps-task-status-values-all.txt
grep -qx "$review_id" /tmp/ps-task-status-values-all.txt
grep -qx "$personal_default" /tmp/ps-task-status-values-all.txt
grep -q 'Workflow custom task' /tmp/ps-tasks-all.html

curl --fail --silent --get --cookie "$COOKIE_JAR" \
  --data-urlencode "project=$project_id" \
  --data-urlencode "status_id=$personal_default" \
  "$BASE_URL/tasks" > /tmp/ps-tasks-invalid-status.html
grep -q 'Workflow custom task' /tmp/ps-tasks-invalid-status.html

calendar_month=$(TZ="${APP_TIMEZONE:-UTC}" date +%Y-%m)
db "UPDATE tasks SET deadline='${calendar_month}-15 12:00:00' WHERE id=$custom_task"

curl --fail --silent --get --cookie "$COOKIE_JAR" \
  --data-urlencode "month=$calendar_month" \
  --data-urlencode 'mode=deadlines_only' \
  "$BASE_URL/calendar" > /tmp/ps-calendar-default.html
grep -q '/assets/status-filter.js' /tmp/ps-calendar-default.html
grep -q 'value="none" selected' /tmp/ps-calendar-default.html
status_filter_values /tmp/ps-calendar-default.html > /tmp/ps-calendar-status-values-default.txt
grep -qx "$personal_default" /tmp/ps-calendar-status-values-default.txt
if grep -qx "$review_id" /tmp/ps-calendar-status-values-default.txt; then
  echo 'Default calendar status filter leaked a project status.' >&2
  exit 1
fi
if grep -q 'Workflow custom task' /tmp/ps-calendar-default.html; then
  echo 'Default calendar included a project task.' >&2
  exit 1
fi

curl --fail --silent --get --cookie "$COOKIE_JAR" \
  --data-urlencode "month=$calendar_month" \
  --data-urlencode 'mode=deadlines_only' \
  --data-urlencode "project=$project_id" \
  "$BASE_URL/calendar" > /tmp/ps-calendar-project.html
status_filter_values /tmp/ps-calendar-project.html > /tmp/ps-calendar-status-values-project.txt
grep -qx "$review_id" /tmp/ps-calendar-status-values-project.txt
if grep -qx "$personal_default" /tmp/ps-calendar-status-values-project.txt; then
  echo 'Project calendar status filter leaked a personal status.' >&2
  exit 1
fi
grep -q 'Workflow custom task' /tmp/ps-calendar-project.html

curl --fail --silent --get --cookie "$COOKIE_JAR" \
  --data-urlencode "month=$calendar_month" \
  --data-urlencode 'mode=deadlines_only' \
  --data-urlencode 'project=all' \
  "$BASE_URL/calendar" > /tmp/ps-calendar-all-projects.html
status_filter_values /tmp/ps-calendar-all-projects.html > /tmp/ps-calendar-status-values-all.txt
grep -qx "$review_id" /tmp/ps-calendar-status-values-all.txt
grep -qx "$personal_default" /tmp/ps-calendar-status-values-all.txt
grep -q 'Workflow custom task' /tmp/ps-calendar-all-projects.html

personal_completion=$(db "SELECT id FROM statuses WHERE user_id=$admin_id AND project_id IS NULL AND is_completion=1 LIMIT 1")
project_completion=$(db "SELECT id FROM statuses WHERE project_id=$project_id AND source_status_id=$personal_completion LIMIT 1")
code=$(curl --silent -o /tmp/ps-move.json -w '%{http_code}' -H 'Accept: application/json' \
  --cookie "$COOKIE_JAR" --data-urlencode "_csrf=$csrf" \
  --data-urlencode "status_id=$project_completion" "$BASE_URL/tasks/$clone_task/status")
test "$code" = "200"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id/statuses" > /tmp/ps-detail.html
grep -q 'Project workflow' /tmp/ps-detail.html
grep -q 'Review' /tmp/ps-detail.html
grep -q 'class="metadata-create status-create"' /tmp/ps-detail.html
grep -q 'class="metadata-edit status-edit"' /tmp/ps-detail.html
grep -q 'class="color-field"' /tmp/ps-detail.html

code=$(curl --silent -o /dev/null -w '%{http_code}' --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" "$BASE_URL/projects/$project_id/delete")
test "$code" = "302"
test "$(db "SELECT project_id IS NULL FROM tasks WHERE id=$clone_task")" = "1"
test "$(db "SELECT status_id FROM tasks WHERE id=$clone_task")" = "$personal_completion"
test "$(db "SELECT project_id IS NULL FROM tasks WHERE id=$custom_task")" = "1"
test "$(db "SELECT status_id FROM tasks WHERE id=$custom_task")" = "$personal_default"
