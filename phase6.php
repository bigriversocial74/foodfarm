<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use Homestead\GrowPreserveService;
use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$householdId = (int)$user['household_id'];
$memberId = (int)$user['member_id'];
$service = new GrowPreserveService($pdo);

$canViewGarden = $auth->can($user, 'garden.view') || $auth->can($user, 'garden.manage') || $auth->can($user, 'harvest.record');
$canManageGarden = $auth->can($user, 'garden.manage');
$canRecordHarvest = $auth->can($user, 'harvest.record');
$canViewPreservation = $auth->can($user, 'preservation.view') || $auth->can($user, 'preservation.manage');
$canManagePreservation = $auth->can($user, 'preservation.manage');
if (!$canViewGarden && !$canViewPreservation) {
    http_response_code(403);
    exit('You do not have permission to view garden or preservation operations.');
}

$section = (string)($_GET['section'] ?? 'overview');
if (!in_array($section, ['overview', 'garden', 'harvests', 'preservation'], true)) {
    $section = 'overview';
}
if (!$canViewGarden && in_array($section, ['overview', 'garden', 'harvests'], true)) {
    $section = 'preservation';
}
if (!$canViewPreservation && $section === 'preservation') {
    $section = 'garden';
}

if (!isset($_SESSION['harvest_action_key']) || !is_string($_SESSION['harvest_action_key'])) {
    $_SESSION['harvest_action_key'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['preservation_action_key']) || !is_string($_SESSION['preservation_action_key'])) {
    $_SESSION['preservation_action_key'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_zone') {
            $auth->requirePermission($user, 'garden.manage');
            $service->createZone($householdId, $_POST);
            flash('success', 'Garden zone created.');
            redirect('/phase6.php?section=garden');
        }
        if ($action === 'create_planting') {
            $auth->requirePermission($user, 'garden.manage');
            $service->createPlanting($householdId, $_POST);
            flash('success', 'Planting added to the grow plan.');
            redirect('/phase6.php?section=garden');
        }
        if ($action === 'record_reading') {
            $auth->requirePermission($user, 'garden.manage');
            $service->recordReading($householdId, $memberId, $_POST);
            flash('success', 'Garden reading recorded.');
            redirect('/phase6.php?section=garden');
        }
        if ($action === 'update_stage') {
            $auth->requirePermission($user, 'garden.manage');
            $service->updatePlantingStage(
                $householdId,
                $memberId,
                (int)($_POST['planting_id'] ?? 0),
                (string)($_POST['growth_stage'] ?? '')
            );
            flash('success', 'Planting stage updated.');
            redirect('/phase6.php?section=garden');
        }
        if ($action === 'record_harvest') {
            $auth->requirePermission($user, 'harvest.record');
            $submittedKey = strtolower(trim((string)($_POST['action_key'] ?? '')));
            $sessionKey = (string)($_SESSION['harvest_action_key'] ?? '');
            if ($sessionKey === '' || !hash_equals($sessionKey, $submittedKey)) {
                throw new RuntimeException('The harvest form expired. Reload the page and try again.');
            }
            $harvestId = $service->recordHarvest($householdId, $memberId, $_POST);
            unset($_SESSION['harvest_action_key']);
            flash('success', 'Harvest #' . $harvestId . ' recorded and posted to the food lifecycle ledger.');
            redirect('/phase6.php?section=harvests');
        }
        if ($action === 'complete_preservation') {
            $auth->requirePermission($user, 'preservation.manage');
            $submittedKey = strtolower(trim((string)($_POST['action_key'] ?? '')));
            $sessionKey = (string)($_SESSION['preservation_action_key'] ?? '');
            if ($sessionKey === '' || !hash_equals($sessionKey, $submittedKey)) {
                throw new RuntimeException('The preservation form expired. Reload the page and try again.');
            }
            $batchId = $service->completePreservation($householdId, $memberId, $_POST);
            unset($_SESSION['preservation_action_key']);
            flash('success', 'Preservation batch #' . $batchId . ' completed and stocked.');
            redirect('/phase6.php?section=preservation');
        }

        throw new InvalidArgumentException('Unknown grow-and-preserve action.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/phase6.php?section=' . rawurlencode($section));
    }
}

$zones = [];
$plantings = [];
$readings = [];
$harvests = [];
if ($canViewGarden) {
    $zonesQuery = $pdo->prepare(
        "SELECT z.*,
                (SELECT COUNT(*) FROM plantings p WHERE p.garden_zone_id = z.id AND p.growth_stage NOT IN ('completed','failed')) AS active_plantings,
                (SELECT COUNT(*) FROM plantings p WHERE p.garden_zone_id = z.id) AS total_plantings,
                (SELECT gr.recorded_at FROM garden_readings gr WHERE gr.garden_zone_id = z.id ORDER BY gr.recorded_at DESC, gr.id DESC LIMIT 1) AS last_reading_at,
                (SELECT gr.temperature FROM garden_readings gr WHERE gr.garden_zone_id = z.id ORDER BY gr.recorded_at DESC, gr.id DESC LIMIT 1) AS latest_temperature,
                (SELECT gr.humidity FROM garden_readings gr WHERE gr.garden_zone_id = z.id ORDER BY gr.recorded_at DESC, gr.id DESC LIMIT 1) AS latest_humidity,
                (SELECT gr.soil_moisture FROM garden_readings gr WHERE gr.garden_zone_id = z.id ORDER BY gr.recorded_at DESC, gr.id DESC LIMIT 1) AS latest_soil_moisture
         FROM garden_zones z WHERE z.household_id = ? ORDER BY z.active DESC, z.name"
    );
    $zonesQuery->execute([$householdId]);
    $zones = $zonesQuery->fetchAll();

    $plantingsQuery = $pdo->prepare(
        "SELECT p.*, z.name AS zone_name,
                (SELECT COUNT(*) FROM harvests h WHERE h.planting_id = p.id) AS harvest_count,
                (SELECT MAX(h.harvested_at) FROM harvests h WHERE h.planting_id = p.id) AS last_harvest_at
         FROM plantings p
         JOIN garden_zones z ON z.id = p.garden_zone_id
         WHERE z.household_id = ?
         ORDER BY FIELD(p.growth_stage,'harvest_ready','fruiting','flowering','vegetative','seedling','germinating','planned','completed','failed'),
                  COALESCE(p.expected_harvest_start, '9999-12-31'), p.crop_name"
    );
    $plantingsQuery->execute([$householdId]);
    $plantings = $plantingsQuery->fetchAll();

    $readingsQuery = $pdo->prepare(
        'SELECT gr.*, z.name AS zone_name, hm.display_name AS member_name
         FROM garden_readings gr
         JOIN garden_zones z ON z.id = gr.garden_zone_id
         LEFT JOIN household_members hm ON hm.id = gr.recorded_by_member_id AND hm.household_id = z.household_id
         WHERE z.household_id = ? ORDER BY gr.recorded_at DESC, gr.id DESC LIMIT 40'
    );
    $readingsQuery->execute([$householdId]);
    $readings = $readingsQuery->fetchAll();

    $harvestsQuery = $pdo->prepare(
        'SELECT h.*, p.crop_name, p.variety, z.name AS zone_name,
                hm.display_name AS harvested_by, ii.name AS inventory_name, pb.name AS preservation_name
         FROM harvests h
         JOIN plantings p ON p.id = h.planting_id
         JOIN garden_zones z ON z.id = p.garden_zone_id
         LEFT JOIN household_members hm ON hm.id = h.harvested_by_member_id AND hm.household_id = z.household_id
         LEFT JOIN inventory_items ii ON ii.id = h.inventory_item_id AND ii.household_id = z.household_id
         LEFT JOIN preservation_batches pb ON pb.id = h.preservation_batch_id AND pb.household_id = z.household_id
         WHERE z.household_id = ? ORDER BY h.harvested_at DESC, h.id DESC LIMIT 100'
    );
    $harvestsQuery->execute([$householdId]);
    $harvests = $harvestsQuery->fetchAll();
}

