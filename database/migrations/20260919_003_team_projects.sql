-- Activate team ownership reserved by Projects Core.

ALTER TABLE projects
    ADD CONSTRAINT fk_projects_owner_team
        FOREIGN KEY (owner_team_id) REFERENCES teams (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
