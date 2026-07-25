<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

    $requiredTables = ['users','household_members','household_invitations','authentication_events'];
    $tables = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($requiredTables, $tables));
    $ownerCount = $missing === []
        ? (int)$pdo->query("SELECT COUNT(*) FROM household_members hm JOIN users u ON u.id=hm.user_id WHERE hm.role='owner' AND hm.status='active' AND u.status='active'")->fetchColumn()
        : 0;

    echo json_encode([
        'ok' => $missing === [] && $ownerCount > 0,
        'connected' => true,
        'tables' => ['required' => $requiredTables, 'missing' => $missing],
        'active_owner_accounts' => $ownerCount,
        'timestamp' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    Homestead\health_error($exception, $config ?? []);
}
