# TMS

TMS is a lightweight, self-hosted task management system for people who want to keep their tasks and data on infrastructure they control.

> **Stable release:** TMS v0.1.1 is the current stable self-hosted release. It keeps the v0.1.0 baseline and adds administrator-managed system notification transport settings, Telegram proxy support and hardened secret handling.

## Container channels

The current stable release is `v0.1.1`:

```text
ghcr.io/mirivlad/tms:v0.1.1
```

`latest` follows the newest stable release. Versioned tags are recommended for reproducible deployments. The `preview` channel remains reserved for future prereleases.

Development builds from `main` are published as:

```text
ghcr.io/mirivlad/tms:edge
```

`edge` is updated **only after the full CI workflow for `main` succeeds**. Every edge publication also receives an immutable `sha-<12 hex chars>` tag, so a tested edge build can be pinned or rolled back later. Edge, preview and stable images are multi-architecture images for `linux/amd64` and `linux/arm64`.

## Direction

TMS v0.1.1 focuses on a solid personal/self-hosted task manager with multiple isolated users. Status/type/customer administration, rich task editing, typed custom fields and filters, attachments, notifications, dashboard/table/calendar workflows, profile settings, optional registration and user administration are implemented, together with dense work surfaces and a per-user theme engine.

Team collaboration (workspaces, projects, membership, assignees and ACLs) is planned after the secure single-user-scope foundation is complete.

## Principles

- self-hosted first;
- no SaaS billing or subscription logic;
- no production secrets or data in the repository;
- user-owned data is isolated by default;
- boring, maintainable PHP over framework churn;
- migrations instead of database dumps;
- security checks live at reusable boundaries, not only in controllers.

## Included now

- password login/logout with session ID rotation;
- rotating persistent login tokens stored as selector + verifier hash;
- browser and local-console password recovery with single-use reset tokens;
- CSRF protection for state-changing browser requests;
- Russian and English interface catalogs with a runtime language switch;
- public self-hosted landing/help/privacy pages;
- overview dashboard with a stable per-user daily tip;
- task create/edit/delete;
- sanitized rich-text descriptions;
- global quick-add task modal (including Alt/Cmd+N) and customer autocomplete;
- dashboard counters, status distribution and stale-task view;
- task quick-view modal backed by an owner-scoped JSON endpoint;
- bulk task operations for status/type/priority/deadline changes and deletion;
- advanced task-table filtering: status inversion, type, priority, literal-safe customer substring, overdue state, deadline/created ranges and type-aware custom-field filters (text, multiline text, select, money ranges, checkbox and checkbox-list matching);
- sortable standard/custom columns with active-filter preservation, 10/25/50/100 per-page controls and pagination;
- user-scoped status, task-type and customer administration in separate working tabs;
- six owner-scoped custom-field types: text, textarea, select, money, checkbox and checkbox list, with type-aware option editing;
- Kanban board with drag-and-drop, independent vertical column scrolling, wheel-assisted horizontal navigation and a no-JavaScript select fallback;
- calendar with deadline/no-deadline/combined modes, multi-value status/type/priority filters, inversion, customer autocomplete with literal-safe matching and day-level quick task creation;
- per-user profile settings for username, password, timezone and theme; included themes are Graphite, Midnight, Warm Dark, Paper, Frost and System;
- optional self-registration with a local visual CAPTCHA, hashed single-use email-verification tokens and configurable automatic/manual approval;
- administrator user management, quick user details, pending-user approval, manual verification, resend, activation/role editing and impersonation;
- secure attachment upload/download/delete with persistent out-of-webroot storage;
- SMTP/email and Telegram notifications with a background notifier service;
- automatic MariaDB migrations;
- first-administrator CLI bootstrap;
- Docker Compose and Portainer deployment paths.

## Requirements

The target runtime is PHP 8.2+ with MariaDB/MySQL. The application uses Slim 4, Twig and PDO. Docker/Compose is the recommended deployment path for self-hosted installations and integration testing.

## Localization

TMS currently ships `en` and `ru` interface catalogs. `APP_LOCALE` selects the deployment default and must be one of those locale codes. Users can switch between RU and EN in the web interface; the selected language is stored in the current session and takes precedence over `APP_LOCALE`.

The deployment locale is also used when TMS creates the initial status/type set for a **new** user. Existing status and task-type names are user-owned database data and are never silently renamed when the interface language changes.

## Docker Compose quick start

Copy the example environment and edit it before starting:

```bash
cp .env.example .env
```

At minimum, set:

- `DB_PASS` to a strong random database password;
- `APP_URL` to the URL users will open in their browser;
- `APP_TIMEZONE` to the timezone in which users enter and view task dates, for example `Europe/Berlin`;
- `APP_LOCALE` to `en` or `ru` for the initial interface/default-metadata language;
- `SESSION_SECURE=true` when the browser reaches TMS over HTTPS;
- `SESSION_SECURE=false` only when intentionally testing over plain HTTP.
- leave `REGISTRATION_ENABLED=false` for a closed installation, or set it to `true` to expose self-registration;
- set `REGISTRATION_AUTO_APPROVE_AFTER_EMAIL=false` if an administrator must approve verified users before they can sign in.

Then start a locally built stack:

```bash
docker compose up -d --build
```

Database migrations are applied automatically when the application container starts. MariaDB is not published to the host; only the TMS HTTP port is exposed (`8080` by default).

Create the first administrator interactively:

```bash
docker compose exec app php bin/create-admin.php admin admin@example.com
```

The command asks for a password without echoing it. For non-interactive automation, `TMS_ADMIN_PASSWORD` may be supplied only to that command; it does not need to remain in the stack environment.

