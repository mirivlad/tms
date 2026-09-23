[English](notifications.md) | [Русский](notifications.ru.md)

# Notifications and Telegram

TMS uses one notification engine for email and Telegram. System transports are configured by an administrator; delivery preferences belong to each user.

## User notification rules

The in-app inbox is the canonical notification channel. Email and Telegram are optional transports that carry the same work event after the in-app record has been created.

Under **Settings → Notifications**, users can control event notifications for:

- task assignment and reassignment;
- changes to `scheduled_at` and deadline;
- status changes (off by default to avoid noisy upgrades);
- discussion replies and `@username` mentions.

Scheduled reminder rules cover:

- tasks due tomorrow;
- approaching `scheduled_at` and deadlines, with lead time by priority;
- overdue tasks;
- daily digest.

Assignments and date-change notifications are enabled by default. Status-change notifications are disabled by default and can be enabled per user.

Successful external deliveries are journaled in `sent_notifications` with per-channel deduplication. A failure on one external channel does not suppress another. Scheduled reminders are also deduplicated in the in-app inbox.

The background scheduler evaluates configured send times in `APP_TIMEZONE`. It considers tasks that the user created or is currently assigned to, while respecting current task access and completion status.

## Encryption key

SMTP passwords, Telegram bot tokens, webhook secrets and proxy URLs are encrypted with libsodium before database storage.

By default TMS generates a 32-byte key and stores it in `/var/www/html/var/secrets/notification.key`, backed by the `tms-secrets` volume. The file is reused by `app`, `notifier` and `telegram-poller`.

An external `NOTIFICATION_SECRET` may be supplied as base64-encoded 32 random bytes:

```sh
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Do not change an existing key without re-entering encrypted credentials. Back it up with the database.

## SMTP

Configure SMTP in **Administration → System notifications** and use the built-in test email before relying on scheduled notifications.

## Telegram bot setup

1. Create a bot with BotFather.
2. Enter bot username and token in **Administration → System notifications**.
3. Save and press **Test Telegram connection**.
4. Configure a Telegram proxy if direct access to `api.telegram.org` is unavailable.
5. Choose **Long polling** or **Webhook**.

Environment variables `TELEGRAM_BOT_NAME`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_PROXY_ENABLED` and `TELEGRAM_PROXY_URL` are optional bootstrap/fallback values; the web administration page is the normal configuration path.

### Long polling

Long polling is recommended. `telegram-poller` calls Telegram `getUpdates` through the configured proxy when enabled. It persists the next update offset in MariaDB so restarts do not replay processed updates.

When polling mode becomes active, the worker calls `deleteWebhook` without dropping pending updates, then consumes them via polling. Telegram does not need inbound access to the TMS server.

Only one polling worker should operate against a database. The worker also takes a MariaDB advisory lock to prevent accidental duplicate consumers.

### Webhook

Webhook mode requires a public HTTPS `APP_URL`. Configure or generate a webhook secret, select Webhook and press **Set Telegram webhook**. Telegram sends updates to:

```text
APP_URL/telegram/webhook
```

TMS validates the `X-Telegram-Bot-Api-Secret-Token` header. If Telegram cannot reach this endpoint, use Long polling instead.

### Telegram proxy

Supported schemes: `http://`, `https://`, `socks5://`, `socks5h://`.

The scheme is the proxy protocol. For an ordinary HTTP CONNECT proxy use, for example:

```text
http://172.17.0.1:1081
```

Even though Telegram itself is HTTPS, an HTTP proxy still starts with `http://`.

The proxy applies to Telegram API connection tests, outgoing messages, webhook registration/removal and Long polling.

## Linking a user

A user opens **Settings → Notifications**, generates a one-time `/link_...` command and sends it to the bot. The command expires after 24 hours and is single-use; only a SHA-256 verifier hash is stored.

After a valid link, the bot replies with confirmation and TMS stores that chat for the user. The user should then press **Send test message** in TMS to verify end-to-end delivery.

## Background notifier

The Docker/Portainer `notifier` service runs `php bin/notify.php` every `NOTIFICATION_INTERVAL_SECONDS` seconds (60 by default).

For a native installation, use cron for `bin/notify.php`. Do not run multiple notifier strategies unnecessarily; deduplication protects successful records, but concurrent workers may still race before success is journaled.