$preservationBatches = [];
$plannedBatches = [];
if ($canViewPreservation) {
    $preservationQuery = $pdo->prepare(
        "SELECT pb.*, hm.display_name AS started_by, sl.name AS location_name, ii.name AS output_item_name,
                (SELECT COUNT(*) FROM preservation_batch_inputs pbi WHERE pbi.preservation_batch_id = pb.id) AS input_count,
                (SELECT h.id FROM harvests h WHERE h.preservation_batch_id = pb.id ORDER BY h.id LIMIT 1) AS source_harvest_id
         FROM preservation_batches pb
         LEFT JOIN household_members hm ON hm.id = pb.started_by_member_id AND hm.household_id = pb.household_id
         LEFT JOIN storage_locations sl ON sl.id = pb.storage_location_id AND sl.household_id = pb.household_id
         LEFT JOIN inventory_items ii ON ii.id = pb.output_inventory_item_id AND ii.household_id = pb.household_id
         WHERE pb.household_id = ? ORDER BY pb.created_at DESC, pb.id DESC LIMIT 100"
    );
    $preservationQuery->execute([$householdId]);
    $preservationBatches = $preservationQuery->fetchAll();
    $plannedBatches = array_values(array_filter(
        $preservationBatches,
        static fn(array $batch): bool => in_array((string)$batch['status'], ['planned', 'prepared'], true)
    ));
}

$inventoryItems = [];
$locations = [];
if ($canRecordHarvest || $canManagePreservation) {
    $inventoryQuery = $pdo->prepare(
        "SELECT id, name, item_type, current_quantity, unit, storage_location_id
         FROM inventory_items
         WHERE household_id = ? AND status = 'active' AND current_quantity > 0
           AND item_type IN ('ingredient','preserved_food')
         ORDER BY name"
    );
    $inventoryQuery->execute([$householdId]);
    $inventoryItems = $inventoryQuery->fetchAll();

    $locationsQuery = $pdo->prepare('SELECT id, name, location_type FROM storage_locations WHERE household_id = ? ORDER BY name');
    $locationsQuery->execute([$householdId]);
    $locations = $locationsQuery->fetchAll();
}

$activePlantings = count(array_filter($plantings, static fn(array $row): bool => !in_array((string)$row['growth_stage'], ['completed', 'failed'], true)));
$harvestReady = count(array_filter($plantings, static fn(array $row): bool => (string)$row['growth_stage'] === 'harvest_ready'));
$storedPreservation = count(array_filter($preservationBatches, static fn(array $row): bool => (string)$row['status'] === 'stored'));
$flashes = consume_flashes();
$csrf = csrf_token();

$latestReading = $readings[0] ?? null;
$recentReadingWindow = array_slice($readings, 0, 24);
$readingAverage = static function (array $rows, string $key): ?float {
    $values = [];
    foreach ($rows as $row) {
        if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
            $values[] = (float)$row[$key];
        }
    }
    return $values === [] ? null : array_sum($values) / count($values);
};
$averageTemperature = $readingAverage($recentReadingWindow, 'temperature');
$averageHumidity = $readingAverage($recentReadingWindow, 'humidity');
$averageSoilMoisture = $readingAverage($recentReadingWindow, 'soil_moisture');
$averageVpd = $readingAverage($recentReadingWindow, 'vpd');
$averageLight = $readingAverage($recentReadingWindow, 'light_level');
$totalPlants = array_sum(array_map(static fn(array $row): int => (int)($row['plant_count'] ?? 0), $plantings));
$activeZones = count(array_filter($zones, static fn(array $row): bool => (bool)$row['active']));
$recentHarvests = array_slice($harvests, 0, 5);
$stageProgress = [
    'planned' => 8,
    'germinating' => 18,
    'seedling' => 32,
    'vegetative' => 52,
    'flowering' => 68,
    'fruiting' => 82,
    'harvest_ready' => 96,
    'completed' => 100,
    'failed' => 0,
];
$environmentStatus = static function (?float $value, ?float $minimum, ?float $maximum): string {
    if ($value === null) {
        return 'Awaiting data';
    }
    if ($minimum !== null && $value < $minimum) {
        return 'Below target';
    }
    if ($maximum !== null && $value > $maximum) {
        return 'Above target';
    }
    return 'Optimal';
};

