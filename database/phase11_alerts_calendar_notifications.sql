SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS household_notification_settings (
    household_id BIGINT UNSIGNED PRIMARY KEY,
    due_soon_days SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    forecast_alert_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    prepared_food_alert_days SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    digest_cadence ENUM('off','daily','weekly') NOT NULL DEFAULT 'daily',
    digest_hour TINYINT UNSIGNED NOT NULL DEFAULT 7,
    quiet_start TIME NULL,
    quiet_end TIME NULL,
    email_adapter_enabled TINYINT(1) NOT NULL DEFAULT 0,
    web_push_adapter_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_member_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_settings_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_settings_member FOREIGN KEY (updated_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_notification_preferences (
    household_id BIGINT UNSIGNED NOT NULL,
    household_member_id BIGINT UNSIGNED NOT NULL,
    minimum_priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
    enabled_categories JSON NULL,
    digest_cadence ENUM('inherit','off','daily','weekly') NOT NULL DEFAULT 'inherit',
    email_enabled TINYINT(1) NOT NULL DEFAULT 0,
    web_push_enabled TINYINT(1) NOT NULL DEFAULT 0,
    allow_sensitive_previews TINYINT(1) NOT NULL DEFAULT 0,
    quiet_start TIME NULL,
    quiet_end TIME NULL,
    updated_by_member_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (household_id, household_member_id),
    CONSTRAINT fk_member_notification_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_notification_member FOREIGN KEY (household_member_id) REFERENCES household_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_notification_actor FOREIGN KEY (updated_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_sync_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    run_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_watermark CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    as_of_date DATE NOT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    notification_count INT UNSIGNED NOT NULL DEFAULT 0,
    calendar_event_count INT UNSIGNED NOT NULL DEFAULT 0,
    expired_count INT UNSIGNED NOT NULL DEFAULT 0,
    generated_by_member_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT fk_notification_sync_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_sync_member FOREIGN KEY (generated_by_member_id) REFERENCES household_members(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_notification_sync_run (household_id, run_key),
    INDEX idx_notification_sync_status (household_id, status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS household_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipient_member_id BIGINT UNSIGNED NULL,
    recipient_scope_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_type VARCHAR(80) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    dedup_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    category ENUM('task','inventory','prepared_food','forecast','garden','preservation','finance','nutrition','meal','system') NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    visibility ENUM('household','adults_only','private') NOT NULL DEFAULT 'household',
    status ENUM('unread','acknowledged','completed','dismissed','expired') NOT NULL DEFAULT 'unread',
    sensitive_preview TINYINT(1) NOT NULL DEFAULT 0,
    occurs_at DATETIME NULL,
    due_at DATETIME NULL,
    expires_at DATETIME NULL,
    related_task_id BIGINT UNSIGNED NULL,
    last_seen_sync_run_id BIGINT UNSIGNED NULL,
    acted_by_member_id BIGINT UNSIGNED NULL,
    acted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_household_notification_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_household_notification_recipient FOREIGN KEY (recipient_member_id) REFERENCES household_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_household_notification_task FOREIGN KEY (related_task_id) REFERENCES household_tasks(id) ON DELETE SET NULL,
    CONSTRAINT fk_household_notification_sync FOREIGN KEY (last_seen_sync_run_id) REFERENCES notification_sync_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_household_notification_actor FOREIGN KEY (acted_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    UNIQUE KEY uq_household_notification_dedup (household_id, recipient_scope_key, dedup_key),
    INDEX idx_household_notification_inbox (household_id, recipient_scope_key, status, priority, due_at),
    INDEX idx_household_notification_source (household_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS household_calendar_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    notification_id BIGINT UNSIGNED NULL,
    recipient_member_id BIGINT UNSIGNED NULL,
    source_type VARCHAR(80) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    visibility ENUM('household','adults_only','private') NOT NULL DEFAULT 'household',
    status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_by_member_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_household_calendar_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_household_calendar_notification FOREIGN KEY (notification_id) REFERENCES household_notifications(id) ON DELETE SET NULL,
    CONSTRAINT fk_household_calendar_recipient FOREIGN KEY (recipient_member_id) REFERENCES household_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_household_calendar_creator FOREIGN KEY (created_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    UNIQUE KEY uq_household_calendar_event (household_id, event_key),
    INDEX idx_household_calendar_window (household_id, starts_at, status),
    INDEX idx_household_calendar_recipient (household_id, recipient_member_id, starts_at),
    INDEX idx_household_calendar_source (household_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_delivery_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    notification_id BIGINT UNSIGNED NOT NULL,
    recipient_member_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('email','web_push') NOT NULL,
    status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
    payload_json JSON NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_outbox_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_outbox_notification FOREIGN KEY (notification_id) REFERENCES household_notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_outbox_recipient FOREIGN KEY (recipient_member_id) REFERENCES household_members(id) ON DELETE CASCADE,
    UNIQUE KEY uq_notification_outbox_delivery (notification_id, recipient_member_id, channel),
    INDEX idx_notification_outbox_ready (status, available_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_delivery_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    outbox_id BIGINT UNSIGNED NOT NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    status ENUM('started','sent','failed','cancelled') NOT NULL,
    error_code VARCHAR(80) NULL,
    details VARCHAR(500) NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_attempt_outbox FOREIGN KEY (outbox_id) REFERENCES notification_delivery_outbox(id) ON DELETE CASCADE,
    UNIQUE KEY uq_notification_attempt (outbox_id, attempt_number),
    INDEX idx_notification_attempt_time (outbox_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_digest_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    recipient_member_id BIGINT UNSIGNED NOT NULL,
    cadence ENUM('daily','weekly') NOT NULL,
    period_start DATETIME NOT NULL,
    period_end DATETIME NOT NULL,
    run_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('ready','queued','sent','cancelled') NOT NULL DEFAULT 'ready',
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_member_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    CONSTRAINT fk_notification_digest_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_digest_recipient FOREIGN KEY (recipient_member_id) REFERENCES household_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_digest_creator FOREIGN KEY (created_by_member_id) REFERENCES household_members(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_notification_digest_run (household_id, run_key),
    INDEX idx_notification_digest_recipient (household_id, recipient_member_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_digest_items (
    digest_run_id BIGINT UNSIGNED NOT NULL,
    notification_id BIGINT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (digest_run_id, notification_id),
    CONSTRAINT fk_notification_digest_item_run FOREIGN KEY (digest_run_id) REFERENCES notification_digest_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_digest_item_notification FOREIGN KEY (notification_id) REFERENCES household_notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_lifecycle_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    notification_id BIGINT UNSIGNED NULL,
    calendar_event_id BIGINT UNSIGNED NULL,
    digest_run_id BIGINT UNSIGNED NULL,
    member_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(60) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    notes TEXT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_event_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_event_notification FOREIGN KEY (notification_id) REFERENCES household_notifications(id) ON DELETE SET NULL,
    CONSTRAINT fk_notification_event_calendar FOREIGN KEY (calendar_event_id) REFERENCES household_calendar_events(id) ON DELETE SET NULL,
    CONSTRAINT fk_notification_event_digest FOREIGN KEY (digest_run_id) REFERENCES notification_digest_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_notification_event_member FOREIGN KEY (member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    INDEX idx_notification_event_household_time (household_id, occurred_at, id),
    INDEX idx_notification_event_notification (notification_id, occurred_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
