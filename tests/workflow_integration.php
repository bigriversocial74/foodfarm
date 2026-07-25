<?php

declare(strict_types=1);

use Homestead\Auth;
use Homestead\HouseholdContext;
use Homestead\RecipeService;
use Homestead\StarterKitService;

require dirname(__DIR__) . '/app/Support.php';
require dirname(__DIR__) . '/app/Auth.php';
require dirname(__DIR__) . '/app/HouseholdContext.php';
require dirname(__DIR__) . '/app/RecipeService.php';
require dirname(__DIR__) . '/app/StarterKitService.php';

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
    "SELECT u.id AS user_id, u.email, u.is_platform_admin, hm.id AS member_id, hm.household_id, hm.role
     FROM users u JOIN household_members hm ON hm.user_id = u.id
     WHERE hm.role = 'owner' AND hm.status = 'active' AND u.status = 'active'
     ORDER BY u.id LIMIT 1"
)->fetch();
if (!is_array($owner)) {
    fwrite(STDERR, "No bootstrapped owner exists.\n");
    exit(1);
}

$userId = (int)$owner['user_id'];
$memberId = (int)$owner['member_id'];
$householdId = (int)$owner['household_id'];
$ownerEmail = (string)$owner['email'];
$check('owner bootstrap links user and household member', $userId > 0 && $memberId > 0 && $householdId > 0);
$check('owner bootstrap can grant platform administration', (int)$owner['is_platform_admin'] === 1);

$_SESSION = ['user_id' => $userId, 'member_id' => $memberId, 'household_id' => $householdId];
$context = new HouseholdContext($pdo);
$check('household context resolves authenticated membership', $context->id() === $householdId && $context->memberId() === $memberId);
$_SESSION['household_id'] = $householdId + 999999;
$check('household context rejects mismatched session scope', $expectException(static fn() => $context->id()));
$check('invalid household context clears session identity', !isset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['household_id']));

$auth = new Auth($pdo);
$check('owner receives role permissions', $auth->can(['role' => 'owner', 'permission_overrides' => null], 'inventory.manage'));
$check('guest role does not receive inventory management', !$auth->can(['role' => 'guest_helper', 'permission_overrides' => null], 'inventory.manage'));
$check('permission deny override removes a role default', !$auth->can(['role' => 'adult_member', 'permission_overrides' => json_encode(['inventory.manage' => false])], 'inventory.manage'));

$location = $pdo->prepare('SELECT id FROM storage_locations WHERE household_id = ? ORDER BY id LIMIT 1');
$location->execute([$householdId]);
$locationId = (int)$location->fetchColumn();
if ($locationId < 1) {
    $insert = $pdo->prepare("INSERT INTO storage_locations (household_id, name, location_type) VALUES (?, 'Integration Pantry', 'room')");
    $insert->execute([$householdId]);
    $locationId = (int)$pdo->lastInsertId();
}

$inventoryInsert = $pdo->prepare(
    "INSERT INTO inventory_items
     (household_id, storage_location_id, name, item_type, current_quantity, unit, status)
     VALUES (?, ?, 'Integration Flour', 'ingredient', 10, 'lb', 'active')"
);
$inventoryInsert->execute([$householdId, $locationId]);
$inventoryId = (int)$pdo->lastInsertId();

$recipeService = new RecipeService($pdo);
$recipeId = $recipeService->createRecipe($householdId, $memberId, [
    'name' => 'Integration Bread',
    'servings' => 4,
    'category' => 'Bread',
    'instructions' => 'Mix and bake.',
]);
$recipeService->addIngredient($householdId, $recipeId, [
    'ingredient_name' => 'Integration Flour',
    'quantity' => 2,
    'unit' => 'lb',
    'inventory_item_id' => $inventoryId,
]);
$check('recipe and linked ingredient are created', $recipeId > 0);
$check('recipe unit mismatch is rejected', $expectException(static fn() => $recipeService->addIngredient($householdId, $recipeId, [
    'ingredient_name' => 'Bad unit', 'quantity' => 1, 'unit' => 'kg', 'inventory_item_id' => $inventoryId,
])));

