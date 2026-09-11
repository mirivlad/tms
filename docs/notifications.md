# Notifications

TMS uses one notification engine for email and Telegram. It replaces the donor's overlapping notification jobs.

## Model

SMTP is deployment-wide and is configured by an administrator in **Notification administration**. Each user independently chooses whether email and/or Telegram delivery is enabled and which rules are active: tasks due tomorrow, upcoming deadlines, overdue tasks, and a daily digest.

Notification times use `APP_TIMEZONE`. TMS does not currently reinterpret individual task deadlines in per-user timezones; that decision is intentionally deferred until the user-profile/timezone work.

Successful deliveries are written to `sent_notifications` with a per-channel dedupe key. A failed email does not suppress a Telegram retry, and vice versa. Upcoming-task dedupe includes the deadline, so moving a deadline can legitimately produce a new notification.

## SMTP secret

SMTP metadata is stored in the database. The SMTP password is encrypted with libsodium before storage. Configure `NOTIFICATION_SECRET` as base64-encoded 32 random bytes:

```sh
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Keep this value with the deployment secrets and back it up. Losing or changing it makes the stored SMTP password undecryptable; enter the SMTP password again after rotating the key.

## Telegram

Create a bot with BotFather and configure:

```text
TELEGRAM_BOT_TOKEN=...
TELEGRAM_BOT_NAME=@your_bot_name
TELEGRAM_WEBHOOK_SECRET=<strong-random-secret>
```

`APP_URL` must be an externally reachable HTTPS URL for Telegram webhooks. An administrator then opens `/admin/notifications` and presses **Set Telegram webhook**. TMS verifies every webhook request with Telegram's `X-Telegram-Bot-Api-Secret-Token` header.

Users generate a one-time command from `/settings/notifications` and send it to the bot. Link tokens expire after 24 hours, are single-use, and only a SHA-256 verifier hash is stored in the database.

## Scheduler

The Docker and Portainer stacks include a `notifier` service. It runs `php bin/notify.php` repeatedly; `NOTIFICATION_INTERVAL_SECONDS` defaults to 60 seconds. The runner itself is idempotent through the sent-notification journal, so running it every minute is safe.

For a native installation, do not run the Docker notifier. Use cron instead, for example:

```cron
* * * * * cd /srv/tms && /usr/bin/php bin/notify.php >> var/notification-cron.log 2>&1
```

Run only one scheduler strategy per installation. Running several workers is unnecessary; the database dedupe guard prevents duplicate successful inserts, but two workers can still race and both attempt an external delivery before either records success.
