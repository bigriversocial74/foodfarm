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
            $_POST['action_key'] = (string)$_SESSION['harvest_action_key'];
            $harvestId = $service->recordHarvest($householdId, $memberId, $_POST);
            unset($_SESSION['harvest_action_key']);
            flash('success', 'Harvest #' . $harvestId . ' recorded and posted to the food lifecycle ledger.');
            redirect('/phase6.php?section=harvests');
        }
        if ($action === 'complete_preservation') {
            $auth->requirePermission($user, 'preservation.manage');
            $_POST['action_key'] = (string)$_SESSION['preservation_action_key'];
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

$activePlantings = count(array_filter($plantings, static fn(array $row): bool => !in_array((string)$row['growth_stage'], ['completed', 'failed'], true)));
$harvestReady = count(array_filter($plantings, static fn(array $row): bool => (string)$row['growth_stage'] === 'harvest_ready'));
$storedPreservation = count(array_filter($preservationBatches, static fn(array $row): bool => (string)$row['status'] === 'stored'));
$flashes = consume_flashes();
$csrf = csrf_token();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Grow, Harvest & Preserve · Homestead</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to grow and preserve operations</a>
<main id="main-content" class="page-container">
<header class="page-header">
    <div>
        <p class="eyebrow">Connected field-to-pantry operations</p>
        <h1>Grow, Harvest & Preserve</h1>
        <p class="page-description">Move household food from garden zones and planting plans into harvest records, inventory, preservation batches, and the food lifecycle ledger.</p>
    </div>
    <div class="toolbar">
        <a class="button secondary" href="/phase2.php?section=inventory">Inventory</a>
        <a class="button secondary" href="/phase4.php">Recipes</a>
        <a class="button secondary" href="/account.php">Account</a>
    </div>
</header>

<?php foreach ($flashes as $message): ?>
    <div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div>
<?php endforeach; ?>

<nav class="toolbar" aria-label="Grow and preserve sections">
    <?php if ($canViewGarden): ?><a class="button secondary" href="?section=overview">Overview</a><a class="button secondary" href="?section=garden">Garden</a><a class="button secondary" href="?section=harvests">Harvests</a><?php endif; ?>
    <?php if ($canViewPreservation): ?><a class="button secondary" href="?section=preservation">Preservation</a><?php endif; ?>
</nav>

<section class="metrics-grid compact" aria-label="Grow and preserve summary">
    <article class="metric-card"><div><p>Garden zones</p><strong><?= count($zones) ?></strong></div></article>
    <article class="metric-card"><div><p>Active plantings</p><strong><?= $activePlantings ?></strong></div></article>
    <article class="metric-card"><div><p>Harvest ready</p><strong><?= $harvestReady ?></strong></div></article>
    <article class="metric-card"><div><p>Stored batches</p><strong><?= $storedPreservation ?></strong></div></article>
</section>

<?php if ($section === 'overview'): ?>
<section class="content-grid">
    <article class="panel span-2">
        <p class="eyebrow">Lifecycle</p>
        <h2>Plant → Monitor → Harvest → Stock → Preserve → Cook</h2>
        <p class="page-description" style="margin-top:14px">Every harvest can create a traceable inventory and ledger record. Planned preservation batches can then consume the harvested ingredient and produce a separate preserved-food inventory item.</p>
    </article>
    <article class="panel">
        <p class="eyebrow">Attention</p>
        <h2><?= $harvestReady ?> planting<?= $harvestReady === 1 ? '' : 's' ?> ready</h2>
        <p class="page-description" style="margin-top:14px">Review maturity, record the actual yield, and choose inventory, preservation, recipe use, donation, or compost.</p>
    </article>
    <article class="panel span-2">
        <h2>Active crop board</h2>
        <div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Crop</th><th scope="col">Zone</th><th scope="col">Stage</th><th scope="col">Expected harvest</th><th scope="col">Harvests</th></tr></thead><tbody>
        <?php if ($plantings === []): ?><tr><td colspan="5">No plantings yet.</td></tr><?php endif; ?>
        <?php foreach (array_slice($plantings, 0, 12) as $row): ?><tr><td><strong><?= e((string)$row['crop_name']) ?></strong><?= $row['variety'] ? '<br><span class="page-description">' . e((string)$row['variety']) . '</span>' : '' ?></td><td><?= e((string)$row['zone_name']) ?></td><td><?= e(str_replace('_', ' ', (string)$row['growth_stage'])) ?></td><td><?= e((string)($row['expected_harvest_start'] ?? '—')) ?></td><td><?= (int)$row['harvest_count'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
    <article class="panel">
        <h2>Preservation queue</h2>
        <?php if ($plannedBatches === []): ?><p class="page-description">No planned preservation batches.</p><?php endif; ?>
        <?php foreach (array_slice($plannedBatches, 0, 8) as $batch): ?><div class="member-card" style="margin-bottom:10px"><strong><?= e((string)$batch['name']) ?></strong><br><span class="page-description"><?= e(str_replace('_', ' ', (string)$batch['method'])) ?> · <?= e((string)$batch['status']) ?></span></div><?php endforeach; ?>
    </article>
</section>

<?php elseif ($section === 'garden'): ?>
<section class="content-grid">
    <article class="panel span-2">
        <h2>Garden zones</h2>
        <div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Zone</th><th scope="col">Type</th><th scope="col">Plantings</th><th scope="col">Latest environment</th><th scope="col">Last reading</th></tr></thead><tbody>
        <?php if ($zones === []): ?><tr><td colspan="5">No garden zones yet.</td></tr><?php endif; ?>
        <?php foreach ($zones as $zone): ?><tr><td><strong><?= e((string)$zone['name']) ?></strong><br><span class="page-description"><?= e((string)($zone['dimensions'] ?? '')) ?></span></td><td><?= e((string)$zone['zone_type']) ?></td><td><?= (int)$zone['active_plantings'] ?> active / <?= (int)$zone['total_plantings'] ?> total</td><td><?= $zone['latest_temperature'] !== null ? e((string)$zone['latest_temperature']) . '°' : '—' ?> · <?= $zone['latest_humidity'] !== null ? e((string)$zone['latest_humidity']) . '% RH' : '—' ?> · <?= $zone['latest_soil_moisture'] !== null ? e((string)$zone['latest_soil_moisture']) . '% soil' : '—' ?></td><td><?= e((string)($zone['last_reading_at'] ?? '—')) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
    <?php if ($canManageGarden): ?>
    <article class="panel"><h2>Add garden zone</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create_zone"><label>Name<input class="search-field" name="name" maxlength="140" required></label><label>Type<input class="search-field" name="zone_type" maxlength="80" placeholder="Raised bed, greenhouse, indoor rack" required></label><label>Dimensions<input class="search-field" name="dimensions" maxlength="100"></label><label>Temperature range<div class="toolbar"><input class="search-field" type="number" step="0.1" min="-100" max="250" name="target_temperature_min" placeholder="Min"><input class="search-field" type="number" step="0.1" min="-100" max="250" name="target_temperature_max" placeholder="Max"></div></label><label>Humidity range<div class="toolbar"><input class="search-field" type="number" step="0.1" min="0" max="100" name="target_humidity_min" placeholder="Min"><input class="search-field" type="number" step="0.1" min="0" max="100" name="target_humidity_max" placeholder="Max"></div></label><button class="button primary" type="submit">Create zone</button></form></article>
    <article class="panel"><h2>Add planting</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create_planting"><label>Zone<select name="garden_zone_id" required><option value="">Choose zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= e((string)$zone['name']) ?></option><?php endforeach; ?></select></label><label>Crop<input class="search-field" name="crop_name" maxlength="140" required></label><label>Variety<input class="search-field" name="variety" maxlength="140"></label><label>Planted on<input class="search-field" type="date" name="planted_on" value="<?= e(date('Y-m-d')) ?>" required></label><label>Expected harvest start<input class="search-field" type="date" name="expected_harvest_start"></label><label>Expected harvest end<input class="search-field" type="date" name="expected_harvest_end"></label><label>Stage<select name="growth_stage"><option value="planned">Planned</option><option value="germinating">Germinating</option><option value="seedling">Seedling</option><option value="vegetative">Vegetative</option><option value="flowering">Flowering</option><option value="fruiting">Fruiting</option><option value="harvest_ready">Harvest ready</option></select></label><label>Plant count<input class="search-field" type="number" min="1" max="1000000" name="plant_count"></label><label>Notes<textarea name="notes" maxlength="5000"></textarea></label><button class="button primary" type="submit">Add planting</button></form></article>
    <?php endif; ?>
</section>
<section class="content-grid">
    <article class="panel span-2"><h2>Planting board</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Crop</th><th scope="col">Zone</th><th scope="col">Stage</th><th scope="col">Planted</th><th scope="col">Expected harvest</th><th scope="col">Progress</th></tr></thead><tbody><?php if ($plantings === []): ?><tr><td colspan="6">No plantings yet.</td></tr><?php endif; ?><?php foreach ($plantings as $row): ?><tr><td><strong><?= e((string)$row['crop_name']) ?></strong><br><span class="page-description"><?= e((string)($row['variety'] ?? '')) ?></span></td><td><?= e((string)$row['zone_name']) ?></td><td><?= e(str_replace('_', ' ', (string)$row['growth_stage'])) ?></td><td><?= e((string)$row['planted_on']) ?></td><td><?= e((string)($row['expected_harvest_start'] ?? '—')) ?> – <?= e((string)($row['expected_harvest_end'] ?? '—')) ?></td><td><?php if ($canManageGarden && !in_array((string)$row['growth_stage'], ['completed','failed'], true)): ?><form method="post" style="display:flex;gap:6px"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="update_stage"><input type="hidden" name="planting_id" value="<?= (int)$row['id'] ?>"><label class="visually-hidden" for="stage-<?= (int)$row['id'] ?>">Growth stage</label><select id="stage-<?= (int)$row['id'] ?>" name="growth_stage"><option value="germinating">Germinating</option><option value="seedling">Seedling</option><option value="vegetative">Vegetative</option><option value="flowering">Flowering</option><option value="fruiting">Fruiting</option><option value="harvest_ready">Harvest ready</option><option value="completed">Completed</option><option value="failed">Failed</option></select><button class="button secondary" type="submit">Update</button></form><?php else: ?><?= (int)$row['harvest_count'] ?> harvest record<?= (int)$row['harvest_count'] === 1 ? '' : 's' ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></article>
    <?php if ($canManageGarden): ?><article class="panel"><h2>Record environment</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="record_reading"><label>Zone<select name="garden_zone_id" required><option value="">Choose zone</option><?php foreach ($zones as $zone): ?><option value="<?= (int)$zone['id'] ?>"><?= e((string)$zone['name']) ?></option><?php endforeach; ?></select></label><label>Temperature<input class="search-field" type="number" step="0.1" min="-100" max="250" name="temperature"></label><label>Humidity %<input class="search-field" type="number" step="0.1" min="0" max="100" name="humidity"></label><label>Soil moisture %<input class="search-field" type="number" step="0.1" min="0" max="100" name="soil_moisture"></label><label>VPD<input class="search-field" type="number" step="0.001" min="0" max="20" name="vpd"></label><label>Light level<input class="search-field" type="number" step="0.1" min="0" max="10000000" name="light_level"></label><label>Source<select name="source"><option value="manual">Manual</option><option value="simulated">Simulated</option></select></label><button class="button primary" type="submit">Record reading</button></form></article><?php endif; ?>
    <article class="panel span-3"><h2>Recent garden readings</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Time</th><th scope="col">Zone</th><th scope="col">Temperature</th><th scope="col">Humidity</th><th scope="col">Soil</th><th scope="col">VPD</th><th scope="col">Light</th><th scope="col">Source</th></tr></thead><tbody><?php if ($readings === []): ?><tr><td colspan="8">No readings recorded.</td></tr><?php endif; ?><?php foreach ($readings as $reading): ?><tr><td><?= e((string)$reading['recorded_at']) ?></td><td><strong><?= e((string)$reading['zone_name']) ?></strong></td><td><?= e((string)($reading['temperature'] ?? '—')) ?></td><td><?= e((string)($reading['humidity'] ?? '—')) ?></td><td><?= e((string)($reading['soil_moisture'] ?? '—')) ?></td><td><?= e((string)($reading['vpd'] ?? '—')) ?></td><td><?= e((string)($reading['light_level'] ?? '—')) ?></td><td><?= e((string)$reading['source']) ?></td></tr><?php endforeach; ?></tbody></table></div></article>
</section>

<?php elseif ($section === 'harvests'): ?>
<section class="content-grid">
    <article class="panel span-2"><h2>Harvest history</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Harvested</th><th scope="col">Crop</th><th scope="col">Zone</th><th scope="col">Quantity</th><th scope="col">Destination</th><th scope="col">Linked record</th></tr></thead><tbody><?php if ($harvests === []): ?><tr><td colspan="6">No harvests recorded.</td></tr><?php endif; ?><?php foreach ($harvests as $row): ?><tr><td><?= e((string)$row['harvested_at']) ?></td><td><strong><?= e((string)$row['crop_name']) ?></strong><br><span class="page-description"><?= e((string)($row['variety'] ?? '')) ?></span></td><td><?= e((string)$row['zone_name']) ?></td><td><?= e((string)$row['quantity']) ?> <?= e((string)$row['unit']) ?></td><td><?= e((string)$row['destination']) ?></td><td><?= $row['inventory_name'] ? 'Inventory: ' . e((string)$row['inventory_name']) : ($row['preservation_name'] ? 'Batch: ' . e((string)$row['preservation_name']) : 'Ledger only') ?></td></tr><?php endforeach; ?></tbody></table></div></article>
    <?php if ($canRecordHarvest): ?><article class="panel"><h2>Record harvest</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="record_harvest"><label>Planting<select name="planting_id" required><option value="">Choose planting</option><?php foreach ($plantings as $planting): if ((string)$planting['growth_stage'] === 'failed') continue; ?><option value="<?= (int)$planting['id'] ?>"><?= e((string)$planting['crop_name']) ?> · <?= e((string)$planting['zone_name']) ?> · <?= e(str_replace('_', ' ', (string)$planting['growth_stage'])) ?></option><?php endforeach; ?></select></label><label>Quantity<input class="search-field" type="number" step="0.0001" min="0.0001" name="quantity" required></label><label>Unit<input class="search-field" name="unit" maxlength="30" placeholder="lb, oz, each" required></label><label>Grade<input class="search-field" name="grade" maxlength="60"></label><label>Harvested at<input class="search-field" type="datetime-local" name="harvested_at" value="<?= e(date('Y-m-d\TH:i')) ?>"></label><label>Destination<select name="destination"><option value="inventory">Inventory</option><option value="preservation">Preservation queue</option><option value="recipe">Immediate recipe use</option><option value="donation">Donation</option><option value="compost">Compost</option></select></label><label>Existing inventory<select name="inventory_item_id"><option value="">Create a new inventory item</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label><label>New inventory name<input class="search-field" name="new_inventory_name" maxlength="180" placeholder="Defaults to crop name"></label><label>Storage location<select name="storage_location_id"><option value="">Unassigned</option><?php foreach ($locations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?></option><?php endforeach; ?></select></label><label>Best-use date<input class="search-field" type="date" name="best_use_date"></label><label>Preservation method<select name="preservation_method"><option value="">Only needed for preservation queue</option><option value="water_bath">Water-bath canning</option><option value="pressure_canning">Pressure canning</option><option value="dehydrating">Dehydrating</option><option value="fermenting">Fermenting</option><option value="pickling">Pickling</option><option value="freezing">Freezing</option><option value="vacuum_sealing">Vacuum sealing</option><option value="dry_storage">Dry storage</option></select></label><label><input type="checkbox" name="mark_complete" value="1"> Mark planting completed</label><label>Notes<textarea name="notes" maxlength="5000"></textarea></label><button class="button primary" type="submit">Post harvest</button></form></article><?php endif; ?>
</section>

<?php else: ?>
<section class="content-grid">
    <article class="panel span-2"><h2>Preservation batches</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Batch</th><th scope="col">Method</th><th scope="col">Status</th><th scope="col">Yield</th><th scope="col">Stored</th><th scope="col">Best use</th><th scope="col">Source</th></tr></thead><tbody><?php if ($preservationBatches === []): ?><tr><td colspan="7">No preservation batches yet.</td></tr><?php endif; ?><?php foreach ($preservationBatches as $batch): ?><tr><td><strong><?= e((string)$batch['name']) ?></strong></td><td><?= e(str_replace('_', ' ', (string)$batch['method'])) ?></td><td><?= e((string)$batch['status']) ?></td><td><?= e((string)($batch['yield_quantity'] ?? '—')) ?> <?= e((string)($batch['yield_unit'] ?? '')) ?></td><td><?= e((string)($batch['location_name'] ?? '—')) ?><?= $batch['output_item_name'] ? '<br><span class="page-description">' . e((string)$batch['output_item_name']) . '</span>' : '' ?></td><td><?= e((string)($batch['best_use_date'] ?? '—')) ?></td><td><?= $batch['source_harvest_id'] ? 'Harvest #' . (int)$batch['source_harvest_id'] : ((int)$batch['input_count'] > 0 ? (int)$batch['input_count'] . ' inventory input' : 'Manual plan') ?></td></tr><?php endforeach; ?></tbody></table></div></article>
    <?php if ($canManagePreservation): ?><article class="panel"><h2>Complete preservation batch</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="complete_preservation"><label>Planned batch<select name="preservation_batch_id"><option value="">Create a new batch</option><?php foreach ($plannedBatches as $batch): ?><option value="<?= (int)$batch['id'] ?>"><?= e((string)$batch['name']) ?> · <?= e(str_replace('_', ' ', (string)$batch['method'])) ?></option><?php endforeach; ?></select></label><label>Batch name<input class="search-field" name="name" maxlength="180" required></label><label>Method<select name="method" required><option value="">Choose method</option><option value="water_bath">Water-bath canning</option><option value="pressure_canning">Pressure canning</option><option value="dehydrating">Dehydrating</option><option value="fermenting">Fermenting</option><option value="pickling">Pickling</option><option value="freezing">Freezing</option><option value="vacuum_sealing">Vacuum sealing</option><option value="dry_storage">Dry storage</option></select></label><label>Input inventory<select name="input_inventory_item_id" required><option value="">Choose input</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label><label>Input quantity<input class="search-field" type="number" step="0.0001" min="0.0001" name="input_quantity" required></label><label>Input unit<input class="search-field" name="input_unit" maxlength="30" required></label><label>Output item name<input class="search-field" name="output_name" maxlength="180" required></label><label>Output quantity<input class="search-field" type="number" step="0.0001" min="0.0001" name="output_quantity" required></label><label>Output unit<input class="search-field" name="output_unit" maxlength="30" placeholder="jars, lb, bags" required></label><label>Storage location<select name="storage_location_id"><option value="">Unassigned</option><?php foreach ($locations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?></option><?php endforeach; ?></select></label><label>Best-use date<input class="search-field" type="date" name="best_use_date"></label><label>Safety source or reference<input class="search-field" name="safety_source" maxlength="1000" placeholder="Authoritative recipe, publication, or process reference"></label><label>Batch notes<textarea name="notes" maxlength="5000"></textarea></label><p class="page-description">Homestead records the process you followed. It does not certify food safety; use authoritative, method-specific guidance.</p><button class="button primary" type="submit">Complete and stock batch</button></form></article><?php endif; ?>
</section>
<?php endif; ?>
</main>
</body>
</html>
