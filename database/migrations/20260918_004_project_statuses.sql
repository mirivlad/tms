-- Project-scoped task statuses.
-- Personal statuses remain scoped to a user. Project statuses belong to a
-- project and are independent of the user who created a task, which is required
-- for the later Teams stage.

ALTER TABLE tasks DROP FOREIGN KEY fk_tasks_owned_status;

ALTER TABLE statuses
    MODIFY user_id BIGINT UNSIGNED NULL,
    ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD COLUMN source_status_id BIGINT UNSIGNED NULL AFTER project_id,
    ADD UNIQUE KEY uq_statuses_project_name (project_id, name),
    ADD KEY idx_statuses_project_order (project_id, sort_order),
    ADD KEY idx_statuses_source (source_status_id),
    ADD CONSTRAINT fk_statuses_project
        FOREIGN KEY (project_id) REFERENCES projects (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    ADD CONSTRAINT fk_statuses_source
        FOREIGN KEY (source_status_id) REFERENCES statuses (id)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    ADD CONSTRAINT chk_statuses_exactly_one_scope
        CHECK (
            (user_id IS NOT NULL AND project_id IS NULL)
            OR
            (user_id IS NULL AND project_id IS NOT NULL)
        );

-- Existing projects inherit a snapshot of their owner's current workflow.
INSERT INTO statuses (
    user_id, project_id, source_status_id, name, description, color, sort_order,
    is_default, is_completion, show_on_board, created_at, updated_at
)
SELECT
    NULL, p.id, s.id, s.name, s.description, s.color, s.sort_order,
    s.is_default, s.is_completion, s.show_on_board, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM projects p
INNER JOIN statuses s
    ON s.user_id = p.owner_user_id
   AND s.project_id IS NULL
WHERE p.owner_user_id IS NOT NULL
  AND p.owner_team_id IS NULL;

-- Tasks already assigned to a project keep the semantic equivalent of their
-- previous personal status instead of silently falling back to a new default.
UPDATE tasks t
INNER JOIN statuses project_status
    ON project_status.project_id = t.project_id
   AND project_status.source_status_id = t.status_id
SET t.status_id = project_status.id
WHERE t.project_id IS NOT NULL;

ALTER TABLE tasks
    ADD CONSTRAINT fk_tasks_status
        FOREIGN KEY (status_id) REFERENCES statuses (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
