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
    'prepared_food_batches', 'prepared_food_actions',
    'starter_kits', 'starter_kit_versions', 'starter_kit_items',
    'starter_kit_recipes', 'starter_kit_recipe_snapshots', 'starter_kit_tasks',
    'starter_kit_orders', 'starter_kit_activations', 'starter_kit_activation_items',
    'shopping_lists', 'shopping_list_items', 'household_tasks',
    'preservation_batch_inputs', 'recurring_task_templates', 'planning_cycles',
    'task_automation_metadata', 'planning_suggestions', 'task_lifecycle_events',
    'household_forecast_settings', 'forecast_snapshots', 'forecast_item_projections',
    'self_sufficiency_metrics', 'forecast_recommendations', 'seasonal_plan_entries',
    'forecast_lifecycle_events', 'household_finance_settings', 'household_suppliers',
    'food_purchase_records', 'inventory_cost_basis', 'food_waste_events',
    'recipe_cost_snapshots', 'recipe_cost_snapshot_lines',
    'household_finance_snapshots', 'finance_recommendations', 'finance_lifecycle_events',
    'household_nutrition_settings', 'member_nutrition_profiles', 'member_allergen_rules',
    'inventory_nutrition_profiles', 'inventory_allergen_tags',
    'recipe_nutrition_snapshots', 'recipe_nutrition_snapshot_lines',
    'meal_nutrition_assessments', 'member_nutrition_assessment_lines',
    'nutrition_recommendations', 'nutrition_lifecycle_events',
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
    'harvest inventory provenance installed' => $columnExists($pdo, 'harvests', 'inventory_item_id'),
    'preservation output provenance installed' => $columnExists($pdo, 'preservation_batches', 'output_inventory_item_id'),
    'recipe completion uniqueness installed' => $indexExists($pdo, 'recipe_runs', 'uq_recipe_runs_household_completion'),
    'recipe inventory lookup index installed' => $indexExists($pdo, 'recipe_ingredients', 'idx_recipe_ingredients_inventory'),
    'meal-plan date lookup index installed' => $indexExists($pdo, 'meal_plan_items', 'idx_meal_plan_items_plan_date'),
    'starter-kit recipe snapshot index installed' => $indexExists($pdo, 'starter_kit_recipe_snapshots', 'idx_kit_recipe_snapshots_version'),
    'starter-kit activation state index installed' => $indexExists($pdo, 'starter_kit_activations', 'idx_kit_activations_state'),
    'login throttle index installed' => $indexExists($pdo, 'authentication_events', 'idx_auth_event_ip_type_time'),
    'account throttle index installed' => $indexExists($pdo, 'authentication_events', 'idx_auth_event_user_type_time'),
    'Phase 6 action uniqueness installed' => $indexExists($pdo, 'harvests', 'uq_harvest_action_key'),
    'Phase 7 generation uniqueness installed' => $indexExists($pdo, 'task_automation_metadata', 'uq_task_meta_generation'),
    'Phase 8 forecast uniqueness installed' => $indexExists($pdo, 'forecast_snapshots', 'uq_forecast_snapshots_run'),
    'Phase 9 purchase uniqueness installed' => $indexExists($pdo, 'food_purchase_records', 'uq_purchase_household_action'),
    'Phase 9 waste uniqueness installed' => $indexExists($pdo, 'food_waste_events', 'uq_waste_household_action'),
    'Phase 9 snapshot uniqueness installed' => $indexExists($pdo, 'household_finance_snapshots', 'uq_finance_snapshot_run'),
    'Phase 10 member allergen uniqueness installed' => $indexExists($pdo, 'member_allergen_rules', 'uq_member_allergen_rule'),
    'Phase 10 ingredient allergen uniqueness installed' => $indexExists($pdo, 'inventory_allergen_tags', 'uq_inventory_allergen_tag'),
    'Phase 10 recipe snapshot uniqueness installed' => $indexExists($pdo, 'recipe_nutrition_snapshots', 'uq_recipe_nutrition_calculation'),
    'Phase 10 assessment uniqueness installed' => $indexExists($pdo, 'meal_nutrition_assessments', 'uq_meal_nutrition_run'),
    'Phase 10 recommendation uniqueness installed' => $indexExists($pdo, 'nutrition_recommendations', 'uq_nutrition_recommendation_generation'),
];

$sourceType = $pdo->query("SHOW COLUMNS FROM shopping_list_items LIKE 'source_type'")->fetch();
$checks['starter-kit source enum installed'] = is_array($sourceType)
    && str_contains((string)$sourceType['Type'], "'starter_kit'");

$eventType = $pdo->query("SHOW COLUMNS FROM authentication_events LIKE 'event_type'")->fetch();
$checks['password failure event installed'] = is_array($eventType)
    && str_contains((string)$eventType['Type'], "'password_change_failure'")
    && str_contains((string)$eventType['Type'], "'invitation_revoked'");

$foreignKeyCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'
)->fetchColumn();
$checks['foreign-key protections installed'] = $foreignKeyCount >= 90;

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