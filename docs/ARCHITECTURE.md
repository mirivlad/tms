# Architecture

## Baseline

TMS uses PHP, Slim 4, Twig and PDO. The architecture favors small, explicit components and incremental evolution over framework-heavy abstractions.

New application code uses PSR-4 autoloading under `Tms\\` and is divided by responsibility instead of being manually required from a single oversized front controller.

## Request boundary

`public/index.php` is a composition root only. It should load configuration, build infrastructure/services, register middleware/routes and start the application. Business rules do not belong there.

## Data access and authorization

Authorization is treated as a data-access invariant, not only as a controller concern:

> A user-owned entity is never loaded or mutated by an unscoped public repository method.

For example, a task repository method should accept the authenticated user identity (or an authorization scope) as part of the operation. `findById($id)` for private user-owned tasks is not an acceptable public API; `findForUser($userId, $id)` or an equivalent scoped abstraction is.

This applies to tasks, attachments, statuses, task types, customers, custom fields, settings and future collaborative resources.

Controllers may still reject unauthorized requests, but controller checks are defense in depth rather than the only protection.

## Collaboration roadmap

The initial public model preserves isolated users. True collaboration will be added as an explicit domain model rather than by weakening `created_by` checks:

`Workspace -> Membership -> Project -> Task`

Assignments and ACLs will be built on membership/role rules. Until this exists, TMS should not claim enterprise/team authorization semantics it does not implement.

## Persistence

Schema changes are delivered through versioned migrations. Production SQL dumps are never a distribution mechanism.

## Billing

Billing, trials, tariffs and payment-provider integration are outside the scope of TMS. TMS is a self-hosted task-management application, not a subscription platform.
