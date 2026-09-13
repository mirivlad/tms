#!/bin/sh
set -eu

attachment_storage="${ATTACHMENT_STORAGE_PATH:-/var/www/html/var/storage/attachments}"
notification_secret_file="${NOTIFICATION_SECRET_FILE:-/var/www/html/var/secrets/notification.key}"
notification_secret_dir="$(dirname "$notification_secret_file")"
mkdir -p "$attachment_storage" "$notification_secret_dir"
chown www-data:www-data "$attachment_storage" "$notification_secret_dir"
chmod 0700 "$attachment_storage" "$notification_secret_dir"

if [ "${TMS_SKIP_MIGRATIONS:-false}" != "true" ]; then
    attempt=1
    max_attempts=30

    until php /var/www/html/bin/migrate.php; do
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "TMS database migration did not succeed after ${max_attempts} attempts." >&2
            exit 1
        fi

        echo "Database is not ready yet; retrying migration (${attempt}/${max_attempts})..." >&2
        attempt=$((attempt + 1))
        sleep 2
    done
fi

exec "$@"
