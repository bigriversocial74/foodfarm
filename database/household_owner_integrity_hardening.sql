SET NAMES utf8mb4;
SET time_zone = '+00:00';

DROP TRIGGER IF EXISTS trg_household_owner_insert;
DROP TRIGGER IF EXISTS trg_household_owner_update;
DROP TRIGGER IF EXISTS trg_household_owner_delete;

DELIMITER $$
CREATE TRIGGER trg_household_owner_insert
BEFORE INSERT ON household_members
FOR EACH ROW
BEGIN
    IF NEW.role = 'owner' AND EXISTS (
        SELECT 1 FROM household_members
        WHERE household_id = NEW.household_id AND role = 'owner'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A household can have only one owner.';
    END IF;
END$$

CREATE TRIGGER trg_household_owner_update
BEFORE UPDATE ON household_members
FOR EACH ROW
BEGIN
    IF OLD.role = 'owner' AND (
        NEW.role <> 'owner' OR NEW.status <> 'active' OR NEW.household_id <> OLD.household_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The household owner cannot be demoted, disabled, or moved.';
    END IF;
    IF OLD.role <> 'owner' AND NEW.role = 'owner' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Household ownership cannot be assigned through a member update.';
    END IF;
END$$

CREATE TRIGGER trg_household_owner_delete
BEFORE DELETE ON household_members
FOR EACH ROW
BEGIN
    IF OLD.role = 'owner' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The household owner cannot be deleted.';
    END IF;
END$$
DELIMITER ;