$preservationMethodLabels = [
    'water_bath' => 'Water Bath Canning',
    'pressure_canning' => 'Pressure Canning',
    'dehydrating' => 'Dehydrating',
    'fermenting' => 'Fermenting',
    'pickling' => 'Pickling',
    'freezing' => 'Freezing',
    'vacuum_sealing' => 'Vacuum Sealing',
    'dry_storage' => 'Dry Storage',
];
$preservationMethodGroups = [
    'canning' => ['water_bath', 'pressure_canning', 'pickling'],
    'dehydrating' => ['dehydrating', 'dry_storage', 'vacuum_sealing'],
    'fermenting' => ['fermenting'],
];
$preservationMethodCounts = [];
$preservationStatusCounts = [];
$preservationLocationCounts = [];
$expiringPreserves = 0;
$totalPreservedUnits = 0.0;
$today = new DateTimeImmutable('today');
$expiringThreshold = $today->modify('+30 days');
foreach ($preservationBatches as $batch) {
    $method = (string)$batch['method'];
    $status = (string)$batch['status'];
    $location = trim((string)($batch['location_name'] ?? '')) ?: 'Unassigned';
    $preservationMethodCounts[$method] = ($preservationMethodCounts[$method] ?? 0) + 1;
    $preservationStatusCounts[$status] = ($preservationStatusCounts[$status] ?? 0) + 1;
    $preservationLocationCounts[$location] = ($preservationLocationCounts[$location] ?? 0) + 1;
    if ($status === 'stored' && is_numeric($batch['yield_quantity'] ?? null)) {
        $totalPreservedUnits += (float)$batch['yield_quantity'];
    }
    $bestUse = trim((string)($batch['best_use_date'] ?? ''));
    if ($bestUse !== '') {
        $bestUseDate = DateTimeImmutable::createFromFormat('!Y-m-d', $bestUse);
        if ($bestUseDate && $bestUseDate >= $today && $bestUseDate <= $expiringThreshold) {
            $expiringPreserves++;
        }
    }
}
$preservationGroupCount = static function (array $methods) use ($preservationMethodCounts): int {
    $count = 0;
    foreach ($methods as $method) {
        $count += (int)($preservationMethodCounts[$method] ?? 0);
    }
    return $count;
};
$selectedPreservationId = max(0, (int)($_GET['batch_id'] ?? 0));
$selectedPreservationBatch = null;
foreach ($preservationBatches as $batch) {
    if ($selectedPreservationId === 0 || (int)$batch['id'] === $selectedPreservationId) {
        $selectedPreservationBatch = $batch;
        if ($selectedPreservationId !== 0) {
            break;
        }
    }
}
$preservationSafetySource = '';
if (is_array($selectedPreservationBatch)) {
    $safetyData = json_decode((string)($selectedPreservationBatch['safety_data'] ?? ''), true);
    if (is_array($safetyData)) {
        $preservationSafetySource = trim((string)($safetyData['source'] ?? ''));
    }
}
$preservationImageFor = static function (string $method): string {
    return match ($method) {
        'dehydrating', 'dry_storage', 'vacuum_sealing' => 'assets/images/homestead/sheet-05/dehydrated-food-jars.png',
        'fermenting' => 'assets/images/homestead/sheet-05/fermentation-crock.png',
        'pickling' => 'assets/images/homestead/sheet-05/labeled-pickle-jar.png',
        default => 'assets/images/homestead/sheet-05/preservation-jars-wide.png',
    };
};
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title><?= $section === 'preservation' ? 'Preservation Tracking' : 'Garden Monitoring' ?> · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to garden operations</a>
<main id="main-content" class="page-container garden-page">
<?php if ($section === 'preservation'): ?>
<header class="preserve-hero">
    <div class="preserve-hero-copy">
        <p class="eyebrow">Batch records · shelf-life intelligence</p>
        <h1>Preservation Tracking</h1>
        <p>Track each canning, dehydrating, fermenting, freezing, and dry-storage batch from preparation through safe household use.</p>
        <nav class="garden-tabs preserve-tabs" aria-label="Garden and preservation sections">
            <?php if ($canViewGarden): ?>
                <a href="phase6.php?section=overview">Overview</a>
                <a href="phase6.php?section=garden">Garden</a>
                <a href="phase6.php?section=harvests">Harvests</a>
            <?php endif; ?>
            <a class="active" href="phase6.php?section=preservation">Preservation</a>
        </nav>
    </div>
    <div class="preserve-hero-image" role="img" aria-label="Shelves of preserved vegetables in glass jars"></div>
</header>
<?php else: ?>
<header class="garden-hero">
    <div class="garden-hero-copy">
        <p class="eyebrow">Living systems · field to pantry</p>
        <h1>Garden Monitoring</h1>
        <p>Monitor growing conditions, track plant health, record harvests, and carry every crop into inventory and preservation.</p>
        <nav class="garden-tabs" aria-label="Garden sections">
            <?php if ($canViewGarden): ?>
                <a class="<?= $section === 'overview' ? 'active' : '' ?>" href="phase6.php?section=overview">Overview</a>
                <a class="<?= $section === 'garden' ? 'active' : '' ?>" href="phase6.php?section=garden">Garden</a>
                <a class="<?= $section === 'harvests' ? 'active' : '' ?>" href="phase6.php?section=harvests">Harvests</a>
            <?php endif; ?>
            <?php if ($canViewPreservation): ?><a href="phase6.php?section=preservation">Preservation</a><?php endif; ?>
        </nav>
    </div>
    <div class="garden-hero-image" role="img" aria-label="Indoor garden monitoring racks"></div>
</header>
<?php endif; ?>

<?php foreach ($flashes as $message): ?>
    <div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?> garden-flash"><?= e((string)$message['message']) ?></div>
<?php endforeach; ?>

<?php if ($section === 'overview'): ?>
<section class="garden-metrics" aria-label="Current garden conditions">
    <article><span class="metric-icon">◒</span><div><small>Temperature</small><strong><?= $averageTemperature !== null ? e(number_format($averageTemperature, 1)) . '°F' : '—' ?></strong><em><?= e($environmentStatus($averageTemperature, 60.0, 82.0)) ?></em></div><svg viewBox="0 0 120 28" aria-hidden="true"><path d="M2 20 12 17 22 21 32 12 42 15 52 9 62 17 72 12 82 14 92 8 102 13 118 6"/></svg></article>
    <article><span class="metric-icon">◇</span><div><small>Humidity</small><strong><?= $averageHumidity !== null ? e(number_format($averageHumidity, 0)) . '%' : '—' ?></strong><em><?= e($environmentStatus($averageHumidity, 40.0, 65.0)) ?></em></div><svg viewBox="0 0 120 28" aria-hidden="true"><path d="M2 18 12 12 22 14 32 19 42 11 52 15 62 7 72 14 82 10 92 16 102 9 118 13"/></svg></article>
    <article><span class="metric-icon">⌁</span><div><small>VPD</small><strong><?= $averageVpd !== null ? e(number_format($averageVpd, 1)) . ' kPa' : '—' ?></strong><em><?= e($environmentStatus($averageVpd, 0.8, 1.5)) ?></em></div><svg viewBox="0 0 120 28" aria-hidden="true"><path d="M2 19 12 15 22 18 32 10 42 13 52 8 62 11 72 6 82 12 92 9 102 14 118 7"/></svg></article>
    <article><span class="metric-icon">▽</span><div><small>Soil moisture</small><strong><?= $averageSoilMoisture !== null ? e(number_format($averageSoilMoisture, 0)) . '%' : '—' ?></strong><em><?= e($environmentStatus($averageSoilMoisture, 35.0, 60.0)) ?></em></div><svg viewBox="0 0 120 28" aria-hidden="true"><path d="M2 11 12 14 22 10 32 16 42 12 52 18 62 14 72 17 82 13 92 19 102 15 118 18"/></svg></article>
    <article><span class="metric-icon">☼</span><div><small>Light level</small><strong><?= $averageLight !== null ? e(number_format($averageLight, 0)) : '—' ?></strong><em><?= $averageLight !== null ? 'Recorded average' : 'Awaiting data' ?></em></div><svg viewBox="0 0 120 28" aria-hidden="true"><path d="M2 8 12 9 22 14 32 13 42 18 52 15 62 20 72 17 82 22 92 18 102 21 118 16"/></svg></article>
