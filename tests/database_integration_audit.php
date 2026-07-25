<?php

declare(strict_types=1);

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

$requiredTables = [
    'households', 'users', 'household_members', 'storage_locations',
    'inventory_categories', 'inventory_items', 'food_ledger_events',
    'household_invitations', 'authentication_events', 'recipes',
    'recipe_ingredients', 'recipe_runs', 'recipe_run_ingredients',
    'meal_plans', 'meal_plan_items', 'meal_plan_members',
    'prepared_food_batches', 'starter_kits', 'starter_kit_versions',
    'starter_kit_items', 'starter_kit_orders', 'starter_kit_activations',
    'starter_kit_activation_items', 'shopping_lists', 'shopping_list_items',
];

$tables = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$missingTables = array_values(array_diff($requiredTables, $tables));

$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute([$table, $column]);
    return (int)$statement->fetchColumn() === 1;
};

$indexExists = static function (PDO $pdo, string $table, string $index): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $statement->execute([$table, $index]);
    return (int)$statement->fetchColumn() > 0;
};

$checks = [
    'all required tables installed' => $missingTables === [],
    'platform-admin column installed' => $columnExists($pdo, 'users', 'is_platform_admin'),
    'recipe completion key installed' => $columnExists($pdo, 'recipe_runs', 'completion_key'),
    'starter-kit source column installed' => $columnExists($pdo, 'shopping_list_items', 'source_type'),
    'recipe completion uniqueness installed' => $indexExists($pdo, 'recipe_runs', 'uq_recipe_runs_household_completion'),
    'recipe inventory lookup index installed' => $indexExists($pdo, 'recipe_ingredients', 'idx_recipe_ingredients_inventory'),
    'starter-kit activation state index installed' => $indexExists($pdo, 'starter_kit_activations', 'idx_kit_activations_state'),
];

$sourceType = $pdo->query("SHOW COLUMNS FROM shopping_list_items LIKE 'source_type'")->fetch();
$checks['starter-kit source enum installed'] = is_array($sourceType)
    && str_contains((string)$sourceType['Type'], "'starter_kit'");

$eventType = $pdo->query("SHOW COLUMNS FROM authentication_events LIKE 'event_type'")->fetch();
$checks['password failure event installed'] = is_array($eventType)
    && str_contains((string)$eventType['Type'], "'password_change_failure'");

$foreignKeyCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()"
)->fetchColumn();
$checks['foreign-key protections installed'] = $foreignKeyCount >= 20;

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($missingTables !== []) {
    fwrite(STDERR, 'Missing tables: ' . implode(', ', $missingTables) . PHP_EOL);
}

if ($failed !== []) {
    fwrite(STDERR, 'Database integration audit failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Database-backed migration audit passed.' . PHP_EOL;
