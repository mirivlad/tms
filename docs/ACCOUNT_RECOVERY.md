# Account recovery

TMS does not require an external service to recover access.

## Browser recovery

The `/forgot-password` form accepts a username or account email and always returns the same generic result. If SMTP is configured, TMS sends a one-hour reset link by email. If the account has a linked Telegram chat and the bot is configured, TMS also sends the link there.

The raw reset verifier is never stored in the database; only its SHA-256 hash is persisted.

## Self-hosted emergency recovery

When email and Telegram are unavailable, use the application container console.

Generate a one-time URL without changing the password:

```sh
php bin/issue-password-reset.php admin
```

Or change it directly. Interactive input is preferred because the password does not appear in shell history:

```sh
php bin/reset-password.php admin
```

For automation/non-interactive recovery, `TMS_RESET_PASSWORD` may be supplied to that single process. A successful reset revokes all persistent-login tokens and outstanding password-reset tokens for the account.

If the database contains no users at all, create the first administrator with `bin/create-admin.php` instead of using password recovery.
