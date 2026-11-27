-- Additive V2 inbox; no customer account or legacy order relationships.
CREATE TABLE IF NOT EXISTS dmo_guest_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 operation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(100) NOT NULL,
 email VARCHAR(254) NOT NULL,
 brief TEXT NOT NULL,
 budget VARCHAR(120) NOT NULL DEFAULT '',
 status ENUM('new','read','archived') NOT NULL DEFAULT 'new',
 notice_state ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_guest_operation (operation_hash),
 KEY idx_guest_status (status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dmo_schema_migrations(version) VALUES ('007_guest_requests')
ON DUPLICATE KEY UPDATE version=VALUES(version);
