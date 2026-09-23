[English](USER_GUIDE.md) | [Русский](USER_GUIDE.ru.md)

# User guide

This handbook is the canonical end-user manual for TMS. It explains not only what each screen does, but also how to combine tasks, projects, teams, schedules, saved views, discussions and notifications into a daily workflow.

[TOC]

## Getting started

After sign-in, TMS opens as a workbench rather than a wizard. You can keep everything personal, or add Projects and Teams only when collaboration is useful. A task is always the basic unit of work; Projects add context, Teams add shared access, and Discussions stay attached to the work they describe.

A useful first-time setup is:

1. Open **Settings → Profile** and set your timezone and preferred theme.
2. Open **Settings → Metadata** and review your personal statuses, task types and customers.
3. Create one test task with both a planned time and a deadline.
4. Open **Tasks**, **Board** and **Calendar** to see the same task through different views.
5. If you work with other users, create a Team and then a team-owned Project.

## Main views

- **Dashboard** — counters, status distribution, stale tasks and a daily tip.
- **Tasks** — dense table with sorting, filters, pagination, quick view and bulk actions.
- **Board** — Kanban columns by status with drag-and-drop.
- **Calendar** — separate events for planned work (`scheduled_at`) and deadlines, plus an optional view of tasks that have neither date.

## Creating and editing tasks

Use **New task** or the global quick-add action. The quick-add dialog is also available with `Alt+N` or `Cmd+N` where supported by the browser/OS.

A task can contain title, description, priority, status, type, customer, planned time, deadline and custom fields. In a team-owned project it can also have an optional assignee; assignment expresses responsibility and does not change who can access the project. Open the quick-view dialog from a task card or row to change status, description and deadline without opening the full edit page. Rich descriptions are sanitized server-side. Attachments are stored separately from the public web root and are available only through authorized application routes.

### Planned time versus deadline

TMS deliberately separates **Planned for** from **Deadline**:

- **Planned for** answers “when do I intend to work on this?”;
- **Deadline** answers “when must this be finished?”.

They may be the same, different, or one of them may be empty.

Example: a report is due Friday at 17:00, but you plan to work on it Thursday at 10:00. Set **Planned for = Thursday 10:00** and **Deadline = Friday 17:00**. Calendar **All events** shows both moments. If both timestamps are identical, the calendar collapses them into one combined event.

Creating a task from a calendar day presets **Planned for** at 09:00 on that day rather than inventing a deadline. You can adjust the time immediately in quick edit or the full task form.

### Example: create and plan a personal task

1. Press **New task** or use the global quick-add button.
2. Enter a title such as “Prepare monthly server report”.
3. Choose the personal status and priority.
4. Set **Planned for** to the time you want to start.
5. Set **Deadline** only if there is a real completion constraint.
6. Add a customer, type or custom fields only when they will help you search, sort or report later.
7. Save the task.
8. Open **Tasks** to verify the row, **Board** to see its workflow column and **Calendar** to verify its planned/deadline events.

## Activity history

Existing tasks expose an **Activity** link from the task edit header, and projects expose **Activity** in the project navigation. The history records task/project creation and meaningful field changes such as status, deadline, priority, assignee, project state and ownership.

History stores human-readable snapshots of changed metadata so later renaming a status does not rewrite old events. Rich-text descriptions are not copied into the activity log; the event records only that the description changed. Visibility is snapshotted in the personal/team context that existed when the event was written, so transferring a project between teams does not expose the previous team's history.

## Checklists

An existing task can contain a lightweight ordered checklist. Checklist items are deliberately smaller than tasks: each item has only text, completion state and position. Use a separate task when a step needs its own deadline, status, assignee or discussion.

Checklist items can be added, renamed, marked complete/incomplete, reordered and deleted from the task edit page. The task list shows compact progress such as `2/5` when a checklist exists. Checklist changes are written to the task Activity history and use exactly the same personal/project/team authorization as the parent task.

## Saved task views

On **Tasks**, the current filter/sort/project scope can be saved as a private named view. Saved views appear above the filters and can be reopened with one click. One view may be marked as the personal default; it is applied only when opening a plain `/tasks` URL, so an explicitly requested filter always wins.

