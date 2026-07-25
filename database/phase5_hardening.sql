SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE users
    ADD COLUMN is_platform_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

UPDATE users
SET is_platform_admin = 1
WHERE id = (SELECT first_user.id FROM (SELECT id FROM users ORDER BY id ASC LIMIT 1) AS first_user)
  AND NOT EXISTS (SELECT 1 FROM users WHERE is_platform_admin = 1);

ALTER TABLE shopping_list_items
    MODIFY COLUMN source_type ENUM('manual','low_stock','recipe','garden','preservation','maintenance','starter_kit') NOT NULL DEFAULT 'manual';

CREATE INDEX idx_kit_orders_customer_email ON starter_kit_orders (customer_email);
CREATE INDEX idx_kit_activations_state ON starter_kit_activations (activated_at, revoked_at, expires_at);
CREATE INDEX idx_kit_versions_publishable ON starter_kit_versions (status, starter_kit_id);
