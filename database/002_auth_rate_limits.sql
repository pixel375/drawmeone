-- Draw Me One · auth hardening migration 002 · 2026-11-17
-- Import once into the SAME database as 001_initial_schema.
-- Only stores HMAC-derived limiter keys; no IP addresses, passwords or emails.
-- No admin seed or live customer data.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS dmo_rate_limits (
  key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  KEY idx_dmo_limit_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dmo_schema_migrations (version)
VALUES ('002_auth_rate_limits')
ON DUPLICATE KEY UPDATE version=VALUES(version);
