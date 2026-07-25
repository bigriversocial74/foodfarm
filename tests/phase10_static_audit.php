<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'service' => $root . '/app/NutritionService.php',
    'profile' => $root . '/app/NutritionProfileTrait.php',
    'snapshot' => $root . '/app/NutritionSnapshotTrait.php',
    'recommendation' => $root . '/app/NutritionRecommendationTrait.php',
    'query' => $root . '/app/NutritionQueryTrait.php',
    'support' => $root . '/app/NutritionSupportTrait.php',
    'page' => $root . '/phase10.php',
    'health' => $root . '/api/phase10-health.php',
    'migration' => $root . '/database/phase10_nutrition_dietary_wellness.sql',
    'workflow' => $root . '/.github/workflows/phase10-certification.yml',
];
$failures = [];
foreach ($files as $label => $path) {
    if (!is_file($path)) {
        $failures[] = "Missing Phase 10 {$label} file: {$path}";
    }
}

if ($failures === []) {
    $service = file_get_contents($files['service']);
    $profile = file_get_contents($files['profile']);
    $snapshot = file_get_contents($files['snapshot']);
    $recommendation = file_get_contents($files['recommendation']);
    $query = file_get_contents($files['query']);
    $support = file_get_contents($files['support']);
    $page = file_get_contents($files['page']);
    $health = file_get_contents($files['health']);
    $migration = file_get_contents($files['migration']);
    $workflow = file_get_contents($files['workflow']);

    foreach ([
        'final class NutritionService',
        'NutritionProfileTrait',
        'NutritionSnapshotTrait',
        'NutritionRecommendationTrait',
        'NutritionQueryTrait',
        'NutritionSupportTrait',
    ] as $needle) {
        if (!str_contains($service, $needle)) {
            $failures[] = "Nutrition service is missing component: {$needle}";
        }
    }

    foreach ([
        'saveSettings',
        'saveMemberProfile',
        'saveMemberAllergenRule',
        'saveInventoryNutrition',
        'saveInventoryAllergenTag',
        'household_id',
    ] as $needle) {
        if (!str_contains($profile, $needle)) {
            $failures[] = "Nutrition profile workflow is missing: {$needle}";
        }
    }

    foreach ([
        'calculateRecipeNutrition',
        'runMealAssessment',
        'sourceWatermark',
        'lockHousehold',
        'recipe_nutrition_snapshot_lines',
        'member_nutrition_assessment_lines',
        'generateMemberNutritionRecommendations',
    ] as $needle) {
        if (!str_contains($snapshot, $needle)) {
            $failures[] = "Nutrition snapshot workflow is missing: {$needle}";
        }
    }

    foreach ([
        'acceptRecommendation',
        'transitionNutritionRecommendation',
        'task_automation_metadata',
        'nutrition_recommendation',
        'WHERE id = ? AND household_id = ? FOR UPDATE',
    ] as $needle) {
        if (!str_contains($recommendation, $needle)) {
            $failures[] = "Nutrition recommendation workflow is missing: {$needle}";
        }
    }

    foreach ([
        'assertActiveMember',
        'lockHousehold',
        'assertInventoryItem',
        'assertRecipe',
        'assertMealPlan',
        'recordNutritionEvent',
        'sourceWatermark',
    ] as $needle) {
        if (!str_contains($support, $needle)) {
            $failures[] = "Nutrition support layer is missing: {$needle}";
        }
    }

    foreach ([
        'dashboardData',
        'nutrition_recommendations',
        'recipe_nutrition_snapshots',
        'meal_nutrition_assessments',
    ] as $needle) {
        if (!str_contains($query, $needle)) {
            $failures[] = "Nutrition query layer is missing: {$needle}";
        }
    }

    foreach ([
        'verify_csrf',
        'phase10_action_key',
        'hash_equals',
        'nutrition.view',
        'nutrition.manage',
        'calculate_recipe_nutrition',
        'run_meal_assessment',
        'Nutrition, Dietary Planning & Wellness',
        'not diagnosis, treatment, or medical advice',
    ] as $needle) {
        if (!str_contains($page, $needle)) {
            $failures[] = "Phase 10 page is missing required control: {$needle}";
        }
    }

    $requiredTables = [
        'household_nutrition_settings',
        'member_nutrition_profiles',
        'member_allergen_rules',
        'inventory_nutrition_profiles',
        'inventory_allergen_tags',
        'recipe_nutrition_snapshots',
        'recipe_nutrition_snapshot_lines',
        'meal_nutrition_assessments',
        'member_nutrition_assessment_lines',
        'nutrition_recommendations',
        'nutrition_lifecycle_events',
    ];
    foreach ($requiredTables as $table) {
        if (!str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table)) {
            $failures[] = "Phase 10 migration is missing replay-safe table {$table}.";
        }
        if (!str_contains($health, "'{$table}'")) {
            $failures[] = "Phase 10 health endpoint does not validate {$table}.";
        }
    }

    foreach ([
        'uq_member_allergen_rule',
        'uq_inventory_allergen_tag',
        'uq_recipe_nutrition_calculation',
        'uq_meal_nutrition_run',
        'uq_nutrition_recommendation_generation',
    ] as $index) {
        if (!str_contains($migration, $index)) {
            $failures[] = "Phase 10 migration is missing uniqueness control {$index}.";
        }
    }

    foreach ([
        'mysql:8.0',
        'mariadb:10.11',
        'phase10_nutrition_dietary_wellness.sql',
        'phase10_integration.php',
        'phase10_http_smoke.sh',
    ] as $needle) {
        if (!str_contains($workflow, $needle)) {
            $failures[] = "Phase 10 certification workflow is missing {$needle}.";
        }
    }

    foreach ([
        'SELECT * FROM nutrition_recommendations WHERE id = ? FOR UPDATE',
        'UPDATE nutrition_recommendations SET status = ? WHERE id = ?',
        'SELECT id, name, unit, status FROM inventory_items WHERE id = ? LIMIT 1',
    ] as $unsafeMutation) {
        if (str_contains($service . $profile . $snapshot . $recommendation . $support, $unsafeMutation)) {
            $failures[] = 'A Phase 10 mutation is missing household scoping: ' . $unsafeMutation;
        }
    }

    foreach (['accepted_without_task', 'invalid_assessment_metrics', 'invalid_member_scores', 'stale_running_assessments'] as $needle) {
        if (!str_contains($health, $needle)) {
            $failures[] = "Phase 10 health diagnostics are missing {$needle}.";
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 10 static audit passed.\n";