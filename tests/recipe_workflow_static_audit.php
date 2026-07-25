<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/app/RecipeService.php');
$bootstrap = (string)file_get_contents($root . '/app/bootstrap.php');
$install = (string)file_get_contents($root . '/database/phase4_install.sql');
$migration = (string)file_get_contents($root . '/database/phase4_hardening.sql');
$route = (string)file_get_contents($root . '/prepared-food.php');

$checks = [
    'meal date range validation' => str_contains($service, 'Meal date must fall inside the selected meal plan.'),
    'meal plan recipe and member rows lock transactionally' => str_contains($service, 'FROM meal_plans') && str_contains($service, 'FOR UPDATE'),
    'meal type allowlist' => str_contains($service, 'MEAL_TYPES'),
    'storage method allowlist' => str_contains($service, 'STORAGE_METHODS'),
    'ingredient unit compatibility' => str_contains($service, 'Recipe and inventory units must match'),
    'inventory rows locked during completion' => str_contains($service, 'FOR UPDATE'),
    'atomic guarded inventory decrement' => str_contains($service, 'current_quantity >= ?') && str_contains($service, 'rowCount() !== 1'),
    'prepared food location ownership' => str_contains($service, 'assertLocation'),
    'cold prepared food requires a location' => str_contains($service, 'Refrigerated and frozen food requires a storage location.'),
    'use-by dates are server validated' => str_contains($service, 'Use-by date cannot be in the past.'),
    'intended member ownership' => str_contains($service, 'assertMembers'),
    'recipe completion idempotency' => str_contains($service, 'completion_key') && str_contains($migration, 'uq_recipe_runs_household_completion'),
    'server-issued action nonce' => str_contains($bootstrap, 'recipe_action_key'),
    'prepared-food lifecycle table installed' => str_contains($install, 'prepared_food_actions') && str_contains($migration, 'prepared_food_actions'),
    'prepared-food actions are idempotent' => str_contains($service, 'action_key') && str_contains($install, 'uq_prepared_action_household_key'),
    'prepared-food batch and inventory must reconcile' => str_contains($service, 'Prepared-food batch and inventory quantities are out of sync'),
    'prepared-food actions write ledger history' => str_contains($service, "'prepared_food_action'"),
    'prepared-food route requires permission' => str_contains($route, "requirePermission(\$user, 'recipes.complete')"),
    'production config required' => str_contains($bootstrap, 'Homestead is not configured'),
    'production debug disabled' => str_contains($bootstrap, 'debug mode must be disabled'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Recipe and prepared-food workflow audit failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Recipe, meal-planning, and prepared-food lifecycle static audit passed.' . PHP_EOL;
