#!/usr/bin/env bash
set -euo pipefail

container=tms-upgrade-db
upgrade_db=tms_upgrade
upgrade_pass="$DB_PASS"

cleanup() {
  docker rm -f "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup

app_image=$(docker compose images -q app | head -n1)
test -n "$app_image"

docker run -d --name "$container"   -e MARIADB_DATABASE="$upgrade_db"   -e MARIADB_USER=tms   -e MARIADB_PASSWORD="$upgrade_pass"   -e MARIADB_ROOT_PASSWORD=upgrade-root-password   mariadb:11.4 >/dev/null

for _ in $(seq 1 60); do
  if docker exec "$container" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
docker exec "$container" healthcheck.sh --connect --innodb_initialized >/dev/null

sql() {
  docker exec "$container" mariadb -N -utms -p"$upgrade_pass" "$upgrade_db" -e "$1"
}

docker exec "$container" mariadb -utms -p"$upgrade_pass" "$upgrade_db" -e "
  CREATE TABLE schema_migrations (
    version VARCHAR(255) NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (version)
  ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;
"

# Reconstruct the stable v0.1.4 database: only the numbered baseline migrations
# existed before the v0.2 project/team/discussion line.
for file in $(find database/migrations -maxdepth 1 -type f -name '0*.sql' | sort); do
  docker exec -i "$container" mariadb -utms -p"$upgrade_pass" "$upgrade_db" < "$file"
  version=$(basename "$file")
  sql "INSERT INTO schema_migrations (version, applied_at) VALUES ('$version', UTC_TIMESTAMP())"
done

# Representative v0.1.4 data. IDs are explicit so semantic preservation can be
# checked after status/custom-field schema migrations.
sql "INSERT INTO users (
       id,username,email,password_hash,role,is_active,email_verified_at,approved_at
     ) VALUES (
       101,'legacy','legacy@example.invalid','legacy-hash','user',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()
     )"
sql "INSERT INTO statuses (
       id,user_id,name,description,color,sort_order,is_default,is_completion,show_on_board
     ) VALUES
       (201,101,'Legacy Inbox','old default','#123456',10,1,0,1),
       (202,101,'Legacy Done','old completion','#654321',20,0,1,1)"
sql "INSERT INTO task_types (id,user_id,name,description,sort_order)
     VALUES (301,101,'Legacy Type','old type',10)"
sql "INSERT INTO customers (id,user_id,name) VALUES (401,101,'Legacy Customer')"
sql "INSERT INTO tasks (
       id,created_by,title,description,deadline,status_id,type_id,priority,customer_id
     ) VALUES (
       501,101,'Legacy task','<p>Keep this task</p>','2026-10-01 12:30:00',
       201,301,2,401
     )"
sql "INSERT INTO custom_fields (
       id,user_id,name,field_type,options_json,is_required,sort_order
     ) VALUES (601,101,'Legacy field','text',NULL,0,10)"
sql "INSERT INTO task_custom_field_values (task_id,field_id,user_id,value)
     VALUES (501,601,101,'legacy-value')"
sql "INSERT INTO attachments (
       id,task_id,user_id,storage_name,original_name,mime_type,file_size,sha256
     ) VALUES (
       701,501,101,
       'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
       'legacy.txt','text/plain',12,
       'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
     )"
sql "INSERT INTO notification_settings (
       user_id,email_enabled,email_address,notify_tomorrow,tomorrow_time
     ) VALUES (101,1,'legacy-alerts@example.invalid',1,'07:45:00')"

baseline_migrations=$(sql "SELECT COUNT(*) FROM schema_migrations")
test "$baseline_migrations" = "11"

# Upgrade with the exact application image under test.
upgrade_output=$(docker run --rm   --network "container:$container"   --entrypoint php   -e DB_HOST=127.0.0.1   -e DB_PORT=3306   -e DB_NAME="$upgrade_db"   -e DB_USER=tms   -e DB_PASS="$upgrade_pass"   "$app_image" /var/www/html/bin/migrate.php)
printf '%s\n' "$upgrade_output" | grep -q 'Applied 20260918_001_projects_core.sql'
printf '%s\n' "$upgrade_output" | grep -q 'Applied 20260919_006_internal_notifications.sql'
printf '%s\n' "$upgrade_output" | grep -q 'Applied 20260919_007_discussion_team_context.sql'

# Stable data remains personal/unassigned and keeps its semantic identity.
test "$(sql "SELECT title FROM tasks WHERE id=501")" = "Legacy task"
test "$(sql "SELECT status_id FROM tasks WHERE id=501")" = "201"
test "$(sql "SELECT type_id FROM tasks WHERE id=501")" = "301"
test "$(sql "SELECT customer_id FROM tasks WHERE id=501")" = "401"
test "$(sql "SELECT project_id IS NULL FROM tasks WHERE id=501")" = "1"
test "$(sql "SELECT assignee_user_id IS NULL FROM tasks WHERE id=501")" = "1"
test "$(sql "SELECT user_id=101 AND project_id IS NULL FROM statuses WHERE id=201")" = "1"
test "$(sql "SELECT user_id=101 AND project_id IS NULL FROM custom_fields WHERE id=601")" = "1"
test "$(sql "SELECT value FROM task_custom_field_values WHERE task_id=501 AND field_id=601")" = "legacy-value"
test "$(sql "SELECT original_name FROM attachments WHERE id=701")" = "legacy.txt"
test "$(sql "SELECT email_address FROM notification_settings WHERE user_id=101")" = "legacy-alerts@example.invalid"

# New collaboration structures exist after upgrade.
test "$(sql "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema='$upgrade_db' AND table_name='teams'")" = "1"
test "$(sql "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema='$upgrade_db' AND table_name='discussion_comments'")" = "1"
test "$(sql "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema='$upgrade_db' AND table_name='internal_notifications'")" = "1"
test "$(sql "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema='$upgrade_db' AND table_name='discussion_comments' AND column_name='team_id'")" = "1"

expected_migrations=$(find database/migrations -maxdepth 1 -type f -name '*.sql' | wc -l | tr -d ' ')
test "$(sql "SELECT COUNT(*) FROM schema_migrations")" = "$expected_migrations"

# A second startup is idempotent and does not mutate data.
before_task=$(sql "SELECT CONCAT(title,'|',status_id,'|',COALESCE(project_id,'NULL'),'|',COALESCE(assignee_user_id,'NULL')) FROM tasks WHERE id=501")
second_output=$(docker run --rm   --network "container:$container"   --entrypoint php   -e DB_HOST=127.0.0.1   -e DB_PORT=3306   -e DB_NAME="$upgrade_db"   -e DB_USER=tms   -e DB_PASS="$upgrade_pass"   "$app_image" /var/www/html/bin/migrate.php)
printf '%s\n' "$second_output" | grep -q '^Database is up to date\.$'
test "$(sql "SELECT CONCAT(title,'|',status_id,'|',COALESCE(project_id,'NULL'),'|',COALESCE(assignee_user_id,'NULL')) FROM tasks WHERE id=501")" = "$before_task"
