-- Notification settings and delivery state. SMTP is a single deployment-wide
-- transport; delivery preferences belong to individual users.

CREATE TABLE notification_settings (
    user_id BIGINT UNSIGNED NOT NULL,
    email_enabled TINYINT(1) NOT NULL DEFAULT 0,
    email_address VARCHAR(254) NULL,
    telegram_enabled TINYINT(1) NOT NULL DEFAULT 0,
    telegram_chat_id VARCHAR(64) NULL,
    telegram_username VARCHAR(64) NULL,
    notify_tomorrow TINYINT(1) NOT NULL DEFAULT 0,
    tomorrow_time TIME NOT NULL DEFAULT '08:00:00',
    notify_upcoming TINYINT(1) NOT NULL DEFAULT 0,
    urgent_minutes INT UNSIGNED NOT NULL DEFAULT 15,
    high_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    medium_minutes INT UNSIGNED NOT NULL DEFAULT 240,
    low_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
    notify_overdue TINYINT(1) NOT NULL DEFAULT 0,
    overdue_time TIME NOT NULL DEFAULT '09:00:00',
    notify_digest TINYINT(1) NOT NULL DEFAULT 0,
    digest_time TIME NOT NULL DEFAULT '19:30:00',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_notification_settings_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE smtp_settings (
    id TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    host VARCHAR(255) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    username VARCHAR(255) NOT NULL DEFAULT '',
    password_ciphertext TEXT NULL,
    encryption VARCHAR(16) NOT NULL DEFAULT 'tls',
    from_email VARCHAR(254) NOT NULL,
    from_name VARCHAR(255) NOT NULL DEFAULT 'TMS',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_smtp_singleton CHECK (id = 1),
    CONSTRAINT chk_smtp_encryption CHECK (encryption IN ('', 'tls', 'ssl'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE telegram_link_tokens (
    selector CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    verifier_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (selector),
    UNIQUE KEY uq_telegram_link_user (user_id),
    KEY idx_telegram_link_expires (expires_at),
    CONSTRAINT fk_telegram_link_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sent_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NULL,
    channel VARCHAR(16) NOT NULL,
    notification_type VARCHAR(32) NOT NULL,
    dedupe_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sent_notification_dedupe (user_id, channel, dedupe_key),
    KEY idx_sent_notifications_user_sent (user_id, sent_at),
    KEY idx_sent_notifications_task (task_id),
    CONSTRAINT chk_sent_channel CHECK (channel IN ('email', 'telegram')),
    CONSTRAINT fk_sent_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sent_notifications_task
        FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
