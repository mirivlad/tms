#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

csrf_from() {
  grep -m1 -o 'name="_csrf" value="[^"]*"' "$1" | sed 's/.*value="//;s/"$//'
}

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects" > /tmp/projects.html
csrf=$(csrf_from /tmp/projects.html)
test -n "$csrf"
grep -q 'id="project-create-dialog"' /tmp/projects.html
grep -q 'data-dialog-trigger="#project-create-dialog"' /tmp/projects.html

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

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects" > /tmp/projects-after-create.html
for suffix in "" "/settings" "/statuses" "/fields" "/files"; do
  grep -q "href=\"/projects/$project_id$suffix\"" /tmp/projects-after-create.html
done
if grep -q "action=\"/projects/$project_id\"" /tmp/projects-after-create.html; then
  echo 'Project list exposed project editing instead of card navigation.' >&2
  exit 1
fi

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id" > /tmp/project-overview.html
grep -q 'entity-overview-grid' /tmp/project-overview.html
grep -q 'Project created through the authenticated UI' /tmp/project-overview.html
if grep -q "action=\"/projects/$project_id\"" /tmp/project-overview.html; then
  echo 'Project overview exposed settings form.' >&2
  exit 1
fi

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id/settings" > /tmp/project-settings.html
settings_csrf=$(csrf_from /tmp/project-settings.html)
test -n "$settings_csrf"
grep -q "action=\"/projects/$project_id\"" /tmp/project-settings.html
grep -q 'name="owner_scope"' /tmp/project-settings.html
grep -q "action=\"/projects/$project_id/delete\"" /tmp/project-settings.html

update_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$settings_csrf" \
  --data-urlencode "owner_scope=personal" \
  --data-urlencode "name=CI Project Updated" \
  --data-urlencode "description=Updated project description" \
  --data-urlencode "lifecycle_status=paused" \
  "$BASE_URL/projects/$project_id")
test "$update_status" = "302"
test "$(db "SELECT lifecycle_status FROM projects WHERE id=$project_id")" = "paused"
test "$(db "SELECT name FROM projects WHERE id=$project_id")" = "CI Project Updated"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id" > /tmp/project-overview-updated.html
grep -q 'CI Project Updated' /tmp/project-overview-updated.html
grep -q 'Updated project description' /tmp/project-overview-updated.html

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/dashboard" > /tmp/projects-dashboard.html
grep -q 'dashboard-context-grid' /tmp/projects-dashboard.html
grep -q 'CI Project Updated' /tmp/projects-dashboard.html

other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
db "INSERT INTO projects (owner_user_id, owner_team_id, created_by, name, description, lifecycle_status) VALUES ($other_id, NULL, $other_id, 'Foreign Project', '', 'active')"
foreign_id=$(db "SELECT id FROM projects WHERE owner_user_id=$other_id AND name='Foreign Project' LIMIT 1")
test "$(curl --silent --output /dev/null --write-out '%{http_code}' --cookie "$COOKIE_JAR" "$BASE_URL/projects/$foreign_id/settings")" = "404"

foreign_status=$(curl --silent --output /tmp/projects-foreign.html --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$settings_csrf" \
  --data-urlencode "name=Stolen Project" \
  --data-urlencode "description=Must not update" \
  --data-urlencode "lifecycle_status=done" \
  "$BASE_URL/projects/$foreign_id")
test "$foreign_status" = "404"
test "$(db "SELECT name FROM projects WHERE id=$foreign_id")" = "Foreign Project"

delete_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$settings_csrf" \
  "$BASE_URL/projects/$project_id/delete")
test "$delete_status" = "302"
test "$(db "SELECT COUNT(*) FROM projects WHERE id=$project_id")" = "0"
