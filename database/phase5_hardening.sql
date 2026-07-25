SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_platform_admin') = 0,
    'ALTER TABLE users ADD COLUMN is_platform_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @first_user_id := (SELECT id FROM users ORDER BY id ASC LIMIT 1);
SET @platform_admin_count := (SELECT COUNT(*) FROM users WHERE is_platform_admin = 1);
UPDATE users
SET is_platform_admin = 1
WHERE id = @first_user_id
  AND @platform_admin_count = 0;

ALTER TABLE shopping_list_items
    MODIFY COLUMN source_type ENUM('manual','low_stock','recipe','garden','preservation','maintenance','starter_kit') NOT NULL DEFAULT 'manual';

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'starter_kit_orders' AND INDEX_NAME = 'idx_kit_orders_customer_email') = 0,
    'CREATE INDEX idx_kit_orders_customer_email ON starter_kit_orders (customer_email)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'starter_kit_activations' AND INDEX_NAME = 'idx_kit_activations_state') = 0,
    'CREATE INDEX idx_kit_activations_state ON starter_kit_activations (activated_at, revoked_at, expires_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'starter_kit_versions' AND INDEX_NAME = 'idx_kit_versions_publishable') = 0,
    'CREATE INDEX idx_kit_versions_publishable ON starter_kit_versions (status, starter_kit_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
