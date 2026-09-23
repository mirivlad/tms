# TMS — Complete administrator handbook

This is the operations handbook for the owner of a self-hosted TMS deployment. It covers deployment, upgrades, users, notification transports, outbound webhooks, backup/restore, maintenance and troubleshooting.

[TOC]

## What an administrator should know first

The standard Docker/Portainer stack consists of MariaDB and four application roles:

| Service | Purpose |
| --- | --- |
| `db` | MariaDB and persistent relational data |
| `app` | Web UI, API, migrations and administrative actions |
| `notifier` | Recurring task generation and in-app/email/Telegram notification delivery |
| `webhook-worker` | Asynchronous delivery of signed outbound webhooks |
| `telegram-poller` | Telegram command intake when Long polling is selected |

Critical persistent data is one logical set: `tms-db`, `tms-attachments`, and `tms-secrets`. Losing one part can make a restore incomplete.

## Canonical TMS administration reference

{{include:../ADMINISTRATION.md|shift=1|strip_nav}}

## Installation, reverse proxy, upgrades and backup

{{include:../INSTALLATION.md|shift=1|strip_nav}}

## System notifications, SMTP and Telegram

{{include:../notifications.md|shift=1|strip_nav}}

## Outbound webhooks

{{include:../webhooks.md|shift=1|strip_nav}}

## Account recovery

{{include:../ACCOUNT_RECOVERY.md|shift=1|strip_nav}}

## Practical runbooks

### Runbook 1. First deployment with Portainer

1. Take `compose.portainer.yaml` from the TMS version you intend to run.
2. Create a Stack and define at least `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE`, `DB_PASS`, and `SESSION_SECURE`.
3. For reproducible production, use a versioned `TMS_IMAGE` instead of floating `edge`.
4. Start the stack and wait for `app` to become healthy.
5. In the `app` container console create the first administrator:

```bash
php bin/create-admin.php admin admin@example.com
```

6. Sign in and verify deployment timezone and the administration menu.
7. Check `app`, `notifier`, and `webhook-worker`; `telegram-poller` is required when Long polling is selected.
8. Before production data accumulates, configure regular backups of all three persistent volumes.

### Runbook 2. Safe upgrade

1. Read the release notes.
2. Take a consistent MariaDB dump and back up `tms-attachments` plus `tms-secrets`.
3. Record the current image tag and backup time.
4. Change `TMS_IMAGE` to the new release.
5. Run:

```bash
docker compose -f compose.portainer.yaml pull
docker compose -f compose.portainer.yaml up -d
```

6. Verify health and migrations, then sign in.
7. Exercise task create/edit, scheduler, notifications and webhooks when used.
8. Never delete persistent volumes during a normal upgrade.

Migrations are forward-oriented. A full rollback after an incompatible schema migration requires the matching database backup, not only the previous image tag.

### Runbook 3. Complete backup

Start with the database:

```bash
docker compose -f compose.portainer.yaml exec -T db \
  sh -c 'mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' > tms.sql
```

In the same backup set preserve:

- `tms-attachments`;
- `tms-secrets`;
- the compose/.env configuration and active image tag;
- external `NOTIFICATION_SECRET` when used instead of the generated volume key.

Periodically restore into a separate test deployment. A backup that has never been restored is not a tested backup.

### Runbook 4. Disaster restore

1. Do not start a fresh empty TMS over the only copies of old volumes.
2. Prepare an application version compatible with the saved database schema.
3. Restore `tms-db`, `tms-attachments`, and `tms-secrets` as one consistent set.
4. When using an SQL dump instead of a raw database volume, create a clean MariaDB and import it using your normal database restore procedure.
5. Restore the same external `NOTIFICATION_SECRET` when applicable.
6. Start the stack.
7. Verify sign-in, attachments, stored transport credentials, scheduler and workers.
8. Switch production traffic only after validation.

### Runbook 5. Create or approve a user

1. Open **Administration → Users**.
2. With registration disabled, create an account directly.
3. With registration enabled, review pending accounts.
4. Confirm email/approve according to deployment policy.
5. Grant administrator role only when system administration is required.
6. Before disabling/deleting a user, verify they are not the last lead of a team.

### Runbook 6. Configure SMTP

1. Open **Administration → System notifications**.
2. Configure host, port, encryption, credentials and From address.
3. Save.
4. Use **Send SMTP test email**.
5. Verify actual delivery, not only a successful UI response.
6. Ensure `tms-secrets` or external `NOTIFICATION_SECRET` is backed up.

### Runbook 7. Configure Telegram

1. Create a bot with BotFather.
2. Enter bot name/token in system notifications.
3. Run the connection test.
4. Choose Long polling for a typical self-hosted setup, or Webhook with a public HTTPS `APP_URL`.
5. Configure proxy settings when required using the proxy's actual scheme.
6. In a user account generate the one-time link command and send it to the bot.
7. Send a test message.
8. In Long polling mode inspect `telegram-poller` logs when troubleshooting.

### Runbook 8. Create an outbound webhook

1. Open **Administration → Webhooks**.
2. Create a subscription, endpoint and the minimum required event types.
3. Copy the signing secret immediately; it is not shown in plaintext again.
4. The receiver must verify the HMAC-SHA256 signature over `timestamp.body` as documented in the webhook guide.
5. Generate a test event in TMS.
6. Inspect delivery history and HTTP status.
7. After fixing a temporary receiver failure, let retry policy continue or use manual retry.
8. If the secret is compromised, rotate it and update the consumer.

### Runbook 9. Fast worker diagnosis

Start with:

```bash
docker compose -f compose.portainer.yaml ps
docker compose -f compose.portainer.yaml logs --tail=150 app
docker compose -f compose.portainer.yaml logs --tail=150 notifier
docker compose -f compose.portainer.yaml logs --tail=150 webhook-worker
docker compose -f compose.portainer.yaml logs --tail=150 telegram-poller
```

If the web UI is healthy but automation is not:

- missing recurring tasks/reminders → inspect `notifier`;
- the event exists but webhook delivery does not → inspect `webhook-worker` and delivery history;
- Telegram sends messages but commands are not received → inspect `telegram-poller`/Webhook mode;
- application fails after an upgrade → inspect `app`, MariaDB health and migrations.

### Runbook 10. Minimal security check after changes

- Production uses `APP_DEBUG=false`.
- `APP_URL` is the real external HTTPS URL.
- `SESSION_SECURE=true` behind HTTPS.
- MariaDB is not exposed externally without a reason.
- Backups contain database, attachments and secrets.
- Administrator role is limited to accounts that need it.
- SMTP/Telegram/webhook secrets are not pasted into issues, chats or the repository.
- Rotated credentials are followed by an actual delivery test.
- Webhook consumers verify signatures.

## Routine maintenance

Review container health and worker errors weekly or after meaningful configuration changes. Take a backup before every upgrade and run a short smoke test of the features important to your deployment afterward. Preserve deployment configuration with backup metadata so disaster recovery does not depend on administrator memory.