</section>

<section class="garden-overview-grid">
    <article class="garden-panel grow-room-card">
        <div class="panel-heading"><div><p class="eyebrow">Live system view</p><h2>Grow Room Overview</h2></div><span class="live-dot">Live view</span></div>
        <div class="grow-room-photo">
            <?php foreach (array_slice($zones, 0, 4) as $index => $zone): ?>
                <div class="zone-float zone-<?= $index + 1 ?>"><strong><?= e((string)$zone['name']) ?></strong><span><?= $zone['latest_temperature'] !== null ? e(number_format((float)$zone['latest_temperature'], 1)) . '°F' : '—' ?> · <?= $zone['latest_humidity'] !== null ? e(number_format((float)$zone['latest_humidity'], 0)) . '%' : '—' ?></span><em><?= (int)$zone['active_plantings'] > 0 ? '● Healthy' : '○ Ready' ?></em></div>
            <?php endforeach; ?>
            <?php if ($zones === []): ?><div class="garden-empty-overlay"><strong>No garden zones yet</strong><span>Create the first growing area to begin monitoring.</span></div><?php endif; ?>
        </div>
        <div class="grow-room-totals"><div><strong><?= $activeZones ?></strong><span>Active zones</span></div><div><strong><?= $totalPlants ?></strong><span>Plants growing</span></div><div><strong><?= count($readings) ?></strong><span>Readings logged</span></div><div><strong><?= $activePlantings > 0 ? '100%' : '—' ?></strong><span>System visibility</span></div></div>
    </article>

    <aside class="garden-side-stack">
        <article class="garden-panel"><div class="panel-heading"><h2>Zone Conditions</h2><a href="phase6.php?section=garden">Manage</a></div><div class="schedule-list"><?php if ($zones === []): ?><p class="empty-state">No zones configured.</p><?php endif; ?><?php foreach (array_slice($zones, 0, 5) as $zone): ?><div><span class="schedule-icon">◉</span><strong><?= e((string)$zone['name']) ?></strong><small><?= e((string)$zone['zone_type']) ?></small><em><?= (int)$zone['active_plantings'] ?> active</em></div><?php endforeach; ?></div></article>
        <article class="garden-panel"><div class="panel-heading"><h2>Harvest Readiness</h2><a href="phase6.php?section=harvests">View all</a></div><div class="schedule-list harvest-ready-list"><?php $readyRows = array_values(array_filter($plantings, static fn(array $row): bool => in_array((string)$row['growth_stage'], ['fruiting','harvest_ready'], true))); ?><?php if ($readyRows === []): ?><p class="empty-state">No crops are currently near harvest.</p><?php endif; ?><?php foreach (array_slice($readyRows, 0, 5) as $row): ?><div><span class="schedule-icon">♧</span><strong><?= e((string)$row['crop_name']) ?></strong><small><?= e((string)$row['zone_name']) ?></small><em><?= e(str_replace('_',' ',(string)$row['growth_stage'])) ?></em></div><?php endforeach; ?></div></article>
        <article class="garden-panel"><div class="panel-heading"><h2>Recent Activity</h2><a href="phase6.php?section=garden">View all</a></div><div class="alert-list"><?php if ($readings === []): ?><p class="empty-state">No environmental readings yet.</p><?php endif; ?><?php foreach (array_slice($readings, 0, 4) as $reading): ?><div><span>△</span><p><strong><?= e((string)$reading['zone_name']) ?></strong><small><?= e((string)$reading['recorded_at']) ?> · <?= e((string)$reading['source']) ?></small></p></div><?php endforeach; ?></div></article>
    </aside>
</section>

<section class="garden-lower-grid">
    <article class="garden-panel plant-health"><div class="panel-heading"><h2>Plant Health</h2><a href="phase6.php?section=garden">View all plants</a></div><div class="plant-table"><div class="plant-row plant-head"><span>Plant / variety</span><span>Zone</span><span>Health</span><span>Progress</span><span>Next harvest</span></div><?php if ($plantings === []): ?><p class="empty-state">No plantings yet.</p><?php endif; ?><?php foreach (array_slice($plantings, 0, 8) as $row): $progress = $stageProgress[(string)$row['growth_stage']] ?? 0; ?><div class="plant-row"><span><i class="plant-thumb"></i><b><?= e((string)$row['crop_name']) ?></b><small><?= e((string)($row['variety'] ?? '')) ?></small></span><span><?= e((string)$row['zone_name']) ?></span><span class="health-state">● <?= e(ucwords(str_replace('_',' ',(string)$row['growth_stage']))) ?></span><span><span class="progress-track"><i style="width:<?= $progress ?>%"></i></span><small><?= $progress ?>%</small></span><span><?= e((string)($row['expected_harvest_start'] ?? '—')) ?></span></div><?php endforeach; ?></div></article>
    <article class="garden-panel history-card"><div class="panel-heading"><h2>Environmental History</h2><span>Latest 24 readings</span></div><div class="history-legend"><span>Temperature</span><span>Humidity</span><span>VPD</span></div><svg class="history-chart" viewBox="0 0 640 250" preserveAspectRatio="none" aria-label="Environmental trend illustration"><g class="chart-grid"><path d="M35 30H620M35 75H620M35 120H620M35 165H620M35 210H620"/><path d="M35 20V220M180 20V220M325 20V220M470 20V220M620 20V220"/></g><path class="chart-temp" d="M35 80 C85 90 110 120 160 105 S250 70 310 82 S400 120 460 100 S550 72 620 85"/><path class="chart-humidity" d="M35 145 C90 125 125 155 180 140 S275 120 330 145 S420 135 470 150 S560 120 620 138"/><path class="chart-vpd" d="M35 185 C85 170 115 195 165 176 S260 190 315 180 S405 195 465 178 S550 188 620 165"/></svg><div class="history-averages"><div><strong><?= $averageTemperature !== null ? e(number_format($averageTemperature, 1)) . '°F' : '—' ?></strong><span>Temperature</span></div><div><strong><?= $averageHumidity !== null ? e(number_format($averageHumidity, 0)) . '%' : '—' ?></strong><span>Humidity</span></div><div><strong><?= $averageVpd !== null ? e(number_format($averageVpd, 1)) . ' kPa' : '—' ?></strong><span>VPD</span></div></div></article>
