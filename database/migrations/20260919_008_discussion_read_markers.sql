-- Per-user, per-team read cursors for project and task discussion contexts.

CREATE TABLE discussion_read_markers (
    user_id BIGINT UNSIGNED NOT NULL,
    team_id BIGINT UNSIGNED NOT NULL,
    context_type VARCHAR(16) NOT NULL,
    context_id BIGINT UNSIGNED NOT NULL,
    last_read_comment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, team_id, context_type, context_id),
    KEY idx_discussion_read_context (team_id, context_type, context_id, user_id),
    CONSTRAINT chk_discussion_read_context_type
        CHECK (context_type IN ('project', 'task')),
    CONSTRAINT fk_discussion_read_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_discussion_read_team
        FOREIGN KEY (team_id) REFERENCES teams (id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
