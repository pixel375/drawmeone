-- Draw Me One v2.1: private customer reference images.
-- Apply only via the fixed Hostinger SSH migration workflow.
-- Adds an empty table; it does not enable customer upload or public access.
CREATE TABLE IF NOT EXISTS dmo_request_attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  storage_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mime_type VARCHAR(40) NOT NULL DEFAULT 'image/webp',
  pixel_width INT UNSIGNED NOT NULL,
  pixel_height INT UNSIGNED NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dmo_reference_storage_key (storage_key),
  KEY idx_dmo_reference_request (request_id, id),
  CONSTRAINT fk_dmo_reference_request FOREIGN KEY (request_id)
    REFERENCES dmo_commission_requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_dmo_reference_uploader FOREIGN KEY (uploaded_by)
    REFERENCES dmo_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dmo_schema_migrations (version)
VALUES ('003_private_references')
ON DUPLICATE KEY UPDATE version=VALUES(version);