</section>

<section class="garden-tip"><span>♧</span><div><strong>Field-to-pantry tip</strong><p>Record readings consistently and move mature crops through harvest, inventory, preservation, and recipes while the source history is still fresh.</p></div><a href="phase6.php?section=harvests">Review harvests</a></section>

<?php elseif ($section === 'garden'): ?>
<section class="garden-workspace">
    <article class="garden-panel span-2"><div class="panel-heading"><div><p class="eyebrow">Current spaces</p><h2>Garden Zones</h2></div><span><?= count($zones) ?> configured</span></div><div class="zone-card-grid"><?php if ($zones === []): ?><p class="empty-state">No garden zones yet.</p><?php endif; ?><?php foreach ($zones as $zone): ?><article class="zone-card"><div><span class="zone-symbol">♧</span><div><h3><?= e((string)$zone['name']) ?></h3><p><?= e((string)$zone['zone_type']) ?><?= $zone['dimensions'] ? ' · ' . e((string)$zone['dimensions']) : '' ?></p></div></div><dl><div><dt>Temperature</dt><dd><?= $zone['latest_temperature'] !== null ? e((string)$zone['latest_temperature']) . '°F' : '—' ?></dd></div><div><dt>Humidity</dt><dd><?= $zone['latest_humidity'] !== null ? e((string)$zone['latest_humidity']) . '%' : '—' ?></dd></div><div><dt>Soil</dt><dd><?= $zone['latest_soil_moisture'] !== null ? e((string)$zone['latest_soil_moisture']) . '%' : '—' ?></dd></div><div><dt>Plantings</dt><dd><?= (int)$zone['active_plantings'] ?> active</dd></div></dl><small>Last reading <?= e((string)($zone['last_reading_at'] ?? 'not recorded')) ?></small></article><?php endforeach; ?></div></article>
    <?php if ($canManageGarden): ?><aside class="garden-form-stack"><article class="garden-panel"><div class="panel-heading"><h2>Add Garden Zone</h2></div><form method="post" class="garden-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create_zone"><label>Name<input name="name" maxlength="140" required></label><label>Type<input name="zone_type" maxlength="80" placeholder="Raised bed, greenhouse, indoor rack" required></label><label>Dimensions<input name="dimensions" maxlength="100"></label><div class="form-pair"><label>Temp min<input type="number" step="0.1" min="-100" max="250" name="target_temperature_min"></label><label>Temp max<input type="number" step="0.1" min="-100" max="250" name="target_temperature_max"></label></div><div class="form-pair"><label>Humidity min<input type="number" step="0.1" min="0" max="100" name="target_humidity_min"></label><label>Humidity max<input type="number" step="0.1" min="0" max="100" name="target_humidity_max"></label></div><button class="button primary" type="submit">Create zone</button></form></article></aside><?php endif; ?>
</section>
<section class="garden-workspace">
    <article class="garden-panel span-2"><div class="panel-heading"><div><p class="eyebrow">Crop lifecycle</p><h2>Planting Board</h2></div><span><?= $activePlantings ?> active</span></div><div class="plant-table"><div class="plant-row plant-head"><span>Crop</span><span>Zone</span><span>Stage</span><span>Planted</span><span>Expected harvest</span></div><?php if ($plantings === []): ?><p class="empty-state">No plantings yet.</p><?php endif; ?><?php foreach ($plantings as $row): ?><div class="plant-row"><span><i class="plant-thumb"></i><b><?= e((string)$row['crop_name']) ?></b><small><?= e((string)($row['variety'] ?? '')) ?></small></span><span><?= e((string)$row['zone_name']) ?></span><span><?php if ($canManageGarden && !in_array((string)$row['growth_stage'], ['completed','failed'], true)): ?><form method="post" class="stage-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="update_stage"><input type="hidden" name="planting_id" value="<?= (int)$row['id'] ?>"><select name="growth_stage" aria-label="Growth stage for <?= e((string)$row['crop_name']) ?>"><?php foreach (['germinating','seedling','vegetative','flowering','fruiting','harvest_ready','completed','failed'] as $stage): ?><option value="<?= $stage ?>" <?= $stage === (string)$row['growth_stage'] ? 'selected' : '' ?>><?= e(ucwords(str_replace('_',' ',$stage))) ?></option><?php endforeach; ?></select><button type="submit">Update</button></form><?php else: ?><?= e(ucwords(str_replace('_',' ',(string)$row['growth_stage']))) ?><?php endif; ?></span><span><?= e((string)$row['planted_on']) ?></span><span><?= e((string)($row['expected_harvest_start'] ?? '—')) ?> – <?= e((string)($row['expected_harvest_end'] ?? '—')) ?></span></div><?php endforeach; ?></div></article>
    <?php if ($canManageGarden): ?><aside class="garden-form-stack"><article class="garden-panel"><div class="panel-heading"><h2>Add Planting</h2></div><form method="post" class="garden-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create_planting"><label>Zone<select name="garden_zone_id" required><option value="">Choose zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= e((string)$zone['name']) ?></option><?php endforeach; ?></select></label><label>Crop<input name="crop_name" maxlength="140" required></label><label>Variety<input name="variety" maxlength="140"></label><div class="form-pair"><label>Planted on<input type="date" name="planted_on" value="<?= e(date('Y-m-d')) ?>" required></label><label>Plant count<input type="number" min="1" max="1000000" name="plant_count"></label></div><div class="form-pair"><label>Harvest start<input type="date" name="expected_harvest_start"></label><label>Harvest end<input type="date" name="expected_harvest_end"></label></div><label>Stage<select name="growth_stage"><?php foreach (['planned','germinating','seedling','vegetative','flowering','fruiting','harvest_ready'] as $stage): ?><option value="<?= $stage ?>"><?= e(ucwords(str_replace('_',' ',$stage))) ?></option><?php endforeach; ?></select></label><label>Notes<textarea name="notes" maxlength="5000"></textarea></label><button class="button primary" type="submit">Add planting</button></form></article></aside><?php endif; ?>
