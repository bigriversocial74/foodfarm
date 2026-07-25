SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'harvests' AND COLUMN_NAME = 'inventory_item_id') = 0,
    'ALTER TABLE harvests ADD COLUMN inventory_item_id BIGINT UNSIGNED NULL AFTER destination',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'harvests' AND COLUMN_NAME = 'preservation_batch_id') = 0,
    'ALTER TABLE harvests ADD COLUMN preservation_batch_id BIGINT UNSIGNED NULL AFTER inventory_item_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'harvests' AND COLUMN_NAME = 'action_key') = 0,
    'ALTER TABLE harvests ADD COLUMN action_key CHAR(64) NULL AFTER preservation_batch_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preservation_batches' AND COLUMN_NAME = 'output_inventory_item_id') = 0,
    'ALTER TABLE preservation_batches ADD COLUMN output_inventory_item_id BIGINT UNSIGNED NULL AFTER storage_location_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preservation_batches' AND COLUMN_NAME = 'action_key') = 0,
    'ALTER TABLE preservation_batches ADD COLUMN action_key CHAR(64) NULL AFTER output_inventory_item_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preservation_batches' AND COLUMN_NAME = 'updated_at') = 0,
    'ALTER TABLE preservation_batches ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS preservation_batch_inputs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    preservation_batch_id BIGINT UNSIGNED NOT NULL,
    inventory_item_id BIGINT UNSIGNED NOT NULL,
    source_harvest_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(14,4) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_preservation_input_batch FOREIGN KEY (preservation_batch_id) REFERENCES preservation_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_preservation_input_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_preservation_input_harvest FOREIGN KEY (source_harvest_id) REFERENCES harvests(id) ON DELETE SET NULL,
    INDEX idx_preservation_inputs_batch (preservation_batch_id),
    INDEX idx_preservation_inputs_inventory (inventory_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'harvests' AND CONSTRAINT_NAME = 'fk_harvest_inventory') = 0,
    'ALTER TABLE harvests ADD CONSTRAINT fk_harvest_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'harvests' AND CONSTRAINT_NAME = 'fk_harvest_preservation') = 0,
    'ALTER TABLE harvests ADD CONSTRAINT fk_harvest_preservation FOREIGN KEY (preservation_batch_id) REFERENCES preservation_batches(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'preservation_batches' AND CONSTRAINT_NAME = 'fk_preservation_output_inventory') = 0,
    'ALTER TABLE preservation_batches ADD CONSTRAINT fk_preservation_output_inventory FOREIGN KEY (output_inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'harvests' AND INDEX_NAME = 'uq_harvest_action_key') = 0,
    'CREATE UNIQUE INDEX uq_harvest_action_key ON harvests (action_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preservation_batches' AND INDEX_NAME = 'uq_preservation_action_key') = 0,
    'CREATE UNIQUE INDEX uq_preservation_action_key ON preservation_batches (action_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'garden_zones' AND INDEX_NAME = 'idx_garden_zones_household_active') = 0,
    'CREATE INDEX idx_garden_zones_household_active ON garden_zones (household_id, active)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plantings' AND INDEX_NAME = 'idx_plantings_zone_stage') = 0,
    'CREATE INDEX idx_plantings_zone_stage ON plantings (garden_zone_id, growth_stage, expected_harvest_start)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'harvests' AND INDEX_NAME = 'idx_harvests_planting_time') = 0,
    'CREATE INDEX idx_harvests_planting_time ON harvests (planting_id, harvested_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preservation_batches' AND INDEX_NAME = 'idx_preservation_household_status') = 0,
    'CREATE INDEX idx_preservation_household_status ON preservation_batches (household_id, status, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
