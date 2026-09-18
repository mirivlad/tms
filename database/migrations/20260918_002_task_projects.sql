-- Optional project assignment for tasks.
-- Existing tasks remain unassigned. Deleting a project returns its tasks to
-- the personal "No project" pool instead of deleting work.

ALTER TABLE tasks
    ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER customer_id,
    ADD KEY idx_tasks_project_owner (project_id, created_by),
    ADD CONSTRAINT fk_tasks_project
        FOREIGN KEY (project_id) REFERENCES projects (id)
        ON UPDATE RESTRICT ON DELETE SET NULL;
