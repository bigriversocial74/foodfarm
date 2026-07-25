SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE starter_kit_recipe_snapshots
    MODIFY COLUMN recipe_snapshot LONGTEXT NOT NULL;

UPDATE starter_kit_recipe_snapshots
SET snapshot_hash = SHA2(recipe_snapshot, 256);
