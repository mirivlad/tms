-- Durable outbound webhooks for v0.4 Stage E.

CREATE TABLE webhook_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    endpoint_url VARCHAR(2048) NOT NULL,
    secret_ciphertext TEXT NOT NULL,
    event_types_json JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    active_since DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_webhook_subscription_active (is_active, id),
    CONSTRAINT fk_webhook_subscription_creator
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscription_id BIGINT UNSIGNED NOT NULL,
    event_id CHAR(36) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_attempt_at DATETIME(6) NULL,
    response_status SMALLINT UNSIGNED NULL,
    last_error VARCHAR(512) NULL,
    delivered_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_delivery_subscription_event (subscription_id, event_id),
    KEY idx_webhook_delivery_due (status, next_attempt_at, id),
    KEY idx_webhook_delivery_event (event_id),
    CONSTRAINT fk_webhook_delivery_subscription
        FOREIGN KEY (subscription_id) REFERENCES webhook_subscriptions (id) ON DELETE CASCADE,
    CONSTRAINT fk_webhook_delivery_event
        FOREIGN KEY (event_id) REFERENCES domain_events (event_id) ON DELETE CASCADE,
    CONSTRAINT chk_webhook_delivery_status
        CHECK (status IN ('pending', 'retry', 'succeeded', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
