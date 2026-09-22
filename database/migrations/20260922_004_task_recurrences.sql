CREATE TABLE task_recurrences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    current_task_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(32) NOT NULL,
    interval_value SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    spawn_status_id BIGINT UNSIGNED NULL,
    timezone VARCHAR(64) NOT NULL,
    anchor_day TINYINT UNSIGNED NULL,
    next_deadline DATETIME NULL,
    next_run_at DATETIME NULL,
    sequence INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_generated_at DATETIME NULL,
    last_error VARCHAR(512) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_task_recurrence_current_task (current_task_id),
    KEY idx_task_recurrence_owner (owner_user_id),
    KEY idx_task_recurrence_due (is_active, next_run_at),
    KEY idx_task_recurrence_spawn_status (spawn_status_id),
    CONSTRAINT fk_task_recurrence_owner
        FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_task_recurrence_current_task
        FOREIGN KEY (current_task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_task_recurrence_spawn_status
        FOREIGN KEY (spawn_status_id) REFERENCES statuses (id) ON DELETE SET NULL,
    CONSTRAINT chk_task_recurrence_mode
        CHECK (mode IN ('daily', 'weekly', 'monthly', 'interval', 'after_completion')),
    CONSTRAINT chk_task_recurrence_interval
        CHECK (interval_value BETWEEN 1 AND 3650),
    CONSTRAINT chk_task_recurrence_anchor
        CHECK (anchor_day IS NULL OR anchor_day BETWEEN 1 AND 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_recurrence_occurrences (
    recurrence_id BIGINT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NOT NULL,
    scheduled_deadline DATETIME NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (recurrence_id, sequence),
    UNIQUE KEY uq_task_recurrence_occurrence_task (task_id),
    CONSTRAINT fk_task_recurrence_occurrence_recurrence
        FOREIGN KEY (recurrence_id) REFERENCES task_recurrences (id) ON DELETE CASCADE,
    CONSTRAINT fk_task_recurrence_occurrence_task
        FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
