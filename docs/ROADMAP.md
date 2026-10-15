# Roadmap

This file records the near-term product direction for TMS. Shipped behavior belongs in the user documentation; this file only describes the next agreed product steps.

## v0.5 — Agents as first-class collaborators

[Русская версия дорожной карты v0.5](ROADMAP_V0.5.ru.md)

**Status:** planned; not yet implemented. The current stable baseline remains v0.4.1.

### Product goal and design invariants

External AI agents (Claude Code, Codex CLI, local agents and other MCP clients) participate in TMS as identifiable team members: they can access authorized tasks, contribute to the *same* task/project discussions as humans, mention each other, receive notifications and report real work results.

- Keep TMS a self-hosted **system of record**, not an LLM host, chat-model router, mandatory agent runtime or CI platform. Integration does **not** require a model-provider API key or inference billing within TMS; agents use their own external authentication.
- Preserve the User → Team → Project → Task hierarchy, team membership ACLs and isolation of personal tasks/projects. Task assignment is **responsibility**, not authorization.
- Reuse the existing project/task discussions (including one-level replies), @mentions, user notification inbox, domain events, webhook queue and audit/history. Do not build a parallel "AI chat".
- An agent is a distinct actor in all edits, mentions, events and history. Never attribute agent actions to its human creator.
- Interactive MCP access (agent already running) and autonomous execution (external dispatcher activates an available agent) are separate, optional layers.
- Use migrations and additive compatibility contracts; keep RU/EN docs and HTML/PDF handbooks in sync with released behavior.

### Stage A — Agent identity, membership and API credentials

1. Add an explicit human/agent account type with stable user ID. Migrate all existing accounts to human without changing IDs or ownership.
2. Create and manage agents through authorized administrators. Agent accounts do not need password login, browser sessions, self-registration or email verification.
3. Join/leave teams under the existing membership model; a team lead must approve team access. No implicit cross-team access; no automatic team-lead/system-admin privileges for agents.
4. Keep the human manager of an agent distinct from the agent principal and from its team memberships. Agent deactivation immediately disables its effective access.
5. Support named, individually revocable bearer tokens. Reveal secret once, store only a strong verifier plus principal, issuer, scopes, expiry, last use and revocation metadata. Provide rotation.
6. Enforce least-privilege scopes: read tasks/projects, write task/checklist, read/write discussion, read/ack notifications (precise scope contract to be defined). Effective rights are the **intersection** of token scope, account state and current team/project ACL.
7. Humans' personal projects/tasks do not become visible to agents merely because the human created them or assigned a task ID. Explicit private delegation is not part of 0.5.

**Acceptance:** expired/revoked tokens and disabled agents cannot access TMS; assignment alone never grants access; audit shows the agent identity, not its creator.

### Stage B — Authenticated REST API v1

Expose /api/v1 through shared domain services and repositories, without controller-local bypasses of scoped data access.

- Read: current identity/capabilities, available teams/projects, filtered tasks and detail, project-specific statuses/types/custom fields, checklists, discussion history and replies, notifications and activity history.
- Write: permitted task creation/updates/assignment, checklist changes, create/reply/edit/delete *own* discussion comments and @mentions, notification acknowledgement. Other project/team operations must follow existing role authorization.
- Stable JSON schemas, documented filters/pagination/timezones, revision and concurrency preconditions, idempotency keys for retryable operations, clear validation/errors, structured request IDs and canonical resource URLs.
- Project-aware statuses and custom fields are mandatory; an agent must discover valid options for the chosen project rather than guessing a global status name.
- Preserve the same sanitization, attachments ACL, event emissions, visibility and immutable audit semantics as the web UI. Ensure private ID enumeration cannot reveal another user's objects.
- Isolate bearer auth from cookie sessions; retain CSRF for browser routes. Enforce TLS, rate/body limits and credential redaction in logs.

**Acceptance:** UI and API enforce equivalent rules; cross-user/team/task/attachment negative tests pass; concurrent retries cannot silently duplicate a write.

### Stage C — MCP access for external agents

1. Offer tools for searching/listing projects/tasks, fetching full task/project context, discovering allowed statuses, creating/updating/assigning tasks, working with checklists, reading/posting/replying/editing own discussion comments, reading/acknowledging notifications and inspecting permitted history.
2. Ship a documented local stdio MCP adapter calling the REST API, suitable for Claude Code, Codex CLI and other clients. A secure authenticated Streamable HTTP endpoint for remote clients may be delivered with tested client/auth compatibility.
3. MCP must not reimplement application ACL or state transition rules. Return bounded/paginated context instead of dumping unrelated project history.
4. Treat task descriptions, attachments, comments and tool results as **untrusted content**, not as elevated instructions to the language model.

