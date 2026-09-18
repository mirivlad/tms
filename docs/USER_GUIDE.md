[English](USER_GUIDE.md) | [Русский](USER_GUIDE.ru.md)

# User guide

## Main views

- **Dashboard** — counters, status distribution, stale tasks and a daily tip.
- **Tasks** — dense table with sorting, filters, pagination, quick view and bulk actions.
- **Board** — Kanban columns by status with drag-and-drop.
- **Calendar** — tasks by deadline, creation date for no-deadline mode, or both.

## Creating and editing tasks

Use **New task** or the global quick-add action. The quick-add dialog is also available with `Alt+N` or `Cmd+N` where supported by the browser/OS.

A task can contain title, description, priority, status, type, customer, deadline and custom fields. Open the quick-view dialog from a task card or row to change status, description and deadline without opening the full edit page. Rich descriptions are sanitized server-side. Attachments are stored separately from the public web root and are available only through authorized application routes.

## Filters and bulk work

The task table supports filters by status, type, priority, customer, overdue state, date ranges and custom-field values. Multiple status/type/priority values and status inversion are available where shown by the UI.

Select several tasks to apply supported bulk changes or delete them. All operations remain limited to the signed-in user's tasks.

## Personal metadata

Statuses, task types, customers and custom fields are personal to your account. Use the metadata/custom-field screens to adapt TMS to your workflow without changing other users' data.

## Profile

Under profile/settings you can change username, password, timezone and theme. The current themes are Graphite, Midnight, Warm Dark, Paper, Frost and System.

Language can be switched between English and Russian. The chosen UI language does not rename existing user-created metadata.

## Notifications

Open **Settings → Notifications** to choose email/Telegram channels and rules for tomorrow, upcoming deadlines, overdue tasks and digest delivery.

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
