-- Rate-limit mobile credential authentication before issuing trusted-app tickets.
CREATE TABLE chimeport_mobile_login_attempts (
  identifier CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (identifier),
  KEY idx_chimeport_attempts_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
