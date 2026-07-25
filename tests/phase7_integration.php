<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/PlanningAutomationService.php';

use Homestead\PlanningAutomationService;

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
$service = new PlanningAutomationService($pdo);
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
    $planDate = (new DateTimeImmutable('+3 days'))->format('Y-m-d');

    $pdo->prepare(
        "INSERT INTO household_members
         (household_id, display_name, age_group, role, status, joined_at)
         VALUES (?, ?, 'adult', 'adult_member', 'active', CURDATE())"
    )->execute([$householdId, 'Phase 7 Helper ' . $suffix]);
    $helperMemberId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO households (name, slug, timezone) VALUES (?, ?, ?)')
        ->execute(['Phase 7 Isolation ' . $suffix, 'phase7-isolation-' . $suffix, 'America/Phoenix']);
    $otherHouseholdId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO household_members
         (household_id, display_name, age_group, role, status, joined_at)
         VALUES (?, 'Phase 7 Other', 'adult', 'owner', 'active', CURDATE())"
    )->execute([$otherHouseholdId]);
    $otherMemberId = (int)$pdo->lastInsertId();

    $category = $scalar(
        "SELECT id FROM inventory_categories
         WHERE category_type = 'food' AND (household_id IS NULL OR household_id = ?)
         ORDER BY household_id DESC LIMIT 1",
        [$householdId]
    );
    $pdo->prepare(
        "INSERT INTO inventory_items
         (household_id, category_id, name, item_type, current_quantity, unit,
          reorder_level, target_stock_level, status)
         VALUES (?, ?, ?, 'ingredient', 0.5, 'lb', 1, 4, 'active')"
    )->execute([$householdId, $category !== false ? (int)$category : null, 'Phase 7 Flour ' . $suffix]);
    $inventoryId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO recipes (household_id, name, servings, status, created_by_member_id)
         VALUES (?, ?, 4, 'active', ?)"
    )->execute([$householdId, 'Phase 7 Supper ' . $suffix, $memberId]);
    $recipeId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO meal_plans (household_id, name, starts_on, ends_on, status)
         VALUES (?, ?, ?, ?, 'active')"
    )->execute([$householdId, 'Phase 7 Plan ' . $suffix, $planDate, $planDate]);
    $mealPlanId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO meal_plan_items
         (meal_plan_id, recipe_id, meal_date, meal_type, planned_servings)
         VALUES (?, ?, ?, 'dinner', 4)"
    )->execute([$mealPlanId, $recipeId, $planDate]);

    $pdo->prepare("INSERT INTO garden_zones (household_id, name, zone_type, active) VALUES (?, ?, 'raised_bed', 1)")
        ->execute([$householdId, 'Phase 7 Garden ' . $suffix]);
    $zoneId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO plantings
         (garden_zone_id, crop_name, planted_on, expected_harvest_start, expected_harvest_end, growth_stage)
         VALUES (?, ?, DATE_SUB(?, INTERVAL 30 DAY), ?, DATE_ADD(?, INTERVAL 5 DAY), 'harvest_ready')"
    )->execute([$zoneId, 'Phase 7 Tomatoes ' . $suffix, $planDate, $planDate, $planDate]);

    $pdo->prepare(
        "INSERT INTO preservation_batches
         (household_id, name, method, status, started_by_member_id)
         VALUES (?, ?, 'dehydrating', 'planned', ?)"
    )->execute([$householdId, 'Phase 7 Dried Herbs ' . $suffix, $memberId]);

    $templateId = $service->createRecurringTemplate($householdId, $memberId, [
        'title' => 'Phase 7 Clean pantry shelf ' . $suffix,
        'assigned_member_id' => $memberId,
        'cadence' => 'daily',
        'starts_on' => $planDate,
        'due_time' => '08:30',
        'priority' => 'medium',
        'estimated_minutes' => 15,
    ]);

    $cycle = $service->runPlanningCycle($householdId, $memberId, $planDate, bin2hex(random_bytes(32)));
    $assert($cycle['reused'] === false, 'The first planning cycle should be new.');
    $assert($cycle['tasks'] >= 5, 'The planning cycle should generate all core task sources.');
    $assert($cycle['suggestions'] === 1, 'The planning cycle should generate one shopping suggestion.');
    $cycleId = (int)$cycle['cycle_id'];

    $sourceQuery = $pdo->prepare('SELECT DISTINCT source_type FROM task_automation_metadata WHERE planning_cycle_id = ?');
    $sourceQuery->execute([$cycleId]);
    $sources = array_column($sourceQuery->fetchAll(), 'source_type');
    foreach (['recurring', 'low_stock', 'meal', 'harvest', 'preservation'] as $source) {
        $assert(in_array($source, $sources, true), 'Missing generated source type: ' . $source . '.');
    }
    $taskCount = (int)$scalar('SELECT COUNT(*) FROM task_automation_metadata WHERE planning_cycle_id = ?', [$cycleId]);
    $suggestionCount = (int)$scalar('SELECT COUNT(*) FROM planning_suggestions WHERE planning_cycle_id = ?', [$cycleId]);
    $again = $service->runPlanningCycle($householdId, $memberId, $planDate, bin2hex(random_bytes(32)));
    $assert($again['reused'] === true && (int)$again['cycle_id'] === $cycleId, 'Same-date planning must reuse its cycle.');
    $assert((int)$scalar('SELECT COUNT(*) FROM task_automation_metadata WHERE planning_cycle_id = ?', [$cycleId]) === $taskCount, 'Reused cycles must not duplicate tasks.');
    $assert((int)$scalar('SELECT COUNT(*) FROM planning_suggestions WHERE planning_cycle_id = ?', [$cycleId]) === $suggestionCount, 'Reused cycles must not duplicate suggestions.');

    $actionKey = bin2hex(random_bytes(32));
    $manualData = [
        'title' => 'Phase 7 Manual task ' . $suffix,
        'assigned_member_id' => $memberId,
        'due_at' => (new DateTimeImmutable('+4 days 10:00'))->format('Y-m-d H:i:s'),
        'priority' => 'high',
        'estimated_minutes' => 25,
        'action_key' => $actionKey,
    ];
    $manualTaskId = $service->createManualTask($householdId, $memberId, $manualData);
    $assert($service->createManualTask($householdId, $memberId, $manualData) === $manualTaskId, 'Manual task creation should be idempotent.');
    $expectFailure(
        fn() => $service->createManualTask($householdId, $memberId, [
            'title' => 'Phase 7 Invalid assignment ' . $suffix,
            'assigned_member_id' => $otherMemberId,
            'action_key' => bin2hex(random_bytes(32)),
        ]),
        'Cross-household manual assignments must be rejected.'
    );
    $expectFailure(
        fn() => $service->startTask($householdId, $helperMemberId, $manualTaskId, false),
        'A non-manager must not act on another member\'s task.'
    );
    $service->startTask($householdId, $memberId, $manualTaskId, false);
    $service->completeTask($householdId, $memberId, $manualTaskId, 'Integration complete.', false);
    $assert((string)$scalar('SELECT status FROM household_tasks WHERE id = ?', [$manualTaskId]) === 'completed', 'Assigned member should complete a task.');

    $workflowTaskId = $service->createManualTask($householdId, $memberId, [
        'title' => 'Phase 7 Workflow task ' . $suffix,
        'due_at' => (new DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s'),
        'priority' => 'medium',
        'action_key' => bin2hex(random_bytes(32)),
    ]);
    $snoozeUntil = (new DateTimeImmutable('+5 days 13:15'))->format('Y-m-d H:i:s');
    $service->snoozeTask($householdId, $helperMemberId, $workflowTaskId, $snoozeUntil, false);
    $assert((string)$scalar('SELECT snoozed_until FROM task_automation_metadata WHERE household_task_id = ?', [$workflowTaskId]) === $snoozeUntil, 'Snooze metadata should be recorded.');
    $service->cancelTask($householdId, $memberId, $workflowTaskId, 'Manager cancellation test.');
    $service->reopenTask($householdId, $memberId, $workflowTaskId);
    $assert((string)$scalar('SELECT status FROM household_tasks WHERE id = ?', [$workflowTaskId]) === 'ready', 'Managers should reopen cancelled tasks.');

    $suggestionId = (int)$scalar("SELECT id FROM planning_suggestions WHERE planning_cycle_id = ? AND status = 'pending' LIMIT 1", [$cycleId]);
    $shoppingItemId = $service->acceptShoppingSuggestion($householdId, $memberId, $suggestionId);
    $assert((int)$scalar('SELECT inventory_item_id FROM shopping_list_items WHERE id = ?', [$shoppingItemId]) === $inventoryId, 'Accepted shopping suggestions should preserve inventory provenance.');
    $expectFailure(fn() => $service->acceptShoppingSuggestion($householdId, $memberId, $suggestionId), 'Shopping suggestions must be single-use.');

    $service->toggleRecurringTemplate($householdId, $memberId, $templateId);
    $assert((int)$scalar('SELECT enabled FROM recurring_task_templates WHERE id = ?', [$templateId]) === 0, 'Template toggle should disable a template.');
    $service->toggleRecurringTemplate($householdId, $memberId, $templateId);

    $helperDashboard = $service->dashboardData($householdId, $helperMemberId, false);
    $visibleTaskIds = array_map(static fn(array $task): int => (int)$task['id'], $helperDashboard['tasks']);
    $assert(!in_array($manualTaskId, $visibleTaskIds, true), 'Non-managers must not see another member\'s assigned task.');
    $assert(in_array($workflowTaskId, $visibleTaskIds, true), 'Non-managers should see unassigned household tasks.');

    $assert((int)$scalar(
        'SELECT COUNT(*) FROM task_lifecycle_events tle LEFT JOIN household_tasks ht ON ht.id = tle.household_task_id AND ht.household_id = tle.household_id WHERE ht.id IS NULL'
    ) === 0, 'Task lifecycle events must not be orphaned.');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected integration exception: ' . $exception->getMessage();
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 7 integration audit passed.\n";
