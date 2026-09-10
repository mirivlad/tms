# TMS

TMS is a lightweight, self-hosted task management system for people who want to keep their tasks and data on infrastructure they control.

> **Pre-release status:** TMS is being reconstructed from a production-tested internal application. Production data, secrets, billing code and deployment-specific artifacts are intentionally not imported. The current repository contains a runnable authenticated application shell; task UI migration is still in progress.

## Direction

The first public release focuses on a solid personal/self-hosted task manager with multiple isolated users. The internal application already provides task lists, a Kanban board, calendar views, filters and sorting, priorities, customers, custom fields, attachments, email/SMTP integration and Telegram notifications. These features are being migrated incrementally behind cleaner authorization and application boundaries.

Team collaboration (workspaces, projects, membership, assignees and ACLs) is planned after the secure single-user-scope foundation is complete.

## Principles

- self-hosted first;
- no SaaS billing or subscription logic;
- no production secrets or data in the repository;
- user-owned data is isolated by default;
- boring, maintainable PHP over framework churn;
- migrations and reproducible deployment instead of database dumps;
- security checks live at reusable boundaries, not only in controllers.

## Requirements

The target runtime is PHP 8.2+ with MariaDB/MySQL. The application uses Slim 4, Twig and PDO. Docker/Compose is the recommended deployment path while the project is pre-release.

## Docker Compose quick start

Copy the example environment and edit it before starting:

```bash
cp .env.example .env
```

At minimum, set:

- `DB_PASS` to a strong random database password;
- `APP_URL` to the URL users will open in their browser;
- `SESSION_SECURE=true` when the browser reaches TMS over HTTPS;
- `SESSION_SECURE=false` only when intentionally testing over plain HTTP.

Then start the stack:

```bash
docker compose up -d --build
```

Database migrations are applied automatically when the application container starts. MariaDB is not published to the host; only the TMS HTTP port is exposed (`8080` by default).

Create the first administrator interactively:

```bash
docker compose exec app php bin/create-admin.php admin admin@example.com
```

The command asks for a password without echoing it. For non-interactive automation, `TMS_ADMIN_PASSWORD` may be supplied only to that command; it does not need to remain in the stack environment.

Open `APP_URL` and sign in. The current dashboard is deliberately minimal until the task/status/type/customer UI has been ported onto the new owner-scoped domain layer.

### Reverse proxy / HTTPS

The application container serves HTTP internally. In a normal Internet-facing deployment, terminate TLS at nginx, Caddy, Traefik or another reverse proxy and keep `SESSION_SECURE=true`. Do not publish the MariaDB container port.

## Portainer

TMS can be deployed as a normal Portainer Stack.

1. Open **Stacks → Add stack** and use the **Git repository** deployment method.
2. Point it at this repository and use `compose.yaml` as the Compose path. While the repository is private, configure Git credentials with read access.
3. Define at least `APP_URL`, `DB_PASS` and `SESSION_SECURE` in the Stack environment. `TMS_PORT` defaults to `8080`.
4. Deploy the Stack.
5. Open the `app` container console and run:

```bash
php bin/create-admin.php admin admin@example.com
```

Migrations run automatically on startup, so there is no SQL dump to import. Persistent database state lives in the named `tms-db` volume.

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

`composer.lock` will be committed once the dependency baseline is finalized. It must remain tracked after that point.

## Security baseline already implemented

- real CSRF validation for state-changing browser requests;
- mandatory owner scope for task reads and mutations;
- protection against assigning a task to another user's status;
- session ID rotation when authenticated state is established or cleared;
- persistent-login tokens stored as selector + verifier hash instead of bearer secrets;
- persistent-token rotation after successful use and replay rejection;
- explicit Secure/HttpOnly/SameSite cookie policy;
- production errors do not expose raw PDO connection failures.

See `SECURITY.md` and `docs/ARCHITECTURE.md` for the invariants applied during migration from the internal application.

## Repository history

This repository intentionally starts with a clean history. It does **not** preserve the Git history of the internal production repository because that repository contains deployment artifacts and production-derived material that must never become public.

## License

TMS is released under the GNU Affero General Public License, version 3 or (at your option) any later version: `AGPL-3.0-or-later`.

Copyright (C) 2026 Vladimir Tomashevskiy.
