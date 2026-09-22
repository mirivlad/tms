[English](USER_GUIDE.md) | [Русский](USER_GUIDE.ru.md)

# User guide

## Main views

- **Dashboard** — counters, status distribution, stale tasks and a daily tip.
- **Tasks** — dense table with sorting, filters, pagination, quick view and bulk actions.
- **Board** — Kanban columns by status with drag-and-drop.
- **Calendar** — tasks by deadline, creation date for no-deadline mode, or both.

## Creating and editing tasks

Use **New task** or the global quick-add action. The quick-add dialog is also available with `Alt+N` or `Cmd+N` where supported by the browser/OS.

A task can contain title, description, priority, status, type, customer, deadline and custom fields. In a team-owned project it can also have an optional assignee; assignment expresses responsibility and does not change who can access the project. Open the quick-view dialog from a task card or row to change status, description and deadline without opening the full edit page. Rich descriptions are sanitized server-side. Attachments are stored separately from the public web root and are available only through authorized application routes.

## Activity history

Existing tasks expose an **Activity** link from the task edit header, and projects expose **Activity** in the project navigation. The history records task/project creation and meaningful field changes such as status, deadline, priority, assignee, project state and ownership.

History stores human-readable snapshots of changed metadata so later renaming a status does not rewrite old events. Rich-text descriptions are not copied into the activity log; the event records only that the description changed. Visibility is snapshotted in the personal/team context that existed when the event was written, so transferring a project between teams does not expose the previous team's history.

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

The **Notifications** item in the top bar is the canonical in-app inbox for team mentions and direct replies. Its badge counts unread items. Opening an item marks it read and jumps to the relevant project/task comment when that context is still accessible. Historical notifications remain visible after access is removed, but TMS will not reopen a project/task you can no longer access.

Open **Settings → Notification settings** to choose email/Telegram channels and rules for tomorrow, upcoming deadlines, overdue tasks and digest delivery. The in-app inbox is always available; enabled email and Telegram channels also carry team invitations, mentions and direct replies as links back to TMS.

To link Telegram:

1. Ask TMS to generate a one-time link command.
2. Send the complete `/link_...` command to the configured bot.
3. The bot confirms the link.
4. Use **Send test message** in TMS to verify real delivery.

A link command is valid for 24 hours and can be used once. You can disconnect Telegram from the same settings page.

## Password recovery

Use **Forgot password** on the login page. Depending on deployment configuration, a reset link can be delivered by email and/or linked Telegram. The server administrator also has a local recovery path.

## Useful habits

- keep your profile timezone correct before relying on deadlines/calendar;
- use a completion status so overdue calculations know what is finished;
- use custom fields for structured data you actually filter or sort by;
- use a versioned browser bookmark to the normal TMS URL — no special admin URL is required for daily work.
