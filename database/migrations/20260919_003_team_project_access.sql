-- Team project collaboration keeps tasks.created_by as author identity.
-- Task attachment uploader identity is independent from task authorship once
-- another team member may upload a file to the same task.

ALTER TABLE attachments
    DROP FOREIGN KEY fk_attachments_owned_task,
    MODIFY user_id BIGINT UNSIGNED NULL,
    ADD KEY idx_attachments_uploader (user_id),
    ADD CONSTRAINT fk_attachments_task
        FOREIGN KEY (task_id) REFERENCES tasks (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    ADD CONSTRAINT fk_attachments_uploader
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE SET NULL;

ALTER TABLE projects
    ADD CONSTRAINT fk_projects_owner_team
        FOREIGN KEY (owner_team_id) REFERENCES teams (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
