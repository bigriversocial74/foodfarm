<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'service' => $root . '/app/PlanningAutomationService.php',
    'route' => $root . '/phase7.php',
    'health' => $root . '/api/phase7-health.php',
    'migration' => $root . '/database/phase7_planning_tasks_automation.sql',
];

$failures = [];
foreach ($files as $name => $path) {
    if (!is_file($path) || filesize($path) < 50) {
        $failures[] = "$name file is missing or empty";
    }
}

$service = is_file($files['service']) ? file_get_contents($files['service']) : '';
$route = is_file($files['route']) ? file_get_contents($files['route']) : '';
$health = is_file($files['health']) ? file_get_contents($files['health']) : '';
$migration = is_file($files['migration']) ? file_get_contents($files['migration']) : '';

$requiredServicePatterns = [
    'final class PlanningAutomationService',
    'runPlanningCycle',
    'createManualTask',
    'createRecurringTemplate',
    'acceptShoppingSuggestion',
    'FOR UPDATE',
    'generation_key',
    'planning_cycles',
    'task_lifecycle_events',
    'current_quantity <= reorder_level',
    "status IN ('planned','ready','in_progress')",
    'assertTaskActor',
    'hash(\'sha256\'',
];
foreach ($requiredServicePatterns as $pattern) {
    if (!str_contains($service, $pattern)) {
        $failures[] = "service missing required pattern: $pattern";
    }
}

$requiredRoutePatterns = [
    "require __DIR__ . '/app/bootstrap.php'",
    "require_once __DIR__ . '/app/PlanningAutomationService.php'",
    "verify_csrf",
    "hash_equals",
    "tasks.manage",
    "tasks.complete",
    "run_cycle",
    "accept_suggestion",
    "complete_task",
];
foreach ($requiredRoutePatterns as $pattern) {
    if (!str_contains($route, $pattern)) {
        $failures[] = "route missing required pattern: $pattern";
    }
}

if (preg_match('/\$_(GET|POST|REQUEST)\[[^\]]+\].*?(SELECT|UPDATE|INSERT|DELETE)/is', $service) === 1) {
    $failures[] = 'service appears to interpolate request data into SQL';
}
if (!str_contains($health, 'require_health_access') || !str_contains($health, 'Cache-Control: no-store')) {
    $failures[] = 'health endpoint is not protected and non-cacheable';
}

$requiredTables = [
    'recurring_task_templates',
    'planning_cycles',
    'task_automation_metadata',
    'planning_suggestions',
    'task_lifecycle_events',
];
foreach ($requiredTables as $table) {
    if (!str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table)) {
        $failures[] = "migration missing replay-safe table: $table";
    }
}
if (!str_contains($migration, 'uq_planning_cycles_household_date')
    || !str_contains($migration, 'uq_task_meta_generation')
    || !str_contains($migration, 'uq_planning_suggestions_generation')) {
    $failures[] = 'migration is missing required idempotency constraints';
}

if ($failures !== []) {
    fwrite(STDERR, "Phase 7 static audit failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Phase 7 static audit passed.\n";
