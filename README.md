[English](README.md) | [Русский](README.ru.md)

# TMS

TMS is a lightweight, self-hosted task management system for people who want to keep tasks and data on infrastructure they control.

> **Current stable release:** `v0.4.1`

```text
ghcr.io/mirivlad/tms:v0.4.1
```

`latest` follows the newest stable release. For reproducible deployments, pin a versioned tag. CI-tested development builds from `main` are published as `edge` and immutable `sha-<commit>` tags.

## What TMS includes

- isolated multi-user task data;
- list, Kanban board and calendar views;
- dense responsive workbench layout for desktop, tablet and mobile;
- quick task creation, rich descriptions and attachments;
- statuses, task types, customers and typed custom fields;
- personal and team-owned projects with project files, project-specific task statuses and custom fields;
- teams with lead/member roles, invitations and task assignees;
- contextual project/task discussions with replies, `@mentions` and an internal notification inbox;
- advanced filters, sorting, pagination and bulk actions;
- append-only task and project activity history for meaningful changes;
- ordered task checklists with progress tracking;
- private saved task views for reusable filters, sorting and project scope;
- recurring task series with calendar schedules and N-days-after-completion mode;
- per-user language, timezone and theme settings;
- optional self-registration and administrator approval;
- SMTP/email notifications;
- Telegram notifications with **Long polling** or **Webhook**, optional HTTP/SOCKS proxy and per-user delivery testing;
- browser and local-console password recovery;
- MariaDB migrations, Docker Compose and Portainer deployment templates;
- `linux/amd64` and `linux/arm64` container images.

## Documentation

| Topic | English | Русский |
| --- | --- | --- |
| Installation, update, backup | [Installation](docs/INSTALLATION.md) | [Установка](docs/INSTALLATION.ru.md) |
| Administration | [Administration](docs/ADMINISTRATION.md) | [Администрирование](docs/ADMINISTRATION.ru.md) |
| User guide | [User guide](docs/USER_GUIDE.md) | [Руководство пользователя](docs/USER_GUIDE.ru.md) |
| Complete User/Admin handbooks (HTML/PDF in releases) | [Handbook build](docs/handbooks/README.md) | [Сборка handbook'ов](docs/handbooks/README.md) |
| Documentation maintenance contract | [Policy](docs/DOCUMENTATION_POLICY.md) | [Политика](docs/DOCUMENTATION_POLICY.md) |
| Notifications & Telegram | [Notifications](docs/notifications.md) | [Уведомления](docs/notifications.ru.md) |
| Webhooks | [Webhooks](docs/webhooks.md) | [Вебхуки](docs/webhooks.ru.md) |
| FAQ | [FAQ](docs/FAQ.md) | [FAQ](docs/FAQ.ru.md) |
| Account recovery | [Account recovery](docs/ACCOUNT_RECOVERY.md) | [Восстановление доступа](docs/ACCOUNT_RECOVERY.ru.md) |
| Architecture | [Architecture](docs/ARCHITECTURE.md) | — |
| Project scope | [Project scope](docs/PROJECT_SCOPE.md) | — |
| Roadmap | [Roadmap](docs/ROADMAP.md) | — |
| Security policy | [Security](SECURITY.md) | — |

## Ready-to-read handbooks

The complete manuals for the current stable release are available directly from the GitHub Release:

| Manual | HTML | PDF |
| --- | --- | --- |
| User handbook — English | [Open HTML](https://github.com/mirivlad/tms/releases/download/v0.4.1/tms-user-handbook-en.html) | [Download PDF](https://github.com/mirivlad/tms/releases/download/v0.4.1/tms-user-handbook-en.pdf) |
| User handbook — Русский | [Открыть HTML](https://github.com/mirivlad/tms/releases/download/v0.4.1/tms-user-handbook-ru.html) | [Скачать PDF](https://github.com/mirivlad/tms/releases/download/v0.4.1/tms-user-handbook-ru.pdf) |
| Administrator handbook — English | [Open HTML](https://github.com/mirivlad/tms/releases/download/v0.4.1/tms-admin-handbook-en.html) | [Download PDF](https://github.com/mirivlad/tms/releases/download/v0.4.1/tms-admin-handbook-en.pdf) |
| Administrator handbook — Русский | [Открыть HTML](https://github.com/mirivlad/tms/releases/download/v0.4.1/tms-admin-handbook-ru.html) | [Скачать PDF](https://github.com/mirivlad/tms/releases/download/v0.4.1/tms-admin-handbook-ru.pdf) |

[Open the full v0.4.1 release](https://github.com/mirivlad/tms/releases/tag/v0.4.1) for release notes and all attached artifacts.

## Quick start with the published image

Copy `.env.example` to `.env`, set at least `DB_PASS`, `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE` and `SESSION_SECURE`, then run:

```bash
docker compose -f compose.portainer.yaml up -d
```

Create the first administrator:

```bash
docker compose -f compose.portainer.yaml exec app php bin/create-admin.php admin admin@example.com
```

Open `APP_URL` and sign in. For reverse proxy/TLS, Portainer, upgrades, backups and all environment variables, use the [installation guide](docs/INSTALLATION.md).

## Core principles

- self-hosted first;
- no billing, trial or subscription logic;
- no production secrets or user data in the repository;
- user-owned data is isolated by default;
- migrations instead of database dumps;
- security checks at reusable boundaries, not only in controllers;
- stable releases are CI-tested before publication.

## Localization

The web interface ships in English and Russian. `APP_LOCALE=en|ru` sets the deployment default. A signed-in user can switch language in the interface; the session preference overrides the deployment default.

Initial statuses and task types for a new user are created in the configured locale. Existing user-owned names are never silently translated when the UI language changes.

## Development from source

```bash
cp .env.example .env
composer install
php bin/migrate.php
php bin/create-admin.php admin admin@example.com
composer check
php -S 127.0.0.1:8080 -t public
```

Docker/Apache is the reference integration runtime. `composer.lock` is tracked so CI and releases share the same dependency baseline.

## Security

TMS enforces CSRF protection for browser mutations, owner scoping for private entities, parameterized database access, protected attachment storage, secure session/remember-token handling and encrypted notification secrets. See [SECURITY.md](SECURITY.md) for the release-blocking invariants.

## License

TMS is released under the GNU Affero General Public License, version 3 or (at your option) any later version: `AGPL-3.0-or-later`.

Copyright (C) 2026 Vladimir Tomashevskiy.
