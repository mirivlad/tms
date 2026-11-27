CREATE TABLE mcp_oauth_clients (
    client_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    client_name VARCHAR(100) NOT NULL,
    redirect_uris TEXT NOT NULL,
    auth_method VARCHAR(32) NOT NULL,
    secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL,
    KEY idx_oauth_client_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mcp_tokens
    ADD COLUMN oauth_client_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN oauth_resource VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN oauth_refresh_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN oauth_refresh_expires_at DATETIME NULL,
    ADD UNIQUE KEY uq_oauth_refresh_hash (oauth_refresh_hash),
    ADD CONSTRAINT fk_mcp_oauth_client FOREIGN KEY (oauth_client_id) REFERENCES mcp_oauth_clients(client_id);

CREATE TABLE mcp_oauth_codes (
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    client_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    redirect_uri VARCHAR(2048) NOT NULL,
    resource_uri VARCHAR(512) NOT NULL,
    scope VARCHAR(80) NOT NULL,
    consumed_token_id BIGINT UNSIGNED NULL,
    challenge CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_oauth_code_expiry (expires_at),
    CONSTRAINT fk_mcp_code_token FOREIGN KEY (consumed_token_id) REFERENCES mcp_tokens(id) ON DELETE CASCADE,
    CONSTRAINT fk_mcp_code_client FOREIGN KEY (client_id) REFERENCES mcp_oauth_clients(client_id),
    CONSTRAINT fk_mcp_code_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mcp_oauth_used_refresh (
    refresh_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    token_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_oauth_used_expiry (expires_at),
    CONSTRAINT fk_mcp_used_token FOREIGN KEY (token_id) REFERENCES mcp_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
