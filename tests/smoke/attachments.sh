#!/usr/bin/env bash
set -euo pipefail

base_url="${APP_URL:?APP_URL is required}"
cookies=/tmp/tms-cookies

db() {
  docker compose exec -T db mariadb -N -utms -p"$DB_PASS" tms -e "$1"
}

admin_id=$(db "SELECT id FROM users WHERE username='ciadmin' LIMIT 1")
other_id=$(db "SELECT id FROM users WHERE username='other' LIMIT 1")
task_id=$(db "SELECT id FROM tasks WHERE created_by=$admin_id AND title='CI rich task updated' LIMIT 1")
foreign_task=$(db "SELECT id FROM tasks WHERE created_by=$other_id AND title='Foreign calendar leak' LIMIT 1")
test -n "$admin_id"
test -n "$other_id"
test -n "$task_id"
test -n "$foreign_task"

curl --fail --silent --cookie "$cookies" "$base_url/tasks/$task_id/edit" > /tmp/attachment-edit.html
grep -q '>Attachments<' /tmp/attachment-edit.html
grep -q 'enctype="multipart/form-data"' /tmp/attachment-edit.html
csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' /tmp/attachment-edit.html | head -n1)
test -n "$csrf"

printf 'private attachment content\n' > /tmp/tms-attachment.txt
own_sha=$(sha256sum /tmp/tms-attachment.txt | awk '{print $1}')
missing_csrf=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  -F 'attachments[]=@/tmp/tms-attachment.txt;filename=notes.txt;type=text/plain' \
  "$base_url/tasks/$task_id/attachments")
test "$missing_csrf" = "403"

upload_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  -F "_csrf=$csrf" \
  -F 'attachments[]=@/tmp/tms-attachment.txt;filename=notes.txt;type=text/plain' \
  "$base_url/tasks/$task_id/attachments")
test "$upload_status" = "302"

attachment_id=$(db "SELECT id FROM attachments WHERE task_id=$task_id AND user_id=$admin_id AND original_name='notes.txt' ORDER BY id DESC LIMIT 1")
storage_name=$(db "SELECT storage_name FROM attachments WHERE id=$attachment_id")
test -n "$attachment_id"
printf '%s' "$storage_name" | grep -Eq '^[a-f0-9]{64}$'
test "$(db "SELECT sha256 FROM attachments WHERE id=$attachment_id")" = "$own_sha"
storage_path="/var/www/html/var/storage/attachments/${storage_name:0:2}/$storage_name"
docker compose exec -T app test -f "$storage_path"

curl --fail --silent --cookie "$cookies" -D /tmp/attachment-headers.txt \
  "$base_url/tasks/$task_id/attachments/$attachment_id" > /tmp/attachment-download.txt
cmp /tmp/tms-attachment.txt /tmp/attachment-download.txt
grep -qi '^Content-Disposition: attachment;' /tmp/attachment-headers.txt
grep -qi 'filename\*=UTF-8' /tmp/attachment-headers.txt

curl --fail --silent --cookie "$cookies" "$base_url/tasks/$task_id/edit" > /tmp/attachment-edit-after.html
grep -q 'notes.txt' /tmp/attachment-edit-after.html

bad_before=$(db "SELECT COUNT(*) FROM attachments WHERE task_id=$task_id")
printf '<?php echo 1; ?>\n' > /tmp/evil.php
bad_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" \
  -F "_csrf=$csrf" \
  -F 'attachments[]=@/tmp/evil.php;filename=evil.php;type=text/plain' \
  "$base_url/tasks/$task_id/attachments")
test "$bad_status" = "422"
test "$(db "SELECT COUNT(*) FROM attachments WHERE task_id=$task_id")" = "$bad_before"

foreign_storage=$(printf 'f%.0s' $(seq 1 64))
foreign_sha=$(printf '0%.0s' $(seq 1 64))
foreign_path="/var/www/html/var/storage/attachments/ff/$foreign_storage"
docker compose exec -T app sh -c "mkdir -p /var/www/html/var/storage/attachments/ff && printf 'foreign secret\\n' > '$foreign_path' && chown -R www-data:www-data /var/www/html/var/storage/attachments/ff"
db "INSERT INTO attachments (task_id,user_id,storage_name,original_name,mime_type,file_size,sha256) VALUES ($foreign_task,$other_id,'$foreign_storage','foreign.txt','text/plain',15,'$foreign_sha')"
foreign_attachment=$(db "SELECT id FROM attachments WHERE storage_name='$foreign_storage' LIMIT 1")
test -n "$foreign_attachment"

foreign_download=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" "$base_url/tasks/$foreign_task/attachments/$foreign_attachment")
test "$foreign_download" = "404"
foreign_delete=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" --data-urlencode "_csrf=$csrf" \
  "$base_url/tasks/$foreign_task/attachments/$foreign_attachment/delete")
test "$foreign_delete" = "404"
test "$(db "SELECT COUNT(*) FROM attachments WHERE id=$foreign_attachment")" = "1"

foreign_upload=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" -F "_csrf=$csrf" \
  -F 'attachments[]=@/tmp/tms-attachment.txt;filename=attack.txt;type=text/plain' \
  "$base_url/tasks/$foreign_task/attachments")
test "$foreign_upload" = "404"

cross_storage=$(printf 'e%.0s' $(seq 1 64))
cross_sha=$(printf '1%.0s' $(seq 1 64))
if db "INSERT INTO attachments (task_id,user_id,storage_name,original_name,mime_type,file_size,sha256) VALUES ($task_id,$other_id,'$cross_storage','cross.txt','text/plain',1,'$cross_sha')"; then
  echo 'Cross-user attachment foreign key unexpectedly accepted.' >&2
  exit 1
fi

delete_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" --data-urlencode "_csrf=$csrf" \
  "$base_url/tasks/$task_id/attachments/$attachment_id/delete")
test "$delete_status" = "302"
test "$(db "SELECT COUNT(*) FROM attachments WHERE id=$attachment_id")" = "0"
if docker compose exec -T app test -e "$storage_path"; then
  echo 'Physical attachment survived explicit deletion.' >&2
  exit 1
fi

upload_again=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" -F "_csrf=$csrf" \
  -F 'attachments[]=@/tmp/tms-attachment.txt;filename=cleanup.txt;type=text/plain' \
  "$base_url/tasks/$task_id/attachments")
test "$upload_again" = "302"
cleanup_storage=$(db "SELECT storage_name FROM attachments WHERE task_id=$task_id AND original_name='cleanup.txt' ORDER BY id DESC LIMIT 1")
test -n "$cleanup_storage"
cleanup_path="/var/www/html/var/storage/attachments/${cleanup_storage:0:2}/$cleanup_storage"
docker compose exec -T app test -f "$cleanup_path"

delete_task=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --cookie "$cookies" --data-urlencode "_csrf=$csrf" \
  "$base_url/tasks/$task_id/delete")
test "$delete_task" = "302"
test "$(db "SELECT COUNT(*) FROM tasks WHERE id=$task_id")" = "0"
test "$(db "SELECT COUNT(*) FROM attachments WHERE task_id=$task_id")" = "0"
if docker compose exec -T app test -e "$cleanup_path"; then
  echo 'Physical attachment survived task deletion.' >&2
  exit 1
fi
