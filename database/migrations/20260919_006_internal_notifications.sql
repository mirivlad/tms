-- Canonical in-app collaboration notification inbox.
-- External email/Telegram delivery remains a transport, not notification state.

CREATE TABLE internal_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_username VARCHAR(255) NOT NULL,
    notification_type VARCHAR(32) NOT NULL,
    context_label VARCHAR(255) NOT NULL,
    body_preview VARCHAR(512) NOT NULL DEFAULT '',
    target_url VARCHAR(512) NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    task_id BIGINT UNSIGNED NULL,
    comment_id BIGINT UNSIGNED NULL,
    dedupe_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_internal_notification_dedupe (user_id, dedupe_key),
    KEY idx_internal_notifications_user_unread (user_id, read_at, created_at, id),
    KEY idx_internal_notifications_actor (actor_user_id),
    KEY idx_internal_notifications_project (project_id),
    KEY idx_internal_notifications_task (task_id),
    KEY idx_internal_notifications_comment (comment_id),
    CONSTRAINT fk_internal_notification_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_internal_notification_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_internal_notification_project
        FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_internal_notification_task
        FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL,
    CONSTRAINT fk_internal_notification_comment
        FOREIGN KEY (comment_id) REFERENCES discussion_comments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
