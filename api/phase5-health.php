<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';

    $requiredTables = [
        'starter_kits',
        'starter_kit_versions',
        'starter_kit_items',
        'starter_kit_recipes',
        'starter_kit_tasks',
        'starter_kit_orders',
        'starter_kit_activations',
        'starter_kit_activation_items',
    ];

    $tables = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($requiredTables, $tables));

    $shoppingColumns = array_map(
        static fn(array $column): string => (string)$column['Field'],
        $pdo->query('SHOW COLUMNS FROM shopping_list_items')->fetchAll()
    );
    $missingShoppingColumns = array_values(array_diff(['status', 'notes'], $shoppingColumns));

    $counts = [];
    foreach (['starter_kits', 'starter_kit_versions', 'starter_kit_items', 'starter_kit_orders', 'starter_kit_activations'] as $table) {
        $counts[$table] = in_array($table, $tables, true) ? (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() : 0;
    }

    echo json_encode([
        'ok' => $missing === [] && $missingShoppingColumns === [],
        'connected' => true,
        'tables' => ['required' => $requiredTables, 'missing' => $missing],
        'shopping_columns_missing' => $missingShoppingColumns,
        'counts' => $counts,
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
