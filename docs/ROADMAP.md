# Roadmap

This file records the near-term product direction for TMS. Shipped behavior belongs in the user documentation; this file only describes the next agreed product steps.

## v0.1.4 — task preview quick edit

Scope:

- change task status directly in the task preview dialog;
- edit the task description directly in the preview while preserving the existing rich-text format;
- change or clear the task deadline directly in the preview;
- keep the full task edit page for title, type, priority, customer, custom fields and attachments;
- keep all mutations owner-scoped, CSRF-protected and passed through the existing description sanitizer;
- refresh the current list, board or calendar view after a successful quick save so the surrounding view immediately reflects the change.

No schema migration is required.

## v0.2.0 — Projects

Projects are the next level above individual tasks:

```text
Project
└── Tasks
```

Initial scope:

- user-owned projects with a name, description and lifecycle status;
- lifecycle statuses: `active`, `paused`, `done`, `archived`;
- optional project assignment on a task so existing tasks can remain unassigned;
- a project list and project detail view;
- project filtering in the task list, Kanban board and calendar;
- project assignment in task create/edit and quick workflows where it remains compact;
- owner-scoped repository and database constraints, migration coverage and negative cross-user tests.

The first Projects release is deliberately not a team-management system. Workspaces, memberships, assignees, project ACLs and other collaboration layers remain outside the v0.2.0 scope. They can be considered later without making Projects depend on them.
