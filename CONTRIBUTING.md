# Contributing to TMS

TMS is developed as a clean open-source codebase. Small, focused changes are preferred over broad rewrites.

## Development rules

- Target PHP 8.2 or newer.
- Use PSR-4 classes under the `Tms\\` namespace for new code.
- Do not add SaaS billing, licensing checks or subscription gates to the core project.
- Never commit `.env`, database dumps, uploaded user files, logs, backups or credentials.
- User-owned records must be queried through an explicit user/authorization scope.
- New state-changing HTTP endpoints require CSRF protection where browser sessions are used.
- Add or update tests for security boundaries and bug fixes.
- Keep `composer.lock` tracked once it is introduced.

Before submitting a change, run:

```bash
composer validate --strict
composer check
```
