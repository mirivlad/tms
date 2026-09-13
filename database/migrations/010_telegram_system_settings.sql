-- Deployment-wide Telegram bot configuration. Secret values are encrypted
-- with the notification master key before being stored.

CREATE TABLE telegram_system_settings (
    id TINYINT UNSIGNED NOT NULL,
    bot_name VARCHAR(128) NOT NULL DEFAULT '',
    bot_token_ciphertext TEXT NULL,
    webhook_secret_ciphertext TEXT NULL,
    proxy_enabled TINYINT(1) NOT NULL DEFAULT 0,
    proxy_url_ciphertext TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_telegram_system_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
