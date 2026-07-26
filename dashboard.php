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
$canViewForecast = $canViewInventory || $canViewGarden || $canViewPreservation || $canViewPlanning;
$canViewFinance = $auth->can($user, 'finance.view') || $auth->can($user, 'finance.manage');
$canViewNutrition = $auth->can($user, 'nutrition.view') || $auth->can($user, 'nutrition.manage');
$canViewNotifications = $auth->can($user, 'notifications.view') || $auth->can($user, 'notifications.manage');
$canManageAccess = $auth->can($user, 'members.manage') || $auth->can($user, 'members.invite') || $auth->can($user, 'permissions.manage');
$isPlatformAdmin = !empty($user['is_platform_admin']);

$scalar = static function (PDO $pdo, string $sql, array $params = []): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int)$statement->fetchColumn();
};

$phase8Available = false;
if ($canViewForecast) {
    $availability = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('forecast_snapshots','forecast_recommendations','seasonal_plan_entries')"
    );
    $availability->execute();
    $phase8Available = (int)$availability->fetchColumn() === 3;
}

$phase9Available = false;
if ($canViewFinance) {
    $availability = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('food_purchase_records','food_waste_events','household_finance_snapshots','finance_recommendations')"
    );
    $availability->execute();
    $phase9Available = (int)$availability->fetchColumn() === 4;
}

$phase10Available = false;
if ($canViewNutrition) {
    $availability = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('recipe_nutrition_snapshots','meal_nutrition_assessments','nutrition_recommendations')"
    );
    $availability->execute();
    $phase10Available = (int)$availability->fetchColumn() === 3;
}

$phase11Available = false;
if ($canViewNotifications) {
    $availability = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('household_notifications','household_calendar_events','notification_sync_runs')"
    );
    $availability->execute();
    $phase11Available = (int)$availability->fetchColumn() === 3;
}

$metrics = [];
if ($phase11Available) {
    $adultAccess = in_array((string)$user['role'], ['owner','administrator','adult_member'], true) ? 1 : 0;
    $notificationMetrics = $pdo->prepare(
        "SELECT
            SUM(status = 'unread') AS unread_count,
            SUM(priority IN ('high','critical') AND status IN ('unread','acknowledged')) AS urgent_count
         FROM household_notifications
         WHERE household_id = ? AND status <> 'expired'
           AND (
               visibility = 'household'
               OR (visibility = 'adults_only' AND ? = 1)
               OR (visibility = 'private' AND recipient_member_id = ?)
           )"
    );
    $notificationMetrics->execute([$householdId, $adultAccess, (int)$user['member_id']]);
    $notificationCounts = $notificationMetrics->fetch();
    if (is_array($notificationCounts)) {
        $metrics[] = ['label' => 'Unread alerts', 'value' => (int)$notificationCounts['unread_count'], 'prefix' => '', 'suffix' => '', 'href' => '/phase11.php'];
        $metrics[] = ['label' => 'Urgent alerts', 'value' => (int)$notificationCounts['urgent_count'], 'prefix' => '', 'suffix' => '', 'href' => '/phase11.php'];
    }
}

