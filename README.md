# TMS

TMS is a lightweight, self-hosted task management system for people who want to keep their tasks and data on infrastructure they control.

> **Pre-release status:** the public repository is being reconstructed from a production-tested internal application. Production data, secrets, billing code and deployment-specific artifacts are intentionally not imported.

## Direction

The first public release focuses on a solid personal/self-hosted task manager with multiple isolated users. The existing application already provides task lists, a Kanban board, calendar views, filters and sorting, priorities, customers, custom fields, attachments, email/SMTP integration and Telegram notifications. These features are being migrated incrementally behind a cleaner application boundary.

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

The target runtime is PHP 8.2+ with MariaDB/MySQL. The application uses Slim 4, Twig, PDO, PHPMailer, Monolog and Dotenv.

## Development bootstrap

The application migration is still in progress, so the repository is not yet a deployable release.

```bash
cp .env.example .env
composer install
composer check
```

`composer.lock` will be committed once the dependency baseline is resolved during the application import. It must remain tracked after that point.

## Repository history

This repository intentionally starts with a clean history. It does **not** preserve the Git history of the internal production repository because that repository contains deployment artifacts and production-derived material that must never become public.

## License

TMS is released under the GNU Affero General Public License, version 3 or (at your option) any later version: `AGPL-3.0-or-later`.

Copyright (C) 2026 Vladimir Tomashevskiy.
