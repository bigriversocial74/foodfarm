<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'service' => $root . '/app/ForecastingService.php',
    'page' => $root . '/phase8.php',
    'health' => $root . '/api/phase8-health.php',
    'migration' => $root . '/database/phase8_forecasting_seasonal_self_sufficiency.sql',
    'workflow' => $root . '/.github/workflows/phase8-certification.yml',
];
$failures = [];

foreach ($files as $label => $path) {
    if (!is_file($path)) {
        $failures[] = "Missing Phase 8 {$label} file: {$path}";
    }
}
if ($failures === []) {
    $service = file_get_contents($files['service']);
    $page = file_get_contents($files['page']);
    $health = file_get_contents($files['health']);
    $migration = file_get_contents($files['migration']);
    $workflow = file_get_contents($files['workflow']);

    $requiredService = [
        'final class ForecastingService',
        'public function runForecast',
        'public function saveSettings',
        'public function acceptRecommendation',
        'public function createSeasonalEntry',
        'sourceWatermark',
        'FOR UPDATE',
        'household_id = ?',
        'task_automation_metadata',
        'forecast_lifecycle_events',
        'model_version',
        'tracked production',
    ];
    foreach ($requiredService as $needle) {
        if (!str_contains($service, $needle)) {
            $failures[] = "Forecasting service is missing required control: {$needle}";
        }
    }

    $requiredPage = [
        'verify_csrf',
        'phase8_action_key',
        'hash_equals',
        '$canManage',
        'run_forecast',
        'save_settings',
        'accept_recommendation',
        'seasonal_status',
        'Forecasting, Seasons & Self-Sufficiency',
    ];
    foreach ($requiredPage as $needle) {
        if (!str_contains($page, $needle)) {
            $failures[] = "Phase 8 page is missing required control: {$needle}";
        }
    }

    $requiredTables = [
        'household_forecast_settings',
        'forecast_snapshots',
        'forecast_item_projections',
        'self_sufficiency_metrics',
        'forecast_recommendations',
        'seasonal_plan_entries',
        'forecast_lifecycle_events',
    ];
    foreach ($requiredTables as $table) {
        if (!str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table)) {
            $failures[] = "Phase 8 migration is missing replay-safe table {$table}.";
        }
        if (!str_contains($health, "'" . $table . "'")) {
            $failures[] = "Phase 8 health endpoint does not validate {$table}.";
        }
    }

    foreach ([
        'uq_forecast_snapshots_run',
        'uq_forecast_projection_item',
        'uq_forecast_recommendation_generation',
        'uq_seasonal_entry_generation',
    ] as $index) {
        if (!str_contains($migration, $index)) {
            $failures[] = "Phase 8 migration is missing uniqueness control {$index}.";
        }
    }

    foreach ([
        'mysql:8.0',
        'mariadb:10.11',
        'phase8_forecasting_seasonal_self_sufficiency.sql',
        'phase8_integration.php',
        'phase8_http_smoke.sh',
    ] as $needle) {
        if (!str_contains($workflow, $needle)) {
            $failures[] = "Phase 8 certification workflow is missing {$needle}.";
        }
    }

    foreach ([
        'UPDATE forecast_recommendations SET status = ? WHERE id = ?',
        'UPDATE seasonal_plan_entries SET status = ? WHERE id = ?',
    ] as $unsafeMutation) {
        if (str_contains($service, $unsafeMutation)) {
            $failures[] = 'A Phase 8 mutation is missing household scoping: ' . $unsafeMutation;
        }
    }
    if (str_contains($service, 'SELECT * FROM forecast_recommendations WHERE id = ? FOR UPDATE')) {
        $failures[] = 'Recommendation locking must include household ownership.';
    }
    if (str_contains($service, 'SELECT * FROM seasonal_plan_entries WHERE id = ? FOR UPDATE')) {
        $failures[] = 'Seasonal-entry locking must include household ownership.';
    }
    if (!str_contains($health, 'stale_running_snapshots')
        || !str_contains($health, 'accepted_without_task')
        || !str_contains($health, 'invalid_scores')) {
        $failures[] = 'Phase 8 health diagnostics are incomplete.';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 8 static audit passed.\n";
