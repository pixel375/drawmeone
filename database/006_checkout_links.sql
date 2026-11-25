-- Order-specific external invoice references; no payment data or charges.
CREATE TABLE IF NOT EXISTS dmo_checkout_links (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 url_ciphertext TEXT NOT NULL,
 url_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_host VARCHAR(253) NOT NULL,
 currency CHAR(3) NOT NULL,
 amount_minor INT UNSIGNED NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'active',
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 revoked_at DATETIME NULL,
 UNIQUE KEY uq_dmo_checkout006_url (url_fingerprint),
 KEY idx_dmo_checkout006_order (order_id,id),
 CONSTRAINT fk_dmo_checkout006_order FOREIGN KEY (order_id) REFERENCES dmo_orders(id) ON DELETE CASCADE,
 CONSTRAINT fk_dmo_checkout006_creator FOREIGN KEY (created_by) REFERENCES dmo_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dmo_schema_migrations(version) VALUES ('006_checkout_links')
ON DUPLICATE KEY UPDATE version=VALUES(version);
