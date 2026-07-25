SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shopping_list_items' AND COLUMN_NAME = 'status') = 0,
    "ALTER TABLE shopping_list_items ADD COLUMN status ENUM('needed','ordered','delivery_requested','purchased','skipped') NOT NULL DEFAULT 'needed' AFTER estimated_cost",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shopping_list_items' AND COLUMN_NAME = 'notes') = 0,
    'ALTER TABLE shopping_list_items ADD COLUMN notes TEXT NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
