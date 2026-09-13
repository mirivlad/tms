# Security Policy

## Supported versions

TMS is currently pre-release. Until the first tagged release, security fixes are applied to the default branch only.

## Reporting a vulnerability

Please do not open a public issue for a vulnerability that could expose user data, credentials, attachments or authentication/session material. Use GitHub's private vulnerability reporting feature when it is enabled for this repository, or contact the maintainer privately.

Include enough information to reproduce and understand the impact, but never include real production credentials or personal data.

## Security invariants

The following rules are release blockers, not optional hardening:

1. A normal user must never be able to read, mutate, delete, download or enumerate another user's private entities by changing an ID.
2. All state-changing browser requests must be protected against CSRF.
3. Secrets and environment-specific credentials must never be committed.
4. Uploaded files must be authorized independently from knowledge of their path or numeric identifier.
5. Production mode must not expose stack traces, SQL errors, filesystem paths or secret material to clients.
6. Passwords must be stored only through PHP's password hashing API; session and remember-me tokens must be unguessable and revocable.

Code that does not satisfy these invariants is not accepted into the release baseline.
