# Architecture

## Baseline

TMS uses PHP, Slim 4, Twig and PDO. The architecture favors small, explicit components and incremental evolution over framework-heavy abstractions.

New application code uses PSR-4 autoloading under `Tms\\` and is divided by responsibility instead of being manually required from a single oversized front controller.

## Request boundary

`public/index.php` is a composition root only. It should load configuration, build infrastructure/services, register middleware/routes and start the application. Business rules do not belong there.

## Data access and authorization

Authorization is treated as a data-access invariant, not only as a controller concern:

> A private entity is never loaded or mutated by an unscoped public repository method.

For example, a task repository method should accept the authenticated user identity (or an authorization scope) as part of the operation. `findById($id)` for private tasks is not an acceptable public API; `findForUser($userId, $id)` or an equivalent scoped abstraction is.

This applies to tasks, attachments, statuses, task types, customers, custom fields, settings and collaborative resources.

Controllers may still reject unauthorized requests, but controller checks are defense in depth rather than the only protection.

## Projects and collaboration

Projects are an explicit domain layer above tasks. Existing tasks are allowed to remain unassigned.

A project has exactly one owner:

- a user for a personal project; or
- a team for a collaborative project.

The schema reserves both ownership forms from the start, but the application only activates team ownership after the Teams stage is implemented. This avoids bolting team ACLs onto a user-owned project after the fact.

The collaboration hierarchy is deliberately small:

```text
User -> personal Project -> Task
User -> Team membership -> Team -> Project -> Task
```

A Workspace layer is not part of the current architecture. It should be introduced only if a concrete use case requires grouping multiple teams/projects above this level.

Team membership controls access. Task assignment is a separate responsibility marker and must not become an ACL mechanism.

Discussions are attached to Project and Task only after team authorization exists. Human comments and immutable/system activity events remain separate data concepts even when presented in one timeline.

## Persistence

Schema changes are delivered through versioned migrations. Production SQL dumps are never a distribution mechanism.

## Billing

Billing, trials, tariffs and payment-provider integration are outside the scope of TMS. TMS is a self-hosted task-management application, not a subscription platform.
