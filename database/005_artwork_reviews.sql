-- Customer proof decisions; no public files or payment changes.
CREATE TABLE IF NOT EXISTS dmo_artwork_reviews (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 draft_id BIGINT UNSIGNED NULL,
 round_number INT UNSIGNED NOT NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'pending',
 feedback VARCHAR(2000) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 responded_at DATETIME NULL,
 UNIQUE KEY uq_dmo_review005_round (order_id,round_number),
 CONSTRAINT fk_dmo_review005_order FOREIGN KEY (order_id) REFERENCES dmo_orders(id) ON DELETE CASCADE,
 CONSTRAINT fk_dmo_review005_draft FOREIGN KEY (draft_id) REFERENCES dmo_delivery_drafts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dmo_schema_migrations(version) VALUES ('005_artwork_reviews')
ON DUPLICATE KEY UPDATE version=VALUES(version);
