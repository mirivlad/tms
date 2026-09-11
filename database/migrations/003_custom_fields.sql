-- Owner-scoped custom fields and values.
-- user_id is repeated on task_custom_field_values so the database itself can
-- enforce that a value connects a task and a field belonging to the same user.

ALTER TABLE tasks
    ADD UNIQUE KEY uq_tasks_id_owner (id, created_by);

CREATE TABLE custom_fields (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(96) NOT NULL,
    field_type VARCHAR(32) NOT NULL,
    options_json JSON NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_custom_fields_user_name (user_id, name),
    UNIQUE KEY uq_custom_fields_id_user (id, user_id),
    KEY idx_custom_fields_user_order (user_id, sort_order),
    CONSTRAINT chk_custom_fields_type
        CHECK (field_type IN ('text', 'textarea', 'select', 'money', 'checkbox', 'checkbox_list')),
    CONSTRAINT fk_custom_fields_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_custom_field_values (
    task_id BIGINT UNSIGNED NOT NULL,
    field_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    value MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, field_id),
    KEY idx_task_custom_values_field_owner (field_id, user_id),
    KEY idx_task_custom_values_owner_task (user_id, task_id),
    CONSTRAINT fk_task_custom_values_owned_task
        FOREIGN KEY (task_id, user_id) REFERENCES tasks (id, created_by)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_task_custom_values_owned_field
        FOREIGN KEY (field_id, user_id) REFERENCES custom_fields (id, user_id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
