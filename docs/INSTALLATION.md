[English](INSTALLATION.md) | [Русский](INSTALLATION.ru.md)

# Installation and operations

The recommended deployment is Docker Compose with the published image. TMS needs an application container, MariaDB, a notification scheduler and, when enabled, the Telegram polling worker.

## 1. Prepare configuration

Copy the example file:

```bash
cp .env.example .env
```

Required or strongly recommended values:

| Variable | Purpose |
| --- | --- |
| `APP_URL` | Browser-visible base URL, for example `https://tasks.example.com` |
| `APP_TIMEZONE` | Default IANA timezone, for example `Europe/Berlin` |
| `APP_LOCALE` | Default interface/new-user metadata locale: `en` or `ru` |
| `DB_PASS` | Strong MariaDB password |
| `SESSION_SECURE` | `true` behind HTTPS; `false` only for intentional plain HTTP |
| `TMS_PORT` | Host HTTP port, default `8080` |

Other supported settings:

| Variable | Default | Meaning |
| --- | --- | --- |
| `APP_ENV` | `production` | Application environment |
| `APP_DEBUG` | `false` | Debug output; keep disabled in production |
| `DB_NAME` / `DB_USER` | `tms` / `tms` | Application database credentials |
| `SESSION_NAME` | `tms_session` | Session cookie name |
| `SESSION_SAMESITE` | `Lax` | SameSite cookie policy |
| `REMEMBER_COOKIE_NAME` | `tms_remember` | Persistent-login cookie name |
| `REMEMBER_DAYS` | `30` | Persistent-login lifetime |
| `REGISTRATION_ENABLED` | `false` | Expose public registration |
| `REGISTRATION_AUTO_APPROVE_AFTER_EMAIL` | `true` | Approve a user after email verification |
| `ATTACHMENT_MAX_BYTES` | `10485760` | Maximum attachment size in bytes |
| `NOTIFICATION_INTERVAL_SECONDS` | `60` | Docker notifier interval |
| `NOTIFICATION_SECRET` | empty | Optional external 32-byte base64 encryption key |
| `TELEGRAM_*` | empty | Optional bootstrap/fallback Telegram configuration |
| `TMS_IMAGE` | stable pinned image | Override image used by `compose.portainer.yaml` |

`DB_HOST`, `DB_PORT`, `ATTACHMENT_STORAGE_PATH` and `NOTIFICATION_SECRET_FILE` normally keep their Compose defaults. `TMS_SKIP_MIGRATIONS` is an internal worker setting and should not be set for the application container.

## 2. Start TMS

Using the published image:

```bash
docker compose -f compose.portainer.yaml up -d
```

Or build from the current source tree:

```bash
docker compose up -d --build
```

Migrations run automatically in the application container. MariaDB is not published to the host.

Check state:

```bash
docker compose -f compose.portainer.yaml ps
```

## 3. Create the first administrator

```bash
docker compose -f compose.portainer.yaml exec app \
  php bin/create-admin.php admin admin@example.com
```

The password is requested interactively and is not echoed. `TMS_ADMIN_PASSWORD` is available for one-shot automation but should not remain in the stack environment.

## 4. Reverse proxy and HTTPS

Expose only the application HTTP port to your reverse proxy. Terminate TLS in nginx, Caddy, Traefik or a similar proxy and keep `SESSION_SECURE=true`.

Minimal nginx proxy block:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

Set `APP_URL` to the public browser URL, not the Docker service name.

## Portainer

Create a Stack from `compose.portainer.yaml` or paste its contents. Define `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE`, `DB_PASS`, `SESSION_SECURE` and optionally `TMS_PORT` in the Stack environment.

The file is pinned to `ghcr.io/mirivlad/tms:v0.1.3`. Set `TMS_IMAGE` only when you deliberately want another version, `latest`, `edge`, or an immutable `sha-*` build.

After deployment, open the `app` container console and run `php bin/create-admin.php ...` for the first account.

## Persistent data and backup

The standard stack uses three named volumes:

- `tms-db` — MariaDB data;
- `tms-attachments` — uploaded attachments;
- `tms-secrets` — automatically generated notification encryption key.

Back up **all three**. A database backup without attachments is incomplete; a database containing encrypted SMTP/Telegram credentials without the matching notification key cannot decrypt those credentials.

For a consistent database dump:

```bash
docker compose -f compose.portainer.yaml exec -T db \
  sh -c 'mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' > tms.sql
```

Back up named volumes with your normal Docker-volume backup method. Test restore procedures before relying on them.

If you explicitly use `NOTIFICATION_SECRET`, back up that deployment secret instead of relying on the generated key volume. Never rotate or remove it without re-entering encrypted notification credentials.

## Updating

For a pinned release, edit `TMS_IMAGE` or the compose default to the new version and then:

```bash
docker compose -f compose.portainer.yaml pull
docker compose -f compose.portainer.yaml up -d
```

Migrations apply automatically. Do not delete the database, attachments or secrets volumes during a normal update.

Before upgrading, read the release notes and take a backup. Prefer versioned tags over `latest` when deterministic rollback matters.

## Rollback

Application rollback is done by restoring the previous image tag. Database migrations are forward-only; if a release introduces an incompatible migration, a full rollback requires restoring the matching database backup. Do not assume changing only the image is always sufficient.

## Native installation

Native development is supported, but Docker/Apache is the reference runtime. For native notifications, run `php bin/notify.php` from cron and run `php bin/telegram-poll.php` as a supervised long-lived process when Long polling is selected. Run only one polling worker per database.