</section>
<section class="garden-workspace readings-workspace"><article class="garden-panel span-2"><div class="panel-heading"><div><p class="eyebrow">Environmental history</p><h2>Recent Garden Readings</h2></div><span><?= count($readings) ?> shown</span></div><div class="reading-grid"><?php if ($readings === []): ?><p class="empty-state">No readings recorded.</p><?php endif; ?><?php foreach ($readings as $reading): ?><article><strong><?= e((string)$reading['zone_name']) ?></strong><small><?= e((string)$reading['recorded_at']) ?></small><dl><div><dt>Temperature</dt><dd><?= e((string)($reading['temperature'] ?? '—')) ?></dd></div><div><dt>Humidity</dt><dd><?= e((string)($reading['humidity'] ?? '—')) ?></dd></div><div><dt>Soil</dt><dd><?= e((string)($reading['soil_moisture'] ?? '—')) ?></dd></div><div><dt>VPD</dt><dd><?= e((string)($reading['vpd'] ?? '—')) ?></dd></div><div><dt>Light</dt><dd><?= e((string)($reading['light_level'] ?? '—')) ?></dd></div><div><dt>Source</dt><dd><?= e((string)$reading['source']) ?></dd></div></dl></article><?php endforeach; ?></div></article><?php if ($canManageGarden): ?><aside class="garden-form-stack"><article class="garden-panel"><div class="panel-heading"><h2>Record Reading</h2></div><form method="post" class="garden-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="record_reading"><label>Zone<select name="garden_zone_id" required><option value="">Choose zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= e((string)$zone['name']) ?></option><?php endforeach; ?></select></label><label>Recorded at<input type="datetime-local" name="recorded_at" value="<?= e(date('Y-m-d\TH:i')) ?>"></label><div class="form-pair"><label>Temperature<input type="number" step="0.1" min="-100" max="250" name="temperature"></label><label>Humidity<input type="number" step="0.1" min="0" max="100" name="humidity"></label></div><div class="form-pair"><label>Soil moisture<input type="number" step="0.1" min="0" max="100" name="soil_moisture"></label><label>VPD<input type="number" step="0.01" min="0" max="10" name="vpd"></label></div><label>Light level<input type="number" step="0.1" min="0" max="10000000" name="light_level"></label><label>Source<select name="source"><option value="manual">Manual</option><option value="simulated">Simulated</option></select></label><button class="button primary" type="submit">Record reading</button></form></article></aside><?php endif; ?></section>

<?php elseif ($section === 'harvests'): ?>
<section class="garden-workspace"><article class="garden-panel span-2"><div class="panel-heading"><div><p class="eyebrow">Field-to-pantry history</p><h2>Harvest Records</h2></div><span><?= count($harvests) ?> recorded</span></div><div class="harvest-card-list"><?php if ($harvests === []): ?><p class="empty-state">No harvests recorded.</p><?php endif; ?><?php foreach ($harvests as $row): ?><article><div><span class="harvest-icon">♧</span><div><h3><?= e((string)$row['crop_name']) ?></h3><p><?= e((string)($row['variety'] ?? '')) ?> · <?= e((string)$row['zone_name']) ?></p></div></div><strong><?= e((string)$row['quantity']) ?> <?= e((string)$row['unit']) ?></strong><span><?= e((string)$row['destination']) ?></span><small><?= e((string)$row['harvested_at']) ?> · <?= $row['inventory_name'] ? 'Inventory: ' . e((string)$row['inventory_name']) : ($row['preservation_name'] ? 'Batch: ' . e((string)$row['preservation_name']) : 'Ledger only') ?></small></article><?php endforeach; ?></div></article><?php if ($canRecordHarvest): ?><aside class="garden-form-stack"><article class="garden-panel"><div class="panel-heading"><h2>Record Harvest</h2></div><form method="post" class="garden-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="record_harvest"><input type="hidden" name="action_key" value="<?= e((string)$_SESSION['harvest_action_key']) ?>"><label>Planting<select name="planting_id" required><option value="">Choose planting</option><?php foreach ($plantings as $planting): if ((string)$planting['growth_stage'] === 'failed') continue; ?><option value="<?= (int)$planting['id'] ?>"><?= e((string)$planting['crop_name']) ?> · <?= e((string)$planting['zone_name']) ?> · <?= e(str_replace('_',' ',(string)$planting['growth_stage'])) ?></option><?php endforeach; ?></select></label><div class="form-pair"><label>Quantity<input type="number" step="0.0001" min="0.0001" name="quantity" required></label><label>Unit<input name="unit" maxlength="30" placeholder="lb, oz, each" required></label></div><label>Grade<input name="grade" maxlength="60"></label><label>Harvested at<input type="datetime-local" name="harvested_at" value="<?= e(date('Y-m-d\TH:i')) ?>"></label><label>Destination<select name="destination"><option value="inventory">Inventory</option><option value="preservation">Preservation queue</option><option value="recipe">Immediate recipe use</option><option value="donation">Donation</option><option value="compost">Compost</option></select></label><label>Existing inventory<select name="inventory_item_id"><option value="">Create a new inventory item</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label><label>New inventory name<input name="new_inventory_name" maxlength="180" placeholder="Defaults to crop name"></label><label>Storage location<select name="storage_location_id"><option value="">Unassigned</option><?php foreach ($locations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?></option><?php endforeach; ?></select></label><label>Best-use date<input type="date" name="best_use_date"></label><label>Preservation method<select name="preservation_method"><option value="">Only needed for preservation queue</option><?php foreach (['water_bath','pressure_canning','dehydrating','fermenting','pickling','freezing','vacuum_sealing','dry_storage'] as $method): ?><option value="<?= $method ?>"><?= e(ucwords(str_replace('_',' ',$method))) ?></option><?php endforeach; ?></select></label><label class="check-row"><input type="checkbox" name="mark_complete" value="1"> Mark planting completed</label><label>Notes<textarea name="notes" maxlength="5000"></textarea></label><button class="button primary" type="submit">Post harvest</button></form></article></aside><?php endif; ?></section>

