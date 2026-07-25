<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'service' => $root . '/app/CostWasteService.php',
    'settings' => $root . '/app/CostWasteSettingsTrait.php',
    'purchase' => $root . '/app/CostWastePurchaseTrait.php',
    'waste' => $root . '/app/CostWasteWasteTrait.php',
    'snapshot' => $root . '/app/CostWasteSnapshotTrait.php',
    'recommendation' => $root . '/app/CostWasteRecommendationTrait.php',
    'query' => $root . '/app/CostWasteQueryTrait.php',
    'support' => $root . '/app/CostWasteSupportTrait.php',
    'page' => $root . '/phase9.php',
    'health' => $root . '/api/phase9-health.php',
    'migration' => $root . '/database/phase9_cost_waste_savings_intelligence.sql',
    'workflow' => $root . '/.github/workflows/phase9-certification.yml',
];
$failures = [];

foreach ($files as $label => $path) {
    if (!is_file($path)) {
        $failures[] = "Missing Phase 9 {$label} file: {$path}";
    }
}

if ($failures === []) {
    $service = file_get_contents($files['service']);
    $purchase = file_get_contents($files['purchase']);
    $waste = file_get_contents($files['waste']);
    $snapshot = file_get_contents($files['snapshot']);
    $recommendation = file_get_contents($files['recommendation']);
    $support = file_get_contents($files['support']);
    $page = file_get_contents($files['page']);
    $health = file_get_contents($files['health']);
    $migration = file_get_contents($files['migration']);
    $workflow = file_get_contents($files['workflow']);

    foreach ([
        'final class CostWasteService',
        'CostWastePurchaseTrait',
        'CostWasteWasteTrait',
        'CostWasteSnapshotTrait',
        'CostWasteRecommendationTrait',
        'CostWasteQueryTrait',
        'CostWasteSupportTrait',
    ] as $needle) {
        if (!str_contains($service, $needle)) {
            $failures[] = "Phase 9 service is missing required component: {$needle}";
        }
    }

    foreach ([
        'public function recordPurchase',
        'action_key = ? FOR UPDATE',
        'lockHousehold',
        'lockInventoryItem',
        'updateCostBasis',
        'food_ledger_events',
        'household_id = ?',
    ] as $needle) {
        if (!str_contains($purchase, $needle)) {
            $failures[] = "Purchase workflow is missing required control: {$needle}";
        }
    }

    foreach ([
        'public function recordWaste',
        'prepared_food_batch_id',
        'lockHousehold',
        'FOR UPDATE',
        'estimated_value',
        'food_ledger_events',
        'household_id = ?',
    ] as $needle) {
        if (!str_contains($waste, $needle)) {
            $failures[] = "Waste workflow is missing required control: {$needle}";
        }
    }

    foreach ([
        'public function calculateRecipeCost',
        'public function runFinanceSnapshot',
        'sourceWatermark',
        'recipe_cost_snapshot_lines',
        'generateFinanceRecommendations',
        'household_production_value',
        'preservation_value',
        'model_version',
    ] as $needle) {
        if (!str_contains($snapshot, $needle)) {
            $failures[] = "Snapshot workflow is missing required control: {$needle}";
        }
    }

    foreach ([
        'public function acceptRecommendation',
        'task_automation_metadata',
        'finance_recommendation',
        'WHERE id = ? AND household_id = ? FOR UPDATE',
        'status = "pending"',
    ] as $needle) {
        if (!str_contains($recommendation, $needle)) {
            $failures[] = "Recommendation workflow is missing required control: {$needle}";
        }
    }

    foreach ([
        'assertActiveMember',
        'lockHousehold',
        'lockInventoryItem',
        'assertSupplier',
        'updateCostBasis',
        'sourceWatermark',
        'recordEvent',
        'actionKey',
    ] as $needle) {
        if (!str_contains($support, $needle)) {
            $failures[] = "Phase 9 support layer is missing required control: {$needle}";
        }
    }

    foreach ([
        'verify_csrf',
        'phase9_action_key',
        'hash_equals',
        'finance.view',
        'finance.manage',
        'record_purchase',
        'record_waste',
        'calculate_recipe_cost',
        'run_finance_snapshot',
        'Cost, Waste & Savings Intelligence',
    ] as $needle) {
        if (!str_contains($page, $needle)) {
            $failures[] = "Phase 9 page is missing required control: {$needle}";
        }
    }

    $requiredTables = [
        'household_finance_settings',
        'household_suppliers',
        'food_purchase_records',
        'inventory_cost_basis',
        'food_waste_events',
        'recipe_cost_snapshots',
        'recipe_cost_snapshot_lines',
        'household_finance_snapshots',
        'finance_recommendations',
        'finance_lifecycle_events',
    ];
    foreach ($requiredTables as $table) {
        if (!str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table)) {
            $failures[] = "Phase 9 migration is missing replay-safe table {$table}.";
        }
        if (!str_contains($health, "'{$table}'")) {
            $failures[] = "Phase 9 health endpoint does not validate {$table}.";
        }
    }

    foreach ([
        'uq_purchase_household_action',
        'uq_waste_household_action',
        'uq_recipe_cost_calculation',
        'uq_finance_snapshot_run',
        'uq_finance_recommendation_generation',
    ] as $index) {
        if (!str_contains($migration, $index)) {
            $failures[] = "Phase 9 migration is missing uniqueness control {$index}.";
        }
    }

    foreach ([
        'mysql:8.0',
        'mariadb:10.11',
        'phase9_cost_waste_savings_intelligence.sql',
        'phase9_integration.php',
        'phase9_http_smoke.sh',
    ] as $needle) {
        if (!str_contains($workflow, $needle)) {
            $failures[] = "Phase 9 certification workflow is missing {$needle}.";
        }
    }

    foreach ([
        'SELECT * FROM finance_recommendations WHERE id = ? FOR UPDATE',
        'UPDATE finance_recommendations SET status = ? WHERE id = ?',
        'SELECT * FROM inventory_items WHERE id = ? FOR UPDATE',
    ] as $unsafeMutation) {
        if (str_contains($service . $recommendation . $support, $unsafeMutation)) {
            $failures[] = 'A Phase 9 mutation is missing household scoping: ' . $unsafeMutation;
        }
    }

    if (!str_contains($health, 'invalid_waste_sources')
        || !str_contains($health, 'accepted_without_task')
        || !str_contains($health, 'invalid_snapshot_metrics')
        || !str_contains($health, 'stale_running_snapshots')) {
        $failures[] = 'Phase 9 health diagnostics are incomplete.';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 9 static audit passed.\n";