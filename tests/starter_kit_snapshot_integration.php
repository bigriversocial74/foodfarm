<?php

declare(strict_types=1);

use Homestead\StarterKitService;

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

$owner = $pdo->query(
    "SELECT u.id AS user_id, u.email, hm.id AS member_id, hm.household_id
     FROM users u JOIN household_members hm ON hm.user_id = u.id
     WHERE u.status = 'active' AND hm.status = 'active' AND hm.role = 'owner'
     ORDER BY u.id LIMIT 1"
)->fetch();
if (!is_array($owner)) {
    fwrite(STDERR, "A bootstrapped owner is required.\n");
    exit(1);
}
$userId = (int)$owner['user_id'];
$memberId = (int)$owner['member_id'];
$householdId = (int)$owner['household_id'];

$location = $pdo->prepare('SELECT id FROM storage_locations WHERE household_id = ? ORDER BY id LIMIT 1');
$location->execute([$householdId]);
$locationId = (int)$location->fetchColumn();
if ($locationId < 1) {
    $pdo->prepare("INSERT INTO storage_locations (household_id, name, location_type) VALUES (?, 'Snapshot Pantry', 'room')")
        ->execute([$householdId]);
    $locationId = (int)$pdo->lastInsertId();
}

$pdo->prepare(
    "INSERT INTO recipes
     (household_id, name, category, servings, prep_minutes, cook_minutes, status, instructions, created_by_member_id)
     VALUES (?, 'Snapshot Original Bread', 'Bread', 4, 15, 35, 'active', 'Original immutable instructions.', ?)"
)->execute([$householdId, $memberId]);
$recipeId = (int)$pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO recipe_ingredients
     (recipe_id, ingredient_name, quantity, unit, optional, sort_order)
     VALUES (?, 'Snapshot Flour', 2, 'lb', 0, 1)"
)->execute([$recipeId]);

$pdo->prepare("INSERT INTO households (name, slug) VALUES ('Snapshot Isolation', 'snapshot-isolation')")->execute();
$otherHouseholdId = (int)$pdo->lastInsertId();

$service = new StarterKitService($pdo);
$kitId = $service->createKit([
    'name' => 'Snapshot Integrity Kit',
    'slug' => 'snapshot-integrity-kit',
    'kit_type' => 'specialized',
], $userId);
$versionId = $service->createVersion($kitId, [
    'version_number' => 1,
    'sku' => 'SNAPSHOT-V1',
    'price' => 10,
    'currency_code' => 'USD',
]);
$service->addItem($versionId, [
    'item_name' => 'Snapshot Guide',
    'item_kind' => 'digital',
    'fulfillment_type' => 'digital_only',
    'required' => 1,
]);

$crossHouseholdRejected = false;
try {
    $service->attachRecipe($versionId, $recipeId, $otherHouseholdId);
} catch (Throwable) {
    $crossHouseholdRejected = true;
}
if (!$crossHouseholdRejected) {
    fwrite(STDERR, "Cross-household recipe attachment was not rejected.\n");
    exit(1);
}
echo "[PASS] cross-household recipe attachment rejected while draft\n";

$service->attachRecipe($versionId, $recipeId, $householdId);
$service->publishVersion($versionId);
$snapshot = $pdo->query(
    'SELECT snapshot_hash, recipe_snapshot FROM starter_kit_recipe_snapshots WHERE starter_kit_version_id = ' . $versionId
)->fetch();
if (!is_array($snapshot) || !hash_equals((string)$snapshot['snapshot_hash'], hash('sha256', (string)$snapshot['recipe_snapshot']))) {
    fwrite(STDERR, "Published recipe snapshot is missing or invalid.\n");
    exit(1);
}
echo "[PASS] publication creates a verified recipe snapshot\n";

$pdo->prepare("UPDATE recipes SET name = 'Mutated Live Recipe', instructions = 'Mutated instructions.' WHERE id = ?")
    ->execute([$recipeId]);
