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
$today = new DateTimeImmutable('today');
$activeBatches = array_values(array_filter($preparedFoods, static fn(array $batch): bool => in_array((string)$batch['status'], ['active', 'frozen'], true) && (float)$batch['servings_remaining'] > 0));
$frozenBatches = array_values(array_filter($preparedFoods, static fn(array $batch): bool => (string)$batch['status'] === 'frozen'));
$expiringBatches = array_values(array_filter($activeBatches, static function (array $batch) use ($today): bool {
    if (empty($batch['use_by_date'])) { return false; }
    $useBy = new DateTimeImmutable((string)$batch['use_by_date']);
    return $useBy >= $today && $useBy <= $today->modify('+3 days');
}));
$overdueBatches = array_values(array_filter($activeBatches, static function (array $batch) use ($today): bool {
    return !empty($batch['use_by_date']) && new DateTimeImmutable((string)$batch['use_by_date']) < $today;
}));
$remainingServings = array_reduce($activeBatches, static fn(float $sum, array $batch): float => $sum + (float)$batch['servings_remaining'], 0.0);
$producedServings = array_reduce($preparedFoods, static fn(float $sum, array $batch): float => $sum + (float)$batch['servings_produced'], 0.0);
$consumedServings = array_reduce($history, static fn(float $sum, array $row): float => $sum + ((string)$row['action_type'] === 'consumed' ? (float)$row['quantity'] : 0.0), 0.0);
$lossServings = array_reduce($history, static fn(float $sum, array $row): float => $sum + ((string)$row['action_type'] === 'spoiled' ? (float)$row['quantity'] : 0.0), 0.0);
$storageCounts = [];
foreach ($activeBatches as $batch) {
    $storage = (string)$batch['storage_method'];
    $storageCounts[$storage] = ($storageCounts[$storage] ?? 0) + 1;
}
arsort($storageCounts);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title>Prepared Food & Leftovers · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to prepared food</a>
<main id="main-content" class="page-container prepared-page">
    <section class="prepared-hero">
        <div class="prepared-hero__copy">
            <p class="prepared-kicker">Prepared-food lifecycle</p>
            <h1>Cook once. <span>Waste less.</span></h1>
            <p>See every prepared batch, remaining serving, storage location, use-by date, and household action in one calm operating view.</p>
            <div class="prepared-hero__links">
                <a href="phase4.php">Recipes & meal planning</a>
                <a href="phase2.php?section=inventory">Pantry inventory</a>
                <a href="#prepared-history">Lifecycle history</a>
            </div>
        </div>
        <div class="prepared-hero__summary" aria-label="Prepared food priority summary">
            <p class="prepared-kicker">Use first</p>
            <strong><?= count($overdueBatches) + count($expiringBatches) ?></strong>
            <span><?= count($overdueBatches) ?> overdue · <?= count($expiringBatches) ?> due within 3 days</span>
            <div class="prepared-hero__bar"><span style="width: <?= $activeBatches === [] ? 0 : min(100, round(((count($overdueBatches) + count($expiringBatches)) / count($activeBatches)) * 100)) ?>%"></span></div>
            <small><?= e(number_format($remainingServings, 1)) ?> servings currently available</small>
        </div>
    </section>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="prepared-flash prepared-flash--<?= $message['type'] === 'error' ? 'warning' : 'good' ?>"><?= e((string)$message['message']) ?></div>
    <?php endforeach; ?>

    <section class="prepared-metrics" aria-label="Prepared-food metrics">
        <article><span>◫</span><div><small>Tracked batches</small><strong><?= count($preparedFoods) ?></strong><p>All lifecycle states</p></div></article>
        <article><span>◉</span><div><small>Open batches</small><strong><?= count($activeBatches) ?></strong><p>Active or frozen</p></div></article>
        <article><span>◒</span><div><small>Servings ready</small><strong><?= e(number_format($remainingServings, 1)) ?></strong><p>Across open batches</p></div></article>
        <article class="prepared-metric--blue"><span>❄</span><div><small>Frozen</small><strong><?= count($frozenBatches) ?></strong><p>Extended storage</p></div></article>
        <article class="prepared-metric--danger"><span>!</span><div><small>Use first</small><strong><?= count($overdueBatches) + count($expiringBatches) ?></strong><p>Overdue or near due</p></div></article>
        <article class="prepared-metric--green"><span>✓</span><div><small>Consumed</small><strong><?= e(number_format($consumedServings, 1)) ?></strong><p><?= e(number_format($lossServings, 1)) ?> servings lost</p></div></article>
    </section>

    <div class="prepared-layout">
        <div class="prepared-main">
            <section class="prepared-panel">
                <div class="prepared-panel__heading prepared-panel__heading--toolbar">
                    <div><p class="prepared-kicker">Household food queue</p><h2>Current prepared food</h2></div>
                    <label class="prepared-search"><span>⌕</span><input type="search" placeholder="Search food, storage, or member" data-prepared-search></label>
                </div>
                <div class="prepared-tabs" role="tablist" aria-label="Prepared-food filters">
                    <button type="button" class="active" data-prepared-filter="all">All <span><?= count($preparedFoods) ?></span></button>
                    <button type="button" data-prepared-filter="priority">Use first <span><?= count($overdueBatches) + count($expiringBatches) ?></span></button>
                    <button type="button" data-prepared-filter="active">Active <span><?= count(array_filter($preparedFoods, static fn(array $b): bool => (string)$b['status'] === 'active')) ?></span></button>
                    <button type="button" data-prepared-filter="frozen">Frozen <span><?= count($frozenBatches) ?></span></button>
                    <button type="button" data-prepared-filter="closed">Closed <span><?= count($preparedFoods) - count($activeBatches) ?></span></button>
                </div>
                <div class="prepared-batches" data-prepared-list>
                    <?php if ($preparedFoods === []): ?>
                        <div class="prepared-empty"><strong>No prepared food yet</strong><p>Complete a recipe to create the first tracked batch.</p><a href="phase4.php">Open recipes</a></div>
                    <?php endif; ?>
                    <?php foreach ($preparedFoods as $batch):
                        $status = (string)$batch['status'];
                        $open = in_array($status, ['active','frozen'], true) && (float)$batch['servings_remaining'] > 0;
                        $useBy = !empty($batch['use_by_date']) ? new DateTimeImmutable((string)$batch['use_by_date']) : null;
                        $days = $useBy ? (int)$today->diff($useBy)->format('%r%a') : null;
                        $priority = $open && $days !== null && $days <= 3;
                        $remaining = (float)$batch['servings_remaining'];
                        $produced = max(.01, (float)$batch['servings_produced']);
                        $remainingPct = min(100, max(0, ($remaining / $produced) * 100));
                        $searchText = strtolower(implode(' ', [(string)$batch['name'], (string)($batch['prepared_by'] ?? ''), (string)($batch['location_name'] ?? ''), (string)$batch['storage_method'], $status]));
                    ?>
                    <article class="prepared-batch <?= $priority ? 'prepared-batch--priority' : '' ?>" data-status="<?= e($status) ?>" data-priority="<?= $priority ? '1' : '0' ?>" data-search="<?= e($searchText) ?>">
                        <div class="prepared-batch__icon" aria-hidden="true"><?= $status === 'frozen' ? '❄' : ($open ? '◉' : '○') ?></div>
                        <div class="prepared-batch__body">
                            <div class="prepared-batch__title">
                                <div><h3><?= e((string)$batch['name']) ?></h3><p>Prepared by <?= e((string)($batch['prepared_by'] ?: 'Household')) ?> · <?= e(date('M j, Y', strtotime((string)$batch['prepared_at']))) ?></p></div>
                                <span class="prepared-status prepared-status--<?= e($status) ?>"><?= e(ucfirst($status)) ?></span>
                            </div>
                            <div class="prepared-batch__facts">
                                <span><small>Remaining</small><strong><?= e(number_format($remaining, 1)) ?> / <?= e(number_format((float)$batch['servings_produced'], 1)) ?></strong></span>
                                <span><small>Storage</small><strong><?= e(ucwords(str_replace('_',' ',(string)$batch['storage_method']))) ?></strong></span>
                                <span><small>Location</small><strong><?= e((string)($batch['location_name'] ?: 'Not assigned')) ?></strong></span>
                                <span class="<?= $priority ? 'prepared-fact--urgent' : '' ?>"><small>Use by</small><strong><?= $useBy ? e($useBy->format('M j, Y')) : 'Not set' ?></strong></span>
                            </div>
                            <div class="prepared-progress"><span style="width: <?= e(number_format($remainingPct, 2, '.', '')) ?>%"></span></div>
                            <?php if (!empty($batch['reheating_notes'])): ?><p class="prepared-batch__note"><strong>Reheating:</strong> <?= e((string)$batch['reheating_notes']) ?></p><?php endif; ?>
                        </div>
                        <div class="prepared-batch__action">
                            <?php if ($open): ?>
                                <details>
                                    <summary>Record action</summary>
                                    <form method="post" class="prepared-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update_prepared_food">
                                        <input type="hidden" name="prepared_food_batch_id" value="<?= (int)$batch['id'] ?>">
                                        <label><span>Action</span><select name="prepared_action" required><option value="consumed">Consumed</option><option value="spoiled">Food loss</option><?php if ($status !== 'frozen'): ?><option value="frozen">Move to freezer</option><?php endif; ?></select></label>
                                        <label><span>Servings</span><input type="number" name="servings" min="0.01" max="<?= e((string)$batch['servings_remaining']) ?>" step="0.01" value="1"></label>
                                        <label><span>Freezer location</span><select name="storage_location_id"><option value="">Only required when freezing</option><?php foreach ($storageLocations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?> · <?= e((string)$location['location_type']) ?></option><?php endforeach; ?></select></label>
                                        <label><span>Notes</span><textarea name="notes" maxlength="5000" rows="2"></textarea></label>
                                        <button type="submit">Record lifecycle action</button>
                                    </form>
                                </details>
                            <?php else: ?><span class="prepared-closed">Lifecycle closed</span><?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section id="prepared-history" class="prepared-panel">
                <div class="prepared-panel__heading"><div><p class="prepared-kicker">Immutable household record</p><h2>Lifecycle history</h2></div><span><?= count($history) ?> actions</span></div>
                <div class="prepared-history">
                    <?php if ($history === []): ?><div class="prepared-empty"><strong>No actions recorded</strong><p>Consumption, food loss, and freezing activity will appear here.</p></div><?php endif; ?>
                    <?php foreach ($history as $row): ?>
                        <article>
                            <span class="prepared-history__mark prepared-history__mark--<?= e((string)$row['action_type']) ?>"><?= (string)$row['action_type'] === 'frozen' ? '❄' : ((string)$row['action_type'] === 'spoiled' ? '!' : '✓') ?></span>
                            <div><strong><?= e((string)$row['batch_name']) ?></strong><p><?= e(ucfirst((string)$row['action_type'])) ?> · <?= e(number_format((float)$row['quantity'], 1)) ?> <?= e((string)$row['unit']) ?><?= $row['destination_name'] ? ' · '.e((string)$row['destination_name']) : '' ?></p><?php if ($row['notes']): ?><small><?= e((string)$row['notes']) ?></small><?php endif; ?></div>
                            <time><?= e(date('M j, g:i a', strtotime((string)$row['created_at']))) ?><small><?= e((string)($row['member_name'] ?: 'System')) ?></small></time>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <aside class="prepared-sidebar">
            <section class="prepared-panel">
                <div class="prepared-panel__heading"><div><p class="prepared-kicker">Storage distribution</p><h2>Where food lives</h2></div></div>
                <div class="prepared-storage">
                    <?php if ($storageCounts === []): ?><p>No open batches to summarize.</p><?php endif; ?>
                    <?php foreach ($storageCounts as $method => $count): ?>
                        <div><span><?= e(ucwords(str_replace('_',' ',$method))) ?></span><strong><?= (int)$count ?></strong><i><b style="width: <?= e(number_format(($count / max(1, count($activeBatches))) * 100, 2, '.', '')) ?>%"></b></i></div>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="prepared-panel prepared-insight">
                <p class="prepared-kicker">Kitchen intelligence</p>
                <h2><?= count($overdueBatches) > 0 ? 'Food needs attention today.' : (count($expiringBatches) > 0 ? 'Plan the next meals around use-by dates.' : 'Prepared food is under control.') ?></h2>
                <p><?= count($overdueBatches) ?> overdue batches, <?= count($expiringBatches) ?> nearing use-by, and <?= count($frozenBatches) ?> safely frozen.</p>
                <a href="phase4.php#meal-plan">Review meal plan</a>
            </section>
            <section class="prepared-panel">
                <div class="prepared-panel__heading"><div><p class="prepared-kicker">Lifecycle yield</p><h2>Food utilization</h2></div></div>
                <div class="prepared-utilization">
                    <strong><?= $producedServings > 0 ? e(number_format(($consumedServings / $producedServings) * 100, 0)) : '0' ?>%</strong>
                    <span>of recorded production consumed</span>
                    <div><i style="width: <?= e(number_format($producedServings > 0 ? min(100, ($consumedServings / $producedServings) * 100) : 0, 2, '.', '')) ?>%"></i></div>
                    <small><?= e(number_format($producedServings, 1)) ?> produced · <?= e(number_format($consumedServings, 1)) ?> consumed · <?= e(number_format($lossServings, 1)) ?> lost</small>
                </div>
            </section>
        </aside>
    </div>
</main>
<script src="assets/js/homestead-prepared.js?v=20260727-1"></script>
</body>
</html>
