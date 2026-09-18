-- Project files use the same private content-address-like storage mechanism as
-- task attachments, but remain a separate domain table. This avoids making
-- task attachments polymorphic and keeps future team-project authorization local
-- to the project boundary.

CREATE TABLE project_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    storage_name CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_attachments_storage_name (storage_name),
    KEY idx_project_attachments_project_created (project_id, created_at),
    KEY idx_project_attachments_uploader (uploaded_by),
    CONSTRAINT fk_project_attachments_project
        FOREIGN KEY (project_id) REFERENCES projects (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_project_attachments_uploader
        FOREIGN KEY (uploaded_by) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