A saved view stores only normalized task-list state that TMS understands. Page numbers and transient view identifiers are not stored. Changing filters or sorting after opening a saved view does not silently modify it; save a new view if the changed combination should be kept.

## Recurring tasks

The author of an existing task can enable **Recurrence** from the edit page. TMS supports daily, weekly, monthly, every-N-days schedules and an “N days after completion” mode.

Calendar recurrence uses the current task deadline as its seed. If the source task also has a planned time, TMS preserves the same planned-time-to-deadline offset for each generated occurrence. When that schedule point is reached, TMS creates a new occurrence with the following deadline instead of mutating the old task. Monthly recurrence keeps the original day anchor: a series seeded on January 31 uses February 28/29 and returns to March 31.

**After completion** waits until the current occurrence enters a completion status, then creates the next task with a deadline N days later. New occurrences use the configured non-completion status. TMS carries forward title, description, project, priority, still-valid metadata, assignee, custom-field values and checklist text/order; the copied checklist starts incomplete. Attachments and discussions remain local to each occurrence.

Only the task author manages recurrence. Team-project members can continue working with the task under normal project permissions, but cannot enable or alter automation on another author's behalf.

## Projects

Create a project when several tasks need shared context. Tasks may stay unassigned (**No project**) or belong to a personal or team-owned project. Project filters are available in the task list, board and calendar, and a project page shows the tasks that belong to it.

Each project has its own task statuses and custom fields. Team Leads manage team-project settings; all current team members can work with the project's tasks and files. Personal projects remain private to their owner.

Project files use the same private upload policy as task attachments: they are stored outside the public web root and downloaded only through authorized application routes. Deleting a personal project keeps its tasks by returning them to **No project**. A team project cannot be deleted while it still has tasks.

Project lifecycle values (**active**, **paused**, **done**, **archived**) describe organization/state; they do not silently change authorization or make tasks read-only. A Team Lead or personal owner can change the lifecycle again when work is resumed.

## Teams

Create a team from **Teams**. The creator becomes the first **Lead**. Leads can invite existing TMS users, change member roles and manage team-owned projects. The initial role model is intentionally small: **Lead** and **Member**.

Invitations are accepted or declined inside TMS. Pending invitations are shown in the navigation badge and expire automatically. If the invited user has email and/or Telegram enabled, TMS may also send a link to the invitation page through those channels; the external message does not itself accept the invitation.

Removing a member immediately removes access to team-owned projects. Tasks remain intact, and any tasks assigned to that member become unassigned. A team must always keep at least one Lead; the last Lead cannot leave, be removed, be disabled by an administrator or be deleted until another Lead exists.

## Discussions

Team-owned projects and their tasks have contextual discussions. Use project discussion for project-wide decisions and task discussion for conversation about one task. Replies are intentionally limited to one level so discussions stay attached to work instead of becoming a general-purpose chat.

Use `@username` to notify a current member of the owning team. Direct replies notify the parent comment author. Authors can edit or delete their own comments; Team Leads can delete any comment but cannot rewrite another person's message. Deleted comments leave a placeholder when replies still depend on them.

## Filters and bulk work

The task table supports filters by status, type, priority, customer, overdue state, date ranges and custom-field values. Task list and Calendar default to **No project**. In that scope the status filter shows personal statuses only; choosing a project switches the status list to that project's workflow, while **All projects** exposes all accessible statuses. The status list refreshes immediately when the project selector changes. Multiple status/type/priority values and status inversion are available where shown by the UI.

Select several tasks to apply supported bulk changes or delete them. Operations remain limited to tasks the signed-in user is authorized to access: personal tasks/projects plus projects owned by teams they currently belong to.

## Personal metadata

Statuses, task types, customers and custom fields are personal to your account. Use the metadata/custom-field screens to adapt TMS to your workflow without changing other users' data.

## Profile

Under profile/settings you can change username, password, timezone and theme. The current themes are Graphite, Midnight, Warm Dark, Paper, Frost and System.

Language can be switched between English and Russian. The chosen UI language does not rename existing user-created metadata.

