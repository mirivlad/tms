[English](USER_GUIDE.md) | [Русский](USER_GUIDE.ru.md)

# User guide

## Main views

- **Dashboard** — counters, status distribution, stale tasks and a daily tip.
- **Tasks** — dense table with sorting, filters, pagination, quick view and bulk actions.
- **Board** — Kanban columns by status with drag-and-drop.
- **Calendar** — tasks by planned time and deadline; tasks with neither date can use creation date in the corresponding mode.

## Creating and editing tasks

Use **New task** or the global quick-add action. The quick-add dialog is also available with `Alt+N` or `Cmd+N` where supported by the browser/OS.

A task can contain title, description, priority, status, type, customer, planned time (**Planned for**), deadline and custom fields. In a team-owned project it can also have an optional assignee; assignment expresses responsibility and does not change who can access the project. Open the quick-view dialog from a task card or row to change status, description, planned time and deadline without opening the full edit page. Rich descriptions are sanitized server-side. Attachments are stored separately from the public web root and are available only through authorized application routes.

## Planned time and deadline

**Planned for** (`scheduled_at`) is when you intend to start or work on the task. **Deadline** is the hard completion boundary. They are independent: a task can have either one, both, or neither.

Planned time is available in create/full edit/quick edit, task-list sorting and filtering, Saved Views and Calendar. Calendar prefers planned time as the work moment, falls back to deadline when no planned time exists, and can use creation date for undated tasks in the corresponding mode. Recurring tasks carry planned time into generated occurrences consistently with the recurrence schedule.

## Activity history

Existing tasks expose an **Activity** link from the task edit header, and projects expose **Activity** in the project navigation. The history records task/project creation and meaningful field changes such as status, planned time, deadline, priority, assignee, project state and ownership.

History stores human-readable snapshots of changed metadata so later renaming a status does not rewrite old events. Rich-text descriptions are not copied into the activity log; the event records only that the description changed. Visibility is snapshotted in the personal/team context that existed when the event was written, so transferring a project between teams does not expose the previous team's history.

## Checklists

An existing task can contain a lightweight ordered checklist. Checklist items are deliberately smaller than tasks: each item has only text, completion state and position. Use a separate task when a step needs its own deadline, status, assignee or discussion.

Checklist items can be added, renamed, marked complete/incomplete, reordered and deleted from the task edit page. The task list shows compact progress such as `2/5` when a checklist exists. Checklist changes are written to the task Activity history and use exactly the same personal/project/team authorization as the parent task.

## Saved task views

On **Tasks**, the current filter/sort/project scope can be saved as a private named view. Saved views appear above the filters and can be reopened with one click. One view may be marked as the personal default; it is applied only when opening a plain `/tasks` URL, so an explicitly requested filter always wins.

A saved view stores only normalized task-list state that TMS understands. Page numbers and transient view identifiers are not stored. Changing filters or sorting after opening a saved view does not silently modify it; save a new view if the changed combination should be kept.

## Recurring tasks

The author of an existing task can enable **Recurrence** from the edit page. TMS supports daily, weekly, monthly, every-N-days schedules and an “N days after completion” mode.

Calendar recurrence uses the current task deadline as its seed. When that schedule point is reached, TMS creates a new occurrence with the following deadline instead of mutating the old task. Monthly recurrence keeps the original day anchor: a series seeded on January 31 uses February 28/29 and returns to March 31.

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

The **Notifications** item in the top bar is the canonical in-app inbox for work events: mentions/replies, assignments/reassignments, planned-time/deadline changes, optional status changes and time-based reminders. The unread badge refreshes without a full page reload while the tab is visible and is refreshed immediately when a hidden tab becomes visible again. Opening an item marks it read and opens its accessible task/project context. Historical notifications remain visible after access is removed, but inaccessible work is not reopened.

Open **Settings → Notification settings** to select work-event rules, reminders and additional email/Telegram transports. Assignments/reassignments, planned-time/deadline changes, status changes, upcoming planned/deadline reminders, tomorrow tasks, overdue tasks and digest delivery can be controlled independently where applicable. The in-app inbox remains canonical; email and Telegram carry the same events as additional transports.

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


## Push notifications through your TMS account

If the administrator configured the optional ChimePort delivery service, open **Settings → Notifications** in TMS and click **Enable notifications on this device**. Allow notifications in your browser. You do not need a second account: notification access and message recipients are verified by TMS. On Android, the ChimePort app can also accept your **TMS server address** and existing TMS login and password; the password is sent only to TMS. A browser may delay delivery while asleep or offline; the in-app TMS inbox remains the canonical history.

## AI task management

Open Profile → AI integrations (MCP), enter a connection name, choose read-only, read/write or read/write/delete, set a validity period (0 days means no expiration; default is 30 days) and confirm your password. Copy the endpoint and the newly issued secret into an MCP client supporting a custom Authorization: Bearer header. The secret is displayed once. Revoke a lost or unused credential from the same screen.

