-- Track recent authenticated user activity for administrator account overview.
ALTER TABLE users
    ADD COLUMN last_activity_at DATETIME NULL AFTER approved_at;

CREATE INDEX idx_users_last_activity ON users (last_activity_at);
