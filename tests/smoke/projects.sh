#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects" > /tmp/projects.html
grep -q '>Projects<' /tmp/projects.html
csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/projects.html | head -n1)
test -n "$csrf"

create_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "name=CI Project" \
  --data-urlencode "description=Project created through the authenticated UI" \
  --data-urlencode "lifecycle_status=active" \
  "$BASE_URL/projects")
test "$create_status" = "302"

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
project_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='CI Project' LIMIT 1")
test -n "$project_id"
test "$(db "SELECT lifecycle_status FROM projects WHERE id=$project_id")" = "active"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects" > /tmp/projects-after-create.html
grep -q 'CI Project' /tmp/projects-after-create.html
grep -q "href=\"/projects/$project_id\"" /tmp/projects-after-create.html

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id" > /tmp/project-detail.html
grep -q 'project-settings-card' /tmp/project-detail.html
grep -q "href=\"/projects/$project_id\"" /tmp/projects-after-create.html
if grep -q "action=\"/projects/$project_id\"" /tmp/projects-after-create.html; then
  echo 'Project list exposed inline project editing instead of selector-first cards.' >&2
  exit 1
fi

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id" > /tmp/project-detail.html
project_csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/project-detail.html | head -n1)
test -n "$project_csrf"
grep -q "action=\"/projects/$project_id\"" /tmp/project-detail.html
grep -q 'project-settings-card' /tmp/project-detail.html

update_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "name=CI Project Updated" \
  --data-urlencode "description=Updated project description" \
  --data-urlencode "lifecycle_status=paused" \
  "$BASE_URL/projects/$project_id")
test "$update_status" = "302"
test "$(db "SELECT lifecycle_status FROM projects WHERE id=$project_id")" = "paused"
test "$(db "SELECT name FROM projects WHERE id=$project_id")" = "CI Project Updated"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/dashboard" > /tmp/projects-dashboard.html
grep -q 'dashboard-context-grid' /tmp/projects-dashboard.html
grep -q 'CI Project Updated' /tmp/projects-dashboard.html
curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id" > /tmp/project-detail-updated.html
grep -q 'CI Project Updated' /tmp/project-detail-updated.html
grep -q 'Updated project description' /tmp/project-detail-updated.html

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/dashboard" > /tmp/projects-dashboard.html
grep -q 'dashboard-context-grid' /tmp/projects-dashboard.html
grep -q 'CI Project Updated' /tmp/projects-dashboard.html

other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
db "INSERT INTO projects (owner_user_id, owner_team_id, created_by, name, description, lifecycle_status) VALUES ($other_id, NULL, $other_id, 'Foreign Project', '', 'active')"
foreign_id=$(db "SELECT id FROM projects WHERE owner_user_id=$other_id AND name='Foreign Project' LIMIT 1")

foreign_status=$(curl --silent --output /tmp/projects-foreign.html --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "name=Stolen Project" \
  --data-urlencode "description=Must not update" \
  --data-urlencode "lifecycle_status=done" \
  "$BASE_URL/projects/$foreign_id")
test "$foreign_status" = "404"
test "$(db "SELECT name FROM projects WHERE id=$foreign_id")" = "Foreign Project"

delete_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  "$BASE_URL/projects/$project_id/delete")
test "$delete_status" = "302"
test "$(db "SELECT COUNT(*) FROM projects WHERE id=$project_id")" = "0"
