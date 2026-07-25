<?php

declare(strict_types=1);

use Homestead\GrowPreserveService;

require dirname(__DIR__) . '/app/GrowPreserveService.php';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    (int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_NAME') ?: 'homestead'
);
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$checks = [];
$check = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
};
$expectException = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (Throwable) {
        return true;
    }
};

$owner = $pdo->query(
    "SELECT u.id AS user_id, hm.id AS member_id, hm.household_id
     FROM users u JOIN household_members hm ON hm.user_id = u.id
     WHERE u.status = 'active' AND hm.status = 'active' AND hm.role = 'owner'
     ORDER BY u.id LIMIT 1"
)->fetch();
if (!is_array($owner)) {
    fwrite(STDERR, "A bootstrapped owner is required.\n");
    exit(1);
}
$memberId = (int)$owner['member_id'];
$householdId = (int)$owner['household_id'];
$service = new GrowPreserveService($pdo);

$locationQuery = $pdo->prepare('SELECT id FROM storage_locations WHERE household_id = ? ORDER BY id LIMIT 1');
$locationQuery->execute([$householdId]);
$locationId = (int)$locationQuery->fetchColumn();
if ($locationId < 1) {
    $pdo->prepare("INSERT INTO storage_locations (household_id, name, location_type) VALUES (?, 'Phase 6 Pantry', 'pantry')")
        ->execute([$householdId]);
    $locationId = (int)$pdo->lastInsertId();
}

$zoneId = $service->createZone($householdId, [
    'name' => 'Phase 6 Raised Bed',
    'zone_type' => 'raised_bed',
    'dimensions' => '4x8 ft',
    'target_temperature_min' => 45,
    'target_temperature_max' => 95,
    'target_humidity_min' => 20,
    'target_humidity_max' => 90,
]);
$check('garden zone created for household', $zoneId > 0);

$plantingId = $service->createPlanting($householdId, [
    'garden_zone_id' => $zoneId,
    'crop_name' => 'Phase 6 Tomato',
    'variety' => 'Roma',
    'planted_on' => date('Y-m-d', strtotime('-70 days')),
    'expected_harvest_start' => date('Y-m-d', strtotime('-5 days')),
    'expected_harvest_end' => date('Y-m-d', strtotime('+20 days')),
    'growth_stage' => 'fruiting',
    'plant_count' => 6,
]);
$check('planting created inside owned zone', $plantingId > 0);

$readingId = $service->recordReading($householdId, $memberId, [
    'garden_zone_id' => $zoneId,
    'temperature' => 78.5,
    'humidity' => 48,
    'soil_moisture' => 62,
    'vpd' => 1.25,
    'light_level' => 41000,
    'source' => 'manual',
]);
$check('environmental reading created', $readingId > 0);

$service->updatePlantingStage($householdId, $memberId, $plantingId, 'harvest_ready');
$stage = (string)$pdo->query('SELECT growth_stage FROM plantings WHERE id = ' . $plantingId)->fetchColumn();
$check('planting advances to harvest ready', $stage === 'harvest_ready');
$check('planting stage cannot move backward', $expectException(
    static fn() => $service->updatePlantingStage($householdId, $memberId, $plantingId, 'seedling')
));

$harvestKey = hash('sha256', 'phase6-harvest-integration');
$harvestId = $service->recordHarvest($householdId, $memberId, [
    'planting_id' => $plantingId,
    'quantity' => 5,
    'unit' => 'lb',
    'destination' => 'preservation',
    'preservation_method' => 'dehydrating',
    'new_inventory_name' => 'Phase 6 Fresh Roma Tomatoes',
    'storage_location_id' => $locationId,
    'best_use_date' => date('Y-m-d', strtotime('+7 days')),
    'action_key' => $harvestKey,
]);
$harvest = $pdo->query('SELECT * FROM harvests WHERE id = ' . $harvestId)->fetch();
$check('harvest is recorded with inventory provenance', is_array($harvest) && (int)$harvest['inventory_item_id'] > 0);
$check('harvest creates planned preservation batch', is_array($harvest) && (int)$harvest['preservation_batch_id'] > 0);

$inputItemId = (int)$harvest['inventory_item_id'];
$plannedBatchId = (int)$harvest['preservation_batch_id'];
$inputQuantity = (float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $inputItemId)->fetchColumn();
$check('harvest stocks exact inventory quantity', abs($inputQuantity - 5.0) < 0.0001);
$check('harvest writes ledger event', (int)$pdo->query("SELECT COUNT(*) FROM food_ledger_events WHERE related_type = 'harvest' AND related_id = {$harvestId} AND event_type = 'harvested'")->fetchColumn() === 1);
$check('duplicate harvest is rejected', $expectException(static fn() => $service->recordHarvest($householdId, $memberId, [
    'planting_id' => $plantingId,
    'quantity' => 5,
    'unit' => 'lb',
    'destination' => 'inventory',
    'inventory_item_id' => $inputItemId,
    'action_key' => $harvestKey,
])));
$check('duplicate harvest does not stock twice', abs((float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $inputItemId)->fetchColumn() - 5.0) < 0.0001);

$pdo->prepare("INSERT INTO inventory_items (household_id, storage_location_id, name, item_type, current_quantity, unit, status) VALUES (?, ?, 'Phase 6 Unrelated Produce', 'ingredient', 4, 'lb', 'active')")
    ->execute([$householdId, $locationId]);
