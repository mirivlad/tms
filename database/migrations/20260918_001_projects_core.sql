-- Projects core.
-- A project has exactly one owner. Team ownership is reserved now and becomes
-- active when the Teams stage introduces the teams table and its foreign key.

CREATE TABLE projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NULL,
    owner_team_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    lifecycle_status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_id_user_owner (id, owner_user_id),
    KEY idx_projects_user_status (owner_user_id, lifecycle_status, updated_at),
    KEY idx_projects_team_status (owner_team_id, lifecycle_status, updated_at),
    KEY idx_projects_created_by (created_by),
    CONSTRAINT chk_projects_exactly_one_owner
        CHECK (
            (owner_user_id IS NOT NULL AND owner_team_id IS NULL)
            OR
            (owner_user_id IS NULL AND owner_team_id IS NOT NULL)
        ),
    CONSTRAINT chk_projects_lifecycle_status
        CHECK (lifecycle_status IN ('active', 'paused', 'done', 'archived')),
    CONSTRAINT fk_projects_owner_user
        FOREIGN KEY (owner_user_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_projects_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
