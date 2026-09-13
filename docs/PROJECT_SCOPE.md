# Project scope and acceptance policy

TMS is a self-hosted task-management application. Features are accepted only when they satisfy the repository security, ownership and deployment invariants.

## Repository hygiene

Never commit or distribute:

- `.env` files or credentials;
- database dumps or production records;
- `vendor/`;
- uploads, logs, sessions, caches or backups;
- TLS private keys or server configuration containing private details;
- deployment-specific secrets and private hostnames.

## Out of scope

The core TMS project does not include:

- billing, tariffs or trials;
- payment history;
- payment-provider integrations;
- hosted-service subscription management.

## Feature acceptance checklist

Before a feature is considered complete, check:

- ownership and authorization scope;
- CSRF behavior for browser mutations;
- SQL parameterization and error handling;
- file upload/download authorization where applicable;
- secret and configuration dependencies;
- schema changes delivered through versioned migrations;
- automated tests for normal and cross-user/negative paths;
- deployment documentation when new environment variables or services are introduced.

The goal is a small, auditable self-hosted application whose documented behavior matches what the release actually enforces.
