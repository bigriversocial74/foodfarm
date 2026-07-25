<?php

declare(strict_types=1);

use Homestead\RecipeService;

require dirname(__DIR__) . '/app/RecipeService.php';

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

$owner = $pdo->query(
    "SELECT hm.id AS member_id, hm.household_id
     FROM household_members hm JOIN users u ON u.id = hm.user_id
     WHERE hm.role = 'owner' AND hm.status = 'active' AND u.status = 'active'
     ORDER BY hm.id LIMIT 1"
)->fetch();
if (!is_array($owner)) {
    fwrite(STDERR, "A bootstrapped owner is required.\n");
    exit(1);
}
$memberId = (int)$owner['member_id'];
$householdId = (int)$owner['household_id'];

$pdo->prepare("INSERT INTO storage_locations (household_id, name, location_type) VALUES (?, 'Prepared Food Refrigerator', 'refrigerator')")
    ->execute([$householdId]);
$refrigeratorId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO storage_locations (household_id, name, location_type) VALUES (?, 'Prepared Food Freezer', 'freezer')")
    ->execute([$householdId]);
$freezerId = (int)$pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO inventory_items
     (household_id, storage_location_id, name, item_type, current_quantity, unit, status)
     VALUES (?, ?, 'Prepared Food Test Ingredient', 'ingredient', 20, 'lb', 'active')"
)->execute([$householdId, $refrigeratorId]);
$ingredientInventoryId = (int)$pdo->lastInsertId();

$service = new RecipeService($pdo);
$recipeId = $service->createRecipe($householdId, $memberId, [
    'name' => 'Prepared Food Integration Recipe',
    'servings' => 4,
    'instructions' => 'Cook safely.',
]);
$service->addIngredient($householdId, $recipeId, [
    'ingredient_name' => 'Prepared Food Test Ingredient',
    'quantity' => 1,
    'unit' => 'lb',
    'inventory_item_id' => $ingredientInventoryId,
]);

$pastDateRejected = false;
try {
    $service->completeRecipe($householdId, $memberId, [
        'recipe_id' => $recipeId,
        'scale_factor' => 1,
        'actual_servings' => 4,
        'storage_location_id' => $refrigeratorId,
        'storage_method' => 'refrigerated',
        'completion_key' => hash('sha256', 'past-date-recipe'),
        'use_by_date' => (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d'),
    ]);
} catch (Throwable) {
    $pastDateRejected = true;
}
if (!$pastDateRejected) {
    fwrite(STDERR, "Past use-by date was accepted.\n");
    exit(1);
}
echo "[PASS] past use-by dates are rejected\n";

$missingLocationRejected = false;
try {
    $service->completeRecipe($householdId, $memberId, [
        'recipe_id' => $recipeId,
        'scale_factor' => 1,
        'actual_servings' => 4,
        'storage_method' => 'refrigerated',
        'completion_key' => hash('sha256', 'missing-cold-location'),
        'use_by_date' => (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d'),
    ]);
} catch (Throwable) {
    $missingLocationRejected = true;
}
if (!$missingLocationRejected) {
    fwrite(STDERR, "Refrigerated food without a location was accepted.\n");
    exit(1);
}
echo "[PASS] cold prepared food requires a storage location\n";

$batchId = $service->completeRecipe($householdId, $memberId, [
    'recipe_id' => $recipeId,
    'scale_factor' => 1,
    'actual_servings' => 4,
    'storage_location_id' => $refrigeratorId,
    'storage_method' => 'refrigerated',
    'completion_key' => hash('sha256', 'prepared-food-completion'),
    'intended_member_ids' => [$memberId],
    'use_by_date' => (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d'),
]);
$actionKey = hash('sha256', 'prepared-food-consume');
$consumeActionId = $service->updatePreparedFood($householdId, $memberId, [
    'prepared_food_batch_id' => $batchId,
    'prepared_action' => 'consumed',
    'servings' => 1.5,
    'action_key' => $actionKey,
    'notes' => 'Lunch servings',
]);
$batch = $pdo->query('SELECT inventory_item_id, servings_remaining, status FROM prepared_food_batches WHERE id = ' . $batchId)->fetch();
$inventoryRemaining = (float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . (int)$batch['inventory_item_id'])->fetchColumn();
if ($consumeActionId < 1 || abs((float)$batch['servings_remaining'] - 2.5) > 0.0001 || abs($inventoryRemaining - 2.5) > 0.0001 || $batch['status'] !== 'active') {
    fwrite(STDERR, "Prepared-food consumption did not synchronize quantities.\n");
    exit(1);
}
echo "[PASS] consumption synchronizes batch inventory and ledger state\n";

$duplicateRejected = false;
try {
    $service->updatePreparedFood($householdId, $memberId, [
        'prepared_food_batch_id' => $batchId,
        'prepared_action' => 'consumed',
        'servings' => 1.5,
        'action_key' => $actionKey,
    ]);
} catch (Throwable) {
    $duplicateRejected = true;
}
$afterDuplicate = (float)$pdo->query('SELECT servings_remaining FROM prepared_food_batches WHERE id = ' . $batchId)->fetchColumn();
if (!$duplicateRejected || abs($afterDuplicate - 2.5) > 0.0001) {
    fwrite(STDERR, "Duplicate prepared-food action changed quantities.\n");
    exit(1);
}
echo "[PASS] prepared-food actions are idempotent\n";

$freezeActionId = $service->updatePreparedFood($householdId, $memberId, [
    'prepared_food_batch_id' => $batchId,
    'prepared_action' => 'frozen',
    'storage_location_id' => $freezerId,
    'action_key' => hash('sha256', 'prepared-food-freeze'),
]);
$frozen = $pdo->query('SELECT status, storage_method, storage_location_id, servings_remaining FROM prepared_food_batches WHERE id = ' . $batchId)->fetch();
if ($freezeActionId < 1 || $frozen['status'] !== 'frozen' || $frozen['storage_method'] !== 'frozen'
    || (int)$frozen['storage_location_id'] !== $freezerId || abs((float)$frozen['servings_remaining'] - 2.5) > 0.0001) {
    fwrite(STDERR, "Prepared-food freezing did not preserve quantity and update storage.\n");
    exit(1);
}
echo "[PASS] freezing preserves servings and moves storage\n";

$spoilActionId = $service->updatePreparedFood($householdId, $memberId, [
    'prepared_food_batch_id' => $batchId,
    'prepared_action' => 'spoiled',
    'servings' => 2.5,
    'action_key' => hash('sha256', 'prepared-food-loss'),
]);
$closed = $pdo->query('SELECT status, servings_remaining FROM prepared_food_batches WHERE id = ' . $batchId)->fetch();
$closedInventory = (float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . (int)$batch['inventory_item_id'])->fetchColumn();
if ($spoilActionId < 1 || $closed['status'] !== 'spoiled' || abs((float)$closed['servings_remaining']) > 0.0001 || abs($closedInventory) > 0.0001) {
    fwrite(STDERR, "Prepared-food loss did not close the batch.\n");
    exit(1);
}
echo "[PASS] food loss closes the batch and inventory record\n";

$ledgerCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM food_ledger_events WHERE related_type = 'prepared_food_action'"
)->fetchColumn();
$actionCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM prepared_food_actions WHERE prepared_food_batch_id = ' . $batchId
)->fetchColumn();
if ($ledgerCount < 3 || $actionCount !== 3) {
    fwrite(STDERR, "Prepared-food lifecycle history is incomplete.\n");
    exit(1);
}
echo "[PASS] prepared-food lifecycle actions are fully auditable\n";

echo "Prepared-food integration suite passed.\n";