if ($phase10Available) {
    $latestNutrition = $pdo->prepare(
        "SELECT household_balance_score, allergen_conflict_count
         FROM meal_nutrition_assessments
         WHERE household_id = ? AND status = 'completed'
         ORDER BY completed_at DESC, id DESC LIMIT 1"
    );
    $latestNutrition->execute([$householdId]);
    $nutrition = $latestNutrition->fetch();
    if (is_array($nutrition)) {
        $metrics[] = [
            'label' => 'Meal balance',
            'value' => (int)round((float)$nutrition['household_balance_score']),
            'prefix' => '',
            'suffix' => '%',
            'href' => '/phase10.php',
        ];
        $metrics[] = [
            'label' => 'Allergen conflicts',
            'value' => (int)$nutrition['allergen_conflict_count'],
            'prefix' => '',
            'suffix' => '',
            'href' => '/phase10.php',
        ];
    }
}
if ($phase9Available) {
    $latestFinance = $pdo->prepare(
        "SELECT purchase_spend, waste_value, estimated_savings
         FROM household_finance_snapshots
         WHERE household_id = ? AND status = 'completed'
         ORDER BY month_start DESC, id DESC LIMIT 1"
    );
    $latestFinance->execute([$householdId]);
    $finance = $latestFinance->fetch();
    if (is_array($finance)) {
        $metrics[] = [
            'label' => 'Food spending',
            'value' => (int)round((float)$finance['purchase_spend']),
            'prefix' => '$',
            'suffix' => '',
            'href' => '/phase9.php',
        ];
        $metrics[] = [
            'label' => 'Waste value',
            'value' => (int)round((float)$finance['waste_value']),
            'prefix' => '$',
            'suffix' => '',
            'href' => '/phase9.php',
        ];
        $metrics[] = [
            'label' => 'Estimated savings',
            'value' => (int)round((float)$finance['estimated_savings']),
            'prefix' => '$',
            'suffix' => '',
            'href' => '/phase9.php',
        ];
    }
}
if ($phase8Available) {
    $latestForecast = $pdo->prepare(
        "SELECT resilience_score, projected_shortage_count
         FROM forecast_snapshots
         WHERE household_id = ? AND status = 'completed'
         ORDER BY as_of_date DESC, id DESC LIMIT 1"
    );
    $latestForecast->execute([$householdId]);
    $forecast = $latestForecast->fetch();
    if (is_array($forecast)) {
        $metrics[] = [
            'label' => 'Food resilience',
            'value' => (int)round((float)$forecast['resilience_score']),
            'prefix' => '',
            'suffix' => '%',
            'href' => '/phase8.php',
        ];
        $metrics[] = [
            'label' => 'Forecast shortages',
            'value' => (int)$forecast['projected_shortage_count'],
            'prefix' => '',
            'suffix' => '',
            'href' => '/phase8.php',
        ];
    }
}
if ($canViewPlanning) {
    $taskScope = $auth->can($user, 'tasks.manage') ? '' : ' AND (assigned_member_id IS NULL OR assigned_member_id = ?)';
    $taskParams = $auth->can($user, 'tasks.manage') ? [$householdId] : [$householdId, (int)$user['member_id']];
    $metrics[] = [
        'label' => 'Active tasks',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM household_tasks WHERE household_id = ? AND status IN ('planned','ready','in_progress')" . $taskScope, $taskParams),
        'prefix' => '',
        'suffix' => '',
        'href' => '/phase7.php',
    ];
    $metrics[] = [
        'label' => 'Overdue tasks',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM household_tasks WHERE household_id = ? AND status IN ('planned','ready','in_progress') AND due_at < UTC_TIMESTAMP()" . $taskScope, $taskParams),
        'prefix' => '',
        'suffix' => '',
        'href' => '/phase7.php',
    ];
}
if ($canViewInventory) {
    $metrics[] = [
        'label' => 'Active inventory',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM inventory_items WHERE household_id = ? AND status = 'active'", [$householdId]),
        'prefix' => '',
        'suffix' => '',
        'href' => '/phase2.php?section=inventory',
    ];
    $metrics[] = [
        'label' => 'Below reorder',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM inventory_items WHERE household_id = ? AND status = 'active' AND reorder_level IS NOT NULL AND current_quantity <= reorder_level", [$householdId]),
        'prefix' => '',
        'suffix' => '',
        'href' => '/phase2.php?section=inventory',
    ];
}
if ($canViewRecipes) {
    $metrics[] = [
        'label' => 'Active recipes',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM recipes WHERE household_id = ? AND status = 'active'", [$householdId]),
        'prefix' => '',
        'suffix' => '',
        'href' => '/phase4.php',
    ];
    $metrics[] = [
        'label' => 'Prepared batches',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM prepared_food_batches WHERE household_id = ? AND status IN ('active','frozen')", [$householdId]),
        'prefix' => '',
        'suffix' => '',
        'href' => '/prepared-food.php',
    ];
}
if ($canViewGarden) {
    $metrics[] = [
        'label' => 'Active plantings',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM plantings p JOIN garden_zones z ON z.id = p.garden_zone_id WHERE z.household_id = ? AND p.growth_stage NOT IN ('completed','failed')", [$householdId]),
        'prefix' => '',
        'suffix' => '',
        'href' => '/phase6.php?section=garden',
    ];
    $metrics[] = [
        'label' => 'Harvest ready',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM plantings p JOIN garden_zones z ON z.id = p.garden_zone_id WHERE z.household_id = ? AND p.growth_stage = 'harvest_ready'", [$householdId]),
        'prefix' => '',
        'suffix' => '',
        'href' => '/phase6.php?section=harvests',
    ];
}
if ($canViewPreservation) {
    $metrics[] = [
        'label' => 'Preservation queue',
        'value' => $scalar($pdo, "SELECT COUNT(*) FROM preservation_batches WHERE household_id = ? AND status IN ('planned','prepared')", [$householdId]),
        'prefix' => '',
        'suffix' => '',
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
        <p class="page-description">Detect, notify, assign, stock, grow, cook, preserve, balance household nutrition, measure cost and waste, and improve the next food cycle.</p>
    </div>
    <div class="toolbar">
        <a class="button secondary" href="/account.php">Account</a>
        <a class="button secondary" href="/logout.php">Sign out</a>
    </div>
</header>

<?php if ($metrics !== []): ?><section class="metrics-grid compact" aria-label="Household food metrics">
<?php foreach ($metrics as $metric): ?><a class="metric-card" href="<?= e((string)$metric['href']) ?>"><div><p><?= e((string)$metric['label']) ?></p><strong><?= e((string)($metric['prefix'] ?? '')) ?><?= (int)$metric['value'] ?><?= e((string)($metric['suffix'] ?? '')) ?></strong></div></a><?php endforeach; ?>
</section><?php endif; ?>

<section class="content-grid">
    <?php if ($phase11Available): ?><a class="panel" href="/phase11.php"><p class="eyebrow">Notify</p><h2>Alerts & shared calendar</h2><p class="page-description" style="margin-top:12px">One permission-aware inbox for tasks, shortages, meals, harvest windows, use-by dates, finance reviews, nutrition follow-up, digests, and ICS calendar export.</p></a><?php endif; ?>
    <?php if ($phase10Available): ?><a class="panel" href="/phase10.php"><p class="eyebrow">Balance</p><h2>Nutrition & dietary planning</h2><p class="page-description" style="margin-top:12px">Ingredient label data, optional family targets, dietary patterns, allergen controls, recipe nutrition, meal-plan assessments, and task-ready recommendations.</p></a><?php endif; ?>
    <?php if ($phase9Available): ?><a class="panel" href="/phase9.php"><p class="eyebrow">Measure</p><h2>Cost, waste & savings</h2><p class="page-description" style="margin-top:12px">Purchase prices, weighted unit costs, recipe cost per serving, budgets, waste value, supplier comparisons, household-production value, and savings recommendations.</p></a><?php endif; ?>
    <?php if ($phase8Available): ?><a class="panel" href="/phase8.php"><p class="eyebrow">Forecast</p><h2>Seasons & self-sufficiency</h2><p class="page-description" style="margin-top:12px">Pantry coverage, planned demand, days on hand, expected harvests, preservation output, seasonal plans, and evidence-linked recommendations.</p></a><?php endif; ?>
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
