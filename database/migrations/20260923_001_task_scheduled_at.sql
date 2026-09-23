-- Planned execution time is independent from the task deadline.
-- Existing tasks remain unscheduled.

ALTER TABLE tasks
    ADD COLUMN scheduled_at DATETIME NULL AFTER deadline,
    ADD KEY idx_tasks_scheduled_at (scheduled_at);
