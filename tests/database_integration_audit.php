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
    'starter_kit_items', 'starter_kit_recipes', 'starter_kit_recipe_snapshots',
    'starter_kit_tasks', 'starter_kit_orders', 'starter_kit_activations',
    'starter_kit_activation_items', 'shopping_lists', 'shopping_list_items',
    'household_tasks',
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
    'authentication version installed' => $columnExists($pdo, 'users', 'auth_version'),
    'recipe completion key installed' => $columnExists($pdo, 'recipe_runs', 'completion_key'),
    'shopping status installed' => $columnExists($pdo, 'shopping_list_items', 'status'),
    'starter-kit source column installed' => $columnExists($pdo, 'shopping_list_items', 'source_type'),
    'recipe snapshot hash installed' => $columnExists($pdo, 'starter_kit_recipe_snapshots', 'snapshot_hash'),
    'recipe completion uniqueness installed' => $indexExists($pdo, 'recipe_runs', 'uq_recipe_runs_household_completion'),
    'recipe inventory lookup index installed' => $indexExists($pdo, 'recipe_ingredients', 'idx_recipe_ingredients_inventory'),
    'meal-plan date lookup index installed' => $indexExists($pdo, 'meal_plan_items', 'idx_meal_plan_items_plan_date'),
    'starter-kit recipe snapshot index installed' => $indexExists($pdo, 'starter_kit_recipe_snapshots', 'idx_kit_recipe_snapshots_version'),
    'starter-kit activation state index installed' => $indexExists($pdo, 'starter_kit_activations', 'idx_kit_activations_state'),
    'login throttle index installed' => $indexExists($pdo, 'authentication_events', 'idx_auth_event_ip_type_time'),
    'account throttle index installed' => $indexExists($pdo, 'authentication_events', 'idx_auth_event_user_type_time'),
];

$sourceType = $pdo->query("SHOW COLUMNS FROM shopping_list_items LIKE 'source_type'")->fetch();
$checks['starter-kit source enum installed'] = is_array($sourceType)
    && str_contains((string)$sourceType['Type'], "'starter_kit'");

$eventType = $pdo->query("SHOW COLUMNS FROM authentication_events LIKE 'event_type'")->fetch();
$checks['password failure event installed'] = is_array($eventType)
    && str_contains((string)$eventType['Type'], "'password_change_failure'")
    && str_contains((string)$eventType['Type'], "'invitation_revoked'");

$foreignKeyCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()"
)->fetchColumn();
$checks['foreign-key protections installed'] = $foreignKeyCount >= 22;

$checks['no seeded application users'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
$checks['no seeded private households'] = (int)$pdo->query('SELECT COUNT(*) FROM households')->fetchColumn() === 0;
$checks['no seeded household members'] = (int)$pdo->query('SELECT COUNT(*) FROM household_members')->fetchColumn() === 0;
$checks['no seeded starter kits'] = (int)$pdo->query('SELECT COUNT(*) FROM starter_kits')->fetchColumn() === 0;
$checks['no seeded starter-kit versions'] = (int)$pdo->query('SELECT COUNT(*) FROM starter_kit_versions')->fetchColumn() === 0;
$checks['global pantry categories installed'] = (int)$pdo->query('SELECT COUNT(*) FROM inventory_categories WHERE household_id IS NULL')->fetchColumn() >= 4;

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
