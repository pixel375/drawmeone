-- Private artist-only staged artwork. No customer access or release.
CREATE TABLE IF NOT EXISTS dmo_delivery_drafts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  storage_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  pixel_width INT UNSIGNED NOT NULL,
  pixel_height INT UNSIGNED NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dmo_delivery_storage (storage_key),
  KEY idx_dmo_delivery_order (order_id,id),
  CONSTRAINT fk_dmo_delivery_request FOREIGN KEY (request_id)
    REFERENCES dmo_commission_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_dmo_delivery_order FOREIGN KEY (order_id)
    REFERENCES dmo_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_dmo_delivery_uploader FOREIGN KEY (uploaded_by)
    REFERENCES dmo_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dmo_schema_migrations(version) VALUES ('004_delivery_drafts')
ON DUPLICATE KEY UPDATE version=VALUES(version);
