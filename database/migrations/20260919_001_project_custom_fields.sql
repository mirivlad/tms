-- Project-scoped custom fields.
-- Values keep user_id as the task-owner identity for the existing task/value
-- composite FK; field ownership is now independent and can be personal/project.

ALTER TABLE task_custom_field_values
    DROP FOREIGN KEY fk_task_custom_values_owned_field;

ALTER TABLE custom_fields
    MODIFY user_id BIGINT UNSIGNED NULL,
    ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD COLUMN source_field_id BIGINT UNSIGNED NULL AFTER project_id,
    ADD UNIQUE KEY uq_custom_fields_project_name (project_id, name),
    ADD KEY idx_custom_fields_project_order (project_id, sort_order),
    ADD KEY idx_custom_fields_source (source_field_id),
    ADD CONSTRAINT fk_custom_fields_project
        FOREIGN KEY (project_id) REFERENCES projects (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    ADD CONSTRAINT fk_custom_fields_source
        FOREIGN KEY (source_field_id) REFERENCES custom_fields (id)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    ADD CONSTRAINT chk_custom_fields_exactly_one_scope
        CHECK (
            (user_id IS NOT NULL AND project_id IS NULL)
            OR
            (user_id IS NULL AND project_id IS NOT NULL)
        );

-- Existing projects snapshot the owner's current field definitions.
INSERT INTO custom_fields (
    user_id, project_id, source_field_id, name, field_type, options_json,
    is_required, sort_order, created_at, updated_at
)
SELECT
    NULL, p.id, f.id, f.name, f.field_type, f.options_json,
    f.is_required, f.sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM projects p
INNER JOIN custom_fields f
    ON f.user_id = p.owner_user_id
   AND f.project_id IS NULL
WHERE p.owner_user_id IS NOT NULL
  AND p.owner_team_id IS NULL;

-- Existing values of tasks already assigned to projects follow the cloned
-- project fields without changing their encoded value.
INSERT INTO task_custom_field_values (
    task_id, field_id, user_id, value, created_at, updated_at
)
SELECT
    v.task_id, project_field.id, v.user_id, v.value, v.created_at, CURRENT_TIMESTAMP
FROM task_custom_field_values v
INNER JOIN tasks t
    ON t.id = v.task_id
   AND t.created_by = v.user_id
INNER JOIN custom_fields personal_field
    ON personal_field.id = v.field_id
   AND personal_field.user_id = v.user_id
   AND personal_field.project_id IS NULL
INNER JOIN custom_fields project_field
    ON project_field.project_id = t.project_id
   AND project_field.source_field_id = personal_field.id
WHERE t.project_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    value = VALUES(value),
    updated_at = CURRENT_TIMESTAMP;

DELETE v
FROM task_custom_field_values v
INNER JOIN tasks t
    ON t.id = v.task_id
   AND t.created_by = v.user_id
INNER JOIN custom_fields personal_field
    ON personal_field.id = v.field_id
   AND personal_field.user_id = v.user_id
   AND personal_field.project_id IS NULL
WHERE t.project_id IS NOT NULL;

ALTER TABLE task_custom_field_values
    ADD CONSTRAINT fk_task_custom_values_field
        FOREIGN KEY (field_id) REFERENCES custom_fields (id)
        ON UPDATE RESTRICT ON DELETE CASCADE;
