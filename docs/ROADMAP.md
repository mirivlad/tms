# Roadmap

This file records the near-term product direction for TMS. Shipped behavior belongs in the user documentation; this file only describes the next agreed product steps.

## v0.4 — Scheduling & Events

The 0.4 line makes TMS time-aware and event-driven while keeping the current User → Team → Project → Task hierarchy intact.

**Implementation status:** Stage A in progress.

### Stage A — Realtime notification beacon

- expose one authenticated, non-cacheable endpoint with unread internal-notification and pending team-invitation counts;
- refresh navigation badges without a full page reload;
- poll only while the browser tab is visible;
- refresh immediately when a hidden tab becomes visible again;
- create, update and remove both summary and menu badges as counts cross zero;
- use simple polling for 0.4 rather than introducing SSE/WebSocket infrastructure prematurely.

### Stage B — `scheduled_at`

- add planned execution time separately from the existing deadline;
- support create/edit and quick edit;
- expose planned time in task list/filtering and Saved Views;
- integrate planned time into Calendar;
- preserve/generate planned time correctly for recurring task occurrences.

### Stage C — Domain events

- introduce explicit task/project/discussion domain events with stable event identifiers;
- treat activity history as a consumer of events rather than the source of notification behavior;
- define payload contracts that can later drive notifications and external integrations.

### Stage D — Event notifications

- notify on assignment/reassignment;
- preserve existing mention and discussion-reply notification behavior under the event model;
- notify on deadline and `scheduled_at` changes where useful;
- support upcoming planned/deadline notifications;
- make noisy event classes such as status changes user-configurable;
- keep internal TMS notifications canonical; email and Telegram remain transports.

### Stage E — Webhook foundation

- event subscriptions;
- signed webhook deliveries;
- retry policy and delivery history;
- API/event contracts suitable for external tools and agent integrations.

### Stage F — Documentation & maintenance contract

- maintain a complete end-user handbook with step-by-step examples for every user-facing feature;
- maintain a separate administrator handbook covering deployment, upgrades, configuration, user/team administration, notification transports, backup/restore, maintenance and troubleshooting;
- keep handbook source files in the repository and publish both manuals as HTML and PDF;
- treat documentation updates as part of feature completion: behavior or configuration changes must update the affected handbook sections in the same release;
- add an explicit documentation-impact check to the release/review process so manuals cannot silently drift behind the application.

### v0.4 non-goals

No Gantt view, task dependency graph, time tracking, Workspace layer, general-purpose chat, or embedded AI workbench is planned for 0.4 unless a concrete blocking use case appears.

## v0.3 — Daily workflow

The 0.3 line focuses on making TMS faster and more useful in day-to-day work without adding a new hierarchy layer. Each stage must remain useful for personal tasks as well as team projects.

**Implementation status:** complete and shipped as stable `v0.3.0`.

### v0.3.0 — Activity history

- append-only task and project activity events;
- record task creation and meaningful field changes from full edit, quick edit, Kanban and bulk actions;
- record project creation, lifecycle/settings changes and ownership transfers;
- snapshot human-readable values so later metadata renames do not rewrite history;
- never duplicate full rich-text descriptions in the event log: record only that the description changed;
- snapshot personal/team visibility at event time so project ownership transfers cannot leak old team history;
- expose dedicated task and project history views.

### v0.3.1 — Checklists

- ordered checklist items inside a task;
- add, rename, complete, reorder and delete items without creating full task records;
- show checklist progress in task preview/list where it stays compact;
- record checklist changes in activity history;
- keep checklist authorization identical to the parent task.

### v0.3.2 — Saved views

- save a named set of task filters/sort/project scope;
- optionally choose a default personal view;
- make saved views available from Tasks and compatible with project-aware status filtering;
- keep views private to the user in 0.3; shared team views are intentionally deferred.

### v0.3.3 — Recurring tasks

- daily, weekly, monthly and interval recurrence;
- support both calendar recurrence and "N days after completion";
- create a new task occurrence instead of mutating one eternal task;
- preserve project/status/priority/metadata/checklist template where valid;
- make generation idempotent so scheduler retries cannot create duplicate occurrences.

### 0.3 hardening

Completed before the stable 0.3 release:

- cross-user/cross-team negative coverage for all new data;
- migration/update-path and scheduler idempotency tests;
- responsive UI pass for history, checklists and saved views;
- user/admin documentation updated to match shipped behavior.

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

**Current implementation status:** Stages A-E are complete in `v0.2.0`. This release is the stable collaboration baseline for Projects, Teams and Discussions; the next product stage is intentionally left open until a concrete use case is agreed.

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

Discussions are added only after Teams because discussion visibility depends on the team/project authorization model.

#### D1 — Discussion core

- project-level discussion for team-owned projects;
- task-level discussion for tasks in team-owned projects;
- sanitized rich-text comments attached to work context rather than external chats or email threads;
- replies are limited to one level rather than an unlimited tree;
- authors may edit/delete their own comments;
- Team Leads may moderate by deleting comments without silently rewriting another author;
- human comments stay separate from future immutable system activity.

#### D2 — Mentions and notifications

- `@mentions` resolve only against current members of the owning team;
- direct replies and mentions create internal TMS notifications;
- notification links open the exact project/task/comment context;
- Telegram/email act only as notification transports linking back to TMS;
- unread state and internal notification history remain canonical inside TMS.

### Stage E — Hardening and v0.2.0 stable

Release hardening for 0.2 covers:

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
