<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/ForecastingService.php';

use Homestead\ForecastingService;

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
$service = new ForecastingService($pdo);
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
    $asOf = new DateTimeImmutable('today');
    $historyDate = $asOf->modify('-20 days')->format('Y-m-d H:i:s');
    $futureHarvest = $asOf->modify('+20 days')->format('Y-m-d');
    $futureHarvestEnd = $asOf->modify('+28 days')->format('Y-m-d');

    $pdo->prepare('INSERT INTO households (name, slug, timezone) VALUES (?, ?, ?)')
        ->execute(['Phase 8 Isolation ' . $suffix, 'phase8-isolation-' . $suffix, 'America/Phoenix']);
    $otherHouseholdId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO household_members
         (household_id, display_name, age_group, role, status, joined_at)
         VALUES (?, 'Phase 8 Other', 'adult', 'owner', 'active', CURDATE())"
    )->execute([$otherHouseholdId]);
    $otherMemberId = (int)$pdo->lastInsertId();

    $category = $scalar(
        "SELECT id FROM inventory_categories
         WHERE category_type = 'food' AND (household_id IS NULL OR household_id = ?)
         ORDER BY household_id DESC LIMIT 1",
        [$householdId]
    );
    $itemName = 'Phase 8 Beans ' . $suffix;
    $pdo->prepare(
        "INSERT INTO inventory_items
         (household_id, category_id, name, item_type, current_quantity, unit,
          reorder_level, target_stock_level, best_use_date, status)
         VALUES (?, ?, ?, 'ingredient', 2, 'lb', 5, 12, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'active')"
    )->execute([$householdId, $category !== false ? (int)$category : null, $itemName]);
    $inventoryId = (int)$pdo->lastInsertId();

    $ledgerInsert = $pdo->prepare(
        "INSERT INTO food_ledger_events
         (household_id, inventory_item_id, member_id, event_type, quantity, unit, occurred_at, notes)
         VALUES (?, ?, ?, ?, ?, 'lb', ?, ?)"
    );
    $ledgerInsert->execute([$householdId, $inventoryId, $memberId, 'purchased', 8, $historyDate, 'Phase 8 test purchase']);
    $ledgerInsert->execute([$householdId, $inventoryId, $memberId, 'harvested', 2, $historyDate, 'Phase 8 test production']);
    for ($i = 0; $i < 6; $i++) {
        $ledgerInsert->execute([
            $householdId,
            $inventoryId,
            $memberId,
            $i % 2 === 0 ? 'consumed' : 'used_in_recipe',
            1,
            $asOf->modify('-' . (3 + $i) . ' days')->format('Y-m-d H:i:s'),
            'Phase 8 test depletion',
        ]);
    }

    $pdo->prepare("INSERT INTO garden_zones (household_id, name, zone_type, active) VALUES (?, ?, 'raised_bed', 1)")
        ->execute([$householdId, 'Phase 8 Garden ' . $suffix]);
    $zoneId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO plantings
         (garden_zone_id, crop_name, planted_on, expected_harvest_start, expected_harvest_end, growth_stage)
         VALUES (?, ?, DATE_SUB(CURDATE(), INTERVAL 90 DAY), DATE_SUB(CURDATE(), INTERVAL 30 DAY),
                 DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'completed')"
    )->execute([$zoneId, $itemName]);
    $historicalPlantingId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO harvests
         (planting_id, harvested_by_member_id, quantity, unit, destination, inventory_item_id,
          harvested_at, notes)
         VALUES (?, ?, 4, 'lb', 'inventory', ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 18 DAY), ?)"
    )->execute([$historicalPlantingId, $memberId, $inventoryId, 'Phase 8 historical harvest']);

    $pdo->prepare(
        "INSERT INTO plantings
         (garden_zone_id, crop_name, planted_on, expected_harvest_start, expected_harvest_end, growth_stage)
         VALUES (?, ?, DATE_SUB(CURDATE(), INTERVAL 10 DAY), ?, ?, 'vegetative')"
    )->execute([$zoneId, $itemName, $futureHarvest, $futureHarvestEnd]);

    $pdo->prepare(
        "INSERT INTO preservation_batches
         (household_id, name, method, status, started_by_member_id, output_inventory_item_id,
          yield_quantity, yield_unit, started_at)
         VALUES (?, ?, 'dehydrating', 'planned', ?, ?, 3, 'lb', UTC_TIMESTAMP())"
    )->execute([$householdId, 'Phase 8 Bean Preserve ' . $suffix, $memberId, $inventoryId]);

    $pdo->prepare(
        "INSERT INTO recipes (household_id, name, servings, status, created_by_member_id)
         VALUES (?, ?, 4, 'active', ?)"
    )->execute([$householdId, 'Phase 8 Supper ' . $suffix, $memberId]);
    $recipeId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO recipe_ingredients
         (recipe_id, inventory_item_id, ingredient_name, quantity, unit, optional, sort_order)
         VALUES (?, ?, ?, 2, 'lb', 0, 1)"
    )->execute([$recipeId, $inventoryId, $itemName]);

    $mealDate = $asOf->modify('+5 days')->format('Y-m-d');
    $pdo->prepare(
        "INSERT INTO meal_plans (household_id, name, starts_on, ends_on, status)
         VALUES (?, ?, ?, ?, 'active')"
    )->execute([$householdId, 'Phase 8 Meal Plan ' . $suffix, $mealDate, $mealDate]);
    $mealPlanId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO meal_plan_items
         (meal_plan_id, recipe_id, meal_date, meal_type, planned_servings)
         VALUES (?, ?, ?, 'dinner', 8)"
    )->execute([$mealPlanId, $recipeId, $mealDate]);

    $service->saveSettings($householdId, $memberId, [
        'horizon_days' => 90,
        'history_days' => 90,
        'target_self_sufficiency_percent' => 35,
        'target_buffer_days' => 30,
    ]);
    $settings = $service->settings($householdId);
    $assert((int)$settings['horizon_days'] === 90, 'Forecast settings should persist.');
    $expectFailure(
        fn() => $service->saveSettings($householdId, $otherMemberId, [
            'horizon_days' => 90,
            'history_days' => 90,
            'target_self_sufficiency_percent' => 35,
            'target_buffer_days' => 30,
        ]),
        'Cross-household members must not change forecast settings.'
    );

    $result = $service->runForecast($householdId, $memberId, $asOf->format('Y-m-d'));
    $assert($result['reused'] === false, 'The first Phase 8 forecast should be new.');
    $assert($result['recommendations'] >= 2, 'The forecast should generate evidence-linked recommendations.');
    $assert($result['harvests'] >= 1, 'The forecast should count expected harvests.');
    $assert($result['resilience_score'] >= 0 && $result['resilience_score'] <= 100, 'Resilience score must be bounded.');
    $snapshotId = (int)$result['snapshot_id'];

    $projection = $pdo->prepare(
        'SELECT * FROM forecast_item_projections
         WHERE snapshot_id = ? AND household_id = ? AND inventory_item_id = ?'
    );
    $projection->execute([$snapshotId, $householdId, $inventoryId]);
    $projectionRow = $projection->fetch();
    $assert(is_array($projectionRow), 'The tracked inventory item should receive a projection.');
    if (is_array($projectionRow)) {
        $assert((float)$projectionRow['projected_harvest_quantity'] > 0, 'Matching harvest history should estimate future harvest inflow.');
        $assert((float)$projectionRow['projected_preservation_quantity'] === 3.0, 'Planned preservation output should be included.');
        $assert((int)$projectionRow['source_event_count'] === 6, 'Depletion event count should match tracked history.');
    }

    $again = $service->runForecast($householdId, $memberId, $asOf->format('Y-m-d'));
    $assert($again['reused'] === true && (int)$again['snapshot_id'] === $snapshotId, 'Unchanged source data should reuse the same forecast snapshot.');
    $assert(
        (int)$scalar('SELECT COUNT(*) FROM forecast_snapshots WHERE household_id = ? AND run_key = (SELECT run_key FROM forecast_snapshots WHERE id = ?)', [$householdId, $snapshotId]) === 1,
        'Forecast reuse must not duplicate snapshots.'
    );

    $actionKey = bin2hex(random_bytes(32));
    $entryInput = [
        'title' => 'Phase 8 Fall planting ' . $suffix,
        'entry_type' => 'plant',
        'crop_or_item' => 'Garlic',
        'starts_on' => $asOf->modify('+40 days')->format('Y-m-d'),
        'ends_on' => $asOf->modify('+45 days')->format('Y-m-d'),
        'assigned_member_id' => $memberId,
        'notes' => 'Integration seasonal entry.',
        'action_key' => $actionKey,
    ];
    $entryId = $service->createSeasonalEntry($householdId, $memberId, $entryInput);
    $assert($service->createSeasonalEntry($householdId, $memberId, $entryInput) === $entryId, 'Manual seasonal entry creation should be idempotent.');
    $expectFailure(
        fn() => $service->createSeasonalEntry($householdId, $memberId, array_merge($entryInput, [
            'action_key' => bin2hex(random_bytes(32)),
            'assigned_member_id' => $otherMemberId,
        ])),
        'Cross-household seasonal assignments must be rejected.'
    );
    $service->updateSeasonalEntry($householdId, $memberId, $entryId, 'accepted');
    $service->updateSeasonalEntry($householdId, $memberId, $entryId, 'completed');
    $assert(
        (string)$scalar('SELECT status FROM seasonal_plan_entries WHERE id = ? AND household_id = ?', [$entryId, $householdId]) === 'completed',
        'Seasonal entries should support guarded lifecycle transitions.'
    );

    $recommendationId = (int)$scalar(
        "SELECT id FROM forecast_recommendations
         WHERE household_id = ? AND snapshot_id = ? AND status = 'pending'
         ORDER BY FIELD(priority, 'critical','high','medium','low'), id LIMIT 1",
        [$householdId, $snapshotId]
    );
    $assert($recommendationId > 0, 'A pending recommendation should exist.');
    $taskId = $service->acceptRecommendation($householdId, $memberId, $recommendationId);
    $assert($taskId > 0, 'Accepting a recommendation should create a household task.');
    $assert($service->acceptRecommendation($householdId, $memberId, $recommendationId) === $taskId, 'Accepted recommendations should return their existing task.');
    $assert(
        (string)$scalar('SELECT related_type FROM household_tasks WHERE id = ? AND household_id = ?', [$taskId, $householdId]) === 'forecast_recommendation',
        'Forecast tasks should preserve recommendation provenance.'
    );

    $otherRecommendation = $pdo->prepare(
        "INSERT INTO forecast_snapshots
         (household_id, as_of_date, horizon_days, history_days, run_key, source_watermark,
          model_version, status, inventory_coverage_score, self_sufficiency_score,
          seasonal_readiness_score, resilience_score, generated_by_member_id, completed_at)
         VALUES (?, CURDATE(), 90, 90, ?, ?, 'deterministic-v1', 'completed', 0, 0, 0, 0, ?, UTC_TIMESTAMP())"
    );
    $otherRecommendation->execute([
        $otherHouseholdId,
        hash('sha256', 'other-run-' . $suffix),
        hash('sha256', 'other-watermark-' . $suffix),
        $otherMemberId,
    ]);
    $otherSnapshotId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO forecast_recommendations
         (household_id, snapshot_id, recommendation_type, generation_key, title, rationale,
          recommended_action, priority, status)
         VALUES (?, ?, 'review_data', ?, 'Other recommendation', 'Isolation test',
                 'No action', 'low', 'pending')"
    )->execute([$otherHouseholdId, $otherSnapshotId, hash('sha256', 'other-rec-' . $suffix)]);
    $otherRecommendationId = (int)$pdo->lastInsertId();
    $expectFailure(
        fn() => $service->acceptRecommendation($householdId, $memberId, $otherRecommendationId),
        'Cross-household recommendations must not be accepted.'
    );

    $pdo->prepare(
        "INSERT INTO food_ledger_events
         (household_id, inventory_item_id, member_id, event_type, quantity, unit, occurred_at, notes)
         VALUES (?, ?, ?, 'consumed', 0.5, 'lb', UTC_TIMESTAMP(), 'Watermark change')"
    )->execute([$householdId, $inventoryId, $memberId]);
    $changed = $service->runForecast($householdId, $memberId, $asOf->format('Y-m-d'));
    $assert($changed['reused'] === false && (int)$changed['snapshot_id'] !== $snapshotId, 'Changed source data should create a new snapshot.');

    $dashboard = $service->dashboardData($householdId);
    $assert(is_array($dashboard['snapshot']), 'Forecast dashboard should expose the latest completed snapshot.');
    $assert($dashboard['projections'] !== [], 'Forecast dashboard should expose item projections.');

    $assert((int)$scalar(
        'SELECT COUNT(*) FROM forecast_item_projections fp
         LEFT JOIN forecast_snapshots fs ON fs.id = fp.snapshot_id AND fs.household_id = fp.household_id
         WHERE fs.id IS NULL'
    ) === 0, 'Forecast projections must not be orphaned.');
    $assert((int)$scalar(
        'SELECT COUNT(*) FROM forecast_lifecycle_events fle
         LEFT JOIN forecast_recommendations fr ON fr.id = fle.recommendation_id AND fr.household_id = fle.household_id
         WHERE fle.recommendation_id IS NOT NULL AND fr.id IS NULL'
    ) === 0, 'Forecast lifecycle recommendation events must not be orphaned.');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected integration exception: ' . $exception->getMessage();
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 8 integration audit passed.\n";
