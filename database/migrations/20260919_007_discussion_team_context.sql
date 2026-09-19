-- Preserve the team security context of a discussion across project ownership changes.

ALTER TABLE discussion_comments
    ADD COLUMN team_id BIGINT UNSIGNED NULL AFTER task_id,
    ADD KEY idx_discussion_team (team_id, created_at, id),
    ADD CONSTRAINT fk_discussion_team
        FOREIGN KEY (team_id) REFERENCES teams (id)
        ON UPDATE RESTRICT ON DELETE CASCADE;

UPDATE discussion_comments c
LEFT JOIN tasks t ON t.id = c.task_id
INNER JOIN projects p
    ON p.id = CASE WHEN c.project_id IS NOT NULL THEN c.project_id ELSE t.project_id END
SET c.team_id = p.owner_team_id
WHERE p.owner_team_id IS NOT NULL;
