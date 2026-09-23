[English](ADMINISTRATION.md) | [Русский](ADMINISTRATION.ru.md)

# Administration

This handbook is the canonical administrator/operator manual for TMS. It covers deployment, instance-wide configuration, account administration, delivery workers, backups, restores, upgrades, webhooks, troubleshooting and release-documentation duties.

[TOC]

## Deployment model

The reference self-hosted deployment consists of:

- **app** — Apache/PHP web application; runs database migrations on startup;
- **db** — MariaDB persistent data;
- **notifier** — recurring-task generation plus scheduled notification runner;
- **webhook-worker** — durable outbound webhook recovery and delivery;
- **telegram-poller** — long-lived Telegram command receiver when Long polling is selected;
- persistent volumes for the database, attachments and encryption secrets.

The web application should normally be the only service exposed through the reverse proxy. MariaDB and worker services remain private to the deployment network.

### Example: first Docker/Portainer deployment

1. Copy `.env.example` to a protected environment configuration.
2. Set at least `DB_PASS`, `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE` and `SESSION_SECURE`.
3. Start `compose.portainer.yaml` in Portainer, or use Docker Compose directly.
4. Wait until **app** reports healthy.
5. Create the first administrator from the app container.
6. Sign in and configure system notifications/webhooks as required.
7. Make a first backup before importing real production data.

See [Installation](INSTALLATION.md) for the complete environment-variable and reverse-proxy instructions.

## First administrator

Create the initial administrator from the application container:

```bash
php bin/create-admin.php admin admin@example.com
```

After sign-in, administration pages are available from the user/admin navigation.

For a Portainer deployment:

```bash
docker compose -f compose.portainer.yaml exec app \
  php bin/create-admin.php admin admin@example.com
```

The command prompts/uses the configured secure password path; do not put a real production password into shell history or a committed compose file.

## Users and registration

Public registration is controlled by `REGISTRATION_ENABLED`.

When registration is enabled, email verification links use `APP_URL`. With `REGISTRATION_AUTO_APPROVE_AFTER_EMAIL=true`, successful verification also approves the account. Set it to `false` when every new account must be approved under **Administration → Pending users**.

Administrators can create users directly, change role/active state, approve accounts, mark email as verified, resend verification, inspect user details and impersonate an account for troubleshooting. Do not use impersonation as a substitute for reproducing authorization bugs with a normal test account.

A user who is the sole Lead of any team cannot be disabled or deleted. Promote another team member to Lead first; this prevents a team from becoming permanently unmanaged through an administrative account operation.

## Metadata and custom fields

Statuses, task types, customers and custom fields are user-owned. A user's metadata does not become global merely because the user is an administrator.

Status administration supports ordering, a default status and a completion status. TMS protects status/type/customer ownership at both application and database boundaries.

Custom fields support text, textarea, select, money, checkbox and checkbox-list types. Changing options may prune values that are no longer valid.

## SMTP

Open **Administration → System notifications**. Configure host, port, TLS/SSL mode, credentials and sender identity, enable the transport, save and use **Send SMTP test email**.

The SMTP password is encrypted before storage. The encryption key is either the external `NOTIFICATION_SECRET` or the automatically generated key in `tms-secrets`.

## Telegram

Create a bot with BotFather, then configure its username and token under **Administration → System notifications**. Use **Test Telegram connection** before configuring delivery mode.

Choose one command-receiving mode:

- **Long polling (recommended):** TMS calls `getUpdates`; no inbound Telegram connection is required. The `telegram-poller` service removes an existing webhook when polling becomes active and persists its update offset in MariaDB.
- **Webhook:** requires a public HTTPS `APP_URL`. Configure/generate a webhook secret, select Webhook and press **Set Telegram webhook**.

If direct access to `api.telegram.org` is filtered, configure the separate Telegram proxy block. Supported proxy URL schemes are `http://`, `https://`, `socks5://` and `socks5h://`. The URL describes the **proxy protocol**, not the protocol used by Telegram. An HTTP proxy used to reach HTTPS Telegram endpoints is therefore written as `http://proxy:port`.

The Telegram proxy is used for connection tests, outgoing messages, webhook API calls and Long polling.

See [Notifications](notifications.md) for the user-linking flow and troubleshooting.

## Outbound webhooks

Open **Administration → Webhooks** to connect TMS domain events to automation, monitoring or agent systems.

For each subscription:

1. choose a descriptive name;
2. enter the receiver HTTP/HTTPS URL;
3. select only the event types the receiver actually needs;
4. create the subscription;
5. copy the generated signing secret immediately — it is displayed only once;
6. configure the receiver to verify `X-TMS-Signature` and replay age;
7. trigger one harmless test event and inspect the delivery history.

Webhook delivery is asynchronous. The `webhook-worker` reconciles missing queue rows from the durable event journal, signs each request and retries failures with backoff. A broken external receiver therefore does not block TMS task/project operations.

Webhook administration is intentionally admin-only. Private/internal endpoint addresses are allowed because self-hosted automation often lives on the same network; that also means webhook administrators must be treated as trusted infrastructure operators.

See [Webhooks](webhooks.md) for the protocol, HMAC verification and retry schedule.

## Background workers

### notifier

Runs:

- `bin/recurring.php` — generates due recurring occurrences;
- `bin/notify.php` — creates/delivers scheduled reminders.

Default loop interval: `NOTIFICATION_INTERVAL_SECONDS=60`.

