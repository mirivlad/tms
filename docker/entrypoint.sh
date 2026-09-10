#!/bin/sh
set -eu

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
