#!/bin/sh
set -eu

attachment_storage="${ATTACHMENT_STORAGE_PATH:-/var/www/html/var/storage/attachments}"
mkdir -p "$attachment_storage"
chown www-data:www-data "$attachment_storage"
chmod 0700 "$attachment_storage"

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

exec "$@"
