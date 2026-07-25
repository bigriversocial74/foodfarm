<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'service' => $root . '/app/GrowPreserveService.php',
    'route' => $root . '/phase6.php',
    'health' => $root . '/api/phase6-health.php',
    'migration' => $root . '/database/phase6_grow_harvest_preserve.sql',
    'auth' => $root . '/app/Auth.php',
    'bootstrap' => $root . '/app/bootstrap.php',
    'phase3' => $root . '/phase3.php',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing {$name}: {$path}\n");
        exit(1);
    }
}

$content = array_map(static fn(string $path): string => (string)file_get_contents($path), $files);
$checks = [
    'route requires authenticated user' => str_contains($content['route'], '$auth->requireUser()'),
    'route enforces garden permission' => str_contains($content['route'], "$auth->requirePermission($user, 'garden.manage')"),
    'route enforces harvest permission' => str_contains($content['route'], "$auth->requirePermission($user, 'harvest.record')"),
    'route enforces preservation permission' => str_contains($content['route'], "$auth->requirePermission($user, 'preservation.manage')"),
    'route scopes garden and preservation reads' => str_contains($content['route'], 'if ($canViewGarden)')
        && str_contains($content['route'], 'if ($canViewPreservation)'),
    'harvest uses transaction' => str_contains($content['service'], 'public function recordHarvest')
        && str_contains($content['service'], '$this->pdo->beginTransaction()'),
    'harvest locks planting' => str_contains($content['service'], 'WHERE p.id = ? AND z.household_id = ? FOR UPDATE'),
    'harvest form token is session-bound' => str_contains($content['route'], 'hash_equals($sessionKey, $submittedKey)')
        && str_contains($content['route'], 'name="action_key"'),
    'harvest is idempotent' => str_contains($content['service'], 'SELECT id FROM harvests WHERE action_key = ? LIMIT 1'),
    'inventory harvest unit is validated' => str_contains($content['service'], 'Harvest and inventory units must match exactly.'),
    'preservation locks inventory' => str_contains($content['service'], "status = 'active' FOR UPDATE"),
    'preservation uses guarded deduction' => str_contains($content['service'], 'current_quantity >= ?'),
    'planned preservation binds source inventory' => str_contains($content['service'], 'The preservation input must match the inventory created by the source harvest.'),
    'preservation records input provenance' => str_contains($content['service'], 'INSERT INTO preservation_batch_inputs'),
    'preservation creates output inventory' => str_contains($content['service'], "'preserved_food'"),
    'health endpoint is protected' => str_contains($content['health'], 'require_health_access'),
    'health checks household integrity' => str_contains($content['health'], 'harvest_household_integrity')
        && str_contains($content['health'], 'preservation_household_integrity'),
    'migration is replay safe' => str_contains($content['migration'], 'information_schema.COLUMNS')
        && str_contains($content['migration'], 'information_schema.STATISTICS'),
    'migration adds idempotency indexes' => str_contains($content['migration'], 'uq_harvest_action_key')
        && str_contains($content['migration'], 'uq_preservation_action_key'),
    'bootstrap loads phase6 service' => str_contains($content['bootstrap'], "require_once __DIR__ . '/GrowPreserveService.php';"),
    'role defaults include grow permissions' => str_contains($content['auth'], "'garden.view'")
        && str_contains($content['auth'], "'harvest.record'")
        && str_contains($content['auth'], "'preservation.manage'"),
    'permission administration exposes grow permissions' => str_contains($content['phase3'], "'garden.manage'")
        && str_contains($content['phase3'], "'harvest.record'")
        && str_contains($content['phase3'], "'preservation.manage'"),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Phase 6 static audit failures: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "Phase 6 static audit passed.\n";