## Notifications

The **Notifications** item in the user menu is the canonical in-app inbox. It contains team mentions, direct replies, task assignments/reassignments, configured date/status-change events and scheduled reminders. Its badge updates in the background without a full page reload while the tab is visible; returning to a previously hidden tab triggers an immediate refresh. Opening an item marks it read and jumps to the relevant project/task comment when that context is still accessible. Historical notifications remain visible after access is removed, but TMS will not reopen a project/task you can no longer access.

Open **Settings → Notification settings** to choose email/Telegram channels and event/reminder rules. Assignment/reassignment and planned/deadline-change notifications are enabled by default; noisy status-change notifications are opt-in. Reminder rules cover tomorrow, approaching planned times and deadlines, overdue tasks and digest delivery. The in-app inbox is always available; enabled email and Telegram channels also carry team invitations, mentions and direct replies as links back to TMS.

To link Telegram:

1. Ask TMS to generate a one-time link command.
2. Send the complete `/link_...` command to the configured bot.
3. The bot confirms the link.
4. Use **Send test message** in TMS to verify real delivery.

A link command is valid for 24 hours and can be used once. You can disconnect Telegram from the same settings page.

## Password recovery

Use **Forgot password** on the login page. Depending on deployment configuration, a reset link can be delivered by email and/or linked Telegram. The server administrator also has a local recovery path.

## Practical workflows

### Plan a workday

1. Open **Calendar** in **Planned work** mode.
2. Add or move `scheduled_at` values for the tasks you intend to work on.
3. Leave deadlines unchanged unless the real due date changed.
4. Use **Tasks** with a saved view for today's project/status/priority combination.
5. Work the queue on **Board**, where wheel scrolling first moves a long column vertically and then hands off to horizontal board movement.

### Build a reusable project workflow

1. Create a Project.
2. Open **Statuses** inside the project and adjust the workflow for that project.
3. Add project-specific custom fields only for structured data unique to this project.
4. Assign existing or new tasks to the project.
5. Save a task-list view filtered to the project and the statuses you care about.
6. If collaboration is needed, transfer/create the project under a Team and assign responsible members.

### Collaborate without losing context in chat

1. Open the Project or Task discussion instead of moving the conversation to an external messenger.
2. Use `@username` when a specific team member must see the message.
3. Reply to a comment when context matters.
4. Use the notification inbox to return to the exact work item.
5. Keep Telegram/email as notification transports rather than the source of truth.

### Turn a repeated responsibility into a recurring task

1. Create the first real occurrence with its normal project, priority, checklist, planned time and deadline.
2. Save it, then open **Recurrence**.
3. Choose calendar recurrence or **N days after completion**.
4. Choose the non-completion status for generated occurrences.
5. Verify the next generated task before relying on the series long-term.
6. Edit the recurrence rule rather than manually cloning future tasks.

### Find work quickly with Saved Views

1. Configure project, status, priority, customer, date and custom-field filters on **Tasks**.
2. Choose the sort order.
3. Save the current combination with a descriptive name such as “Production · this week”.
4. Mark it as default only if it is genuinely your normal landing view.
5. Create a separate saved view when you want a different reusable combination; opening a saved view and changing filters does not silently overwrite it.

## Files and attachments

Task attachments and Project files are private application data. Upload them from the relevant task/project page and download them only through TMS. Do not treat the generated storage path as a public file share.

Use task attachments for material specific to one task. Use Project files for shared project-level material such as requirements, reference documents or exported reports.

## What access and assignment mean

Access and assignment are separate concepts:

- a personal task/project is visible only to its owner;
- a team-owned project and its tasks are visible to current members of that team;
- assigning a task names the responsible member but does not grant access by itself;
- removing a member removes access immediately and clears assignments that are no longer valid;
- transferring project ownership changes future visibility but does not expose history that belonged to the previous scope.

## Useful habits

- keep your profile timezone correct before relying on deadlines/calendar;
- use a completion status so overdue calculations know what is finished;
- use custom fields for structured data you actually filter or sort by;
- use a versioned browser bookmark to the normal TMS URL — no special admin URL is required for daily work.
