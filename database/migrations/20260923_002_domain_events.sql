-- Durable domain-event journal for v0.4 event consumers and future webhooks.
-- Existing activity rows predate the journal and therefore keep a NULL source_event_id.

CREATE TABLE domain_events (
    event_id CHAR(36) NOT NULL,
    event_type VARCHAR(96) NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_username VARCHAR(64) NOT NULL,
    task_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    comment_id BIGINT UNSIGNED NULL,
    visibility_user_id BIGINT UNSIGNED NULL,
    visibility_team_id BIGINT UNSIGNED NULL,
    payload_json JSON NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (event_id),
    KEY idx_domain_event_type_time (event_type, occurred_at, event_id),
    KEY idx_domain_event_task_time (task_id, occurred_at, event_id),
    KEY idx_domain_event_project_time (project_id, occurred_at, event_id),
    KEY idx_domain_event_comment_time (comment_id, occurred_at, event_id),
    KEY idx_domain_event_user_scope (visibility_user_id, occurred_at, event_id),
    KEY idx_domain_event_team_scope (visibility_team_id, occurred_at, event_id),
    CONSTRAINT chk_domain_event_subject CHECK (
        task_id IS NOT NULL OR project_id IS NOT NULL OR comment_id IS NOT NULL
    ),
    CONSTRAINT chk_domain_event_visibility CHECK (
        (visibility_user_id IS NOT NULL AND visibility_team_id IS NULL)
        OR
        (visibility_user_id IS NULL AND visibility_team_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE activity_events
    ADD COLUMN source_event_id CHAR(36) NULL AFTER id,
    ADD UNIQUE KEY uq_activity_source_event (source_event_id);
