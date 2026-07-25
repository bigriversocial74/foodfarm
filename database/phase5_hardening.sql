SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_platform_admin') = 0,
    'ALTER TABLE users ADD COLUMN is_platform_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE shopping_list_items
    MODIFY COLUMN source_type ENUM('manual','low_stock','recipe','garden','preservation','maintenance','starter_kit') NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS starter_kit_recipe_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    starter_kit_version_id BIGINT UNSIGNED NOT NULL,
    source_recipe_id BIGINT UNSIGNED NULL,
    snapshot_hash CHAR(64) NOT NULL,
    recipe_snapshot JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_kit_recipe_snapshot_version FOREIGN KEY (starter_kit_version_id) REFERENCES starter_kit_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_kit_recipe_snapshot_source FOREIGN KEY (source_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
    UNIQUE KEY uq_kit_recipe_snapshot_source (starter_kit_version_id, source_recipe_id),
    INDEX idx_kit_recipe_snapshots_version (starter_kit_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO starter_kit_recipe_snapshots
    (starter_kit_version_id, source_recipe_id, snapshot_hash, recipe_snapshot)
SELECT snapshots.starter_kit_version_id,
       snapshots.source_recipe_id,
       SHA2(snapshots.recipe_snapshot, 256),
       snapshots.recipe_snapshot
FROM (
    SELECT skr.starter_kit_version_id,
           r.id AS source_recipe_id,
           CAST(JSON_OBJECT(
               'schema_version', 1,
               'name', r.name,
               'category', r.category,
               'servings', r.servings,
               'yield_quantity', r.yield_quantity,
               'yield_unit', r.yield_unit,
               'prep_minutes', r.prep_minutes,
               'cook_minutes', r.cook_minutes,
               'rest_minutes', r.rest_minutes,
               'instructions', r.instructions,
               'notes', r.notes,
               'ingredients', COALESCE((
                   SELECT JSON_ARRAYAGG(JSON_OBJECT(
                       'ingredient_name', ri.ingredient_name,
                       'quantity', ri.quantity,
                       'unit', ri.unit,
                       'optional', ri.optional,
                       'sort_order', ri.sort_order
                   ))
                   FROM recipe_ingredients ri
                   WHERE ri.recipe_id = r.id
               ), JSON_ARRAY())
           ) AS CHAR) AS recipe_snapshot
    FROM starter_kit_recipes skr
    JOIN recipes r ON r.id = skr.recipe_id
) AS snapshots
WHERE NOT EXISTS (
    SELECT 1 FROM starter_kit_recipe_snapshots existing
    WHERE existing.starter_kit_version_id = snapshots.starter_kit_version_id
      AND existing.source_recipe_id = snapshots.source_recipe_id
);

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
