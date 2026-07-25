<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\e;

$user = $auth->requireUser();
$householdId = (int)$user['household_id'];

$canViewInventory = $auth->can($user, 'inventory.view') || $auth->can($user, 'inventory.manage');
$canViewRecipes = $auth->can($user, 'recipes.view') || $auth->can($user, 'recipes.manage') || $auth->can($user, 'recipes.complete');
$canViewGarden = $auth->can($user, 'garden.view') || $auth->can($user, 'garden.manage') || $auth->can($user, 'harvest.record');
$canViewPreservation = $auth->can($user, 'preservation.view') || $auth->can($user, 'preservation.manage');
$canViewPlanning = $auth->can($user, 'tasks.manage') || $auth->can($user, 'tasks.complete');
$canManageAccess = $auth->can($user, 'members.manage') || $auth->can($user, 'members.invite') || $auth->can($user, 'permissions.manage');
$isPlatformAdmin = !empty($user['is_platform_admin']);

$scalar = static function (PDO $pdo, string $sql, array $params = []): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int)$statement->fetchColumn();
};

$metrics = [];
if ($canViewPlanning) {
    $taskScope = $auth->can($user, 'tasks.manage') ? '' : ' AND (assigned_member_id IS NULL OR assigned_member_id = ?)';
    $taskParams = $auth->can($user, 'tasks.manage') ? [$householdId] : [$householdId, (int)$user['member_id']];
    $metrics[] = [
        'label' => 'Active tasks',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM household_tasks WHERE household_id = ? AND status IN ('planned','ready','in_progress')" . $taskScope, $taskParams),
        'href' => '/phase7.php',
    ];
    $metrics[] = [
        'label' => 'Overdue tasks',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM household_tasks WHERE household_id = ? AND status IN ('planned','ready','in_progress') AND due_at < UTC_TIMESTAMP()" . $taskScope, $taskParams),
        'href' => '/phase7.php',
    ];
}
if ($canViewInventory) {
    $metrics[] = [
        'label' => 'Active inventory',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM inventory_items WHERE household_id = ? AND status = 'active'", [$householdId]),
        'href' => '/phase2.php?section=inventory',
    ];
    $metrics[] = [
        'label' => 'Below reorder',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM inventory_items WHERE household_id = ? AND status = 'active' AND reorder_level IS NOT NULL AND current_quantity <= reorder_level", [$householdId]),
        'href' => '/phase2.php?section=inventory',
    ];
}
if ($canViewRecipes) {
    $metrics[] = [
        'label' => 'Active recipes',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM recipes WHERE household_id = ? AND status = 'active'", [$householdId]),
        'href' => '/phase4.php',
    ];
    $metrics[] = [
        'label' => 'Prepared batches',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM prepared_food_batches WHERE household_id = ? AND status IN ('active','frozen')", [$householdId]),
        'href' => '/prepared-food.php',
    ];
}
if ($canViewGarden) {
    $metrics[] = [
        'label' => 'Active plantings',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM plantings p JOIN garden_zones z ON z.id = p.garden_zone_id WHERE z.household_id = ? AND p.growth_stage NOT IN ('completed','failed')", [$householdId]),
        'href' => '/phase6.php?section=garden',
    ];
    $metrics[] = [
        'label' => 'Harvest ready',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM plantings p JOIN garden_zones z ON z.id = p.garden_zone_id WHERE z.household_id = ? AND p.growth_stage = 'harvest_ready'", [$householdId]),
        'href' => '/phase6.php?section=harvests',
    ];
}
if ($canViewPreservation) {
    $metrics[] = [
        'label' => 'Preservation queue',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM preservation_batches WHERE household_id = ? AND status IN ('planned','prepared')", [$householdId]),
        'href' => '/phase6.php?section=preservation',
    ];
}

$activityStatement = $pdo->prepare(
    "SELECT ae.event_key, ae.summary, ae.occurred_at, hm.display_name
     FROM activity_events ae
     LEFT JOIN household_members hm ON hm.id = ae.member_id AND hm.household_id = ae.household_id
     WHERE ae.household_id = ? AND ae.visibility = 'household'
     ORDER BY ae.occurred_at DESC, ae.id DESC LIMIT 12"
);
$activityStatement->execute([$householdId]);
$activities = $activityStatement->fetchAll();

