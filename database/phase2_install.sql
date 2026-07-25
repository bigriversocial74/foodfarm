SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'height_value') = 0,
    'ALTER TABLE household_members ADD COLUMN height_value DECIMAL(7,2) NULL AFTER serving_multiplier',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'height_unit') = 0,
    "ALTER TABLE household_members ADD COLUMN height_unit ENUM('in','cm') NOT NULL DEFAULT 'in' AFTER height_value",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'weight_value') = 0,
    'ALTER TABLE household_members ADD COLUMN weight_value DECIMAL(7,2) NULL AFTER height_unit',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'weight_unit') = 0,
    "ALTER TABLE household_members ADD COLUMN weight_unit ENUM('lb','kg') NOT NULL DEFAULT 'lb' AFTER weight_value",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'activity_level') = 0,
    "ALTER TABLE household_members ADD COLUMN activity_level ENUM('not_set','mostly_sedentary','lightly_active','moderately_active','very_active','physically_demanding') NOT NULL DEFAULT 'not_set' AFTER weight_unit",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'wellness_visibility') = 0,
    "ALTER TABLE household_members ADD COLUMN wellness_visibility ENUM('private','authorized_adults','household_planning') NOT NULL DEFAULT 'private' AFTER activity_level",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'wellness_updated_at') = 0,
    'ALTER TABLE household_members ADD COLUMN wellness_updated_at DATETIME NULL AFTER wellness_visibility',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO inventory_categories (household_id, name, slug, category_type)
SELECT NULL, 'Grains & Flours', 'grains-flours', 'food'
WHERE NOT EXISTS (SELECT 1 FROM inventory_categories WHERE household_id IS NULL AND slug = 'grains-flours');

INSERT INTO inventory_categories (household_id, name, slug, category_type)
SELECT NULL, 'Beans & Legumes', 'beans-legumes', 'food'
WHERE NOT EXISTS (SELECT 1 FROM inventory_categories WHERE household_id IS NULL AND slug = 'beans-legumes');

INSERT INTO inventory_categories (household_id, name, slug, category_type)
SELECT NULL, 'Prepared Foods', 'prepared-foods', 'prepared_food'
WHERE NOT EXISTS (SELECT 1 FROM inventory_categories WHERE household_id IS NULL AND slug = 'prepared-foods');

INSERT INTO inventory_categories (household_id, name, slug, category_type)
SELECT NULL, 'Preserved Foods', 'preserved-foods', 'food'
WHERE NOT EXISTS (SELECT 1 FROM inventory_categories WHERE household_id IS NULL AND slug = 'preserved-foods');