If an account password is lost, browser recovery uses email and/or a linked Telegram chat when available. A self-hosted administrator can always recover access locally even when neither external service is configured; see [`docs/ACCOUNT_RECOVERY.md`](docs/ACCOUNT_RECOVERY.md).

Open `APP_URL` and sign in.

### Reverse proxy / HTTPS

The application container serves HTTP internally. In a normal Internet-facing deployment, terminate TLS at nginx, Caddy, Traefik or another reverse proxy and keep `SESSION_SECURE=true`. Do not publish the MariaDB container port.

### System notifications and Telegram

Deployment-wide transports are configured in **Administration → System notifications**. SMTP and Telegram credentials are encrypted before being stored. When `NOTIFICATION_SECRET` is empty, TMS creates a persistent encryption key in the `tms-secrets` volume; setting `NOTIFICATION_SECRET` remains available for deployments that manage this key externally. Deployments that already use `NOTIFICATION_SECRET` for encrypted SMTP data must keep the same value when upgrading; changing or removing an existing external key makes previously encrypted credentials unreadable.

Telegram bot name, token and webhook secret can be configured from the web administration page. The legacy `TELEGRAM_BOT_NAME`, `TELEGRAM_BOT_TOKEN` and `TELEGRAM_WEBHOOK_SECRET` environment variables remain optional bootstrap/fallback values. The same page can test Telegram API connectivity and install the webhook. The webhook URL is `APP_URL/telegram/webhook`; the reverse proxy only needs to pass ordinary public HTTPS POST requests to TMS.

If the host cannot connect directly to `api.telegram.org`, enable the Telegram proxy in the administration page and enter an `http://`, `https://`, `socks5://` or `socks5h://` proxy URL. The proxy is used for webhook registration and all outgoing Telegram messages, including the background notifier. Proxy credentials, when present in the URL, are encrypted at rest.

## Portainer using a published image

Use `compose.portainer.yaml`. It uses a prebuilt GHCR image and does not require Portainer to build the repository.

In **Stacks → Add stack**, use the repository stack file or paste the contents of `compose.portainer.yaml`. Define at least:

```text
APP_URL=https://tasks.example.com
APP_TIMEZONE=Europe/Berlin
APP_LOCALE=ru
DB_PASS=<strong random database password>
SESSION_SECURE=true
TMS_PORT=8080
REGISTRATION_ENABLED=false
REGISTRATION_AUTO_APPROVE_AFTER_EMAIL=true
```

`APP_URL` must be the browser-visible URL, not the container hostname. `APP_TIMEZONE` must be a valid PHP/IANA timezone and should match the wall-clock timezone users expect for task deadlines and calendar dates. `APP_LOCALE` is the initial/default UI locale (`en` or `ru`). Point your reverse proxy for the chosen subdomain at `TMS_PORT` on the Docker host.

Registration is disabled by default. When enabled, verification links are built from `APP_URL`, so it must be externally correct. `REGISTRATION_AUTO_APPROVE_AFTER_EMAIL=true` is convenient for open self-registration; set it to `false` when new accounts require explicit approval in **Admin → Pending users**. Administrators can also create ready-to-use accounts directly from **Admin → Users**, including role, active state, approval and email-verification state. Email verification requires working SMTP settings in TMS; an administrator can also verify an account manually.

By default the Portainer compose file stays pinned to the current stable version. To follow the newest CI-tested `main`, add:

```text
TMS_IMAGE=ghcr.io/mirivlad/tms:edge
```

For a reproducible deployment, use a versioned release tag or one of the immutable edge tags instead:

```text
TMS_IMAGE=ghcr.io/mirivlad/tms:v0.1.1
# or, after an edge publication:
TMS_IMAGE=ghcr.io/mirivlad/tms:sha-0123456789ab
```

Deploy the Stack, then open the `app` container console and create the first administrator:

```bash
php bin/create-admin.php admin admin@example.com
```

Migrations run automatically on startup, so there is no SQL dump to import. Persistent database state lives in the named `tms-db` volume. Attachments live in `tms-attachments`, and the automatically generated notification encryption key lives in `tms-secrets`.

## Native development bootstrap

For development without Docker:

```bash
cp .env.example .env
composer install
php bin/migrate.php
php bin/create-admin.php admin admin@example.com
composer check
php -S 127.0.0.1:8080 -t public
```

For the built-in PHP server, route fallback behavior is limited; Docker/Apache remains the reference runtime for integration testing.

`composer.lock` is tracked so release and CI builds use the same dependency baseline.

## Security baseline already implemented

- real CSRF validation for state-changing browser requests;
- mandatory owner scope for task reads and mutations;
- database-level protection against assigning tasks to another user's status/type/customer;
- owner-scoped custom-field definitions and values with composite database ownership guards;
- server-side allowlist sanitization for rich-text task descriptions;
- session ID rotation when authenticated state is established or cleared;
- persistent-login tokens stored as selector + verifier hash instead of bearer secrets;
- persistent-token rotation after successful use and replay rejection;
- explicit Secure/HttpOnly/SameSite cookie policy;
- production errors do not expose raw PDO connection failures.

See `SECURITY.md`, `docs/ARCHITECTURE.md` and `docs/PROJECT_SCOPE.md` for the release invariants and project boundaries.

## Project status

The stable v0.1 line contains the core task-management, administration, notification and deployment workflows. Subsequent releases focus on compatible fixes, usability improvements and incremental features while preserving the existing self-hosted data model and security boundaries.

## Repository policy

The repository contains source code, migrations, tests and deployment templates needed to build and operate TMS. Production credentials, user data, uploads, logs, backups and environment-specific secrets must never be committed.

## License

TMS is released under the GNU Affero General Public License, version 3 or (at your option) any later version: `AGPL-3.0-or-later`.

Copyright (C) 2026 Vladimir Tomashevskiy.
