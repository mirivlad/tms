-- Append-only task/project activity history with visibility snapshotted at event time.
-- Personal and team contexts are deliberately mutually exclusive so ownership
-- transfers do not expose historical events to a new owner/team.

CREATE TABLE activity_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_username VARCHAR(64) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    task_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    visibility_user_id BIGINT UNSIGNED NULL,
    visibility_team_id BIGINT UNSIGNED NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_task_created (task_id, created_at, id),
    KEY idx_activity_project_created (project_id, created_at, id),
    KEY idx_activity_visibility_user (visibility_user_id, created_at, id),
    KEY idx_activity_visibility_team (visibility_team_id, created_at, id),
    CONSTRAINT chk_activity_subject CHECK (task_id IS NOT NULL OR project_id IS NOT NULL),
    CONSTRAINT chk_activity_visibility CHECK (
        (visibility_user_id IS NOT NULL AND visibility_team_id IS NULL)
        OR
        (visibility_user_id IS NULL AND visibility_team_id IS NOT NULL)
    ),
    CONSTRAINT fk_activity_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_activity_task
        FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_activity_project
        FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_activity_visibility_user
        FOREIGN KEY (visibility_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_activity_visibility_team
        FOREIGN KEY (visibility_team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
