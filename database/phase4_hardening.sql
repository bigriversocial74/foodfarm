SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS prepared_food_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    prepared_food_batch_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NULL,
    action_key CHAR(64) NOT NULL,
    action_type ENUM('consumed','spoiled','frozen') NOT NULL,
    quantity DECIMAL(8,2) NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'servings',
    destination_location_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prepared_actions_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_prepared_actions_batch FOREIGN KEY (prepared_food_batch_id) REFERENCES prepared_food_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_prepared_actions_member FOREIGN KEY (member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    CONSTRAINT fk_prepared_actions_location FOREIGN KEY (destination_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
    UNIQUE KEY uq_prepared_action_household_key (household_id, action_key),
    INDEX idx_prepared_actions_batch_time (prepared_food_batch_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'auth_version') = 0,
    'ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'authentication_events' AND INDEX_NAME = 'idx_auth_event_ip_type_time') = 0,
    'CREATE INDEX idx_auth_event_ip_type_time ON authentication_events (ip_address, event_type, occurred_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'authentication_events' AND INDEX_NAME = 'idx_auth_event_user_type_time') = 0,
    'CREATE INDEX idx_auth_event_user_type_time ON authentication_events (user_id, event_type, occurred_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE authentication_events
    MODIFY COLUMN event_type ENUM(
        'login_success','login_failure','logout','invitation_created','invitation_accepted',
        'invitation_revoked','password_changed','password_change_failure','permission_updated'
    ) NOT NULL;