<?php else: ?>
<section class="preserve-metrics" aria-label="Preservation summary">
    <article><span class="preserve-metric-icon">▣</span><div><small>Canning batches</small><strong><?= $preservationGroupCount($preservationMethodGroups['canning']) ?></strong><em><?= (int)($preservationStatusCounts['stored'] ?? 0) ?> stored batches</em></div></article>
    <article><span class="preserve-metric-icon">▤</span><div><small>Dehydrator runs</small><strong><?= $preservationGroupCount($preservationMethodGroups['dehydrating']) ?></strong><em><?= e(number_format($totalPreservedUnits, 1)) ?> recorded units</em></div></article>
    <article><span class="preserve-metric-icon">◉</span><div><small>Fermentation projects</small><strong><?= $preservationGroupCount($preservationMethodGroups['fermenting']) ?></strong><em><?= (int)($preservationStatusCounts['planned'] ?? 0) + (int)($preservationStatusCounts['prepared'] ?? 0) ?> active or planned</em></div></article>
    <article class="preserve-warning-metric"><span class="preserve-metric-icon">△</span><div><small>Expiring preserves</small><strong><?= $expiringPreserves ?></strong><em>Within 30 days</em></div></article>
</section>

<section class="preserve-browser garden-panel">
    <div class="preserve-browser-tabs" role="tablist" aria-label="Preservation method groups">
        <button class="active" type="button" data-preserve-method="all">All batches</button>
        <button type="button" data-preserve-method="canning">Canning</button>
        <button type="button" data-preserve-method="dehydrating">Dehydrating</button>
        <button type="button" data-preserve-method="fermenting">Fermenting</button>
    </div>
    <div class="preserve-filters">
        <label class="preserve-search"><span aria-hidden="true">⌕</span><span class="visually-hidden">Search preservation batches</span><input type="search" placeholder="Search batches…" data-preserve-search></label>
        <label><span class="visually-hidden">Method</span><select data-preserve-select="method"><option value="all">All methods</option><?php foreach ($preservationMethodLabels as $method => $label): ?><option value="<?= e($method) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label><span class="visually-hidden">Status</span><select data-preserve-select="status"><option value="all">All statuses</option><?php foreach (array_keys($preservationStatusCounts) as $status): ?><option value="<?= e($status) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></label>
        <label><span class="visually-hidden">Location</span><select data-preserve-select="location"><option value="all">All locations</option><?php foreach (array_keys($preservationLocationCounts) as $location): ?><option value="<?= e(strtolower($location)) ?>"><?= e($location) ?></option><?php endforeach; ?></select></label>
        <button class="preserve-filter-reset" type="button" data-preserve-reset>Reset filters</button>
    </div>
    <div class="preserve-table-wrap" tabindex="0">
        <table class="preserve-table">
            <thead><tr><th>Batch name</th><th>Method</th><th>Date</th><th>Yield</th><th>Status</th><th>Shelf-life</th><th></th></tr></thead>
            <tbody>
            <?php if ($preservationBatches === []): ?><tr><td colspan="7" class="empty-state">No preservation batches yet.</td></tr><?php endif; ?>
            <?php foreach ($preservationBatches as $batch):
                $method = (string)$batch['method'];
                $status = (string)$batch['status'];
                $location = trim((string)($batch['location_name'] ?? '')) ?: 'Unassigned';
                $methodGroup = in_array($method, $preservationMethodGroups['canning'], true) ? 'canning' : (in_array($method, $preservationMethodGroups['dehydrating'], true) ? 'dehydrating' : (in_array($method, $preservationMethodGroups['fermenting'], true) ? 'fermenting' : 'other'));
                $recordDate = (string)($batch['completed_at'] ?? $batch['started_at'] ?? $batch['created_at'] ?? '');
                $bestUse = trim((string)($batch['best_use_date'] ?? ''));
                $expiryClass = '';
                if ($bestUse !== '') {
                    $bestUseObject = DateTimeImmutable::createFromFormat('!Y-m-d', $bestUse);
                    if ($bestUseObject && $bestUseObject < $today) $expiryClass = 'expired';
                    elseif ($bestUseObject && $bestUseObject <= $expiringThreshold) $expiryClass = 'expiring';
                }
            ?>
                <tr data-preserve-row data-name="<?= e(strtolower((string)$batch['name'])) ?>" data-method="<?= e($method) ?>" data-group="<?= e($methodGroup) ?>" data-status="<?= e($status) ?>" data-location="<?= e(strtolower($location)) ?>">
                    <td><a class="preserve-batch-name" href="phase6.php?section=preservation&amp;batch_id=<?= (int)$batch['id'] ?>"><img src="<?= e($preservationImageFor($method)) ?>" alt=""><span><strong><?= e((string)$batch['name']) ?></strong><small><?= e($location) ?></small></span></a></td>
                    <td><?= e($preservationMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method))) ?></td>
                    <td><?= $recordDate !== '' ? e(date('M j, Y', strtotime($recordDate))) : '—' ?></td>
                    <td><?= e((string)($batch['yield_quantity'] ?? '—')) ?> <?= e((string)($batch['yield_unit'] ?? '')) ?></td>
                    <td><span class="preserve-status status-<?= e($status) ?>">● <?= e(ucwords(str_replace('_', ' ', $status))) ?></span></td>
                    <td><span class="shelf-life <?= e($expiryClass) ?>"><?= $bestUse !== '' ? e(date('M j, Y', strtotime($bestUse))) : 'Ongoing' ?></span></td>
                    <td><a class="preserve-row-action" href="phase6.php?section=preservation&amp;batch_id=<?= (int)$batch['id'] ?>" aria-label="View <?= e((string)$batch['name']) ?>">•••</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="preserve-table-footer"><span data-preserve-count><?= count($preservationBatches) ?> batches shown</span><a href="phase6.php?section=harvests">Review source harvests →</a></div>
</section>

<?php if (is_array($selectedPreservationBatch)):
    $selectedMethod = (string)$selectedPreservationBatch['method'];
    $selectedStatus = (string)$selectedPreservationBatch['status'];
    $selectedStart = (string)($selectedPreservationBatch['started_at'] ?? $selectedPreservationBatch['created_at'] ?? '');
    $selectedCompleted = (string)($selectedPreservationBatch['completed_at'] ?? '');