$start = new DateTimeImmutable('today');
$end = $start->modify('+7 days');
$planId = $recipeService->createMealPlan($householdId, 'Integration Week', $start->format('Y-m-d'), $end->format('Y-m-d'));
$mealId = $recipeService->addMeal($householdId, [
    'meal_plan_id' => $planId,
    'recipe_id' => $recipeId,
    'meal_date' => $start->modify('+1 day')->format('Y-m-d'),
    'meal_type' => 'dinner',
    'member_ids' => [$memberId],
]);
$check('meal planning creates member serving snapshots', $mealId > 0 && (int)$pdo->query('SELECT COUNT(*) FROM meal_plan_members WHERE meal_plan_item_id = ' . $mealId)->fetchColumn() === 1);
$check('meal outside plan range is rejected', $expectException(static fn() => $recipeService->addMeal($householdId, [
    'meal_plan_id' => $planId,
    'recipe_id' => $recipeId,
    'meal_date' => $end->modify('+1 day')->format('Y-m-d'),
    'meal_type' => 'dinner',
    'member_ids' => [$memberId],
])));

$completionKey = hash('sha256', 'integration-recipe-completion');
$batchId = $recipeService->completeRecipe($householdId, $memberId, [
    'recipe_id' => $recipeId,
    'scale_factor' => 1,
    'actual_servings' => 4,
    'storage_location_id' => $locationId,
    'storage_method' => 'refrigerated',
    'completion_key' => $completionKey,
    'intended_member_ids' => [$memberId],
    'use_by_date' => $start->modify('+3 days')->format('Y-m-d'),
]);
$remaining = (float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $inventoryId)->fetchColumn();
$check('recipe completion deducts exact inventory', abs($remaining - 8.0) < 0.0001);
$check('recipe completion creates prepared-food batch', $batchId > 0 && (int)$pdo->query('SELECT COUNT(*) FROM prepared_food_batches WHERE id = ' . $batchId)->fetchColumn() === 1);
$check('recipe completion writes immutable ledger events', (int)$pdo->query("SELECT COUNT(*) FROM food_ledger_events WHERE related_type IN ('recipe_run','prepared_food_batch')")->fetchColumn() >= 2);
$check('duplicate recipe completion is rejected', $expectException(static fn() => $recipeService->completeRecipe($householdId, $memberId, [
    'recipe_id' => $recipeId,
    'scale_factor' => 1,
    'actual_servings' => 4,
    'storage_location_id' => $locationId,
    'storage_method' => 'refrigerated',
    'completion_key' => $completionKey,
    'intended_member_ids' => [$memberId],
])));
$remainingAfterDuplicate = (float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $inventoryId)->fetchColumn();
$check('duplicate recipe completion does not deduct twice', abs($remainingAfterDuplicate - 8.0) < 0.0001);
$check('insufficient inventory rolls back recipe run', $expectException(static fn() => $recipeService->completeRecipe($householdId, $memberId, [
    'recipe_id' => $recipeId,
    'scale_factor' => 100,
    'actual_servings' => 400,
    'storage_location_id' => $locationId,
    'storage_method' => 'frozen',
    'completion_key' => hash('sha256', 'integration-insufficient'),
    'intended_member_ids' => [$memberId],
])));
$check('failed recipe completion leaves inventory unchanged', abs((float)$pdo->query('SELECT current_quantity FROM inventory_items WHERE id = ' . $inventoryId)->fetchColumn() - 8.0) < 0.0001);

$pdo->prepare("INSERT INTO households (name, slug) VALUES ('Isolation Household', 'integration-isolation')")->execute();
$otherHouseholdId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, display_name, status, is_platform_admin) VALUES ('isolation@example.test', ?, 'Isolation User', 'active', 0)")
    ->execute([password_hash('IntegrationPassword!123', PASSWORD_DEFAULT)]);
$otherUserId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO household_members (household_id, user_id, display_name, age_group, role, status) VALUES (?, ?, 'Isolation User', 'adult', 'owner', 'active')")
    ->execute([$otherHouseholdId, $otherUserId]);
$otherMemberId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory_items (household_id, name, item_type, current_quantity, unit, status) VALUES (?, 'Other Flour', 'ingredient', 10, 'lb', 'active')")
    ->execute([$otherHouseholdId]);
