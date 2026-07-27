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


$metricByLabel = [];
foreach ($metrics as $metric) {
    $metricByLabel[(string)$metric['label']] = $metric;
}
$metricValue = static function (string $label, int $default = 0) use ($metricByLabel): int {
    return isset($metricByLabel[$label]) ? (int)$metricByLabel[$label]['value'] : $default;
};
$formatCurrency = static fn(float $value): string => '$' . number_format($value, 0);
$formatDate = static function (?string $value, string $fallback = 'Not scheduled'): string {
    if ($value === null || trim($value) === '') {
        return $fallback;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $fallback : date('M j, g:i A', $timestamp);
};

$inventoryCount = $metricValue('Active inventory');
$belowReorder = $metricValue('Below reorder');
$activeRecipes = $metricValue('Active recipes');
$activePlantings = $metricValue('Active plantings');
$harvestReady = $metricValue('Harvest ready');
$preservationQueue = $metricValue('Preservation queue');
$activeTasks = $metricValue('Active tasks');
$overdueTasks = $metricValue('Overdue tasks');
$unreadAlerts = $metricValue('Unread alerts');
$urgentAlerts = $metricValue('Urgent alerts');

$inventoryValue = 0.0;
$expiringSoon = 0;
$categoryCount = 0;
if ($canViewInventory) {
    $inventorySummary = $pdo->prepare(
        "SELECT
            COALESCE(SUM(current_quantity * COALESCE(purchase_cost, 0)), 0) AS inventory_value,
            SUM(best_use_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring_soon,
            COUNT(DISTINCT category_id) AS category_count
         FROM inventory_items
         WHERE household_id = ? AND status = 'active'"
    );
    $inventorySummary->execute([$householdId]);
    $inventorySummaryRow = $inventorySummary->fetch();
    if (is_array($inventorySummaryRow)) {
        $inventoryValue = (float)$inventorySummaryRow['inventory_value'];
        $expiringSoon = (int)$inventorySummaryRow['expiring_soon'];
        $categoryCount = (int)$inventorySummaryRow['category_count'];
    }
}
$stockedPercent = $inventoryCount > 0
    ? max(0, min(100, (int)round((($inventoryCount - $belowReorder) / $inventoryCount) * 100)))
    : 100;

$activeZones = 0;
$thrivingPlantings = 0;
$nextHarvest = null;
if ($canViewGarden) {
    $activeZones = $scalar($pdo, 'SELECT COUNT(*) FROM garden_zones WHERE household_id = ? AND active = 1', [$householdId]);
    $thrivingPlantings = $scalar(
        $pdo,
        "SELECT COUNT(*) FROM plantings p
         JOIN garden_zones z ON z.id = p.garden_zone_id
         WHERE z.household_id = ?
           AND p.growth_stage IN ('seedling','vegetative','flowering','fruiting','harvest_ready')",
        [$householdId]
    );
    $nextHarvestStatement = $pdo->prepare(
        "SELECT p.crop_name, p.variety, p.expected_harvest_start
         FROM plantings p
         JOIN garden_zones z ON z.id = p.garden_zone_id
         WHERE z.household_id = ?
           AND p.growth_stage NOT IN ('completed','failed')
           AND p.expected_harvest_start IS NOT NULL
         ORDER BY p.expected_harvest_start ASC, p.id ASC LIMIT 1"
    );
    $nextHarvestStatement->execute([$householdId]);
    $nextHarvest = $nextHarvestStatement->fetch() ?: null;
}

$preservationActive = 0;
$preservationStored = 0;
$preservationExpiring = 0;
$preservationMethod = null;
if ($canViewPreservation) {
    $preservationSummary = $pdo->prepare(
        "SELECT
            SUM(status IN ('planned','prepared','processed','cooling','labeled')) AS active_count,
            SUM(status = 'stored') AS stored_count,
            SUM(best_use_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring_count
         FROM preservation_batches WHERE household_id = ?"
    );
    $preservationSummary->execute([$householdId]);
    $preservationSummaryRow = $preservationSummary->fetch();
    if (is_array($preservationSummaryRow)) {
        $preservationActive = (int)$preservationSummaryRow['active_count'];
        $preservationStored = (int)$preservationSummaryRow['stored_count'];
        $preservationExpiring = (int)$preservationSummaryRow['expiring_count'];
    }
    $methodStatement = $pdo->prepare(
        "SELECT method, COUNT(*) AS method_count
         FROM preservation_batches
         WHERE household_id = ? AND status NOT IN ('finished','discarded')
         GROUP BY method ORDER BY method_count DESC, method LIMIT 1"
    );
    $methodStatement->execute([$householdId]);
    $methodRow = $methodStatement->fetch();
    $preservationMethod = is_array($methodRow) ? (string)$methodRow['method'] : null;
}

$mealPlanCount = 0;
if ($canViewRecipes) {
    $mealPlanCount = $scalar($pdo, "SELECT COUNT(*) FROM meal_plans WHERE household_id = ? AND status = 'active'", [$householdId]);
}

$dashboardAlerts = [];
if ($phase11Available) {
    $alertStatement = $pdo->prepare(
        "SELECT title, body, category, priority, status, COALESCE(due_at, occurs_at, created_at) AS attention_at
         FROM household_notifications
         WHERE household_id = ?
           AND status IN ('unread','acknowledged')
           AND (
               visibility = 'household'
               OR (visibility = 'adults_only' AND ? = 1)
               OR (visibility = 'private' AND recipient_member_id = ?)
           )
         ORDER BY CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
                  COALESCE(due_at, occurs_at, created_at), id DESC
         LIMIT 4"
    );
    $alertStatement->execute([$householdId, $adultAccess, (int)$user['member_id']]);
    $dashboardAlerts = $alertStatement->fetchAll();
}

$dashboardTasks = [];
$completedThisWeek = 0;
if ($canViewPlanning) {
    $manageTasks = $auth->can($user, 'tasks.manage');
    $taskVisibility = $manageTasks ? '' : ' AND (t.assigned_member_id IS NULL OR t.assigned_member_id = ?)';
    $taskQueryParams = $manageTasks ? [$householdId] : [$householdId, (int)$user['member_id']];
    $taskStatement = $pdo->prepare(
        "SELECT t.title, t.due_at, t.priority, t.status, hm.display_name
         FROM household_tasks t
         LEFT JOIN household_members hm ON hm.id = t.assigned_member_id AND hm.household_id = t.household_id
         WHERE t.household_id = ? AND t.status IN ('planned','ready','in_progress')" . $taskVisibility . "
         ORDER BY t.due_at IS NULL, t.due_at ASC, t.id ASC LIMIT 7"
    );
    $taskStatement->execute($taskQueryParams);
    $dashboardTasks = $taskStatement->fetchAll();

    $completedParams = $manageTasks ? [$householdId] : [$householdId, (int)$user['member_id']];
    $completedScope = $manageTasks ? '' : ' AND (assigned_member_id IS NULL OR assigned_member_id = ?)';
    $completedThisWeek = $scalar(
        $pdo,
        "SELECT COUNT(*) FROM household_tasks
         WHERE household_id = ? AND status = 'completed'
           AND completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)" . $completedScope,
        $completedParams
    );
}

$overviewCards = [];
if ($canViewInventory) {
    $overviewCards[] = [
        'title' => 'Pantry',
        'image' => 'assets/images/homestead/sheet-05/preservation-jars-wide.png',
        'value' => $inventoryCount,
        'unit' => 'items',
        'status' => $stockedPercent . '% well stocked',
        'rows' => [
            ['Low stock alerts', $belowReorder],
            ['Categories stocked', $categoryCount],
            ['Inventory value', $formatCurrency($inventoryValue)],
        ],
        'href' => 'phase2.php?section=inventory',
    ];
}
if ($canViewGarden) {
    $overviewCards[] = [
        'title' => 'Garden',
        'image' => 'assets/images/homestead/sheet-04/garden-monitoring-wide.png',
        'value' => $activePlantings,
        'unit' => 'growing',
        'status' => $thrivingPlantings . ' thriving',
        'rows' => [
            ['Active zones', $activeZones],
            ['Harvest ready', $harvestReady],
            ['Next harvest', is_array($nextHarvest) ? (string)$nextHarvest['crop_name'] : 'Not scheduled'],
        ],
        'href' => 'phase6.php?section=garden',
    ];
}
if ($canViewPreservation) {
    $overviewCards[] = [
        'title' => 'Preservation',
        'image' => 'assets/images/homestead/sheet-05/dehydrated-food-jars.png',
        'value' => $preservationActive,
        'unit' => 'batches',
        'status' => $preservationQueue . ' in progress',
        'rows' => [
            ['Stored batches', $preservationStored],
            ['Expiring soon', $preservationExpiring],
            ['Method in focus', $preservationMethod ? ucwords(str_replace('_', ' ', $preservationMethod)) : 'Not set'],
        ],
        'href' => 'phase6.php?section=preservation',
    ];
}

$metricCards = [];
if ($canViewInventory) {
    $metricCards[] = ['label' => 'Pantry Inventory', 'value' => $inventoryCount, 'unit' => 'items', 'detail' => $formatCurrency($inventoryValue) . ' tracked value', 'note' => $belowReorder . ' below reorder', 'href' => 'phase2.php?section=inventory', 'icon' => '▦', 'tone' => 'gold'];
}
if ($canViewGarden) {
    $metricCards[] = ['label' => 'Upcoming Harvests', 'value' => $harvestReady, 'unit' => 'ready', 'detail' => is_array($nextHarvest) ? (string)$nextHarvest['crop_name'] : 'No harvest date', 'note' => $activePlantings . ' active plantings', 'href' => 'phase6.php?section=harvests', 'icon' => '♧', 'tone' => 'green'];
}
if ($canViewInventory || $canViewPreservation) {
    $metricCards[] = ['label' => 'Expiring Soon', 'value' => $expiringSoon + $preservationExpiring, 'unit' => 'items', 'detail' => 'Within 30 days', 'note' => 'Review dates', 'href' => $canViewInventory ? 'phase2.php?section=inventory' : 'phase6.php?section=preservation', 'icon' => '△', 'tone' => 'amber'];
}
if ($canViewRecipes) {
    $metricCards[] = ['label' => 'Active Recipes', 'value' => $activeRecipes, 'unit' => 'available', 'detail' => $mealPlanCount . ' active meal plans', 'note' => 'View plans', 'href' => 'phase4.php', 'icon' => '✣', 'tone' => 'gold'];
}
if ($canViewPreservation) {
    $metricCards[] = ['label' => 'Preservation Batches', 'value' => $preservationActive, 'unit' => 'active', 'detail' => $preservationExpiring . ' expiring soon', 'note' => 'View batches', 'href' => 'phase6.php?section=preservation', 'icon' => '▣', 'tone' => 'gold'];
}
$metricCards[] = ['label' => 'System Health', 'value' => 'Good', 'unit' => '', 'detail' => 'Core workflows available', 'note' => ($activeTasks + $unreadAlerts) . ' active signals', 'href' => $phase11Available ? 'phase11.php' : 'account.php', 'icon' => '⌁', 'tone' => 'green'];

$quickActions = [];
if ($canViewInventory) {
    $quickActions[] = ['label' => 'Add inventory item', 'href' => 'phase2.php?section=inventory', 'icon' => '▦'];
}
if ($canViewGarden) {
    $quickActions[] = ['label' => 'Log harvest', 'href' => 'phase6.php?section=harvests', 'icon' => '♧'];
}
if ($canViewRecipes) {
    $quickActions[] = ['label' => 'Create recipe', 'href' => 'phase4.php', 'icon' => '✣'];
}
if ($canViewPreservation) {
    $quickActions[] = ['label' => 'Start preservation batch', 'href' => 'phase6.php?section=preservation', 'icon' => '▣'];
}
if ($canViewPlanning) {
    $quickActions[] = ['label' => 'Add household task', 'href' => 'phase7.php', 'icon' => '✓'];
}
$quickActions[] = ['label' => 'Open household settings', 'href' => 'account.php', 'icon' => '⚙'];

$highlights = [];
if ($canViewGarden) {
    $highlights[] = ['label' => 'Harvest', 'value' => $harvestReady, 'detail' => $activePlantings . ' plantings', 'icon' => '♧', 'href' => 'phase6.php?section=harvests'];
}
if ($canViewPreservation) {
    $highlights[] = ['label' => 'Preserve', 'value' => $preservationActive, 'detail' => $preservationStored . ' stored', 'icon' => '▣', 'href' => 'phase6.php?section=preservation'];
}
if ($canViewRecipes) {
    $highlights[] = ['label' => 'Cook', 'value' => $activeRecipes, 'detail' => $mealPlanCount . ' meal plans', 'icon' => '✣', 'href' => 'phase4.php'];
}
if ($canViewInventory) {
    $highlights[] = ['label' => 'Stock Up', 'value' => $belowReorder, 'detail' => 'low stock items', 'icon' => '▦', 'href' => 'phase2.php?section=inventory'];
}

$glanceRows = [];
if ($canViewInventory) {
    $glanceRows[] = ['Total inventory value', $formatCurrency($inventoryValue)];
}
if ($canViewGarden) {
    $glanceRows[] = ['Plants growing', $activePlantings];
}
if ($canViewPreservation) {
    $glanceRows[] = ['Stored preservation batches', $preservationStored];
}
if ($canViewPlanning) {
    $glanceRows[] = ['Active tasks', $activeTasks];
    $glanceRows[] = ['Overdue tasks', $overdueTasks];
    $glanceRows[] = ['Completed this week', $completedThisWeek];
}
if ($phase11Available) {
    $glanceRows[] = ['Unread alerts', $unreadAlerts];
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title>Household Dashboard · Homestead</title>
    <link rel="icon" href="assets/icons/homestead-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/homestead-dashboard.css?v=20260727-3">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to household dashboard</a>
<main id="main-content" class="dashboard-page">
    <header class="dashboard-hero">
        <div class="dashboard-hero__copy">
            <p class="dashboard-kicker">Household food operating system</p>
            <h1>Dashboard <span aria-hidden="true">⌁</span></h1>
            <p>Your homestead at a glance.</p>
        </div>
    </header>

    <section class="dashboard-metrics" aria-label="Household overview metrics">
        <?php foreach ($metricCards as $card): ?>
            <a class="dashboard-metric dashboard-metric--<?= e((string)$card['tone']) ?>" href="<?= e((string)$card['href']) ?>">
                <div class="dashboard-metric__label"><span aria-hidden="true"><?= e((string)$card['icon']) ?></span><?= e((string)$card['label']) ?></div>
                <div class="dashboard-metric__value"><strong><?= is_int($card['value']) ? (int)$card['value'] : e((string)$card['value']) ?></strong><?php if ($card['unit'] !== ''): ?><span><?= e((string)$card['unit']) ?></span><?php endif; ?></div>
                <p><?= e((string)$card['detail']) ?></p>
                <span class="dashboard-metric__link"><?= e((string)$card['note']) ?> →</span>
            </a>
        <?php endforeach; ?>
    </section>

    <div class="dashboard-columns">
        <div class="dashboard-primary">
            <section class="dashboard-panel dashboard-overview">
                <div class="dashboard-panel__heading">
                    <div><p class="dashboard-kicker">Connected household record</p><h2>Homestead Overview</h2></div>
                    <span class="dashboard-live"><i></i>Live</span>
                </div>
                <div class="dashboard-overview__grid">
                    <?php foreach ($overviewCards as $card): ?>
                        <a class="overview-card" href="<?= e((string)$card['href']) ?>">
                            <h3><?= e((string)$card['title']) ?></h3>
                            <div class="overview-card__summary">
                                <img src="<?= e((string)$card['image']) ?>" alt="">
                                <div><strong><?= (int)$card['value'] ?></strong><span><?= e((string)$card['unit']) ?></span><p><i></i><?= e((string)$card['status']) ?></p></div>
                            </div>
                            <div class="overview-card__progress"><span style="width:<?= $card['title'] === 'Pantry' ? $stockedPercent : max(14, min(100, (int)$card['value'] * 6)) ?>%"></span></div>
                            <dl>
                                <?php foreach ($card['rows'] as [$label, $value]): ?><div><dt><?= e((string)$label) ?></dt><dd><?= e((string)$value) ?></dd></div><?php endforeach; ?>
                            </dl>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="dashboard-status">
                    <span class="dashboard-status__leaf" aria-hidden="true">♧</span>
                    <div><h3>Overall Status</h3><p>Your household system is active. Pantry, growing, planning, and preservation records are connected.</p></div>
                    <a href="phase8.php">View reports →</a>
                </div>
            </section>

            <div class="dashboard-dual">
                <section class="dashboard-panel dashboard-highlights">
                    <div class="dashboard-panel__heading"><div><p class="dashboard-kicker">Current week</p><h2>This Week’s Highlights</h2></div><a href="phase7.php">View all</a></div>
                    <div class="highlight-grid">
                        <?php foreach ($highlights as $highlight): ?>
                            <a class="highlight-card" href="<?= e((string)$highlight['href']) ?>">
                                <span class="highlight-card__icon" aria-hidden="true"><?= e((string)$highlight['icon']) ?></span>
                                <h3><?= e((string)$highlight['label']) ?></h3>
                                <strong><?= (int)$highlight['value'] ?></strong>
                                <p><?= e((string)$highlight['detail']) ?></p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="dashboard-panel dashboard-tasks">
                    <div class="dashboard-panel__heading"><div><p class="dashboard-kicker">Today and ahead</p><h2>Upcoming Tasks</h2></div><a href="phase7.php">Calendar →</a></div>
                    <div class="task-date-strip"><span>Today</span><strong><?= e(date('D, M j')) ?></strong></div>
                    <div class="dashboard-task-list">
                        <?php if ($dashboardTasks === []): ?><p class="dashboard-empty">No active tasks. Your household plan is clear.</p><?php endif; ?>
                        <?php foreach ($dashboardTasks as $task): ?>
                            <a class="dashboard-task" href="phase7.php">
                                <span class="dashboard-task__check" aria-hidden="true"></span>
                                <span><strong><?= e((string)$task['title']) ?></strong><small><?= e((string)($task['display_name'] ?? 'Household')) ?></small></span>
                                <time><?= e($formatDate($task['due_at'] ?? null)) ?></time>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <section class="dashboard-panel dashboard-activity">
                <div class="dashboard-panel__heading"><div><p class="dashboard-kicker">Recorded household history</p><h2>Recent Activity</h2></div><a href="phase2.php?section=ledger">View all</a></div>
                <div class="activity-list">
                    <?php if ($activities === []): ?><p class="dashboard-empty">No household activity has been recorded yet.</p><?php endif; ?>
                    <?php foreach (array_slice($activities, 0, 7) as $activity): ?>
                        <div class="activity-row">
                            <span class="activity-row__icon" aria-hidden="true">◇</span>
                            <div><strong><?= e((string)$activity['summary']) ?></strong><small><?= e(str_replace('_', ' ', (string)$activity['event_key'])) ?> · <?= e((string)($activity['display_name'] ?? 'Household')) ?></small></div>
                            <time><?= e($formatDate((string)$activity['occurred_at'])) ?></time>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($ledger !== []): ?>
                    <div class="ledger-strip">
                        <?php foreach (array_slice($ledger, 0, 3) as $event): ?>
                            <a href="phase2.php?section=ledger"><strong><?= e((string)($event['item_name'] ?? 'Household food')) ?></strong><span><?= e(str_replace('_', ' ', (string)$event['event_type'])) ?> · <?= e((string)$event['quantity']) ?> <?= e((string)$event['unit']) ?></span></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-tip">
                <span class="dashboard-tip__icon" aria-hidden="true">♧</span>
                <div><h2>Pro Tip</h2><p>Use recurring tasks and alerts to keep pantry checks, garden readings, and preservation rotations visible.</p><a href="phase7.php">Go to planning →</a></div>
            </section>
        </div>

        <aside class="dashboard-secondary" aria-label="Dashboard attention and actions">
            <section class="dashboard-panel dashboard-alerts">
                <div class="dashboard-panel__heading"><div><p class="dashboard-kicker">Attention</p><h2>Alerts</h2></div><?php if ($phase11Available): ?><a href="phase11.php">View all</a><?php endif; ?></div>
                <div class="alert-list">
                    <?php if ($dashboardAlerts === []): ?><div class="dashboard-empty-card"><span aria-hidden="true">✓</span><p>No current alerts.</p></div><?php endif; ?>
                    <?php foreach ($dashboardAlerts as $alert): ?>
                        <a class="dashboard-alert dashboard-alert--<?= e((string)$alert['priority']) ?>" href="phase11.php">
                            <span class="dashboard-alert__icon" aria-hidden="true"><?= in_array((string)$alert['priority'], ['critical','high'], true) ? '△' : '◇' ?></span>
                            <span><strong><?= e((string)$alert['title']) ?></strong><small><?= e($formatDate($alert['attention_at'] ?? null, 'Current')) ?></small><em>View alert →</em></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dashboard-panel dashboard-quick-actions">
                <div class="dashboard-panel__heading"><div><p class="dashboard-kicker">Common workflows</p><h2>Quick Actions</h2></div></div>
                <nav aria-label="Quick actions">
                    <?php foreach ($quickActions as $action): ?><a href="<?= e((string)$action['href']) ?>"><span aria-hidden="true"><?= e((string)$action['icon']) ?></span><?= e((string)$action['label']) ?><i aria-hidden="true">→</i></a><?php endforeach; ?>
                </nav>
            </section>

            <section class="dashboard-panel dashboard-glance">
                <div class="dashboard-panel__heading"><div><p class="dashboard-kicker">Household totals</p><h2>At a Glance</h2></div></div>
                <dl>
                    <?php foreach ($glanceRows as [$label, $value]): ?><div><dt><?= e((string)$label) ?></dt><dd><?= e((string)$value) ?></dd></div><?php endforeach; ?>
                </dl>
                <p>Keep up the great work! <span aria-hidden="true">♧</span></p>
            </section>
        </aside>
    </div>
</main>
</body>
</html>
