-- Optional responsibility inside team-owned projects.

ALTER TABLE tasks
    ADD COLUMN assignee_user_id BIGINT UNSIGNED NULL AFTER project_id,
    ADD KEY idx_tasks_assignee (assignee_user_id),
    ADD CONSTRAINT fk_tasks_assignee_user
        FOREIGN KEY (assignee_user_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE SET NULL;