$otherInventoryId = (int)$pdo->lastInsertId();
$check('cross-household ingredient link is rejected', $expectException(static fn() => $recipeService->addIngredient($householdId, $recipeId, [
    'ingredient_name' => 'Other Flour', 'quantity' => 1, 'unit' => 'lb', 'inventory_item_id' => $otherInventoryId,
])));

$kitService = new StarterKitService($pdo);
$kitId = $kitService->createKit([
    'name' => 'Integration Starter Kit',
    'slug' => 'integration-starter-kit',
    'kit_type' => 'basic',
    'category' => 'testing',
], $userId);
$versionId = $kitService->createVersion($kitId, [
    'version_number' => 1,
    'sku' => 'INT-KIT-V1',
    'price' => 25,
    'currency_code' => 'USD',
]);
$kitService->addItem($versionId, [
    'item_name' => 'Local Flour',
    'item_kind' => 'ingredient',
    'fulfillment_type' => 'shopping_list',
    'default_quantity' => 5,
    'unit' => 'lb',
    'required' => 1,
]);
$kitService->addItem($versionId, [
    'item_name' => 'Getting Started Guide',
    'item_kind' => 'digital',
    'fulfillment_type' => 'digital_only',
    'required' => 1,
]);
$kitService->attachRecipe($versionId, $recipeId, $householdId);
$kitService->addTask($versionId, ['title' => 'Bake the first loaf', 'due_offset_days' => 1]);
$kitService->publishVersion($versionId);
$check('starter-kit publication freezes validated version', (string)$pdo->query('SELECT status FROM starter_kit_versions WHERE id = ' . $versionId)->fetchColumn() === 'published');
$check('published starter-kit version rejects edits', $expectException(static fn() => $kitService->addTask($versionId, ['title' => 'Late edit'])));

$order = $kitService->createOrderAndActivation($versionId, $ownerEmail, 'INTEGRATION-ORDER-1');
$activation = $kitService->activationByToken((string)$order['token']);
$activationItems = $pdo->prepare('SELECT ai.*, i.item_kind FROM starter_kit_activation_items ai JOIN starter_kit_items i ON i.id = ai.starter_kit_item_id WHERE ai.starter_kit_activation_id = ? ORDER BY ai.id');
$activationItems->execute([(int)$activation['id']]);
$selections = [];
foreach ($activationItems->fetchAll() as $item) {
    $id = (int)$item['id'];
    $selections[$id] = $item['item_kind'] === 'digital'
        ? ['status' => 'received', 'fulfillment_type' => 'digital_only', 'quantity' => 0, 'unit' => '']
        : ['status' => 'shopping', 'fulfillment_type' => 'shopping_list', 'quantity' => $item['confirmed_quantity'], 'unit' => $item['unit']];
}
$kitService->activate((string)$order['token'], [
    'id' => $userId,
    'email' => $ownerEmail,
    'household_id' => $householdId,
    'member_id' => $memberId,
], $selections);
$check('starter-kit activation is consumed once', (int)$pdo->query('SELECT COUNT(*) FROM starter_kit_activations WHERE activated_at IS NOT NULL')->fetchColumn() >= 1);
$check('starter-kit activation creates shopping provenance', (int)$pdo->query("SELECT COUNT(*) FROM shopping_list_items WHERE source_type = 'starter_kit'")->fetchColumn() >= 1);
$check('starter-kit activation clones attached recipes', (int)$pdo->query("SELECT COUNT(*) FROM recipes WHERE notes LIKE 'Provisioned from starter-kit activation #%'")->fetchColumn() >= 1);
$check('starter-kit activation provisions tasks', (int)$pdo->query("SELECT COUNT(*) FROM household_tasks WHERE related_type = 'starter_kit_activation'")->fetchColumn() >= 1);
$check('starter-kit token cannot be activated twice', $expectException(static fn() => $kitService->activate((string)$order['token'], [
    'id' => $userId, 'email' => $ownerEmail, 'household_id' => $householdId, 'member_id' => $memberId,
], $selections)));

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, 'Workflow integration failures: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Database-backed workflow integration suite passed.' . PHP_EOL;