$ledger = [];
if ($canViewInventory) {
    $ledgerStatement = $pdo->prepare(
        'SELECT e.event_type, e.quantity, e.unit, e.occurred_at, i.name AS item_name
         FROM food_ledger_events e
         LEFT JOIN inventory_items i ON i.id = e.inventory_item_id AND i.household_id = e.household_id
         WHERE e.household_id = ? ORDER BY e.occurred_at DESC, e.id DESC LIMIT 10'
    );
    $ledgerStatement->execute([$householdId]);
    $ledger = $ledgerStatement->fetchAll();
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Household Dashboard · Homestead</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to household dashboard</a>
<main id="main-content" class="page-container">
<header class="page-header">
    <div>
        <p class="eyebrow">Household food operating system</p>
        <h1>Welcome, <?= e((string)$user['display_name']) ?></h1>
        <p class="page-description">Plan, assign, stock, grow, cook, preserve, track, and restock from one permission-aware workspace.</p>
    </div>
    <div class="toolbar">
        <a class="button secondary" href="/account.php">Account</a>
        <a class="button secondary" href="/logout.php">Sign out</a>
    </div>
</header>

<?php if ($metrics !== []): ?><section class="metrics-grid compact" aria-label="Household food metrics">
<?php foreach ($metrics as $metric): ?><a class="metric-card" href="<?= e((string)$metric['href']) ?>"><div><p><?= e((string)$metric['label']) ?></p><strong><?= (int)$metric['value'] ?></strong></div></a><?php endforeach; ?>
</section><?php endif; ?>

<section class="content-grid">
    <?php if ($canViewPlanning): ?><a class="panel" href="/phase7.php"><p class="eyebrow">Coordinate</p><h2>Daily planning & tasks</h2><p class="page-description" style="margin-top:12px">Assignments, recurring duties, meal preparation, harvest windows, preservation follow-up, and shopping suggestions.</p></a><?php endif; ?>
    <?php if ($canViewInventory): ?><a class="panel" href="/phase2.php?section=inventory"><p class="eyebrow">Stock</p><h2>Household & inventory</h2><p class="page-description" style="margin-top:12px">Family profiles, storage locations, pantry quantities, reorder levels, and the food ledger.</p></a><?php endif; ?>
    <?php if ($canViewRecipes): ?><a class="panel" href="/phase4.php"><p class="eyebrow">Cook</p><h2>Recipes & meal planning</h2><p class="page-description" style="margin-top:12px">Connected recipes, ingredient deductions, family servings, meal plans, and prepared food.</p></a><?php endif; ?>
    <?php if ($canViewGarden): ?><a class="panel" href="/phase6.php"><p class="eyebrow">Grow</p><h2>Garden & harvest</h2><p class="page-description" style="margin-top:12px">Zones, crop stages, environmental readings, harvest destinations, and field-to-pantry provenance.</p></a><?php endif; ?>
    <?php if ($canViewPreservation): ?><a class="panel" href="/phase6.php?section=preservation"><p class="eyebrow">Preserve</p><h2>Preservation batches</h2><p class="page-description" style="margin-top:12px">Guarded input deductions, preserved-food outputs, storage, dates, and process references.</p></a><?php endif; ?>
    <?php if ($canManageAccess): ?><a class="panel" href="/phase3.php"><p class="eyebrow">Administer</p><h2>Family access</h2><p class="page-description" style="margin-top:12px">Invitations, roles, permission overrides, and authentication history.</p></a><?php endif; ?>
    <?php if ($isPlatformAdmin): ?><a class="panel" href="/phase5.php"><p class="eyebrow">Platform</p><h2>Starter Kits</h2><p class="page-description" style="margin-top:12px">Build, publish, order, and activate guided household food systems.</p></a><?php endif; ?>
</section>

<section class="content-grid">
    <article class="panel span-2">
        <h2>Recent household activity</h2>
        <div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Time</th><th scope="col">Activity</th><th scope="col">Member</th></tr></thead><tbody>
        <?php if ($activities === []): ?><tr><td colspan="3">No household activity yet.</td></tr><?php endif; ?>
        <?php foreach ($activities as $activity): ?><tr><td><?= e((string)$activity['occurred_at']) ?></td><td><strong><?= e((string)$activity['summary']) ?></strong><br><span class="page-description"><?= e(str_replace('_', ' ', (string)$activity['event_key'])) ?></span></td><td><?= e((string)($activity['display_name'] ?? 'Household')) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
    <?php if ($canViewInventory): ?><article class="panel">
        <h2>Latest food-ledger events</h2>
        <?php if ($ledger === []): ?><p class="page-description">No food-ledger activity yet.</p><?php endif; ?>
        <?php foreach ($ledger as $event): ?><div class="member-card" style="margin-bottom:10px"><strong><?= e((string)($event['item_name'] ?? 'Household food')) ?></strong><br><span class="page-description"><?= e(str_replace('_', ' ', (string)$event['event_type'])) ?> · <?= e((string)$event['quantity']) ?> <?= e((string)$event['unit']) ?> · <?= e((string)$event['occurred_at']) ?></span></div><?php endforeach; ?>
    </article><?php endif; ?>
</section>
</main>
</body>
</html>
