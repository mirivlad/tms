-- Human discussion comments are intentionally separate from system activity.

CREATE TABLE discussion_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NULL,
    task_id BIGINT UNSIGNED NULL,
    parent_comment_id BIGINT UNSIGNED NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_discussion_project (project_id, task_id, created_at, id),
    KEY idx_discussion_task (task_id, created_at, id),
    KEY idx_discussion_parent (parent_comment_id, created_at, id),
    KEY idx_discussion_author (author_user_id, created_at),
    CONSTRAINT chk_discussion_context
        CHECK ((project_id IS NOT NULL AND task_id IS NULL)
            OR (project_id IS NULL AND task_id IS NOT NULL)),
    CONSTRAINT fk_discussion_project
        FOREIGN KEY (project_id) REFERENCES projects (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_discussion_task
        FOREIGN KEY (task_id) REFERENCES tasks (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_discussion_parent
        FOREIGN KEY (parent_comment_id) REFERENCES discussion_comments (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_discussion_author
        FOREIGN KEY (author_user_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
