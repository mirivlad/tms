-- TMS is authoritative for delegated device authorization.
-- ChimePort stores routing and short-lived credentials, never TMS accounts.
CREATE TABLE chimeport_devices (
  device_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL,
  label VARCHAR(120) NOT NULL,
  browser_session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  password_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME DEFAULT NULL,
  revoked_at DATETIME DEFAULT NULL,
  PRIMARY KEY (device_id),
  KEY idx_chimeport_devices_user (user_id, revoked_at),
  KEY idx_chimeport_devices_browser (browser_session_hash),
  CONSTRAINT fk_chimeport_devices_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
