<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

    $requiredTables = [
        'household_finance_settings',
        'household_suppliers',
        'food_purchase_records',
        'inventory_cost_basis',
        'food_waste_events',
        'recipe_cost_snapshots',
        'recipe_cost_snapshot_lines',
        'household_finance_snapshots',
        'finance_recommendations',
        'finance_lifecycle_events',
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
        throw new RuntimeException('Phase 9 database objects are incomplete.');
    }

    $checks = [
        'purchase_households' => "SELECT COUNT(*)
            FROM food_purchase_records fpr
            LEFT JOIN inventory_items i
              ON i.id = fpr.inventory_item_id AND i.household_id = fpr.household_id
            WHERE i.id IS NULL",
        'waste_inventory_households' => "SELECT COUNT(*)
            FROM food_waste_events fwe
            LEFT JOIN inventory_items i
              ON i.id = fwe.inventory_item_id AND i.household_id = fwe.household_id
            WHERE fwe.inventory_item_id IS NOT NULL AND i.id IS NULL",
        'waste_prepared_households' => "SELECT COUNT(*)
            FROM food_waste_events fwe
            LEFT JOIN prepared_food_batches pfb
              ON pfb.id = fwe.prepared_food_batch_id AND pfb.household_id = fwe.household_id
            WHERE fwe.prepared_food_batch_id IS NOT NULL AND pfb.id IS NULL",
        'invalid_waste_sources' => "SELECT COUNT(*) FROM food_waste_events
            WHERE (inventory_item_id IS NULL AND prepared_food_batch_id IS NULL)
               OR (inventory_item_id IS NOT NULL AND prepared_food_batch_id IS NOT NULL)",
        'cost_basis_households' => "SELECT COUNT(*)
            FROM inventory_cost_basis icb
            LEFT JOIN inventory_items i
              ON i.id = icb.inventory_item_id AND i.household_id = icb.household_id
            WHERE i.id IS NULL",
        'recipe_cost_households' => "SELECT COUNT(*)
            FROM recipe_cost_snapshots rcs
            LEFT JOIN recipes r ON r.id = rcs.recipe_id AND r.household_id = rcs.household_id
            WHERE r.id IS NULL",
        'recipe_cost_lines' => "SELECT COUNT(*)
            FROM recipe_cost_snapshot_lines rcsl
            LEFT JOIN recipe_cost_snapshots rcs
              ON rcs.id = rcsl.recipe_cost_snapshot_id AND rcs.household_id = rcsl.household_id
            WHERE rcs.id IS NULL",
        'recommendation_snapshots' => "SELECT COUNT(*)
            FROM finance_recommendations fr
            LEFT JOIN household_finance_snapshots hfs
              ON hfs.id = fr.snapshot_id AND hfs.household_id = fr.household_id
            WHERE hfs.id IS NULL",
        'accepted_without_task' => "SELECT COUNT(*) FROM finance_recommendations
            WHERE status = 'accepted' AND related_task_id IS NULL",
        'invalid_snapshot_metrics' => "SELECT COUNT(*) FROM household_finance_snapshots
            WHERE status = 'completed' AND (
                purchase_spend < 0 OR waste_value < 0 OR household_production_value < 0 OR
                preservation_value < 0 OR estimated_savings < 0 OR
                waste_percent < 0 OR waste_percent > 100 OR
                savings_rate_percent < 0 OR savings_rate_percent > 100
            )",
        'stale_running_snapshots' => "SELECT COUNT(*) FROM household_finance_snapshots
            WHERE status = 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)",
    ];

    $results = [];
    foreach ($checks as $name => $sql) {
        $count = (int)$pdo->query($sql)->fetchColumn();
        $results[$name] = $count;
        if ($count !== 0) {
            throw new RuntimeException('Phase 9 relational or lifecycle integrity check failed.');
        }
    }

    $counts = [];
    foreach ($requiredTables as $table) {
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    echo json_encode([
        'ok' => true,
        'phase' => 9,
        'database' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'tables' => $counts,
        'checks' => $results,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    Homestead\health_error($exception, is_array($config ?? null) ? $config : []);
}