$pdo->prepare("UPDATE recipe_ingredients SET ingredient_name = 'Mutated Ingredient', quantity = 99 WHERE recipe_id = ?")
    ->execute([$recipeId]);

$order = $service->createOrderAndActivation($versionId, (string)$owner['email'], 'SNAPSHOT-ORDER-1');
$activation = $service->activationByToken((string)$order['token']);
$items = $pdo->prepare(
    'SELECT ai.id, ai.confirmed_quantity, ai.unit, i.item_kind
     FROM starter_kit_activation_items ai
     JOIN starter_kit_items i ON i.id = ai.starter_kit_item_id
     WHERE ai.starter_kit_activation_id = ?'
);
$items->execute([(int)$activation['id']]);
$selections = [];
foreach ($items->fetchAll() as $item) {
    $selections[(int)$item['id']] = [
        'status' => 'received',
        'fulfillment_type' => 'digital_only',
        'quantity' => 0,
        'unit' => '',
    ];
}
$service->activate((string)$order['token'], [
    'id' => $userId,
    'email' => (string)$owner['email'],
    'household_id' => $householdId,
    'member_id' => $memberId,
], $selections);

$clone = $pdo->prepare(
    "SELECT r.id, r.name, r.instructions
     FROM recipes r
     WHERE r.household_id = ? AND r.notes LIKE 'Provisioned from starter-kit activation #%'
       AND r.name = 'Snapshot Original Bread'
     ORDER BY r.id DESC LIMIT 1"
);
$clone->execute([$householdId]);
$clonedRecipe = $clone->fetch();
if (!is_array($clonedRecipe) || $clonedRecipe['instructions'] !== 'Original immutable instructions.') {
    fwrite(STDERR, "Activation did not use the immutable recipe snapshot.\n");
    exit(1);
}
$clonedIngredient = $pdo->prepare('SELECT ingredient_name, quantity FROM recipe_ingredients WHERE recipe_id = ? ORDER BY id LIMIT 1');
$clonedIngredient->execute([(int)$clonedRecipe['id']]);
$ingredient = $clonedIngredient->fetch();
if (!is_array($ingredient) || $ingredient['ingredient_name'] !== 'Snapshot Flour' || abs((float)$ingredient['quantity'] - 2.0) > 0.0001) {
    fwrite(STDERR, "Snapshot ingredient contents were not preserved.\n");
    exit(1);
}
echo "[PASS] activation ignores later source-recipe mutations\n";

$kit2 = $service->createKit([
    'name' => 'Snapshot Tamper Kit',
    'slug' => 'snapshot-tamper-kit',
    'kit_type' => 'basic',
], $userId);
$version2 = $service->createVersion($kit2, ['version_number' => 1, 'sku' => 'SNAPSHOT-TAMPER-V1']);
$service->addItem($version2, [
    'item_name' => 'Tamper Guide', 'item_kind' => 'digital',
    'fulfillment_type' => 'digital_only', 'required' => 1,
]);
$service->attachRecipe($version2, $recipeId, $householdId);
$service->publishVersion($version2);
$pdo->prepare("UPDATE starter_kit_recipe_snapshots SET recipe_snapshot = JSON_SET(recipe_snapshot, '$.name', 'Tampered') WHERE starter_kit_version_id = ?")
    ->execute([$version2]);
$tamperRejected = false;
try {
    $service->createOrderAndActivation($version2, (string)$owner['email'], 'SNAPSHOT-TAMPER-ORDER');
} catch (Throwable) {
    $tamperRejected = true;
}
if (!$tamperRejected) {
    fwrite(STDERR, "Tampered snapshot was accepted.\n");
    exit(1);
}
echo "[PASS] snapshot hash blocks tampered recipe bundles\n";

echo "Starter Kit recipe snapshot integration suite passed.\n";
