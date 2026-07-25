<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/RecipeService.php');
$bootstrap = file_get_contents($root . '/app/bootstrap.php');
$migration = file_get_contents($root . '/database/phase4_hardening.sql');

$checks = [
    'meal date range validation' => str_contains($service, 'Meal date must fall inside the selected meal plan.'),
    'meal type allowlist' => str_contains($service, 'MEAL_TYPES'),
    'storage method allowlist' => str_contains($service, 'STORAGE_METHODS'),
    'ingredient unit compatibility' => str_contains($service, 'Recipe and inventory units must match'),
    'inventory rows locked during completion' => str_contains($service, 'FOR UPDATE'),
    'atomic guarded inventory decrement' => str_contains($service, 'current_quantity >= ?') && str_contains($service, 'rowCount() !== 1'),
    'prepared food location ownership' => str_contains($service, 'assertLocation'),
    'intended member ownership' => str_contains($service, 'assertMembers'),
    'recipe completion idempotency' => str_contains($service, 'completion_key') && str_contains($migration, 'uq_recipe_runs_household_completion'),
    'server-issued completion nonce' => str_contains($bootstrap, 'recipe_completion_key'),
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
    fwrite(STDERR, 'Recipe workflow audit failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Recipe workflow static audit passed.' . PHP_EOL;
