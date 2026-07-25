<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    $requiredTables = ['users','household_members','household_invitations','authentication_events'];
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $tables[] = (string)$table;
    }
    $missing = array_values(array_diff($requiredTables, $tables));
    $owner = $pdo->query("SELECT COUNT(*) FROM household_members hm JOIN users u ON u.id=hm.user_id WHERE hm.role='owner' AND hm.status='active' AND u.status='active'")->fetchColumn();
    echo json_encode([
        'ok' => $missing === [] && (int)$owner > 0,
        'connected' => true,
        'tables' => ['required'=>$requiredTables,'missing'=>$missing],
        'active_owner_accounts' => (int)$owner,
        'timestamp' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'connected'=>false,'error'=>$exception->getMessage(),'timestamp'=>gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
