SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE recipe_runs
    ADD COLUMN completion_key CHAR(64) NULL AFTER prepared_by_member_id,
    ADD UNIQUE KEY uq_recipe_runs_household_completion (household_id, completion_key);

CREATE INDEX idx_recipe_ingredients_inventory
    ON recipe_ingredients (inventory_item_id, recipe_id);

CREATE INDEX idx_meal_plan_items_plan_date
    ON meal_plan_items (meal_plan_id, meal_date, meal_type);

ALTER TABLE authentication_events
    MODIFY COLUMN event_type ENUM(
        'login_success','login_failure','logout','invitation_created','invitation_accepted',
        'invitation_revoked','password_changed','password_change_failure','permission_updated'
    ) NOT NULL;
