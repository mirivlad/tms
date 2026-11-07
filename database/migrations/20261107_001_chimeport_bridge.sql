-- A durable forwarding cursor for the trusted-app ChimePort transport.
-- This is an application-wide transport: TMS users do not get ChimePort accounts.
CREATE TABLE chimeport_bridge_state (
    id TINYINT UNSIGNED NOT NULL,
    last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_chimeport_bridge_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
