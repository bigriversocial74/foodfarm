<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

    $requiredTables = [
        'starter_kits', 'starter_kit_versions', 'starter_kit_items', 'starter_kit_recipes',
        'starter_kit_recipe_snapshots', 'starter_kit_tasks', 'starter_kit_orders',
        'starter_kit_activations', 'starter_kit_activation_items',
    ];
    $tables = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $missingTables = array_values(array_diff($requiredTables, $tables));
    $shoppingColumns = in_array('shopping_list_items', $tables, true)
        ? array_column($pdo->query('SHOW COLUMNS FROM shopping_list_items')->fetchAll(), 'Field')
        : [];
    $userColumns = in_array('users', $tables, true)
        ? array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field')
        : [];
    $sourceType = in_array('shopping_list_items', $tables, true)
        ? $pdo->query("SHOW COLUMNS FROM shopping_list_items LIKE 'source_type'")->fetch()
        : false;
    $sourceSupportsStarterKits = is_array($sourceType) && str_contains((string)$sourceType['Type'], "'starter_kit'");
    $platformAdminCount = in_array('is_platform_admin', $userColumns, true)
        ? (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_platform_admin = 1 AND status = 'active'")->fetchColumn()
        : 0;

    $indexExists = static function (PDO $pdo, string $table, string $index): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $statement->execute([$table, $index]);
        return (int)$statement->fetchColumn() > 0;
    };

    $missingPublishedSnapshots = 0;
    $invalidSnapshotHashes = 0;
    if (in_array('starter_kit_recipe_snapshots', $tables, true)) {
        $missingPublishedSnapshots = (int)$pdo->query(
            "SELECT COUNT(*)
             FROM starter_kit_recipes links
             JOIN starter_kit_versions versions ON versions.id = links.starter_kit_version_id
             LEFT JOIN starter_kit_recipe_snapshots snapshots
               ON snapshots.starter_kit_version_id = links.starter_kit_version_id
              AND snapshots.source_recipe_id = links.recipe_id
             WHERE versions.status = 'published' AND snapshots.id IS NULL"
        )->fetchColumn();
        $invalidSnapshotHashes = (int)$pdo->query(
            'SELECT COUNT(*) FROM starter_kit_recipe_snapshots
             WHERE snapshot_hash <> SHA2(recipe_snapshot, 256)'
        )->fetchColumn();
    }

    $checks = [
        'tables' => $missingTables === [],
        'shopping_status' => in_array('status', $shoppingColumns, true),
        'shopping_notes' => in_array('notes', $shoppingColumns, true),
        'starter_kit_source_type' => $sourceSupportsStarterKits,
        'platform_admin_column' => in_array('is_platform_admin', $userColumns, true),
        'authentication_version_column' => in_array('auth_version', $userColumns, true),
        'platform_admin_exists' => $platformAdminCount > 0,
        'recipe_snapshot_index' => in_array('starter_kit_recipe_snapshots', $tables, true)
            && $indexExists($pdo, 'starter_kit_recipe_snapshots', 'idx_kit_recipe_snapshots_version'),
        'activation_state_index' => in_array('starter_kit_activations', $tables, true)
            && $indexExists($pdo, 'starter_kit_activations', 'idx_kit_activations_state'),
        'published_recipe_snapshots_complete' => $missingPublishedSnapshots === 0,
        'recipe_snapshot_hashes_valid' => $invalidSnapshotHashes === 0,
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
