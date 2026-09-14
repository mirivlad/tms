[English](FAQ.md) | [Русский](FAQ.ru.md)

# FAQ

## Which image should I deploy?

Use a versioned stable tag such as `ghcr.io/mirivlad/tms:v0.1.3`. `latest` follows stable releases. `edge` is CI-tested `main`, but is intended for users who deliberately want development builds.

## Will an update delete my tasks?

Not when the named volumes are preserved. Normal updates replace containers/images and keep `tms-db`, `tms-attachments` and `tms-secrets`. Never use destructive volume removal as part of a routine update.

## Why can I log in over HTTP only when `SESSION_SECURE=false`?

A cookie marked Secure is not sent over plain HTTP. Production HTTPS installations should use `SESSION_SECURE=true`; direct HTTP testing requires `false`.

## I forgot the administrator password and mail is not configured. Am I locked out?

No. Use the local container-console recovery commands described in [Account recovery](ACCOUNT_RECOVERY.md).

## Why is the Telegram bot silent after I send `/link_...`?

First verify **Test Telegram connection** in administration. Then check the selected receive mode. Long polling requires a running `telegram-poller`; Webhook requires Telegram to reach `APP_URL/telegram/webhook` from the Internet. If inbound Telegram connectivity is filtered, use Long polling.

## Should an HTTP proxy for Telegram start with `https://` because Telegram is HTTPS?

No. The scheme describes the proxy. If your proxy is an ordinary HTTP CONNECT proxy, use `http://host:port`; TMS still accesses Telegram's HTTPS endpoint through it.

## Long polling or Webhook?

Use Long polling by default, especially behind NAT, restrictive filtering or a proxy. Use Webhook when you prefer inbound event delivery and have a public HTTPS endpoint reliably reachable by Telegram.

## Can SMTP and Telegram be configured without environment variables?

Yes. The normal path is **Administration → System notifications**. Telegram environment variables remain bootstrap/fallback options. SMTP settings are database-backed.

## What happens if I lose `tms-secrets`?

The application data remains, but stored encrypted SMTP/Telegram secrets can no longer be decrypted. Re-enter those credentials or restore the matching key backup. If you use an external `NOTIFICATION_SECRET`, protect and back up that value instead.

## Can several users see each other's tasks?

No by default. TMS currently uses isolated user ownership. Team/workspace collaboration is not implemented by weakening this boundary.

## Why didn't my status names change when I switched language?

Interface language and user-owned data are separate. TMS does not silently translate or rename existing statuses/types/custom metadata. New-user defaults use the deployment locale at creation time.

## Why are notification times different from my profile timezone?

The background notification scheduler currently evaluates scheduled delivery times in `APP_TIMEZONE`. Web pages use the user's profile timezone where applicable. Set deployment timezone appropriately when consistent wall-clock notification times matter.

## Where are attachments stored?

In the `tms-attachments` volume by default, outside the public web root. Back up this volume together with the database.

## Is MariaDB supposed to be exposed to the Internet?

No. The supplied Compose files do not publish the database port. Only the application HTTP port should be reachable by the reverse proxy/user network.

## Can I disable public registration?

Yes; it is disabled by default with `REGISTRATION_ENABLED=false`. Administrators can still create users directly.

## How do I report a security problem?

Do not post exploitable details in a public issue. Follow [SECURITY.md](../SECURITY.md).