### webhook-worker

Runs `bin/webhooks.php`, which:

- reconciles the durable domain-event journal with active webhook subscriptions;
- sends due deliveries;
- schedules retry/backoff;
- records delivery history.

Default loop interval: `WEBHOOK_INTERVAL_SECONDS=15`.

Run one webhook worker per database.

### telegram-poller

Runs continuously only when Telegram Long polling is used. A single polling worker per database must own the update stream.

### Worker health check

```bash
docker compose -f compose.portainer.yaml ps
docker compose -f compose.portainer.yaml logs --tail=100 notifier
docker compose -f compose.portainer.yaml logs --tail=100 webhook-worker
docker compose -f compose.portainer.yaml logs --tail=100 telegram-poller
```

A worker may be restarted without restarting the web application. Recurrence generation, notification deduplication and webhook delivery are designed to tolerate retries.

## Backups and upgrades

Treat `tms-db`, `tms-attachments` and `tms-secrets` as one logical backup set. A database dump alone is not a complete TMS backup.

### Backup procedure

1. Record the currently deployed TMS image/version.
2. Create a consistent MariaDB dump.
3. Copy/archive the attachment volume.
4. Copy/archive the secret volume containing the notification encryption key.
5. Store all parts under the same backup identifier/date.
6. Test that the dump can be read and the archives are non-empty.
7. Periodically perform a restore rehearsal on an isolated instance.

Example database dump:

```bash
docker compose -f compose.portainer.yaml exec -T db \
  mariadb-dump -utms -p"$DB_PASS" --single-transaction --routines --triggers tms \
  > tms-$(date +%F).sql
```

Use your Docker/Portainer backup method for volumes. The exact volume names may be stack-prefixed, so verify them with `docker volume ls` instead of copying a hard-coded name from documentation.

### Restore procedure

1. Stop traffic to the target TMS instance.
2. Restore the secret volume **before** expecting encrypted SMTP/Telegram/webhook credentials to work.
3. Restore attachments.
4. Restore the database into a compatible MariaDB instance.
5. Deploy the image version that matches the backup schema.
6. Start the application and let health checks complete.
7. Verify sign-in, one attachment, notification settings and worker state.
8. Only then reopen user traffic.

### Upgrade procedure

1. Read release notes and migration notes.
2. Take the complete backup set above.
3. Pull/deploy the new versioned image.
4. Let **app** run migrations and become healthy.
5. Confirm **notifier**, **webhook-worker** and Telegram worker state.
6. Run a short functional check: sign-in, create/edit a task, open Calendar, inspect Notifications.
7. Keep the backup until the new release has operated normally for your chosen observation period.

Migrations are forward-only. A rollback to an older image is not guaranteed after an incompatible schema change; restore the matching backup for a true rollback.

Read [Installation](INSTALLATION.md) before upgrades or restores.

## Password recovery

Browser recovery can use configured email and/or a linked Telegram account. A self-hosted administrator can always issue or apply a local reset from the container console. See [Account recovery](ACCOUNT_RECOVERY.md).

## Troubleshooting cookbook

### Application is unhealthy

```bash
docker compose -f compose.portainer.yaml ps
docker compose -f compose.portainer.yaml logs --tail=200 app
docker compose -f compose.portainer.yaml logs --tail=200 db
```

Check database health, migration errors, required environment variables and write permissions for `var/`.

### Users can sign in but reminders do not arrive

1. Confirm `notifier` is running.
2. Check the user's Notification settings.
3. Verify the task is not in a completion status.
4. Verify the user owns or is assigned to the task and still has project/team access.
5. Check SMTP/Telegram test delivery separately from the reminder rule.

### Webhook remains pending/retry

1. Check `webhook-worker` logs.
2. Inspect Administration → Webhooks delivery history.
3. Confirm DNS/network reachability **from the worker container**, not only from the host.
4. Confirm the receiver returns a 2xx status without redirect.
5. Verify the receiver uses the current secret after rotation.
6. Use manual Retry only after the endpoint problem is fixed.

### Telegram commands stop working

Confirm the selected delivery mode, bot token, proxy configuration and that exactly one polling worker is active when Long polling is selected. Switching to Long polling removes the Telegram webhook; switching to Webhook requires a public HTTPS `APP_URL`.

### Encrypted notification/webhook credentials suddenly fail after restore

The database was restored without the matching `tms-secrets` encryption key. Restore the secret volume from the same backup set. Generating a new key cannot decrypt ciphertext produced by the previous key.

## Operational checks

Useful checks after a deployment change:

```bash
docker compose -f compose.portainer.yaml ps
docker compose -f compose.portainer.yaml logs --tail=100 app
docker compose -f compose.portainer.yaml logs --tail=100 notifier
docker compose -f compose.portainer.yaml logs --tail=100 webhook-worker
docker compose -f compose.portainer.yaml logs --tail=100 telegram-poller
```

Do not enable `APP_DEBUG` on an Internet-facing production instance except for a short, controlled troubleshooting window.


## Documentation maintenance during releases

User/admin handbooks are release artifacts, not optional notes. A behavior/configuration change must update the affected English **and** Russian handbook source in the same pull request.

CI runs a documentation-impact check and builds all handbook HTML/PDF artifacts. Stable/preview releases attach the generated files to GitHub Release.

Canonical policy: [DOCUMENTATION_POLICY.md](DOCUMENTATION_POLICY.md).
