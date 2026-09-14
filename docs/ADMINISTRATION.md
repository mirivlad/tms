[English](ADMINISTRATION.md) | [Русский](ADMINISTRATION.ru.md)

# Administration

## First administrator

Create the initial administrator from the application container:

```bash
php bin/create-admin.php admin admin@example.com
```

After sign-in, administration pages are available from the user/admin navigation.

## Users and registration

Public registration is controlled by `REGISTRATION_ENABLED`.

When registration is enabled, email verification links use `APP_URL`. With `REGISTRATION_AUTO_APPROVE_AFTER_EMAIL=true`, successful verification also approves the account. Set it to `false` when every new account must be approved under **Administration → Pending users**.

Administrators can create users directly, change role/active state, approve accounts, mark email as verified, resend verification, inspect user details and impersonate an account for troubleshooting. Do not use impersonation as a substitute for reproducing authorization bugs with a normal test account.

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

## Backups and upgrades

Treat `tms-db`, `tms-attachments` and `tms-secrets` as one logical backup set. Read [Installation](INSTALLATION.md) before upgrades or restores.

## Password recovery

Browser recovery can use configured email and/or a linked Telegram account. A self-hosted administrator can always issue or apply a local reset from the container console. See [Account recovery](ACCOUNT_RECOVERY.md).

## Operational checks

Useful checks after a deployment change:

```bash
docker compose -f compose.portainer.yaml ps
docker compose -f compose.portainer.yaml logs --tail=100 app
docker compose -f compose.portainer.yaml logs --tail=100 notifier
docker compose -f compose.portainer.yaml logs --tail=100 telegram-poller
```

Do not enable `APP_DEBUG` on an Internet-facing production instance except for a short, controlled troubleshooting window.
