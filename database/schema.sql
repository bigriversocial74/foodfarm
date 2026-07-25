SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE households (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,
    timezone VARCHAR(80) NOT NULL DEFAULT 'America/Phoenix',
    measurement_system ENUM('us','metric') NOT NULL DEFAULT 'us',
    currency_code CHAR(3) NOT NULL DEFAULT 'USD',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    status ENUM('active','invited','suspended','deleted') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE household_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    display_name VARCHAR(120) NOT NULL,
    age_group ENUM('adult','teen','child','guest') NOT NULL DEFAULT 'adult',
    role ENUM('owner','administrator','adult_member','youth_member','guest_helper') NOT NULL DEFAULT 'adult_member',
    status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    avatar_path VARCHAR(255) NULL,
    serving_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    dietary_pattern VARCHAR(120) NULL,
    allergen_notes TEXT NULL,
    food_preferences JSON NULL,
    permission_overrides JSON NULL,
    joined_at DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_members_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_members_household_status (household_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_member_id BIGINT UNSIGNED NOT NULL,
    skill_key VARCHAR(100) NOT NULL,
    skill_level ENUM('not_started','learning','assisted','independent','can_teach') NOT NULL DEFAULT 'not_started',
    notes TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_member_skills_member FOREIGN KEY (household_member_id) REFERENCES household_members(id) ON DELETE CASCADE,
    UNIQUE KEY uq_member_skill (household_member_id, skill_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(140) NOT NULL,
    location_type VARCHAR(80) NOT NULL,
    capacity_value DECIMAL(12,3) NULL,
    capacity_unit VARCHAR(30) NULL,
    target_temperature DECIMAL(6,2) NULL,
    target_humidity DECIMAL(6,2) NULL,
    qr_value VARCHAR(190) NULL UNIQUE,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_locations_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_locations_parent FOREIGN KEY (parent_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
    INDEX idx_locations_household (household_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    category_type ENUM('food','prepared_food','seed','garden_supply','preservation_supply','household_supply') NOT NULL DEFAULT 'food',
    CONSTRAINT fk_categories_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    UNIQUE KEY uq_category_household_slug (household_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    storage_location_id BIGINT UNSIGNED NULL,
    name VARCHAR(180) NOT NULL,
    item_type ENUM('ingredient','prepared_food','preserved_food','seed','supply') NOT NULL DEFAULT 'ingredient',
    current_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
    unit VARCHAR(30) NOT NULL DEFAULT 'each',
    estimated_quantity TINYINT(1) NOT NULL DEFAULT 0,
    reorder_level DECIMAL(14,4) NULL,
    target_stock_level DECIMAL(14,4) NULL,
    purchase_cost DECIMAL(12,2) NULL,
    best_use_date DATE NULL,
    opened_at DATETIME NULL,
    status ENUM('active','reserved','consumed','spoiled','donated','composted','archived') NOT NULL DEFAULT 'active',
    metadata JSON NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_category FOREIGN KEY (category_id) REFERENCES inventory_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_location FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
    INDEX idx_inventory_household_status (household_id, status),
    INDEX idx_inventory_location (storage_location_id),
    INDEX idx_inventory_best_use (best_use_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE food_ledger_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    inventory_item_id BIGINT UNSIGNED NULL,
    member_id BIGINT UNSIGNED NULL,
    event_type ENUM('purchased','harvested','received','stored','moved','opened','adjusted','reserved','used_in_recipe','prepared','refrigerated','frozen','preserved','consumed','donated','gifted','spoiled','composted','discarded','returned','reversed') NOT NULL,
    quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
    unit VARCHAR(30) NOT NULL,
    source_location_id BIGINT UNSIGNED NULL,
    destination_location_id BIGINT UNSIGNED NULL,
    related_type VARCHAR(80) NULL,
    related_id BIGINT UNSIGNED NULL,
    reversal_event_id BIGINT UNSIGNED NULL,
    cost_effect DECIMAL(12,2) NULL,
    notes TEXT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ledger_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_ledger_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_member FOREIGN KEY (member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_source FOREIGN KEY (source_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_destination FOREIGN KEY (destination_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_reversal FOREIGN KEY (reversal_event_id) REFERENCES food_ledger_events(id) ON DELETE SET NULL,
    INDEX idx_ledger_household_time (household_id, occurred_at),
    INDEX idx_ledger_item (inventory_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    category VARCHAR(100) NULL,
    servings DECIMAL(8,2) NOT NULL DEFAULT 1,
    prep_minutes INT UNSIGNED NULL,
    cook_minutes INT UNSIGNED NULL,
    rest_minutes INT UNSIGNED NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'active',
    instructions TEXT NULL,
    notes TEXT NULL,
    created_by_member_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recipes_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipes_member FOREIGN KEY (created_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipe_ingredients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipe_id BIGINT UNSIGNED NOT NULL,
    inventory_item_id BIGINT UNSIGNED NULL,
    ingredient_name VARCHAR(180) NOT NULL,
    quantity DECIMAL(12,4) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    optional TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_recipe_ingredients_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_ingredients_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meal_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    status ENUM('draft','active','completed','archived') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_meal_plans_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meal_plan_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meal_plan_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NULL,
    meal_date DATE NOT NULL,
    meal_type ENUM('breakfast','lunch','dinner','snack') NOT NULL,
    planned_servings DECIMAL(8,2) NOT NULL DEFAULT 1,
    participating_member_ids JSON NULL,
    notes TEXT NULL,
    CONSTRAINT fk_meal_items_plan FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE,
    CONSTRAINT fk_meal_items_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE garden_zones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(140) NOT NULL,
    zone_type VARCHAR(80) NOT NULL,
    dimensions VARCHAR(100) NULL,
    target_temperature_min DECIMAL(6,2) NULL,
    target_temperature_max DECIMAL(6,2) NULL,
    target_humidity_min DECIMAL(6,2) NULL,
    target_humidity_max DECIMAL(6,2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_zones_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plantings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    garden_zone_id BIGINT UNSIGNED NOT NULL,
    crop_name VARCHAR(140) NOT NULL,
    variety VARCHAR(140) NULL,
    planted_on DATE NOT NULL,
    expected_harvest_start DATE NULL,
    expected_harvest_end DATE NULL,
    growth_stage ENUM('planned','germinating','seedling','vegetative','flowering','fruiting','harvest_ready','completed','failed') NOT NULL DEFAULT 'planned',
    plant_count INT UNSIGNED NULL,
    notes TEXT NULL,
    CONSTRAINT fk_plantings_zone FOREIGN KEY (garden_zone_id) REFERENCES garden_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE garden_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    garden_zone_id BIGINT UNSIGNED NOT NULL,
    recorded_by_member_id BIGINT UNSIGNED NULL,
    temperature DECIMAL(6,2) NULL,
    humidity DECIMAL(6,2) NULL,
    soil_moisture DECIMAL(6,2) NULL,
    vpd DECIMAL(6,3) NULL,
    light_level DECIMAL(10,2) NULL,
    source ENUM('manual','simulated','device') NOT NULL DEFAULT 'manual',
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_readings_zone FOREIGN KEY (garden_zone_id) REFERENCES garden_zones(id) ON DELETE CASCADE,
    CONSTRAINT fk_readings_member FOREIGN KEY (recorded_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    INDEX idx_readings_zone_time (garden_zone_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE harvests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    planting_id BIGINT UNSIGNED NOT NULL,
    harvested_by_member_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(12,4) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    grade VARCHAR(60) NULL,
    destination ENUM('inventory','recipe','preservation','donation','compost') NOT NULL DEFAULT 'inventory',
    harvested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    CONSTRAINT fk_harvests_planting FOREIGN KEY (planting_id) REFERENCES plantings(id) ON DELETE CASCADE,
    CONSTRAINT fk_harvests_member FOREIGN KEY (harvested_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE preservation_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    method ENUM('water_bath','pressure_canning','dehydrating','fermenting','pickling','freezing','vacuum_sealing','dry_storage') NOT NULL,
    status ENUM('planned','prepared','processed','cooling','labeled','stored','opened','finished','discarded') NOT NULL DEFAULT 'planned',
    started_by_member_id BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    yield_quantity DECIMAL(12,4) NULL,
    yield_unit VARCHAR(30) NULL,
    storage_location_id BIGINT UNSIGNED NULL,
    best_use_date DATE NULL,
    safety_data JSON NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_batches_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_batches_member FOREIGN KEY (started_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    CONSTRAINT fk_batches_location FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shopping_lists (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    status ENUM('draft','active','shopping','completed','archived') NOT NULL DEFAULT 'active',
    budget_amount DECIMAL(12,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shopping_lists_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shopping_list_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shopping_list_id BIGINT UNSIGNED NOT NULL,
    inventory_item_id BIGINT UNSIGNED NULL,
    item_name VARCHAR(180) NOT NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 1,
    unit VARCHAR(30) NOT NULL DEFAULT 'each',
    priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    source_type ENUM('manual','low_stock','recipe','garden','preservation','maintenance') NOT NULL DEFAULT 'manual',
    supplier VARCHAR(140) NULL,
    estimated_cost DECIMAL(12,2) NULL,
    purchased TINYINT(1) NOT NULL DEFAULT 0,
    purchased_at DATETIME NULL,
    CONSTRAINT fk_shopping_items_list FOREIGN KEY (shopping_list_id) REFERENCES shopping_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_shopping_items_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grow_light_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    garden_zone_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    mode ENUM('germination','seedling','vegetative','flowering','fruiting','harvest_prep','maintenance') NOT NULL,
    starts_at TIME NOT NULL,
    ends_at TIME NOT NULL,
    intensity_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
    days_of_week SET('mon','tue','wed','thu','fri','sat','sun') NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    manual_override_until DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_light_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_light_zone FOREIGN KEY (garden_zone_id) REFERENCES garden_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE household_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    assigned_member_id BIGINT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    related_type VARCHAR(80) NULL,
    related_id BIGINT UNSIGNED NULL,
    due_at DATETIME NULL,
    recurrence_rule VARCHAR(255) NULL,
    priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('planned','ready','in_progress','completed','skipped','cancelled') NOT NULL DEFAULT 'planned',
    completed_at DATETIME NULL,
    completion_notes TEXT NULL,
    verification_required TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_member FOREIGN KEY (assigned_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    INDEX idx_tasks_household_due (household_id, due_at),
    INDEX idx_tasks_member_status (assigned_member_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NULL,
    event_key VARCHAR(100) NOT NULL,
    subject_type VARCHAR(80) NULL,
    subject_id BIGINT UNSIGNED NULL,
    summary VARCHAR(255) NOT NULL,
    metadata JSON NULL,
    visibility ENUM('household','adults_only','private') NOT NULL DEFAULT 'household',
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_activity_member FOREIGN KEY (member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    INDEX idx_activity_household_time (household_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
