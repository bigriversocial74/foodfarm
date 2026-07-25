<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    Homestead\require_health_access($config, $auth);

    $requiredTables = [
        'household_tasks',
        'recurring_task_templates',
        'planning_cycles',
        'task_automation_metadata',
        'planning_suggestions',
        'task_lifecycle_events',
    ];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $statement = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
    );
    $statement->execute($requiredTables);
    $present = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($requiredTables, $present));
    if ($missing !== []) {
        throw new RuntimeException('Phase 7 database objects are incomplete.');
    }

    $orphanChecks = [
        'task_metadata' => "SELECT COUNT(*) FROM task_automation_metadata tam
                            LEFT JOIN household_tasks ht ON ht.id = tam.household_task_id AND ht.household_id = tam.household_id
                            WHERE ht.id IS NULL",
        'suggestion_cycles' => "SELECT COUNT(*) FROM planning_suggestions ps
                                LEFT JOIN planning_cycles pc ON pc.id = ps.planning_cycle_id AND pc.household_id = ps.household_id
                                WHERE pc.id IS NULL",
        'task_events' => "SELECT COUNT(*) FROM task_lifecycle_events tle
                          LEFT JOIN household_tasks ht ON ht.id = tle.household_task_id AND ht.household_id = tle.household_id
                          WHERE ht.id IS NULL",
        'template_assignees' => "SELECT COUNT(*) FROM recurring_task_templates rtt
                                 LEFT JOIN household_members hm ON hm.id = rtt.assigned_member_id AND hm.household_id = rtt.household_id
                                 WHERE rtt.assigned_member_id IS NOT NULL AND hm.id IS NULL",
    ];
    foreach ($orphanChecks as $name => $sql) {
        if ((int)$pdo->query($sql)->fetchColumn() !== 0) {
            throw new RuntimeException('Phase 7 relational integrity check failed: ' . $name . '.');
        }
    }

    $staleCycles = (int)$pdo->query(
        "SELECT COUNT(*) FROM planning_cycles
         WHERE status = 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)"
    )->fetchColumn();
    if ($staleCycles !== 0) {
        throw new RuntimeException('A stale planning cycle requires review.');
    }

    echo json_encode([
        'ok' => true,
        'phase' => 7,
        'service' => 'planning-tasks-automation',
        'database' => 'ready',
        'integrity' => 'ready',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(503);
    $isProduction = isset($environment) && $environment === 'production';
    echo json_encode([
        'ok' => false,
        'phase' => 7,
        'error' => $isProduction ? 'Planning automation health check failed.' : $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
