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

## v0.2.0 — Projects, Teams and Discussions

The 0.2 line is the transition from isolated personal task management to optional project-based collaboration. The stages are intentionally ordered so each layer has a clear authorization model before the next one depends on it.

**Current implementation status:** Stages A, B and C are implemented on `main`. The next feature stage is D — Discussions, followed by hardening for the 0.2.0 stable release.

### Stage A — Projects Core

1. Project model and CRUD:
   - project name and description;
   - lifecycle status: `active`, `paused`, `done`, `archived`;
   - ownership model prepared for either a user or a team, while only personal ownership is active initially.
2. Task integration:
   - nullable project assignment so existing tasks remain valid as “No project”;
   - project assignment in task create/edit and compact workflows where it stays usable;
   - project filter in task list, Kanban board and calendar;
   - project detail view with its tasks.
3. Keep all personal-project access owner-scoped with negative cross-user tests.

### Stage B — Project configuration

Projects gain their own working context rather than inheriting every account-wide setting. This stage is deliberately split so storage changes do not get mixed with workflow-data migrations:

#### B1 — Project files

- project files/attachments;
- reuse the existing private file policy and storage;
- project-scoped authorization and cleanup;
- keep uploader identity separate from project ownership for the future Teams stage.

#### B2 — Project statuses

- project-specific task statuses;
- initialize a project's workflow from the owner's existing statuses;
- preserve the meaning/status of tasks already assigned to projects;
- make project status ownership compatible with future team-owned projects;
- project workflow settings UI.

#### B3 — Project custom fields

- project-specific custom fields;
- migrate/preserve existing values on tasks already assigned to projects;
- make project-field ownership compatible with future team-owned projects;
- project field settings UI.

Status/custom-field migrations must preserve existing tasks and must not silently change their meaning. Only after B1-B3 are stable does the roadmap move to Teams.

### Stage C — Teams

Teams add collaboration without weakening personal ownership. The stage is split so membership and invitation state exist before project authorization depends on them.

#### C1 — Team core and internal invitations

- create a team; the creator becomes Team Lead;
- roles stay deliberately small: `lead` and `member`;
- team membership and role changes are lead-controlled, with a last-lead guard;
- invite existing users by username/email;
- invitation state: `pending`, `accepted`, `declined`, `revoked`, `expired`;
- invitations are visible inside TMS with accept/decline actions and a navbar indicator;
- TMS invitation state is the source of truth.

#### C2 — Team-owned projects and authorization

- activate the already-reserved team ownership on projects;
- team members can see and work with the team’s projects and tasks;
- team Leads manage project settings and ownership-level operations;
- Members work with project tasks and ordinary task data;
- personal projects remain isolated from team membership.

#### C3 — Assignment and invitation delivery

- task assignee is separate from access: membership grants project access, assignee says who is responsible for the task;
- tasks may remain unassigned;
- assignees are limited to members of the team that owns the project;
- configured Telegram/email channels may deliver team-invitation notifications that link back to TMS;
- external channels are notification transports only and never become invitation state.

No Workspace layer is required for 0.2. It can be introduced later only if a real use case appears.

### Stage D — Discussions

Discussions are added only after Teams because discussion visibility depends on the team/project authorization model:

- project-level discussion;
- task-level discussion;
- comments remain attached to their work context instead of moving into external chats or email threads;
- replies are shallow rather than an unlimited discussion tree;
- `@mentions` and direct replies create TMS notifications;
- Telegram/email act as notification transports linking back to TMS, not as a second source of discussion state;
- system activity and human comments may share a chronological UI but remain different data types.

### Stage E — Hardening and v0.2.0 stable

Before the 0.2 stable release:

- permission matrix and cross-user/cross-team negative tests;
- behavior for member removal, lead changes, team/project archival and orphaned assignees;
- migration and rollback/update-path coverage;
- notification and unread-state edge cases;
- documentation and Docker smoke coverage for the complete collaboration flow.

The intended hierarchy at the end of 0.2 is:

```text
User
├── personal tasks
├── personal projects
└── team membership
    └── Team
        └── Project
            └── Tasks
```

Discussions attach to Project and Task once team collaboration exists.
