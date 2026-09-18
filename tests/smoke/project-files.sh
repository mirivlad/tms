#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${APP_URL:-http://127.0.0.1:18080}"
COOKIE_JAR=/tmp/tms-cookies
STORAGE_ROOT=/var/www/html/var/storage/attachments

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
test -n "$admin_id"
db "INSERT INTO projects (owner_user_id, owner_team_id, created_by, name, description, lifecycle_status)
    VALUES ($admin_id, NULL, $admin_id, 'Project Files Smoke', 'Attachment smoke project', 'active')"
project_id=$(db "SELECT id FROM projects WHERE owner_user_id=$admin_id AND name='Project Files Smoke' LIMIT 1")
test -n "$project_id"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id" > /tmp/project-files-detail.html
csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/project-files-detail.html | head -n1)
test -n "$csrf"

printf 'project file smoke\n' > /tmp/project-file.txt
upload_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  -F "_csrf=$csrf" \
  -F "attachments[]=@/tmp/project-file.txt;type=text/plain" \
  "$BASE_URL/projects/$project_id/attachments")
test "$upload_status" = "302"

attachment_id=$(db "SELECT id FROM project_attachments WHERE project_id=$project_id AND original_name='project-file.txt' LIMIT 1")
storage_name=$(db "SELECT storage_name FROM project_attachments WHERE id=$attachment_id")
test -n "$attachment_id"
test -n "$storage_name"
docker compose exec -T app test -f "$STORAGE_ROOT/${storage_name:0:2}/$storage_name"

curl --fail --silent --cookie "$COOKIE_JAR" "$BASE_URL/projects/$project_id" > /tmp/project-files-after-upload.html
grep -q 'project-file.txt' /tmp/project-files-after-upload.html

curl --fail --silent --cookie "$COOKIE_JAR" \
  "$BASE_URL/projects/$project_id/attachments/$attachment_id" > /tmp/project-file-downloaded.txt
cmp /tmp/project-file.txt /tmp/project-file-downloaded.txt

other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
foreign_project=$(db "SELECT id FROM projects WHERE owner_user_id=$other_id ORDER BY id LIMIT 1")
test -n "$foreign_project"
foreign_status=$(curl --silent --output /tmp/project-files-foreign.txt --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  -F "_csrf=$csrf" \
  -F "attachments[]=@/tmp/project-file.txt;type=text/plain" \
  "$BASE_URL/projects/$foreign_project/attachments")
test "$foreign_status" = "404"

delete_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  "$BASE_URL/projects/$project_id/attachments/$attachment_id/delete")
test "$delete_status" = "302"
test "$(db "SELECT COUNT(*) FROM project_attachments WHERE id=$attachment_id")" = "0"
if docker compose exec -T app test -f "$STORAGE_ROOT/${storage_name:0:2}/$storage_name"; then
  echo 'Deleted project attachment remained on disk.' >&2
  exit 1
fi

printf 'delete project cleanup\n' > /tmp/project-cleanup.txt
upload_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  -F "_csrf=$csrf" \
  -F "attachments[]=@/tmp/project-cleanup.txt;type=text/plain" \
  "$BASE_URL/projects/$project_id/attachments")
test "$upload_status" = "302"
cleanup_storage=$(db "SELECT storage_name FROM project_attachments WHERE project_id=$project_id AND original_name='project-cleanup.txt' LIMIT 1")
test -n "$cleanup_storage"
docker compose exec -T app test -f "$STORAGE_ROOT/${cleanup_storage:0:2}/$cleanup_storage"

project_delete_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" \
  "$BASE_URL/projects/$project_id/delete")
test "$project_delete_status" = "302"
test "$(db "SELECT COUNT(*) FROM projects WHERE id=$project_id")" = "0"
test "$(db "SELECT COUNT(*) FROM project_attachments WHERE project_id=$project_id")" = "0"
if docker compose exec -T app test -f "$STORAGE_ROOT/${cleanup_storage:0:2}/$cleanup_storage"; then
  echo 'Project deletion left attachment content on disk.' >&2
  exit 1
fi