**Acceptance:** two independent CLI clients using existing provider subscriptions, with distinct TMS identities/tokens, can complete a shared task workflow without model-provider API keys.

### Stage D — Full use of existing team discussions

- Agents read/post/reply/edit/delete their own comments in the **same** task/project discussion timeline as humans, with identical sanitation, reply depth, author rules and team-lead moderation.
- @mention lookup includes authorized human and agent team members and must not disclose or mention invisible identities.
- Preserve ordinary discussion events and inbox notifications for agent-authored comments and replies. Deduplicate notifications after delivery retries and honor existing self-mention rules.
- Show a small AI-agent identity marker in UI while keeping stable original authorship/timestamps after deactivation.
- A non-assignee agent can discuss any task **its team membership permits**.

**Acceptance:** a human mentions Claude in a task; Claude answers and mentions Codex; Codex reads the entire discussion and answers; all three see the same thread and notifications.

### Stage E — Durable agent inbox and webhook routing

1. Expose unread-notification listing, context retrieval and explicit acknowledgment/mark-read. Only acknowledge after successful handling; retain unread while the agent/executor is offline.
2. Reuse the current canonical in-app inbox, task-assignment events and discussion mention/reply events. Extend payloads additively and preserve the DOMAIN_EVENTS contract.
3. Route relevant events to optional external dispatchers via existing signed webhook delivery, with authorization checks, signature/timestamp verification and event-ID deduplication.
4. Add bounded per-conversation loop protection against agent ↔ agent mention storms and duplicated auto-replies (chain budget, cooldown, human handoff). Do not impose arbitrary limits on normal meaningful discussions.

**Acceptance:** offline agents catch up later without losing notifications; repeat deliveries cause no double reply; cyclic agent mentions stop with visible diagnostics.

### Stage F — Optional reference external dispatcher

- Specify and provide a small self-hosted reference integration receiving signed TMS events and launching/resuming a locally authenticated Claude Code, Codex CLI or other executor. Keep it external to TMS core.
- Track event ID, agent principal, task ID and execution ID; provide durable idempotent queue, per-agent concurrency caps, timeout/cancellation, retries and failure escalation.
- Fetch the freshest authorized context via TMS API/MCP before work. Never embed model-provider credentials in tasks, webhooks or database records.
- Report genuine results back as normal agent-authored comments and authorized status/checklist changes. A delivered webhook does *not* mean the task was completed.
- Make an offline executor safe: work stays pending/unread rather than being falsely marked done; document subscription-backed CLI limitations on headless automation.

**Acceptance:** an external configured agent can react to assignment/@mention without human copy/paste, or reliably defer until available.

### Stage G — Human-facing UI and operations

- Add Administration → AI agents: create/disable, manage tokens and scope metadata, revoke credentials and inspect audit. Do not redisplay secrets.
- Show agents in team membership, task-assignee and discussion @mention selectors with a discreet AI badge; reuse existing responsive layout and localization.
- Show last observed activity, relevant task runs/errors and clear offline/unknown states. Never claim that "agent enabled" means an executor is online; presence requires real dispatcher telemetry.

### Stage H — Tests, migration, documentation

- Permission matrix: human/agent × personal/team × member/lead × token scope × enabled/revoked/expired, including denied access to comments, files, private projects and task IDs.
- Exercise races/retries, duplicate event/mention deliveries, agent shutdown during execution, revocation mid-run, concurrent writes, and bot-to-bot loops.
- Threat-model prompt injection and exfiltration through task descriptions, comments, attachments and tool output.
- End-to-end fixture: one human, two independent agents and one shared project; create/assign task, discuss with @mentions, update checklist/status, survive offline interval and reject unauthorized operations.
- Document REST API/OpenAPI and MCP contracts, token administration, CLI setup (without inference API keys), external dispatcher and troubleshooting. Maintain English/Russian User/Admin manuals and screenshot pairs; validate HTML/PDF builds in CI.

### Stable v0.5 release gate

Stages A–H accepted; new-install plus migration path from v0.4.1 tested; Docker/Portainer smoke pass; token revocation/agent disable proven; event/API compatibility and loop prevention verified; bilingual docs and distributable handbooks updated. Ship no half-working demo-only agent permissions.

### Out of scope for v0.5

Built-in AI inference/chatbot, model API billing or token brokerage, vendor-specific cloud orchestration, generic CI runner, mandatory always-on local agent, cross-user impersonation, automatic access to human-owned private objects, unrestricted privileges for bots, generic group chat and automatic code merge/deploy.

## v0.4 — Scheduling & Events

The 0.4 line makes TMS time-aware and event-driven while keeping the current User → Team → Project → Task hierarchy intact.

**Implementation status:** complete and shipped as stable `v0.4.0`.

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
