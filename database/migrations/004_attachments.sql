-- Task attachments are private application data. The database records only a
-- random storage key; original client filenames are never filesystem paths.
-- Migration 003 already provides tasks(id, created_by) as a unique owner key;
-- attachments reuse it so MariaDB enforces task ownership at the FK layer.

CREATE TABLE attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    storage_name CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attachments_storage_name (storage_name),
    KEY idx_attachments_task_owner_created (task_id, user_id, created_at),
    CONSTRAINT fk_attachments_owned_task
        FOREIGN KEY (task_id, user_id) REFERENCES tasks (id, created_by)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
