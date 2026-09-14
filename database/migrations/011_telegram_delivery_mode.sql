-- Telegram inbound delivery can use either a public webhook or outbound long polling.
-- The polling offset is persisted so restarts do not replay already handled updates.

ALTER TABLE telegram_system_settings
    ADD COLUMN delivery_mode VARCHAR(16) NOT NULL DEFAULT 'webhook' AFTER webhook_secret_ciphertext,
    ADD COLUMN polling_offset BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER proxy_url_ciphertext,
    ADD CONSTRAINT chk_telegram_delivery_mode CHECK (delivery_mode IN ('webhook', 'polling'));
