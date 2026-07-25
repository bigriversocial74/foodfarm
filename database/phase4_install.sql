SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS recipe_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipe_id BIGINT UNSIGNED NOT NULL,
    step_number INT UNSIGNED NOT NULL,
    instruction TEXT NOT NULL,
    timer_minutes INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recipe_steps_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
    UNIQUE KEY uq_recipe_step_number (recipe_id, step_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    prepared_by_member_id BIGINT UNSIGNED NULL,
    scale_factor DECIMAL(8,3) NOT NULL DEFAULT 1.000,
    planned_servings DECIMAL(8,2) NOT NULL,
    actual_servings DECIMAL(8,2) NULL,
    status ENUM('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recipe_runs_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_runs_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_runs_member FOREIGN KEY (prepared_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    INDEX idx_recipe_runs_household_status (household_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_run_ingredients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipe_run_id BIGINT UNSIGNED NOT NULL,
    recipe_ingredient_id BIGINT UNSIGNED NOT NULL,
    inventory_item_id BIGINT UNSIGNED NULL,
    required_quantity DECIMAL(14,4) NOT NULL,
    consumed_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
    unit VARCHAR(30) NOT NULL,
    status ENUM('available','missing','substituted','consumed') NOT NULL DEFAULT 'available',
    notes TEXT NULL,
    CONSTRAINT fk_run_ingredients_run FOREIGN KEY (recipe_run_id) REFERENCES recipe_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_run_ingredients_recipe_ingredient FOREIGN KEY (recipe_ingredient_id) REFERENCES recipe_ingredients(id) ON DELETE CASCADE,
    CONSTRAINT fk_run_ingredients_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
    UNIQUE KEY uq_run_recipe_ingredient (recipe_run_id, recipe_ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prepared_food_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipe_run_id BIGINT UNSIGNED NULL,
    inventory_item_id BIGINT UNSIGNED NULL,
    prepared_by_member_id BIGINT UNSIGNED NULL,
    name VARCHAR(180) NOT NULL,
    servings_produced DECIMAL(8,2) NOT NULL,
    servings_remaining DECIMAL(8,2) NOT NULL,
    storage_location_id BIGINT UNSIGNED NULL,
    prepared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    use_by_date DATE NULL,
    storage_method ENUM('counter','refrigerated','frozen','shelf_stable') NOT NULL DEFAULT 'refrigerated',
    reheating_notes TEXT NULL,
    intended_member_ids JSON NULL,
    status ENUM('active','consumed','spoiled','frozen','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_prepared_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_prepared_run FOREIGN KEY (recipe_run_id) REFERENCES recipe_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_prepared_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_prepared_member FOREIGN KEY (prepared_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    CONSTRAINT fk_prepared_location FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
    INDEX idx_prepared_household_status (household_id, status),
    INDEX idx_prepared_use_by (use_by_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meal_plan_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meal_plan_item_id BIGINT UNSIGNED NOT NULL,
    household_member_id BIGINT UNSIGNED NOT NULL,
    serving_multiplier_snapshot DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    expected_servings DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    CONSTRAINT fk_meal_plan_members_item FOREIGN KEY (meal_plan_item_id) REFERENCES meal_plan_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_meal_plan_members_member FOREIGN KEY (household_member_id) REFERENCES household_members(id) ON DELETE CASCADE,
    UNIQUE KEY uq_meal_member (meal_plan_item_id, household_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recipes' AND COLUMN_NAME = 'yield_quantity') = 0,
    'ALTER TABLE recipes ADD COLUMN yield_quantity DECIMAL(10,2) NULL AFTER servings',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recipes' AND COLUMN_NAME = 'yield_unit') = 0,
    'ALTER TABLE recipes ADD COLUMN yield_unit VARCHAR(30) NULL AFTER yield_quantity',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME = 'status') = 0,
    "ALTER TABLE meal_plan_items ADD COLUMN status ENUM('planned','prepared','served','skipped') NOT NULL DEFAULT 'planned' AFTER notes",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
