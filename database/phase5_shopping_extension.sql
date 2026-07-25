SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE shopping_list_items
    ADD COLUMN status ENUM('needed','ordered','delivery_requested','purchased','skipped') NOT NULL DEFAULT 'needed' AFTER estimated_cost,
    ADD COLUMN notes TEXT NULL AFTER status;
