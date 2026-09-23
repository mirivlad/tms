-- Per-user domain-event notification preferences for v0.4 Stage D.

ALTER TABLE notification_settings
    ADD COLUMN notify_task_assignments TINYINT(1) NOT NULL DEFAULT 1 AFTER telegram_username,
    ADD COLUMN notify_task_dates TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_task_assignments,
    ADD COLUMN notify_task_status TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_task_dates;