?>
<section class="preserve-detail garden-panel">
    <div class="preserve-detail-photo"><img src="<?= e($preservationImageFor($selectedMethod)) ?>" alt="<?= e((string)$selectedPreservationBatch['name']) ?> preservation batch"></div>
    <div class="preserve-detail-content">
        <header><div><p class="eyebrow">Selected batch</p><h2><?= e((string)$selectedPreservationBatch['name']) ?> <span class="preserve-status status-<?= e($selectedStatus) ?>">● <?= e(ucwords(str_replace('_', ' ', $selectedStatus))) ?></span></h2><p><?= e($preservationMethodLabels[$selectedMethod] ?? ucwords(str_replace('_', ' ', $selectedMethod))) ?><?= $selectedStart !== '' ? ' · Batch from ' . e(date('M j, Y', strtotime($selectedStart))) : '' ?></p></div><?php if ($canManagePreservation): ?><a class="button secondary" href="#complete-preservation">Complete a batch</a><?php endif; ?></header>
        <div class="preserve-detail-grid">
            <dl><h3>Overview</h3><div><dt>Yield</dt><dd><?= e((string)($selectedPreservationBatch['yield_quantity'] ?? '—')) ?> <?= e((string)($selectedPreservationBatch['yield_unit'] ?? '')) ?></dd></div><div><dt>Status</dt><dd><?= e(ucwords(str_replace('_', ' ', $selectedStatus))) ?></dd></div><div><dt>Best by</dt><dd><?= !empty($selectedPreservationBatch['best_use_date']) ? e(date('M j, Y', strtotime((string)$selectedPreservationBatch['best_use_date']))) : 'Ongoing' ?></dd></div></dl>
            <dl><h3>Storage</h3><div><dt>Location</dt><dd><?= e((string)($selectedPreservationBatch['location_name'] ?? 'Unassigned')) ?></dd></div><div><dt>Output item</dt><dd><?= e((string)($selectedPreservationBatch['output_item_name'] ?? 'Not stocked')) ?></dd></div><div><dt>Inputs</dt><dd><?= (int)$selectedPreservationBatch['input_count'] ?></dd></div></dl>
            <dl><h3>Batch record</h3><div><dt>Started by</dt><dd><?= e((string)($selectedPreservationBatch['started_by'] ?? 'Household')) ?></dd></div><div><dt>Source</dt><dd><?= $selectedPreservationBatch['source_harvest_id'] ? 'Harvest #' . (int)$selectedPreservationBatch['source_harvest_id'] : 'Inventory' ?></dd></div><div><dt>Completed</dt><dd><?= $selectedCompleted !== '' ? e(date('M j, Y g:i A', strtotime($selectedCompleted))) : 'In progress' ?></dd></div></dl>
            <div class="preserve-notes"><h3>Label &amp; notes</h3><strong><?= e((string)$selectedPreservationBatch['name']) ?></strong><p><?= e((string)($selectedPreservationBatch['notes'] ?? 'No notes recorded.')) ?></p><?php if ($preservationSafetySource !== ''): ?><small>Safety reference: <?= e($preservationSafetySource) ?></small><?php endif; ?></div>
        </div>
        <div class="preserve-timeline"><h3>Batch timeline</h3><div><span class="done"><i>✣</i><strong>Prepared</strong><small><?= $selectedStart !== '' ? e(date('M j, Y', strtotime($selectedStart))) : 'Recorded' ?></small></span><span class="<?= $selectedStart !== '' ? 'done' : '' ?>"><i>♨</i><strong>Processed</strong><small><?= $selectedStart !== '' ? e(date('g:i A', strtotime($selectedStart))) : 'Pending' ?></small></span><span class="<?= $selectedCompleted !== '' ? 'done' : '' ?>"><i>◌</i><strong>Cooling</strong><small><?= $selectedCompleted !== '' ? e(date('M j, Y', strtotime($selectedCompleted))) : 'Pending' ?></small></span><span class="<?= $selectedCompleted !== '' ? 'done' : '' ?>"><i>◇</i><strong>Labeled</strong><small><?= $selectedCompleted !== '' ? 'Batch record complete' : 'Pending' ?></small></span><span class="<?= $selectedStatus === 'stored' ? 'done' : '' ?>"><i>▣</i><strong>Stored</strong><small><?= $selectedStatus === 'stored' ? e((string)($selectedPreservationBatch['location_name'] ?? 'Stored')) : 'Pending' ?></small></span></div></div>
    </div>
</section>
<?php endif; ?>

<?php if ($canManagePreservation): ?>
<section id="complete-preservation" class="preserve-complete garden-panel">
    <div class="panel-heading"><div><p class="eyebrow">Harvest to shelf</p><h2>Complete Preservation Batch</h2></div><span><?= count($plannedBatches) ?> planned</span></div>
    <form method="post" class="garden-form preserve-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="complete_preservation"><input type="hidden" name="action_key" value="<?= e((string)$_SESSION['preservation_action_key']) ?>">
        <label>Planned batch<select name="preservation_batch_id"><option value="">Create a new batch</option><?php foreach ($plannedBatches as $batch): ?><option value="<?= (int)$batch['id'] ?>"><?= e((string)$batch['name']) ?> · <?= e(str_replace('_',' ',(string)$batch['method'])) ?></option><?php endforeach; ?></select></label>
        <label>Batch name<input name="name" maxlength="180" required></label>
        <label>Method<select name="method" required><option value="">Choose method</option><?php foreach ($preservationMethodLabels as $method => $label): ?><option value="<?= e($method) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Input inventory<select name="input_inventory_item_id" required><option value="">Choose input</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label>
        <div class="form-pair"><label>Input quantity<input type="number" step="0.0001" min="0.0001" name="input_quantity" required></label><label>Input unit<input name="input_unit" maxlength="30" required></label></div>
        <label>Output item name<input name="output_name" maxlength="180" required></label>
        <div class="form-pair"><label>Output quantity<input type="number" step="0.0001" min="0.0001" name="output_quantity" required></label><label>Output unit<input name="output_unit" maxlength="30" placeholder="jars, lb, bags" required></label></div>
        <label>Storage location<select name="storage_location_id"><option value="">Unassigned</option><?php foreach ($locations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?></option><?php endforeach; ?></select></label>
        <label>Best-use date<input type="date" name="best_use_date"></label>
        <label class="preserve-form-wide">Safety source or reference<input name="safety_source" maxlength="1000" placeholder="Authoritative recipe, publication, or process reference"></label>
        <label class="preserve-form-wide">Batch notes<textarea name="notes" maxlength="5000"></textarea></label>
        <p class="form-note preserve-form-wide">Homestead records the process followed. It does not certify food safety; use authoritative, method-specific guidance.</p>
        <button class="button primary preserve-form-wide" type="submit">Complete and stock batch</button>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>
</main>
<?php if ($section === 'preservation'): ?><script src="assets/js/homestead-preserve.js" defer></script><?php else: ?><script src="assets/js/homestead-garden.js" defer></script><?php endif; ?>
</body>
</html>
