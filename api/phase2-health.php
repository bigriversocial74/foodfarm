<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';

    $requiredTables = [
        'households',
        'household_members',
        'storage_locations',
        'inventory_categories',
        'inventory_items',
        'food_ledger_events',
    ];

    $tables = [];
    $statement = $pdo->query('SHOW TABLES');
    while (($table = $statement->fetchColumn()) !== false) {
        $tables[] = (string)$table;
    }

    $missing = array_values(array_diff($requiredTables, $tables));
    $columns = $pdo->query("SHOW COLUMNS FROM household_members")->fetchAll();
    $columnNames = array_map(static fn(array $column): string => (string)$column['Field'], $columns);
    $requiredWellnessColumns = ['height_value', 'height_unit', 'weight_value', 'weight_unit', 'activity_level', 'wellness_visibility', 'wellness_updated_at'];
    $missingColumns = array_values(array_diff($requiredWellnessColumns, $columnNames));

    echo json_encode([
        'ok' => $missing === [] && $missingColumns === [],
        'connected' => true,
        'household_id' => $householdContext->id(),
        'tables' => [
            'required' => $requiredTables,
            'missing' => $missing,
        ],
        'wellness_columns' => [
            'required' => $requiredWellnessColumns,
            'missing' => $missingColumns,
        ],
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