The client can list and read projects/statuses, create and edit personal projects (team projects require a team lead), change their lifecycle including archival, list task types/customers and available project assignees, create/search/edit/complete tasks, assign team members, move tasks between projects and add, rename, reorder or mark checklist items. A move without a supplied status selects the destination default; incompatible metadata must be explicitly cleared with null. Use list_custom_fields to discover field IDs and types, and pass custom_fields as an object keyed by those IDs to create_task or update_task. Values are strings (including decimal money), booleans for checkboxes, arrays of selected options or null. Omitted values are preserved on updates. Required fields are validated; project moves remap values by field lineage and matching type and reject loss of populated values. Recurring tasks must have their recurrence removed before a project move. In 0.6.0 ownership transfer and empty project deletion are available with confirmation. Tasks use your existing permissions and the regular TMS notification rules. Describe what you want in natural language, ask the client to search for duplicates first, and specify dates and time zones explicitly. With a separately issued read/write/delete token, delete one task or a batch of up to 100 exact task IDs after explicit approval. Deletion is permanent, including attachments. The deletion scope also permits removing a specific checklist item with explicit confirmation. Ordinary read/write tokens cannot delete. Administrative actions are unavailable. OAuth is available in 0.6.1; direct ChatGPT connectivity still needs separate integration testing.


### MCP planning and reference management

Use set_recurrence to configure daily, weekly, monthly, interval or after_completion repetition for a task you authored, with an available non-completion spawn status. Calendar modes require a deadline. The existing recurrence worker generates instances. get_task includes the recurrence (author only); delete_recurrence requires the deletion scope and explicit approval, and leaves generated tasks intact.

list_tasks supports project/no-project, status (including inversion), type, customer ID/name, priority, assignee, overdue, and inclusive deadline/planned/creation date ranges in YYYY-MM-DD. custom_filters requires a single project or without_project=true: text contains, select exact, checkbox boolean, money exact decimal or {min,max}, checkbox-list array or {values,match} (any/all). summarize_tasks uses the same filters without pagination and counts tasks by status/project/priority/assignee. get_task_history and get_project_history return accessible history.

create_reference, update_reference, reorder_references, set_status_role and delete_reference manage personal statuses, task types, customers, custom fields, and project statuses/fields where you are the owner or lead. Customers have no manual ordering. Reordering requires the full exact ID set. Used references and default/completion statuses are protected from deletion. Deleting a field or replacing its type/options can remove stored values; delete_reference and replace_custom_field_definition require the deletion scope and explicit confirmation of the exact reference and consequences. update_tasks atomically changes shared task metadata/dates for up to 100 distinct accessible task IDs; it does not move tasks or edit individual custom values.


### Collaboration through MCP

get_team lists accessible team information, members and projects. create_team and update_team create and edit teams; only leads can invite members, change roles and remove members. list_invitations lists your pending invitations or those of a team you lead. invite_team_member takes an exact username or email; respond_to_invitation accepts or declines your own invitation. Both and change_team_role require explicit confirmation. The last usable lead cannot be removed or leave, and teams containing projects cannot be deleted. Revocation, removal, leaving and team deletion require deletion scope and explicit confirmation.

list_comments, add_comment, update_comment, delete_comment and mark_discussion_read operate on exactly one task_id or project_id in a team project. Replies use parent_comment_id; edit your own comments, and delete only as author or team lead. Reading does not mark read automatically. Comment HTML is sanitized.

list_attachments, read_attachment, upload_attachment and delete_attachment operate on exactly one accessible task or project. Upload filename and content_base64; up to 128 KiB per request and the configured file policy applies. Read bytes with offset and length (maximum 32 KiB), continuing at next_offset until null. For larger files use resumable uploads described below. Deleting a file requires explicit confirmation and deletion permission. The server never exposes storage paths.


### Workspace workflows in MCP 0.6.0

list_calendar returns tasks for an inclusive range up to 366 days using calendar filters. list_saved_views, get_saved_view, create_saved_view, set_default_saved_view and delete_saved_view use the web UI's saved views. Supply a URL query string such as project=all&priority=high when creating a view; unknown keys are discarded. Open a view in the web interface at /tasks/views/ID.

list_notifications reads your internal inbox and unread count; mark_notification_read and mark_all_notifications_read mark messages read. get_preferences/update_preferences read and change timezone/theme, and get_notification_settings/update_notification_settings read and patch notification switches and schedules. Settings changes require confirmation; addresses and account links are neither exposed nor changed. restore_default_statuses restores missing personal status templates.

transfer_project assigns a manageable project to a team you lead or to you personally (team_id=null). Access changes, assignees are cleared, and personal type/customer metadata is cleared when transferring to a team. Explicit confirmation is required. delete_project only deletes an empty project with deletion scope and confirmation. Move tasks explicitly before deleting a nonempty project; incompatible populated fields are not silently discarded.

Large files: begin_attachment_upload takes exactly one task_id or project_id, filename, full byte size and sha256. Send append_attachment_chunk with upload_id, offset and content_base64 using 131072-byte chunks except the final remainder. get_attachment_upload returns the next offset. finish_attachment_upload checks size, hash and file type before publication; cancel_attachment_upload discards unfinished bytes. Reuse request_key only for identical retries. Limits are the configured ATTACHMENT_MAX_BYTES, four unfinished uploads per user and a 24-hour lifetime. Binary workflows require a client able to read local files and compute hashes.


OAuth connections: [OAuth 0.6.1 guide](MCP_OAUTH.md).
