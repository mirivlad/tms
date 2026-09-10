# Migration policy for the internal donor application

The historical application is treated as a behavioral reference and source donor, not as a repository to publish or merge wholesale.

## Never import

- `.env` or credentials;
- database dumps or production records;
- `vendor/`;
- uploads, logs, sessions, caches or backups;
- TLS keys/certificates or server configuration containing private details;
- billing, tariffs, trials, payment history or YooKassa/Robokassa integration;
- deployment-specific secrets and hostnames.

## Import with review

Application features may be ported only after checking:

- ownership/authorization scope;
- CSRF behavior for mutations;
- SQL parameterization and error handling;
- file upload/download authorization;
- secret/config dependencies;
- coupling to billing code;
- behavior that should become a migration rather than relying on an existing database.

The goal is behavioral continuity without carrying forward production history or accidental security assumptions.
