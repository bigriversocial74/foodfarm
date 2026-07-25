SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recipe_runs' AND COLUMN_NAME = 'completion_key') = 0,
    'ALTER TABLE recipe_runs ADD COLUMN completion_key CHAR(64) NULL AFTER prepared_by_member_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recipe_runs' AND INDEX_NAME = 'uq_recipe_runs_household_completion') = 0,
    'ALTER TABLE recipe_runs ADD UNIQUE KEY uq_recipe_runs_household_completion (household_id, completion_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recipe_ingredients' AND INDEX_NAME = 'idx_recipe_ingredients_inventory') = 0,
    'CREATE INDEX idx_recipe_ingredients_inventory ON recipe_ingredients (inventory_item_id, recipe_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_items' AND INDEX_NAME = 'idx_meal_plan_items_plan_date') = 0,
    'CREATE INDEX idx_meal_plan_items_plan_date ON meal_plan_items (meal_plan_id, meal_date, meal_type)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE authentication_events
    MODIFY COLUMN event_type ENUM(
        'login_success','login_failure','logout','invitation_created','invitation_accepted',
        'invitation_revoked','password_changed','password_change_failure','permission_updated'
    ) NOT NULL;
