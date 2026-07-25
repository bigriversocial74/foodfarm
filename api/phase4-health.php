<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

    $requiredTables = ['recipes','recipe_ingredients','recipe_steps','recipe_runs','recipe_run_ingredients','meal_plans','meal_plan_items','meal_plan_members','prepared_food_batches','inventory_items','food_ledger_events'];
    $tables = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $missingTables = array_values(array_diff($requiredTables, $tables));
    $recipeColumns = $missingTables === [] ? array_column($pdo->query('SHOW COLUMNS FROM recipes')->fetchAll(), 'Field') : [];
    $mealColumns = $missingTables === [] ? array_column($pdo->query('SHOW COLUMNS FROM meal_plan_items')->fetchAll(), 'Field') : [];
    $runColumns = $missingTables === [] ? array_column($pdo->query('SHOW COLUMNS FROM recipe_runs')->fetchAll(), 'Field') : [];

    $missingRecipeColumns = array_values(array_diff(['yield_quantity','yield_unit'], $recipeColumns));
    $missingMealColumns = array_values(array_diff(['status'], $mealColumns));
    $missingRunColumns = array_values(array_diff(['completion_key'], $runColumns));

    echo json_encode([
        'ok' => $missingTables === [] && $missingRecipeColumns === [] && $missingMealColumns === [] && $missingRunColumns === [],
        'connected' => true,
        'tables' => ['required' => $requiredTables, 'missing' => $missingTables],
        'columns' => [
            'recipes_missing' => $missingRecipeColumns,
            'meal_plan_items_missing' => $missingMealColumns,
            'recipe_runs_missing' => $missingRunColumns,
        ],
        'timestamp' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    Homestead\health_error($exception, $config ?? []);
}
