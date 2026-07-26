<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/NotificationService.php';

use Homestead\NotificationService;

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: 'homestead'
    ),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$service = new NotificationService($pdo);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$expectFailure = static function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (Throwable) {
        // Expected.
    }
};
$scalar = static function (string $sql, array $params = []) use ($pdo): mixed {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
};

try {
    $ownerQuery = $pdo->prepare(
        "SELECT hm.id AS member_id, hm.household_id
         FROM users u JOIN household_members hm ON hm.user_id = u.id
         WHERE LOWER(u.email) = LOWER(?) AND hm.role = 'owner' AND hm.status = 'active' LIMIT 1"
    );
    $ownerQuery->execute([getenv('HOMESTEAD_OWNER_EMAIL') ?: 'owner@example.test']);
    $owner = $ownerQuery->fetch();
    if (!is_array($owner)) {
        throw new RuntimeException('The CI owner account was not found.');
    }
    $householdId = (int)$owner['household_id'];
    $memberId = (int)$owner['member_id'];
    $suffix = bin2hex(random_bytes(5));
    $today = new DateTimeImmutable('today');

    $pdo->prepare('INSERT INTO households (name, slug, timezone) VALUES (?, ?, ?)')
        ->execute(['Phase 11 Isolation ' . $suffix, 'phase11-isolation-' . $suffix, 'America/Phoenix']);
    $otherHouseholdId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO household_members
         (household_id, display_name, age_group, role, status, joined_at)
         VALUES (?, 'Phase 11 Other', 'adult', 'owner', 'active', CURDATE())"
    )->execute([$otherHouseholdId]);
    $otherMemberId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO household_members
         (household_id, display_name, age_group, role, status, joined_at)
         VALUES (?, ?, 'teen', 'youth_member', 'active', CURDATE())"
    )->execute([$householdId, 'Phase 11 Youth ' . $suffix]);
    $youthMemberId = (int)$pdo->lastInsertId();

    $service->saveSettings($householdId, $memberId, [
        'due_soon_days' => 5,
        'forecast_alert_days' => 14,
        'prepared_food_alert_days' => 3,
        'digest_cadence' => 'daily',
        'digest_hour' => 7,
        'quiet_start' => '21:00',
        'quiet_end' => '07:00',
    ]);
    $settings = $service->settings($householdId);
    $assert((int)$settings['due_soon_days'] === 5, 'Notification settings should persist.');
    $expectFailure(
        fn() => $service->saveSettings($householdId, $otherMemberId, [
            'due_soon_days' => 5,
            'forecast_alert_days' => 14,
            'prepared_food_alert_days' => 3,
            'digest_cadence' => 'daily',
            'digest_hour' => 7,
        ]),
        'Cross-household members must not change notification settings.'
    );

    $service->saveMemberPreferences($householdId, $memberId, [
        'household_member_id' => $memberId,
        'minimum_priority' => 'low',
        'digest_cadence' => 'inherit',
        'enabled_categories' => [
            'task', 'inventory', 'prepared_food', 'forecast', 'garden',
            'preservation', 'finance', 'nutrition', 'meal', 'system',
        ],
        'email_enabled' => 0,
        'web_push_enabled' => 0,
        'allow_sensitive_previews' => 0,
    ]);
    $assert((int)$scalar(
        'SELECT COUNT(*) FROM member_notification_preferences
         WHERE household_id = ? AND household_member_id = ?',
        [$householdId, $memberId]
    ) === 1, 'Member notification preferences should persist once.');

    $pdo->prepare(
        "INSERT INTO household_tasks
         (household_id, assigned_member_id, title, description, due_at, priority, status)
         VALUES (?, ?, ?, 'Integration task', ?, 'high', 'ready')"
    )->execute([
        $householdId,
        $memberId,
        'Phase 11 task ' . $suffix,
        $today->modify('+1 day')->format('Y-m-d 09:00:00'),
    ]);
    $taskSourceId = (int)$pdo->lastInsertId();

    $categoryId = $scalar(
        "SELECT id FROM inventory_categories
         WHERE category_type = 'food' AND (household_id IS NULL OR household_id = ?)
         ORDER BY household_id DESC LIMIT 1",
        [$householdId]
    );
    $pdo->prepare(
        "INSERT INTO inventory_items
         (household_id, category_id, name, item_type, current_quantity, unit, reorder_level, status)
         VALUES (?, ?, ?, 'ingredient', 0, 'each', 2, 'active')"
    )->execute([
        $householdId,
        $categoryId !== false ? (int)$categoryId : null,
        'Phase 11 low stock ' . $suffix,
    ]);
    $itemId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO prepared_food_batches
         (household_id, name, servings_produced, servings_remaining, prepared_at,
          use_by_date, storage_method, status)
         VALUES (?, ?, 4, 3, UTC_TIMESTAMP(), ?, 'refrigerated', 'active')"
    )->execute([
        $householdId,
        'Phase 11 prepared food ' . $suffix,
        $today->modify('+1 day')->format('Y-m-d'),
    ]);

    $pdo->prepare(
        "INSERT INTO meal_plans (household_id, name, starts_on, ends_on, status)
         VALUES (?, ?, ?, ?, 'active')"
    )->execute([
        $householdId,
        'Phase 11 plan ' . $suffix,
        $today->format('Y-m-d'),
        $today->modify('+7 days')->format('Y-m-d'),
    ]);
    $mealPlanId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO meal_plan_items
         (meal_plan_id, recipe_id, meal_date, meal_type, planned_servings, notes, status)
         VALUES (?, NULL, ?, 'dinner', 2, 'Integration meal', 'planned')"
    )->execute([$mealPlanId, $today->modify('+1 day')->format('Y-m-d')]);

    $first = $service->runSync($householdId, $memberId, $today->format('Y-m-d'));
    $assert($first['reused'] === false, 'First Phase 11 sync should be new.');
    $assert((int)$first['notification_count'] >= 4, 'Phase 11 sync should create task, inventory, prepared-food, and meal notifications.');
    $assert((int)$first['calendar_event_count'] >= 3, 'Phase 11 sync should create task, prepared-food, and meal calendar events.');

    $second = $service->runSync($householdId, $memberId, $today->format('Y-m-d'));
    $assert($second['reused'] === true, 'Unchanged Phase 11 sync should be reused.');
    $assert((int)$scalar(
        'SELECT COUNT(*) FROM household_notifications
         WHERE household_id = ? AND source_type = "household_task" AND source_id = ?',
        [$householdId, $taskSourceId]
    ) === 1, 'Task alert should be deduplicated.');

    $privateRecipient = (int)$scalar(
        'SELECT recipient_member_id FROM household_notifications
         WHERE household_id = ? AND source_type = "household_task" AND source_id = ?',
        [$householdId, $taskSourceId]
    );
    $assert($privateRecipient === $memberId, 'Assigned task notification should remain private to its assignee.');

    $ownerEvents = $service->calendarEvents(
        $householdId,
        $memberId,
        $today->format('Y-m-d'),
        $today->modify('+30 days')->format('Y-m-d')
    );
    $youthEvents = $service->calendarEvents(
        $householdId,
        $youthMemberId,
        $today->format('Y-m-d'),
        $today->modify('+30 days')->format('Y-m-d')
    );
    $ownerTaskCalendarIds = array_values(array_filter(
        array_map(static fn(array $event): int => (string)$event['source_type'] === 'household_task' ? (int)$event['source_id'] : 0, $ownerEvents)
    ));
    $youthTaskCalendarIds = array_values(array_filter(
        array_map(static fn(array $event): int => (string)$event['source_type'] === 'household_task' ? (int)$event['source_id'] : 0, $youthEvents)
    ));
    $assert(in_array($taskSourceId, $ownerTaskCalendarIds, true), 'Task assignee should see the private task calendar event.');
    $assert(!in_array($taskSourceId, $youthTaskCalendarIds, true), 'Other members must not see a private task calendar event.');

    $privateTaskNotificationId = (int)$scalar(
        'SELECT id FROM household_notifications
         WHERE household_id = ? AND source_type = "household_task" AND source_id = ?',
        [$householdId, $taskSourceId]
    );
    $expectFailure(
        fn() => $service->transitionNotification($householdId, $youthMemberId, $privateTaskNotificationId, 'dismissed'),
        'Other members must not mutate a private notification.'
    );

    $pdo->prepare(
        'INSERT INTO household_notifications
         (household_id, recipient_member_id, recipient_scope_key, source_type, source_id,
          dedup_key, category, title, body, priority, visibility, status)
         VALUES (?, NULL, 0, "finance_recommendation", NULL, ?, "finance", ?, ?, "high", "adults_only", "unread")'
    )->execute([
        $householdId,
        hash('sha256', 'phase11-adults-only|' . $suffix),
        'Phase 11 adults-only finance alert ' . $suffix,
        'Private financial planning details.',
    ]);
    $adultsOnlyNotificationId = (int)$pdo->lastInsertId();
    $ownerDashboardForPrivacy = $service->dashboardData($householdId, $memberId);
    $youthDashboardForPrivacy = $service->dashboardData($householdId, $youthMemberId);
    $ownerNotificationIds = array_map(static fn(array $row): int => (int)$row['id'], $ownerDashboardForPrivacy['notifications']);
    $youthNotificationIds = array_map(static fn(array $row): int => (int)$row['id'], $youthDashboardForPrivacy['notifications']);
    $assert(in_array($adultsOnlyNotificationId, $ownerNotificationIds, true), 'Adult members should see adults-only notifications.');
    $assert(!in_array($adultsOnlyNotificationId, $youthNotificationIds, true), 'Youth members must not see adults-only notifications.');
    $expectFailure(
        fn() => $service->createTaskFromNotification($householdId, $youthMemberId, $adultsOnlyNotificationId),
        'Youth members must not convert adults-only notifications into tasks.'
    );

    $lowStockNotificationId = (int)$scalar(
        'SELECT id FROM household_notifications
         WHERE household_id = ? AND source_type = "inventory_item" AND source_id = ?',
        [$householdId, $itemId]
    );
    $assert($lowStockNotificationId > 0, 'Low-stock notification should exist.');
    $linkedTaskId = $service->createTaskFromNotification($householdId, $memberId, $lowStockNotificationId);
    $assert($linkedTaskId > 0, 'Notification should convert to a household task.');
    $assert(
        $service->createTaskFromNotification($householdId, $memberId, $lowStockNotificationId) === $linkedTaskId,
        'Repeated notification task conversion should reuse the same task.'
    );
    $assert((int)$scalar(
        'SELECT COUNT(*) FROM task_automation_metadata
         WHERE household_id = ? AND household_task_id = ? AND source_type = "household_notification"',
        [$householdId, $linkedTaskId]
    ) === 1, 'Notification task provenance should be recorded once.');

    $service->transitionNotification($householdId, $memberId, $lowStockNotificationId, 'completed');
    $assert((string)$scalar(
        'SELECT status FROM household_notifications WHERE id = ?',
        [$lowStockNotificationId]
    ) === 'completed', 'Notification lifecycle should complete.');

    $digest = $service->generateDigest($householdId, $memberId, 'daily', $today->format('Y-m-d'));
    $assert($digest['reused'] === false, 'First digest should be new.');
    $assert((int)$digest['item_count'] >= 1, 'Digest should contain active household notifications.');
    $assert(
        $service->generateDigest($householdId, $memberId, 'daily', $today->format('Y-m-d'))['reused'] === true,
        'Repeated digest generation should reuse the same run.'
    );

    $events = $service->calendarEvents(
        $householdId,
        $memberId,
        $today->format('Y-m-d'),
        $today->modify('+30 days')->format('Y-m-d')
    );
    $assert(count($events) >= 3, 'Calendar export query should return generated events.');

    $expectFailure(
        fn() => $service->createTaskFromNotification($otherHouseholdId, $otherMemberId, $lowStockNotificationId),
        'Cross-household notification task conversion must be rejected.'
    );

    $service->saveSettings($householdId, $memberId, [
        'due_soon_days' => 5,
        'forecast_alert_days' => 14,
        'prepared_food_alert_days' => 3,
        'digest_cadence' => 'daily',
        'digest_hour' => 7,
        'email_adapter_enabled' => 1,
        'web_push_adapter_enabled' => 1,
    ]);
    $service->saveMemberPreferences($householdId, $memberId, [
        'household_member_id' => $memberId,
        'minimum_priority' => 'low',
        'digest_cadence' => 'inherit',
        'enabled_categories' => ['task', 'inventory', 'prepared_food', 'meal'],
        'email_enabled' => 1,
        'web_push_enabled' => 1,
        'allow_sensitive_previews' => 0,
    ]);
    $pdo->prepare(
        "INSERT INTO household_tasks
         (household_id, assigned_member_id, title, description, due_at, priority, status)
         VALUES (?, ?, ?, 'Outbox integration task', ?, 'critical', 'ready')"
    )->execute([
        $householdId,
        $memberId,
        'Phase 11 outbox task ' . $suffix,
        $today->modify('+2 days')->format('Y-m-d 09:00:00'),
    ]);
    $service->runSync($householdId, $memberId, $today->format('Y-m-d'));
    $assert((int)$scalar(
        'SELECT COUNT(*) FROM notification_delivery_outbox
         WHERE household_id = ? AND recipient_member_id = ? AND channel IN ("email","web_push")',
        [$householdId, $memberId]
    ) >= 2, 'Enabled adapters and member preferences should queue external deliveries.');

    $dashboard = $service->dashboardData($householdId, $memberId);
    $assert(count($dashboard['notifications']) >= 1, 'Phase 11 dashboard should include notifications.');
    $assert(count($dashboard['calendar_events']) >= 1, 'Phase 11 dashboard should include calendar events.');
} catch (Throwable $exception) {
    $failures[] = get_class($exception) . ': ' . $exception->getMessage();
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 11 integration suite passed.\n";
