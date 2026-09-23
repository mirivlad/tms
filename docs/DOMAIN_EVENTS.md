# Domain event contract

TMS v0.4 introduces a durable domain-event journal. The journal is the canonical source for event consumers such as activity history, event notifications and future webhooks.

## Envelope

Every row in `domain_events` has the same stable envelope:

| Field | Meaning |
| --- | --- |
| `event_id` | UUID v4 event identifier. Consumers use this for idempotency. |
| `event_type` | Stable dotted event name. |
| `schema_version` | Payload schema version. Starts at `1`. |
| `actor_user_id` | User that caused the event, when known. |
| `actor_username` | Actor label snapshotted at event time. |
| `task_id` | Related task, if any. |
| `project_id` | Related/effective project, if any. |
| `comment_id` | Related discussion comment, if any. |
| `visibility_user_id` | Personal visibility scope. |
| `visibility_team_id` | Team visibility scope. |
| `payload_json` | Versioned event payload. |
| `occurred_at` | Application-timezone timestamp when the event was published. |

Exactly one visibility scope is required: personal user or team.

## Common payload

Task and project lifecycle events use:

```json
{
  "subject_title": "Deploy TMS",
  "changes": {
    "status": {"old": "Todo", "new": "Done"}
  }
}
```

Descriptions are deliberately represented as a change marker with null values instead of copying their contents into the event journal.

Discussion events add the author, sanitized comment body, reply relationship and discussion context identifiers required by notification consumers.

## Stable event types

### Tasks

- `task.created`
- `task.updated`
- `task.deleted`
- `task.checklist_added`
- `task.checklist_updated`
- `task.checklist_completed`
- `task.checklist_reopened`
- `task.checklist_reordered`
- `task.checklist_deleted`

Recurring task generation publishes the ordinary `task.created` event for the generated occurrence.

### Projects

- `project.created`
- `project.updated`
- `project.deleted`

### Discussions

- `discussion.comment.created`
- `discussion.comment.updated`
- `discussion.comment.deleted`

## Compatibility rules

1. Existing event type strings are not renamed within a stable release line.
2. Additive payload fields are allowed without incrementing `schema_version`.
3. Removing a field, changing its meaning/type, or changing visibility semantics requires a new schema version.
4. Consumers must be idempotent by `event_id`.
5. An event is written to the durable journal before synchronous consumers run.
6. Activity history is a consumer of task/project events; it is not the event source.
7. Notification and webhook stages must consume this contract rather than reconstructing events from UI/controller state.
