SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS starter_kits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    kit_type ENUM('basic','specialized') NOT NULL DEFAULT 'basic',
    category VARCHAR(100) NULL,
    description TEXT NULL,
    image_path VARCHAR(255) NULL,
    status ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_starter_kits_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starter_kit_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    starter_kit_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    sku VARCHAR(100) NOT NULL,
    price DECIMAL(12,2) NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_kit_versions_kit FOREIGN KEY (starter_kit_id) REFERENCES starter_kits(id) ON DELETE CASCADE,
    UNIQUE KEY uq_kit_version (starter_kit_id, version_number),
    UNIQUE KEY uq_kit_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starter_kit_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    starter_kit_version_id BIGINT UNSIGNED NOT NULL,
    item_name VARCHAR(180) NOT NULL,
    item_kind ENUM('ingredient','equipment','supply','seed','digital') NOT NULL DEFAULT 'ingredient',
    fulfillment_type ENUM('shipped','shopping_list','optional_delivery','digital_only','customer_supplied') NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    delivery_eligible TINYINT(1) NOT NULL DEFAULT 0,
    shipping_eligible TINYINT(1) NOT NULL DEFAULT 0,
    default_quantity DECIMAL(14,4) NULL,
    unit VARCHAR(30) NULL,
    inventory_category_id BIGINT UNSIGNED NULL,
    suggested_storage_type VARCHAR(80) NULL,
    reorder_level DECIMAL(14,4) NULL,
    estimated_price DECIMAL(12,2) NULL,
    supplier_name VARCHAR(180) NULL,
    inventory_metadata JSON NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_kit_items_version FOREIGN KEY (starter_kit_version_id) REFERENCES starter_kit_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_kit_items_category FOREIGN KEY (inventory_category_id) REFERENCES inventory_categories(id) ON DELETE SET NULL,
    INDEX idx_kit_items_version (starter_kit_version_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starter_kit_recipes (
    starter_kit_version_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (starter_kit_version_id, recipe_id),
    CONSTRAINT fk_kit_recipes_version FOREIGN KEY (starter_kit_version_id) REFERENCES starter_kit_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_kit_recipes_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starter_kit_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    starter_kit_version_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    area VARCHAR(80) NULL,
    due_offset_days INT NOT NULL DEFAULT 0,
    recurring_rule VARCHAR(190) NULL,
    instructions TEXT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_kit_tasks_version FOREIGN KEY (starter_kit_version_id) REFERENCES starter_kit_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starter_kit_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NULL,
    starter_kit_version_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(190) NULL,
    customer_email VARCHAR(190) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    fulfillment_status ENUM('pending','processing','partially_fulfilled','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
    activation_status ENUM('pending','activated','expired','revoked') NOT NULL DEFAULT 'pending',
    purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_kit_orders_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE SET NULL,
    CONSTRAINT fk_kit_orders_version FOREIGN KEY (starter_kit_version_id) REFERENCES starter_kit_versions(id),
    UNIQUE KEY uq_kit_external_order (external_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starter_kit_activations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    starter_kit_order_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NULL,
    activated_by_member_id BIGINT UNSIGNED NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    activated_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_kit_activation_order FOREIGN KEY (starter_kit_order_id) REFERENCES starter_kit_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_kit_activation_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE SET NULL,
    CONSTRAINT fk_kit_activation_member FOREIGN KEY (activated_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starter_kit_activation_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    starter_kit_activation_id BIGINT UNSIGNED NOT NULL,
    starter_kit_item_id BIGINT UNSIGNED NOT NULL,
    selected_fulfillment_type ENUM('shipped','shopping_list','optional_delivery','digital_only','customer_supplied') NOT NULL,
    confirmed_quantity DECIMAL(14,4) NULL,
    unit VARCHAR(30) NULL,
    status ENUM('pending','shopping','delivery_requested','shipped','received','stocked','skipped') NOT NULL DEFAULT 'pending',
    inventory_item_id BIGINT UNSIGNED NULL,
    shopping_list_item_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_activation_items_activation FOREIGN KEY (starter_kit_activation_id) REFERENCES starter_kit_activations(id) ON DELETE CASCADE,
    CONSTRAINT fk_activation_items_item FOREIGN KEY (starter_kit_item_id) REFERENCES starter_kit_items(id),
    CONSTRAINT fk_activation_items_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_activation_items_shopping FOREIGN KEY (shopping_list_item_id) REFERENCES shopping_list_items(id) ON DELETE SET NULL,
    UNIQUE KEY uq_activation_item (starter_kit_activation_id, starter_kit_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
