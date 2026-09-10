-- TMS task-domain baseline. User ownership is reinforced by composite foreign keys.

CREATE TABLE statuses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(96) NOT NULL,
    description VARCHAR(512) NOT NULL DEFAULT '',
    color CHAR(7) NOT NULL DEFAULT '#6b7280',
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_completion TINYINT(1) NOT NULL DEFAULT 0,
    show_on_board TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_statuses_user_name (user_id, name),
    UNIQUE KEY uq_statuses_id_user (id, user_id),
    KEY idx_statuses_user_order (user_id, sort_order),
    CONSTRAINT fk_statuses_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(96) NOT NULL,
    description VARCHAR(512) NOT NULL DEFAULT '',
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_task_types_user_name (user_id, name),
    UNIQUE KEY uq_task_types_id_user (id, user_id),
    KEY idx_task_types_user_order (user_id, sort_order),
    CONSTRAINT fk_task_types_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customers_user_name (user_id, name),
    UNIQUE KEY uq_customers_id_user (id, user_id),
    KEY idx_customers_user_name (user_id, name),
    CONSTRAINT fk_customers_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    deadline DATETIME NULL,
    status_id BIGINT UNSIGNED NULL,
    type_id BIGINT UNSIGNED NULL,
    priority SMALLINT NOT NULL DEFAULT 0,
    customer_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_owner_created (created_by, created_at),
    KEY idx_tasks_owner_deadline (created_by, deadline),
    KEY idx_tasks_status_owner (status_id, created_by),
    KEY idx_tasks_type_owner (type_id, created_by),
    KEY idx_tasks_customer_owner (customer_id, created_by),
    CONSTRAINT fk_tasks_owner
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_owned_status
        FOREIGN KEY (status_id, created_by) REFERENCES statuses (id, user_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_tasks_owned_type
        FOREIGN KEY (type_id, created_by) REFERENCES task_types (id, user_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_tasks_owned_customer
        FOREIGN KEY (customer_id, created_by) REFERENCES customers (id, user_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
