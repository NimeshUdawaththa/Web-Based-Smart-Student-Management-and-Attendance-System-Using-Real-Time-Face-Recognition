-- Batch Modules v1: which modules a batch currently takes.
-- Idempotent application is via database/scripts/migrate_batch_modules.php

CREATE TABLE IF NOT EXISTS batch_modules (
  batch_module_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id INT UNSIGNED NOT NULL,
  module_id INT UNSIGNED NOT NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (batch_module_id),
  UNIQUE KEY uq_batch_modules_batch_module (batch_id, module_id),
  KEY idx_batch_modules_module (module_id),
  CONSTRAINT fk_batch_modules_batch
    FOREIGN KEY (batch_id) REFERENCES batches (batch_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_batch_modules_module
    FOREIGN KEY (module_id) REFERENCES modules (module_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
