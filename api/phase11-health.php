<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

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
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $tableQuery = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
    );
    $tableQuery->execute($requiredTables);
    $present = array_map('strval', $tableQuery->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($requiredTables, $present));
    if ($missing !== []) {
        throw new RuntimeException('Phase 11 database objects are incomplete.');
    }

    $checks = [
        'notification_recipient_households' => "SELECT COUNT(*)
            FROM household_notifications hn
            LEFT JOIN household_members hm
              ON hm.id = hn.recipient_member_id AND hm.household_id = hn.household_id
            WHERE hn.recipient_member_id IS NOT NULL AND hm.id IS NULL",
        'notification_scope_keys' => "SELECT COUNT(*) FROM household_notifications
            WHERE (recipient_member_id IS NULL AND recipient_scope_key <> 0)
               OR (recipient_member_id IS NOT NULL AND recipient_scope_key <> recipient_member_id)",
        'calendar_notification_households' => "SELECT COUNT(*)
            FROM household_calendar_events hce
            JOIN household_notifications hn ON hn.id = hce.notification_id
            WHERE hce.notification_id IS NOT NULL AND hn.household_id <> hce.household_id",
        'calendar_recipient_households' => "SELECT COUNT(*)
            FROM household_calendar_events hce
            LEFT JOIN household_members hm
              ON hm.id = hce.recipient_member_id AND hm.household_id = hce.household_id
            WHERE hce.recipient_member_id IS NOT NULL AND hm.id IS NULL",
        'private_calendar_recipients' => "SELECT COUNT(*) FROM household_calendar_events
            WHERE visibility = 'private' AND recipient_member_id IS NULL",
        'outbox_notification_households' => "SELECT COUNT(*)
            FROM notification_delivery_outbox ndo
            JOIN household_notifications hn ON hn.id = ndo.notification_id
            JOIN household_members hm ON hm.id = ndo.recipient_member_id
            WHERE hn.household_id <> ndo.household_id
               OR hm.household_id <> ndo.household_id",
        'digest_recipient_households' => "SELECT COUNT(*)
            FROM notification_digest_runs ndr
            JOIN household_members hm ON hm.id = ndr.recipient_member_id
            WHERE hm.household_id <> ndr.household_id",
        'digest_item_households' => "SELECT COUNT(*)
            FROM notification_digest_items ndi
            JOIN notification_digest_runs ndr ON ndr.id = ndi.digest_run_id
            JOIN household_notifications hn ON hn.id = ndi.notification_id
            WHERE hn.household_id <> ndr.household_id",
        'linked_task_households' => "SELECT COUNT(*)
            FROM household_notifications hn
            JOIN household_tasks ht ON ht.id = hn.related_task_id
            WHERE hn.related_task_id IS NOT NULL AND ht.household_id <> hn.household_id",
        'stale_sync_runs' => "SELECT COUNT(*) FROM notification_sync_runs
            WHERE status = 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)",
        'stale_outbox_locks' => "SELECT COUNT(*) FROM notification_delivery_outbox
            WHERE status = 'processing' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)",
    ];

    $results = [];
    foreach ($checks as $name => $sql) {
        $count = (int)$pdo->query($sql)->fetchColumn();
        $results[$name] = $count;
        if ($count !== 0) {
            throw new RuntimeException('Phase 11 relational or lifecycle integrity check failed.');
        }
    }

    $counts = [];
    foreach ($requiredTables as $table) {
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    echo json_encode([
        'ok' => true,
        'phase' => 11,
        'database' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'tables' => $counts,
        'checks' => $results,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    Homestead\health_error($exception, is_array($config ?? null) ? $config : []);
}
