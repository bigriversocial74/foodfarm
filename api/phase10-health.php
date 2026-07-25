<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

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
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $tableQuery = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
    );
    $tableQuery->execute($requiredTables);
    $present = array_map('strval', $tableQuery->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($requiredTables, $present));
    if ($missing !== []) {
        throw new RuntimeException('Phase 10 database objects are incomplete.');
    }

    $checks = [
        'member_profile_households' => "SELECT COUNT(*)
            FROM member_nutrition_profiles mnp
            LEFT JOIN household_members hm
              ON hm.id = mnp.household_member_id AND hm.household_id = mnp.household_id
            WHERE hm.id IS NULL",
        'member_allergen_households' => "SELECT COUNT(*)
            FROM member_allergen_rules mar
            LEFT JOIN household_members hm
              ON hm.id = mar.household_member_id AND hm.household_id = mar.household_id
            WHERE hm.id IS NULL",
        'inventory_profile_households' => "SELECT COUNT(*)
            FROM inventory_nutrition_profiles inp
            LEFT JOIN inventory_items ii
              ON ii.id = inp.inventory_item_id AND ii.household_id = inp.household_id
            WHERE ii.id IS NULL",
        'inventory_allergen_households' => "SELECT COUNT(*)
            FROM inventory_allergen_tags iat
            LEFT JOIN inventory_items ii
              ON ii.id = iat.inventory_item_id AND ii.household_id = iat.household_id
            WHERE ii.id IS NULL",
        'recipe_snapshot_households' => "SELECT COUNT(*)
            FROM recipe_nutrition_snapshots rns
            LEFT JOIN recipes r ON r.id = rns.recipe_id AND r.household_id = rns.household_id
            WHERE r.id IS NULL",
        'recipe_snapshot_lines' => "SELECT COUNT(*)
            FROM recipe_nutrition_snapshot_lines rnsl
            LEFT JOIN recipe_nutrition_snapshots rns
              ON rns.id = rnsl.recipe_nutrition_snapshot_id AND rns.household_id = rnsl.household_id
            WHERE rns.id IS NULL",
        'assessment_plan_households' => "SELECT COUNT(*)
            FROM meal_nutrition_assessments mna
            LEFT JOIN meal_plans mp ON mp.id = mna.meal_plan_id AND mp.household_id = mna.household_id
            WHERE mp.id IS NULL",
        'assessment_member_households' => "SELECT COUNT(*)
            FROM member_nutrition_assessment_lines mnal
            LEFT JOIN household_members hm
              ON hm.id = mnal.household_member_id AND hm.household_id = mnal.household_id
            WHERE hm.id IS NULL",
        'recommendation_assessments' => "SELECT COUNT(*)
            FROM nutrition_recommendations nr
            LEFT JOIN meal_nutrition_assessments mna
              ON mna.id = nr.assessment_id AND mna.household_id = nr.household_id
            WHERE mna.id IS NULL",
        'accepted_without_task' => "SELECT COUNT(*) FROM nutrition_recommendations
            WHERE status = 'accepted' AND related_task_id IS NULL",
        'invalid_assessment_metrics' => "SELECT COUNT(*) FROM meal_nutrition_assessments
            WHERE status = 'completed' AND (
                household_balance_score < 0 OR household_balance_score > 100 OR
                data_completeness_percent < 0 OR data_completeness_percent > 100 OR
                assessed_meal_count > planned_meal_count
            )",
        'invalid_member_scores' => "SELECT COUNT(*) FROM member_nutrition_assessment_lines
            WHERE balance_score < 0 OR balance_score > 100 OR assessed_meal_count > planned_meal_count",
        'stale_running_assessments' => "SELECT COUNT(*) FROM meal_nutrition_assessments
            WHERE status = 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)",
    ];

    $results = [];
    foreach ($checks as $name => $sql) {
        $count = (int)$pdo->query($sql)->fetchColumn();
        $results[$name] = $count;
        if ($count !== 0) {
            throw new RuntimeException('Phase 10 relational or lifecycle integrity check failed.');
        }
    }

    $counts = [];
    foreach ($requiredTables as $table) {
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    echo json_encode([
        'ok' => true,
        'phase' => 10,
        'database' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'tables' => $counts,
        'checks' => $results,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    Homestead\health_error($exception, is_array($config ?? null) ? $config : []);
}