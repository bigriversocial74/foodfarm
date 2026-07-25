<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

    $tables = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $requiredTables = [
        'garden_zones', 'plantings', 'garden_readings', 'harvests',
        'preservation_batches', 'preservation_batch_inputs', 'inventory_items', 'food_ledger_events',
    ];
    $missingTables = array_values(array_diff($requiredTables, $tables));

    $columns = static function (PDO $pdo, string $table): array {
        return array_column($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(), 'Field');
    };
    $indexExists = static function (PDO $pdo, string $table, string $index): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $statement->execute([$table, $index]);
        return (int)$statement->fetchColumn() > 0;
    };

    $harvestColumns = in_array('harvests', $tables, true) ? $columns($pdo, 'harvests') : [];
    $preservationColumns = in_array('preservation_batches', $tables, true) ? $columns($pdo, 'preservation_batches') : [];

    $crossHouseholdHarvestLinks = 0;
    $crossHouseholdPreservationOutputs = 0;
    $negativeInventory = 0;
    if ($missingTables === []) {
        $crossHouseholdHarvestLinks = (int)$pdo->query(
            'SELECT COUNT(*)
             FROM harvests h
             JOIN plantings p ON p.id = h.planting_id
             JOIN garden_zones z ON z.id = p.garden_zone_id
             JOIN inventory_items i ON i.id = h.inventory_item_id
             WHERE i.household_id <> z.household_id'
        )->fetchColumn();
        $crossHouseholdPreservationOutputs = (int)$pdo->query(
            'SELECT COUNT(*)
             FROM preservation_batches pb
             JOIN inventory_items i ON i.id = pb.output_inventory_item_id
             WHERE i.household_id <> pb.household_id'
        )->fetchColumn();
        $negativeInventory = (int)$pdo->query('SELECT COUNT(*) FROM inventory_items WHERE current_quantity < 0')->fetchColumn();
    }

    $checks = [
        'tables' => $missingTables === [],
        'harvest_inventory_column' => in_array('inventory_item_id', $harvestColumns, true),
        'harvest_preservation_column' => in_array('preservation_batch_id', $harvestColumns, true),
        'harvest_action_key' => in_array('action_key', $harvestColumns, true)
            && $indexExists($pdo, 'harvests', 'uq_harvest_action_key'),
        'preservation_output_column' => in_array('output_inventory_item_id', $preservationColumns, true),
        'preservation_action_key' => in_array('action_key', $preservationColumns, true)
            && $indexExists($pdo, 'preservation_batches', 'uq_preservation_action_key'),
        'harvest_household_integrity' => $crossHouseholdHarvestLinks === 0,
        'preservation_household_integrity' => $crossHouseholdPreservationOutputs === 0,
        'inventory_nonnegative' => $negativeInventory === 0,
    ];

    echo json_encode([
        'ok' => !in_array(false, $checks, true),
        'connected' => true,
        'checks' => $checks,
        'missing_tables' => $missingTables,
        'timestamp' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    Homestead\health_error($exception, $config ?? []);
}
