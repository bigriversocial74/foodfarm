<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use Homestead\RecipeService;
use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$auth->requirePermission($user, 'recipes.complete');
$householdId = (int)$user['household_id'];
$memberId = (int)$user['member_id'];
$service = new RecipeService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        if (($_POST['action'] ?? null) !== 'update_prepared_food') {
            throw new InvalidArgumentException('Unknown prepared-food action.');
        }
        $actionId = $service->updatePreparedFood($householdId, $memberId, $_POST);
        unset($_SESSION['recipe_action_key']);
        flash('success', 'Prepared-food action #' . $actionId . ' recorded.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
    }
    redirect('/prepared-food.php');
}

$batches = $pdo->prepare(
    "SELECT pfb.*, hm.display_name AS prepared_by, sl.name AS location_name,
            ii.current_quantity AS inventory_quantity
     FROM prepared_food_batches pfb
     LEFT JOIN household_members hm ON hm.id = pfb.prepared_by_member_id AND hm.household_id = pfb.household_id
     LEFT JOIN storage_locations sl ON sl.id = pfb.storage_location_id AND sl.household_id = pfb.household_id
     LEFT JOIN inventory_items ii ON ii.id = pfb.inventory_item_id AND ii.household_id = pfb.household_id
     WHERE pfb.household_id = ?
     ORDER BY FIELD(pfb.status, 'active','frozen','consumed','spoiled','archived'), pfb.use_by_date, pfb.prepared_at DESC
     LIMIT 100"
);
$batches->execute([$householdId]);
$preparedFoods = $batches->fetchAll();
$locations = $pdo->prepare('SELECT id, name, location_type FROM storage_locations WHERE household_id = ? ORDER BY name');
$locations->execute([$householdId]);
$storageLocations = $locations->fetchAll();
$actions = $pdo->prepare(
    "SELECT pfa.*, pfb.name AS batch_name, hm.display_name AS member_name, sl.name AS destination_name
     FROM prepared_food_actions pfa
     JOIN prepared_food_batches pfb ON pfb.id = pfa.prepared_food_batch_id AND pfb.household_id = pfa.household_id
     LEFT JOIN household_members hm ON hm.id = pfa.member_id AND hm.household_id = pfa.household_id
     LEFT JOIN storage_locations sl ON sl.id = pfa.destination_location_id AND sl.household_id = pfa.household_id
     WHERE pfa.household_id = ? ORDER BY pfa.created_at DESC, pfa.id DESC LIMIT 100"
);
$actions->execute([$householdId]);
$history = $actions->fetchAll();
$flashes = consume_flashes();
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prepared Food & Leftovers · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body>
<a class="skip-link" href="#main-content">Skip to prepared food</a>
<main id="main-content" class="page-container">
<header class="page-header"><div><p class="eyebrow">Prepared-food lifecycle</p><h1>Prepared Food & Leftovers</h1><p class="page-description">Keep servings, inventory, storage, use-by dates, consumption, loss, freezing, and ledger history synchronized.</p></div><a class="button secondary" href="/phase4.php">Recipes & meal planning</a></header>
<?php foreach ($flashes as $message): ?><div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<section class="metrics-grid compact"><article class="metric-card"><div><p>Tracked batches</p><strong><?= count($preparedFoods) ?></strong></div></article><article class="metric-card"><div><p>Active or frozen</p><strong><?= count(array_filter($preparedFoods, static fn(array $batch): bool => in_array($batch['status'], ['active','frozen'], true))) ?></strong></div></article><article class="metric-card"><div><p>Servings remaining</p><strong><?= e(number_format(array_reduce($preparedFoods, static fn(float $sum, array $batch): float => $sum + (float)$batch['servings_remaining'], 0.0), 1)) ?></strong></div></article><article class="metric-card"><div><p>Lifecycle actions</p><strong><?= count($history) ?></strong></div></article></section>
<section class="panel"><h2>Current prepared food</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Food</th><th scope="col">Prepared</th><th scope="col">Remaining</th><th scope="col">Storage</th><th scope="col">Use by</th><th scope="col">Status</th><th scope="col">Record action</th></tr></thead><tbody>
<?php if ($preparedFoods === []): ?><tr><td colspan="7">Complete a recipe to create the first prepared-food batch.</td></tr><?php endif; ?>
<?php foreach ($preparedFoods as $batch): $open = in_array($batch['status'], ['active','frozen'], true) && (float)$batch['servings_remaining'] > 0; ?><tr><td><strong><?= e((string)$batch['name']) ?></strong><br><small>Prepared by <?= e((string)($batch['prepared_by'] ?: 'Household')) ?> · <?= e((string)$batch['prepared_at']) ?></small></td><td><?= e((string)$batch['servings_produced']) ?> servings</td><td><?= e((string)$batch['servings_remaining']) ?> servings<br><small>Inventory <?= e((string)($batch['inventory_quantity'] ?? 'missing')) ?></small></td><td><?= e(ucwords(str_replace('_',' ',(string)$batch['storage_method']))) ?><?= $batch['location_name'] ? '<br><small>'.e((string)$batch['location_name']).'</small>' : '' ?></td><td><?= e((string)($batch['use_by_date'] ?: 'Not set')) ?></td><td><?= e(ucfirst((string)$batch['status'])) ?></td><td><?php if ($open): ?><form method="post" class="form-grid compact-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_prepared_food"><input type="hidden" name="prepared_food_batch_id" value="<?= (int)$batch['id'] ?>"><label>Action<select name="prepared_action" required><option value="consumed">Consumed</option><option value="spoiled">Food loss</option><?php if ($batch['status'] !== 'frozen'): ?><option value="frozen">Move to freezer</option><?php endif; ?></select></label><label>Servings<input class="search-field" type="number" name="servings" min="0.01" max="<?= e((string)$batch['servings_remaining']) ?>" step="0.01" value="1"></label><label>Freezer location<select name="storage_location_id"><option value="">Required only when freezing</option><?php foreach ($storageLocations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?> · <?= e((string)$location['location_type']) ?></option><?php endforeach; ?></select></label><label>Notes<input class="search-field" name="notes" maxlength="5000"></label><button class="button secondary" type="submit">Record</button></form><?php else: ?>Closed<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel" style="margin-top:20px"><h2>Prepared-food action history</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Time</th><th scope="col">Food</th><th scope="col">Action</th><th scope="col">Quantity</th><th scope="col">Member</th><th scope="col">Destination</th><th scope="col">Notes</th></tr></thead><tbody><?php if ($history === []): ?><tr><td colspan="7">No prepared-food actions have been recorded.</td></tr><?php endif; ?><?php foreach ($history as $row): ?><tr><td><?= e((string)$row['created_at']) ?></td><td><strong><?= e((string)$row['batch_name']) ?></strong></td><td><?= e(ucfirst((string)$row['action_type'])) ?></td><td><?= e((string)$row['quantity']) ?> <?= e((string)$row['unit']) ?></td><td><?= e((string)($row['member_name'] ?: 'System')) ?></td><td><?= e((string)($row['destination_name'] ?: '—')) ?></td><td><?= e((string)($row['notes'] ?: '—')) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main>
</body>
</html>