$unrelatedItemId = (int)$pdo->lastInsertId();
$check('planned preservation rejects unrelated inventory input', $expectException(static fn() => $service->completePreservation($householdId, $memberId, [
    'preservation_batch_id' => $plannedBatchId,
    'input_inventory_item_id' => $unrelatedItemId,
    'input_quantity' => 1,
    'input_unit' => 'lb',
    'name' => 'Wrong source batch',
    'method' => 'dehydrating',
    'output_name' => 'Wrong source output',
    'output_quantity' => 1,
    'output_unit' => 'bags',
    'storage_location_id' => $locationId,
    'action_key' => hash('sha256', 'phase6-wrong-source'),
])));
$check('rejected unrelated input remains unchanged', abs((float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $unrelatedItemId)->fetchColumn() - 4.0) < 0.0001);

$preservationKey = hash('sha256', 'phase6-preservation-integration');
$completedBatchId = $service->completePreservation($householdId, $memberId, [
    'preservation_batch_id' => $plannedBatchId,
    'input_inventory_item_id' => $inputItemId,
    'input_quantity' => 2,
    'input_unit' => 'lb',
    'name' => 'Phase 6 Dehydrated Tomatoes',
    'method' => 'dehydrating',
    'output_name' => 'Phase 6 Dried Tomatoes',
    'output_quantity' => 3,
    'output_unit' => 'bags',
    'storage_location_id' => $locationId,
    'best_use_date' => date('Y-m-d', strtotime('+180 days')),
    'safety_source' => 'Extension-service dehydrating guidance',
    'action_key' => $preservationKey,
]);
$batch = $pdo->query('SELECT * FROM preservation_batches WHERE id = ' . $completedBatchId)->fetch();
$check('planned batch completes as stored', is_array($batch) && $batch['status'] === 'stored');
$check('preservation links output inventory', is_array($batch) && (int)$batch['output_inventory_item_id'] > 0);
$check('preservation deducts exact raw input', abs((float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $inputItemId)->fetchColumn() - 3.0) < 0.0001);
$outputItemId = (int)$batch['output_inventory_item_id'];
$check('preservation creates exact output inventory', abs((float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $outputItemId)->fetchColumn() - 3.0) < 0.0001);
$check('preservation records input provenance', (int)$pdo->query('SELECT COUNT(*) FROM preservation_batch_inputs WHERE preservation_batch_id = ' . $completedBatchId)->fetchColumn() === 1);
$check('preservation writes input and output ledger events', (int)$pdo->query("SELECT COUNT(*) FROM food_ledger_events WHERE related_type = 'preservation_batch' AND related_id = {$completedBatchId} AND event_type = 'preserved'")->fetchColumn() === 2);
$check('duplicate preservation is rejected', $expectException(static fn() => $service->completePreservation($householdId, $memberId, [
    'input_inventory_item_id' => $inputItemId,
    'input_quantity' => 1,
    'input_unit' => 'lb',
    'name' => 'Duplicate',
    'method' => 'dehydrating',
    'output_name' => 'Duplicate output',
    'output_quantity' => 1,
    'output_unit' => 'bags',
    'storage_location_id' => $locationId,
    'action_key' => $preservationKey,
])));
$check('duplicate preservation does not deduct twice', abs((float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $inputItemId)->fetchColumn() - 3.0) < 0.0001);

$insufficientBefore = (float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $inputItemId)->fetchColumn();
$check('insufficient preservation input rolls back', $expectException(static fn() => $service->completePreservation($householdId, $memberId, [
    'input_inventory_item_id' => $inputItemId,
    'input_quantity' => 100,
    'input_unit' => 'lb',
    'name' => 'Insufficient batch',
    'method' => 'freezing',
    'output_name' => 'Impossible output',
    'output_quantity' => 100,
    'output_unit' => 'bags',
    'storage_location_id' => $locationId,
    'action_key' => hash('sha256', 'phase6-insufficient'),
])));
$check('failed preservation leaves inventory unchanged', abs((float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $inputItemId)->fetchColumn() - $insufficientBefore) < 0.0001);

$pdo->prepare("INSERT INTO households (name, slug) VALUES ('Phase 6 Isolation', 'phase6-isolation')")->execute();
$otherHouseholdId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO garden_zones (household_id, name, zone_type) VALUES (?, 'Other Zone', 'bed')")
    ->execute([$otherHouseholdId]);
$otherZoneId = (int)$pdo->lastInsertId();
$check('cross-household planting is rejected', $expectException(static fn() => $service->createPlanting($householdId, [
    'garden_zone_id' => $otherZoneId,
    'crop_name' => 'Other Crop',
    'planted_on' => date('Y-m-d'),
])));
$pdo->prepare("INSERT INTO inventory_items (household_id, name, item_type, current_quantity, unit, status) VALUES (?, 'Other Produce', 'ingredient', 10, 'lb', 'active')")
    ->execute([$otherHouseholdId]);
$otherInventoryId = (int)$pdo->lastInsertId();
$check('cross-household preservation input is rejected', $expectException(static fn() => $service->completePreservation($householdId, $memberId, [
    'input_inventory_item_id' => $otherInventoryId,
    'input_quantity' => 1,
    'input_unit' => 'lb',
    'name' => 'Isolation batch',
    'method' => 'freezing',
    'output_name' => 'Isolation output',
    'output_quantity' => 1,
    'output_unit' => 'bags',
    'storage_location_id' => $locationId,
    'action_key' => hash('sha256', 'phase6-isolation-input'),
])));

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, 'Phase 6 integration failures: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "Phase 6 grow, harvest, and preserve integration suite passed.\n";
