SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS recurring_task_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    assigned_member_id BIGINT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    cadence ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
    starts_on DATE NOT NULL,
    due_time TIME NOT NULL DEFAULT '09:00:00',
    priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    estimated_minutes SMALLINT UNSIGNED NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by_member_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recurring_templates_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_recurring_templates_assignee FOREIGN KEY (assigned_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    CONSTRAINT fk_recurring_templates_creator FOREIGN KEY (created_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    INDEX idx_recurring_templates_household_enabled (household_id, enabled, starts_on),
    INDEX idx_recurring_templates_assignee (assigned_member_id, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planning_cycles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    plan_date DATE NOT NULL,
    run_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    initiated_by_member_id BIGINT UNSIGNED NOT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    generated_task_count INT UNSIGNED NOT NULL DEFAULT 0,
    generated_suggestion_count INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT fk_planning_cycles_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_planning_cycles_member FOREIGN KEY (initiated_by_member_id) REFERENCES household_members(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_planning_cycles_household_date (household_id, plan_date),
    UNIQUE KEY uq_planning_cycles_run_key (household_id, run_key),
    INDEX idx_planning_cycles_status (household_id, status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_automation_metadata (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    household_task_id BIGINT UNSIGNED NOT NULL,
    planning_cycle_id BIGINT UNSIGNED NULL,
    recurring_template_id BIGINT UNSIGNED NULL,
    source_type VARCHAR(80) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    generation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    estimated_minutes SMALLINT UNSIGNED NULL,
    snoozed_until DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_metadata_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_metadata_task FOREIGN KEY (household_task_id) REFERENCES household_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_metadata_cycle FOREIGN KEY (planning_cycle_id) REFERENCES planning_cycles(id) ON DELETE SET NULL,
    CONSTRAINT fk_task_metadata_template FOREIGN KEY (recurring_template_id) REFERENCES recurring_task_templates(id) ON DELETE SET NULL,
    UNIQUE KEY uq_task_meta_task (household_id, household_task_id),
    UNIQUE KEY uq_task_meta_generation (household_id, generation_key),
    INDEX idx_task_meta_cycle (planning_cycle_id),
    INDEX idx_task_meta_source (household_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planning_suggestions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    planning_cycle_id BIGINT UNSIGNED NOT NULL,
    suggestion_type VARCHAR(60) NOT NULL,
    source_type VARCHAR(80) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    generation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(180) NOT NULL,
    rationale TEXT NULL,
    recommended_quantity DECIMAL(14,4) NULL,
    unit VARCHAR(30) NULL,
    priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('pending','accepted','dismissed','expired') NOT NULL DEFAULT 'pending',
    acted_by_member_id BIGINT UNSIGNED NULL,
    acted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_planning_suggestions_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_planning_suggestions_cycle FOREIGN KEY (planning_cycle_id) REFERENCES planning_cycles(id) ON DELETE CASCADE,
    CONSTRAINT fk_planning_suggestions_actor FOREIGN KEY (acted_by_member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    UNIQUE KEY uq_planning_suggestions_generation (household_id, generation_key),
    INDEX idx_planning_suggestions_status (household_id, status, priority, created_at),
    INDEX idx_planning_suggestions_source (household_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_lifecycle_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    household_task_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(40) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    notes TEXT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_events_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_events_task FOREIGN KEY (household_task_id) REFERENCES household_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_events_member FOREIGN KEY (member_id) REFERENCES household_members(id) ON DELETE SET NULL,
    INDEX idx_task_events_task_time (household_task_id, occurred_at, id),
    INDEX idx_task_events_household_time (household_id, occurred_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
