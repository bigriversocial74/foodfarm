<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';

    $requiredTables = [
        'recipes',
        'recipe_ingredients',
        'recipe_steps',
        'recipe_runs',
        'recipe_run_ingredients',
        'meal_plans',
        'meal_plan_items',
        'meal_plan_members',
        'prepared_food_batches',
        'inventory_items',
        'food_ledger_events',
    ];

    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $tables[] = (string)$table;
    }
    $missingTables = array_values(array_diff($requiredTables, $tables));

    $recipeColumns = array_map(
        static fn(array $column): string => (string)$column['Field'],
        $pdo->query('SHOW COLUMNS FROM recipes')->fetchAll()
    );
    $mealColumns = array_map(
        static fn(array $column): string => (string)$column['Field'],
        $pdo->query('SHOW COLUMNS FROM meal_plan_items')->fetchAll()
    );

    $missingRecipeColumns = array_values(array_diff(['yield_quantity', 'yield_unit'], $recipeColumns));
    $missingMealColumns = array_values(array_diff(['status'], $mealColumns));

    $householdId = $householdContext->id();
    $counts = [];
    foreach (['recipes', 'meal_plans', 'prepared_food_batches'] as $table) {
        if (in_array($table, $tables, true)) {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE household_id = ?");
            $statement->execute([$householdId]);
            $counts[$table] = (int)$statement->fetchColumn();
        }
    }

    $ok = $missingTables === [] && $missingRecipeColumns === [] && $missingMealColumns === [];
    echo json_encode([
        'ok' => $ok,
        'connected' => true,
        'household_id' => $householdId,
        'tables' => ['required' => $requiredTables, 'missing' => $missingTables],
        'columns' => [
            'recipes_missing' => $missingRecipeColumns,
            'meal_plan_items_missing' => $missingMealColumns,
        ],
        'counts' => $counts,
        'timestamp' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'connected' => false,
        'error' => $exception->getMessage(),
        'timestamp' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
