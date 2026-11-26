ALTER TABLE mcp_tokens ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0 AFTER can_write;
ALTER TABLE mcp_mutations ADD COLUMN deletion_receipt MEDIUMTEXT NULL AFTER task_id;
