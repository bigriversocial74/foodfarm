<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

    $requiredTables = ['households','household_members','storage_locations','inventory_categories','inventory_items','food_ledger_events'];
    $tables = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($requiredTables, $tables));
    $columnNames = array_column($pdo->query('SHOW COLUMNS FROM household_members')->fetchAll(), 'Field');
    $requiredWellnessColumns = ['height_value','height_unit','weight_value','weight_unit','activity_level','wellness_visibility','wellness_updated_at'];
    $missingColumns = array_values(array_diff($requiredWellnessColumns, $columnNames));

    echo json_encode([
        'ok' => $missing === [] && $missingColumns === [],
        'connected' => true,
        'tables' => ['required' => $requiredTables, 'missing' => $missing],
        'wellness_columns' => ['required' => $requiredWellnessColumns, 'missing' => $missingColumns],
        'timestamp' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    Homestead\health_error($exception, $config ?? []);
}
