<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'service' => $root . '/app/NotificationService.php',
    'settings' => $root . '/app/NotificationSettingsTrait.php',
    'sync' => $root . '/app/NotificationSyncTrait.php',
    'lifecycle' => $root . '/app/NotificationLifecycleTrait.php',
    'query' => $root . '/app/NotificationQueryTrait.php',
    'support' => $root . '/app/NotificationSupportTrait.php',
    'page' => $root . '/phase11.php',
    'calendar' => $root . '/phase11-calendar.php',
    'health' => $root . '/api/phase11-health.php',
    'migration' => $root . '/database/phase11_alerts_calendar_notifications.sql',
    'workflow' => $root . '/.github/workflows/phase11-certification.yml',
];
$failures = [];
foreach ($files as $label => $path) {
    if (!is_file($path)) {
        $failures[] = "Missing Phase 11 {$label} file: {$path}";
    }
}

if ($failures === []) {
    $service = file_get_contents($files['service']);
    $settings = file_get_contents($files['settings']);
    $sync = file_get_contents($files['sync']);
    $lifecycle = file_get_contents($files['lifecycle']);
    $support = file_get_contents($files['support']);
    $page = file_get_contents($files['page']);
    $calendar = file_get_contents($files['calendar']);
    $health = file_get_contents($files['health']);
    $migration = file_get_contents($files['migration']);
    $workflow = file_get_contents($files['workflow']);

    foreach ([
        'final class NotificationService',
        'NotificationSettingsTrait',
        'NotificationSyncTrait',
        'NotificationLifecycleTrait',
        'NotificationQueryTrait',
        'NotificationSupportTrait',
    ] as $needle) {
        if (!str_contains($service, $needle)) {
            $failures[] = 'Notification service missing ' . $needle;
        }
    }

    foreach ([
        'saveSettings',
        'saveMemberPreferences',
        'email_adapter_enabled',
        'allow_sensitive_previews',
        'enabled_categories',
    ] as $needle) {
        if (!str_contains($settings, $needle)) {
            $failures[] = 'Notification settings layer missing ' . $needle;
        }
    }

    foreach ([
        'runSync',
        'sourceWatermark',
        'canAccessVisibility',
        'lockHousehold',
        'upsertNotification',
        'upsertCalendarEvent',
        'queueDeliveryCandidates',
        'last_seen_sync_run_id',
        'status = IF(status = "expired", "unread", status)',
        'household_id = ?',
        'recipient_member_id',
    ] as $needle) {
        if (!str_contains($sync . $support, $needle)) {
            $failures[] = 'Notification sync missing required control: ' . $needle;
        }
    }

    foreach ([
        'transitionNotification',
        'createTaskFromNotification',
        'generateDigest',
        'assertNotificationAccess',
        'WHERE id = ? AND household_id = ? FOR UPDATE',
        'task_automation_metadata',
        'household_notification',
    ] as $needle) {
        if (!str_contains($lifecycle, $needle)) {
            $failures[] = 'Notification lifecycle missing required control: ' . $needle;
        }
    }

    foreach ([
        'verify_csrf',
        'phase11_action_key',
        'hash_equals',
        'notifications.view',
        'notifications.manage',
        'run_sync',
        'create_task',
        'generate_digest',
        'In-app first, adapter-ready outside the app',
    ] as $needle) {
        if (!str_contains($page, $needle)) {
            $failures[] = 'Phase 11 page missing required control: ' . $needle;
        }
    }

    foreach ([
        'text/calendar',
        'VCALENDAR',
        'calendarEvents',
        'notifications.view',
    ] as $needle) {
        if (!str_contains($calendar, $needle)) {
            $failures[] = 'Phase 11 calendar export missing ' . $needle;
        }
    }

    $requiredTables = [
        'household_notification_settings',
        'member_notification_preferences',
        'notification_sync_runs',
        'household_notifications',
        'household_calendar_events',
        'notification_delivery_outbox',
        'notification_delivery_attempts',
        'notification_digest_runs',
        'notification_digest_items',
        'notification_lifecycle_events',
    ];
    foreach ($requiredTables as $table) {
        if (!str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table)) {
            $failures[] = 'Phase 11 migration missing replay-safe table ' . $table;
        }
        if (!str_contains($health, "'{$table}'")) {
            $failures[] = 'Phase 11 health endpoint does not validate ' . $table;
        }
    }

    foreach ([
        'uq_household_notification_dedup',
        'uq_household_calendar_event',
        'fk_household_calendar_recipient',
        'idx_household_calendar_recipient',
        'uq_notification_outbox_delivery',
        'uq_notification_digest_run',
    ] as $index) {
        if (!str_contains($migration, $index)) {
            $failures[] = 'Phase 11 migration missing uniqueness control ' . $index;
        }
    }

    foreach ([
        'mysql:8.0',
        'mariadb:10.11',
        'phase11_alerts_calendar_notifications.sql',
        'phase11_integration.php',
        'phase11_http_smoke.sh',
    ] as $needle) {
        if (!str_contains($workflow, $needle)) {
            $failures[] = 'Phase 11 workflow missing ' . $needle;
        }
    }

    foreach ([
        'notification_recipient_households',
        'notification_scope_keys',
        'calendar_recipient_households',
        'private_calendar_recipients',
        'outbox_notification_households',
        'digest_item_households',
        'stale_sync_runs',
        'stale_outbox_locks',
    ] as $needle) {
        if (!str_contains($health, $needle)) {
            $failures[] = 'Phase 11 health diagnostics missing ' . $needle;
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 11 static audit passed.\n";
