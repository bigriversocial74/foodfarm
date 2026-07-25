<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

    $requiredTables = ['starter_kits','starter_kit_versions','starter_kit_items','starter_kit_recipes','starter_kit_tasks','starter_kit_orders','starter_kit_activations','starter_kit_activation_items'];
    $tables = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $missingTables = array_values(array_diff($requiredTables, $tables));
    $shoppingColumns = in_array('shopping_list_items', $tables, true) ? array_column($pdo->query('SHOW COLUMNS FROM shopping_list_items')->fetchAll(), 'Field') : [];
    $userColumns = in_array('users', $tables, true) ? array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field') : [];
    $sourceType = in_array('shopping_list_items', $tables, true) ? $pdo->query("SHOW COLUMNS FROM shopping_list_items LIKE 'source_type'")->fetch() : false;
    $sourceSupportsStarterKits = is_array($sourceType) && str_contains((string)$sourceType['Type'], "'starter_kit'");
    $platformAdminCount = in_array('is_platform_admin', $userColumns, true)
        ? (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_platform_admin = 1 AND status = \'active\'')->fetchColumn()
        : 0;

    $checks = [
        'tables' => $missingTables === [],
        'shopping_status' => in_array('status', $shoppingColumns, true),
        'shopping_notes' => in_array('notes', $shoppingColumns, true),
        'starter_kit_source_type' => $sourceSupportsStarterKits,
        'platform_admin_column' => in_array('is_platform_admin', $userColumns, true),
        'platform_admin_exists' => $platformAdminCount > 0,
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
