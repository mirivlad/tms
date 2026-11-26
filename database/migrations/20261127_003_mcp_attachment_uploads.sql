CREATE TABLE mcp_attachment_uploads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    filename VARCHAR(255) NOT NULL,
    total_bytes BIGINT UNSIGNED NOT NULL,
    received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expected_sha256 CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_mcp_upload_owner (user_id, expires_at),
    KEY idx_mcp_upload_expiry (expires_at),
    CONSTRAINT fk_mcp_upload_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mcp_upload_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_mcp_upload_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mcp_attachment_chunks (
    upload_id BIGINT UNSIGNED NOT NULL,
    byte_offset BIGINT UNSIGNED NOT NULL,
    content MEDIUMBLOB NOT NULL,
    PRIMARY KEY (upload_id, byte_offset),
    CONSTRAINT fk_mcp_chunk_upload FOREIGN KEY (upload_id) REFERENCES mcp_attachment_uploads(id) ON DELETE CASCADE
) ENGINE=InnoDB;
