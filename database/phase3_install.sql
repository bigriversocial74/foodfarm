SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS household_invitations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    age_group ENUM('adult','teen','child','guest') NOT NULL DEFAULT 'adult',
    role ENUM('administrator','adult_member','youth_member','guest_helper') NOT NULL DEFAULT 'adult_member',
    token_hash CHAR(64) NOT NULL UNIQUE,
    permission_overrides JSON NULL,
    invited_by_member_id BIGINT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invitation_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_invitation_inviter FOREIGN KEY (invited_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    INDEX idx_invitation_household_status (household_id, accepted_at, revoked_at),
    INDEX idx_invitation_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS authentication_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    event_type ENUM('login_success','login_failure','logout','invitation_created','invitation_accepted','invitation_revoked','password_changed','permission_updated') NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    metadata JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auth_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_auth_event_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    INDEX idx_auth_event_household_time (household_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE users
SET password_hash = '$2y$12$y9TwQhEAzPO3UyDcZw1M5eo5hEKVAz9/XJWi9GqF5fXi47k90sy9K'
WHERE id = 1 AND (password_hash = '' OR password_hash = 'phase2-placeholder');

UPDATE household_members
SET user_id = 1
WHERE id = 1 AND user_id IS NULL;
