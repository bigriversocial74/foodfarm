<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

    $requiredTables = [
        'household_forecast_settings',
        'forecast_snapshots',
        'forecast_item_projections',
        'self_sufficiency_metrics',
        'forecast_recommendations',
        'seasonal_plan_entries',
        'forecast_lifecycle_events',
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
        throw new RuntimeException('Phase 8 database objects are incomplete.');
    }

    $checks = [
        'projection_snapshots' => "SELECT COUNT(*)
            FROM forecast_item_projections fp
            LEFT JOIN forecast_snapshots fs
              ON fs.id = fp.snapshot_id AND fs.household_id = fp.household_id
            WHERE fs.id IS NULL",
        'recommendation_snapshots' => "SELECT COUNT(*)
            FROM forecast_recommendations fr
            LEFT JOIN forecast_snapshots fs
              ON fs.id = fr.snapshot_id AND fs.household_id = fr.household_id
            WHERE fs.id IS NULL",
        'recommendation_projections' => "SELECT COUNT(*)
            FROM forecast_recommendations fr
            LEFT JOIN forecast_item_projections fp
              ON fp.id = fr.projection_id AND fp.household_id = fr.household_id
            WHERE fr.projection_id IS NOT NULL AND fp.id IS NULL",
        'seasonal_snapshots' => "SELECT COUNT(*)
            FROM seasonal_plan_entries spe
            LEFT JOIN forecast_snapshots fs
              ON fs.id = spe.snapshot_id AND fs.household_id = spe.household_id
            WHERE spe.snapshot_id IS NOT NULL AND fs.id IS NULL",
        'event_recommendations' => "SELECT COUNT(*)
            FROM forecast_lifecycle_events fle
            LEFT JOIN forecast_recommendations fr
              ON fr.id = fle.recommendation_id AND fr.household_id = fle.household_id
            WHERE fle.recommendation_id IS NOT NULL AND fr.id IS NULL",
        'event_seasonal_entries' => "SELECT COUNT(*)
            FROM forecast_lifecycle_events fle
            LEFT JOIN seasonal_plan_entries spe
              ON spe.id = fle.seasonal_entry_id AND spe.household_id = fle.household_id
            WHERE fle.seasonal_entry_id IS NOT NULL AND spe.id IS NULL",
        'invalid_scores' => "SELECT COUNT(*) FROM forecast_snapshots
            WHERE status = 'completed' AND (
                inventory_coverage_score < 0 OR inventory_coverage_score > 100 OR
                self_sufficiency_score < 0 OR self_sufficiency_score > 100 OR
                seasonal_readiness_score < 0 OR seasonal_readiness_score > 100 OR
                resilience_score < 0 OR resilience_score > 100
            )",
        'stale_running_snapshots' => "SELECT COUNT(*) FROM forecast_snapshots
            WHERE status = 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)",
        'accepted_without_task' => "SELECT COUNT(*) FROM forecast_recommendations
            WHERE status = 'accepted' AND related_task_id IS NULL",
    ];

    $results = [];
    foreach ($checks as $name => $sql) {
        $count = (int)$pdo->query($sql)->fetchColumn();
        $results[$name] = $count;
        if ($count !== 0) {
            throw new RuntimeException('Phase 8 relational or lifecycle integrity check failed.');
        }
    }

    $counts = [];
    foreach ($requiredTables as $table) {
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    echo json_encode([
        'ok' => true,
        'phase' => 8,
        'database' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'tables' => $counts,
        'checks' => $results,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    Homestead\health_error($exception, is_array($config ?? null) ? $config : []);
}
