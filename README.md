# TMS

TMS is a lightweight, self-hosted task management system for people who want to keep their tasks and data on infrastructure they control.

> **Preview status:** TMS is being reconstructed from a production-tested internal application. Production data, secrets, billing code and deployment-specific artifacts are intentionally not imported. The current preview is runnable and includes authentication, task CRUD, filtering, a Kanban board and a calendar. Additional donor functionality is still being migrated.

## Current preview

The current published preview is `v0.1.0-preview.3`.

Container image:

```text
ghcr.io/mirivlad/tms:v0.1.0-preview.3
```

The `preview` image tag follows the newest preview build. For a deployment you want to keep stable while evaluating it, prefer the versioned tag above.

This is intentionally a prerelease for deployment and UI/UX evaluation, not the final `v0.1.0`.

`v0.1.0-preview.3` adds the localization foundation: complete Russian and English UI catalogs, a CSRF-protected runtime language switch, `APP_LOCALE`, localized validation/calendar/priority text and locale-aware metadata defaults for newly created users. It retains the timezone consistency fixes from preview.2.

## Direction

The first stable public release focuses on a solid personal/self-hosted task manager with multiple isolated users. The internal application also provides custom fields, attachments, email/SMTP integration, Telegram notifications and additional settings. Those capabilities are being migrated incrementally behind cleaner authorization and application boundaries.

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
- CSRF protection for state-changing browser requests;
- Russian and English interface catalogs with a runtime language switch;
- overview dashboard;
- task create/edit/delete;
- search and filters by status, type, priority, customer and overdue state;
- user-scoped statuses, task types and customers;
- Kanban board with drag-and-drop and a no-JavaScript select fallback;
- calendar with deadlines, no-deadline tasks and combined modes;
- automatic MariaDB migrations;
- first-administrator CLI bootstrap;
- Docker Compose and Portainer deployment paths.

## Requirements

The target runtime is PHP 8.2+ with MariaDB/MySQL. The application uses Slim 4, Twig and PDO. Docker/Compose is the recommended deployment path while the project is pre-release.

## Localization

TMS currently ships `en` and `ru` interface catalogs. `APP_LOCALE` selects the deployment default and must be one of those locale codes. Users can switch between RU and EN in the web interface; the selected language is stored in the current session and takes precedence over `APP_LOCALE`.

The deployment locale is also used when TMS creates the initial status/type set for a **new** user. Existing status and task-type names are user-owned database data and are never silently renamed when the interface language changes. They can be renamed explicitly once the metadata administration UI is restored.

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

Open `APP_URL` and sign in.

### Reverse proxy / HTTPS

The application container serves HTTP internally. In a normal Internet-facing deployment, terminate TLS at nginx, Caddy, Traefik or another reverse proxy and keep `SESSION_SECURE=true`. Do not publish the MariaDB container port.

## Portainer using the published image

For a preview deployment, use `compose.portainer.yaml`. It uses the prebuilt GHCR image and does not require Portainer to build the repository.

In **Stacks → Add stack**, use the repository stack file or paste the contents of `compose.portainer.yaml`. Define at least:

```text
APP_URL=https://tasks.example.com
APP_TIMEZONE=Europe/Berlin
APP_LOCALE=ru
DB_PASS=<strong random database password>
SESSION_SECURE=true
TMS_PORT=8080
```

`APP_URL` must be the browser-visible URL, not the container hostname. `APP_TIMEZONE` must be a valid PHP/IANA timezone and should match the wall-clock timezone users expect for task deadlines and calendar dates. `APP_LOCALE` is the initial/default UI locale (`en` or `ru`). Point your reverse proxy for the chosen subdomain at `TMS_PORT` on the Docker host.

Deploy the Stack, then open the `app` container console and create the first administrator:

```bash
php bin/create-admin.php admin admin@example.com
```

Migrations run automatically on startup, so there is no SQL dump to import. Persistent database state lives in the named `tms-db` volume.

To pin another image explicitly, set for example:

```text
TMS_IMAGE=ghcr.io/mirivlad/tms:v0.1.0-preview.3
```

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

`composer.lock` will be committed before the stable release once the dependency baseline is finalized. It must remain tracked after that point.

## Security baseline already implemented

- real CSRF validation for state-changing browser requests;
- mandatory owner scope for task reads and mutations;
- database-level protection against assigning tasks to another user's status/type/customer;
- session ID rotation when authenticated state is established or cleared;
- persistent-login tokens stored as selector + verifier hash instead of bearer secrets;
- persistent-token rotation after successful use and replay rejection;
- explicit Secure/HttpOnly/SameSite cookie policy;
- production errors do not expose raw PDO connection failures.

See `SECURITY.md` and `docs/ARCHITECTURE.md` for the invariants applied during migration from the internal application.

## Still being migrated before stable v0.1.0

Custom fields, attachments, user/settings administration, SMTP/email notifications, Telegram notifications and final release hardening are not part of this preview yet. Donor feature parity is tracked separately so broad UI polish happens only after retained TaskMS functionality has been restored.

## Repository history

This repository intentionally starts with a clean history. It does **not** preserve the Git history of the internal production repository because that repository contains deployment artifacts and production-derived material that must never become public.

## License

TMS is released under the GNU Affero General Public License, version 3 or (at your option) any later version: `AGPL-3.0-or-later`.

Copyright (C) 2026 Vladimir Tomashevskiy.
