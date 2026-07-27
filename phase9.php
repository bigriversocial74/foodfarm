<?php

declare(strict_types=1);

// Compatibility identifier: Cost, Waste & Savings Intelligence

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/CostWasteService.php';

use Homestead\CostWasteService;
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
$canView = $auth->can($user, 'finance.view') || $auth->can($user, 'finance.manage');
$canManage = $auth->can($user, 'finance.manage');
if (!$canView) {
    http_response_code(403);
    exit('You do not have permission to view household cost and waste intelligence.');
}

$service = new CostWasteService($pdo);
if (!isset($_SESSION['phase9_action_key']) || !is_string($_SESSION['phase9_action_key'])
    || preg_match('/^[a-f0-9]{64}$/', $_SESSION['phase9_action_key']) !== 1) {
    $_SESSION['phase9_action_key'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $postedActionKey = strtolower(trim((string)($_POST['action_key'] ?? '')));
        if (!hash_equals((string)$_SESSION['phase9_action_key'], $postedActionKey)) {
            throw new RuntimeException('This cost and waste form has expired. Refresh and try again.');
        }
        if (!$canManage) {
            throw new RuntimeException('You do not have permission to change household financial records.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_settings') {
            $service->saveSettings($householdId, $memberId, $_POST);
            flash('success', 'Household budget and waste targets were updated.');
        } elseif ($action === 'create_supplier') {
            $supplierId = $service->createSupplier($householdId, $memberId, $_POST);
            flash('success', 'Supplier #' . $supplierId . ' is available for purchase records.');
        } elseif ($action === 'record_purchase') {
            $_POST['action_key'] = $postedActionKey;
            $result = $service->recordPurchase($householdId, $memberId, $_POST);
            flash(
                'success',
                $result['reused']
                    ? 'The previously recorded purchase was reused.'
                    : 'Purchase recorded with a unit cost of ' . number_format((float)$result['unit_cost'], 4) . '.'
            );
        } elseif ($action === 'record_waste') {
            $_POST['action_key'] = $postedActionKey;
            $result = $service->recordWaste($householdId, $memberId, $_POST);
            flash(
                'success',
                $result['reused']
                    ? 'The previously recorded waste event was reused.'
                    : 'Waste recorded with an estimated value of $' . number_format((float)$result['estimated_value'], 2) . '.'
            );
        } elseif ($action === 'calculate_recipe_cost') {
            $result = $service->calculateRecipeCost(
                $householdId,
                $memberId,
                (int)($_POST['recipe_id'] ?? 0),
                (string)($_POST['as_of_date'] ?? date('Y-m-d'))
            );
            flash(
                'success',
                $result['missing_prices'] > 0
                    ? sprintf('Recipe cost saved with %d missing or mismatched prices.', $result['missing_prices'])
                    : sprintf('Recipe cost saved at $%.2f total and $%.2f per serving.', $result['total_cost'], $result['cost_per_serving'])
            );
        } elseif ($action === 'run_finance_snapshot') {
            $result = $service->runFinanceSnapshot(
                $householdId,
                $memberId,
                (string)($_POST['month'] ?? date('Y-m'))
            );
            flash(
                'success',
                $result['reused']
                    ? 'The unchanged monthly cost snapshot was reused.'
                    : sprintf(
                        'Monthly snapshot complete: $%.2f spending, $%.2f waste value, and $%.2f estimated savings.',
                        $result['purchase_spend'],
                        $result['waste_value'],
                        $result['estimated_savings']
                    )
            );
        } elseif ($action === 'accept_recommendation') {
            $taskId = $service->acceptRecommendation(
                $householdId,
                $memberId,
                (int)($_POST['recommendation_id'] ?? 0)
            );
            flash('success', 'Recommendation accepted as household task #' . $taskId . '.');
        } elseif ($action === 'dismiss_recommendation') {
            $service->dismissRecommendation(
                $householdId,
                $memberId,
                (int)($_POST['recommendation_id'] ?? 0)
            );
            flash('success', 'Finance recommendation dismissed.');
        } elseif ($action === 'complete_recommendation') {
            $service->completeRecommendation(
                $householdId,
                $memberId,
                (int)($_POST['recommendation_id'] ?? 0)
            );
            flash('success', 'Finance recommendation marked complete.');
        } else {
            throw new InvalidArgumentException('Unknown cost and waste action.');
        }

        $_SESSION['phase9_action_key'] = bin2hex(random_bytes(32));
        redirect('/phase9.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/phase9.php');
    }
}

$data = $service->dashboardData($householdId);
$settings = $data['settings'];
$snapshot = $data['snapshot'];
$recommendations = $data['recommendations'];
$suppliers = $data['suppliers'];
$inventoryItems = $data['inventory_items'];
$preparedBatches = $data['prepared_batches'];
$recipes = $data['recipes'];
$purchases = $data['purchases'];
$wasteEvents = $data['waste_events'];
$recipeCosts = $data['recipe_costs'];
$supplierComparisons = $data['supplier_comparisons'];
$trends = $data['trends'];
$flashes = consume_flashes();
$token = csrf_token();
$actionKey = (string)$_SESSION['phase9_action_key'];
$currency = strtoupper((string)($settings['currency_code'] ?? 'USD'));
$money = static function (mixed $value) use ($currency): string {
    $prefix = $currency === 'USD' ? '$' : $currency . ' ';
    return $prefix . number_format((float)$value, 2);
};
$currentMonth = date('Y-m');
$today = date('Y-m-d');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title>Cost, Waste &amp; Savings Intelligence · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php
$pendingRecommendations = array_values(array_filter($recommendations, static fn(array $row): bool => (string)$row['status'] === 'pending'));
$acceptedRecommendations = array_values(array_filter($recommendations, static fn(array $row): bool => (string)$row['status'] === 'accepted'));
$criticalRecommendations = array_values(array_filter($recommendations, static fn(array $row): bool => in_array((string)$row['priority'], ['critical','high'], true)));
$purchaseSpend = $snapshot ? (float)$snapshot['purchase_spend'] : 0.0;
$budget = $snapshot ? (float)$snapshot['budget_amount'] : (float)$settings['monthly_budget'];
$budgetUsed = $budget > 0 ? min(100, max(0, ($purchaseSpend / $budget) * 100)) : 0.0;
$wasteValue = $snapshot ? (float)$snapshot['waste_value'] : 0.0;
$savingsValue = $snapshot ? (float)$snapshot['estimated_savings'] : 0.0;
$wastePercent = $snapshot ? (float)$snapshot['waste_percent'] : 0.0;
$savingsRate = $snapshot ? (float)$snapshot['savings_rate_percent'] : 0.0;
$budgetVariance = $snapshot ? (float)$snapshot['budget_variance'] : 0.0;
$formatQty = static fn(mixed $value): string => number_format((float)$value, 2, '.', ',');
?>
<a class="skip-link" href="#main-content">Skip to cost and waste intelligence</a>
<main id="main-content" class="page-container finance-page">
    <section class="finance-hero" aria-labelledby="finance-title">
        <div class="finance-hero__copy">
            <p class="finance-kicker">Household food economics</p>
            <h1 id="finance-title" aria-label="Cost, Waste &amp; Savings Intelligence">Know what food costs.<br><span>Keep more of its value.</span></h1>
            <p>Connect purchases, pantry value, recipe costs, household production, preservation output, waste, budgets, and evidence-linked savings actions.</p>
            <div class="finance-hero__meta">
                <span><?= $snapshot ? 'Snapshot '.e(date('F Y', strtotime((string)$snapshot['month_start']))) : 'No monthly snapshot yet' ?></span>
                <span><?= count($suppliers) ?> suppliers</span>
                <span><?= count($recommendations) ?> recommendations</span>
            </div>
        </div>
        <div class="finance-budget-card">
            <p>Monthly food budget</p>
            <strong><?= e($money($purchaseSpend)) ?><small> / <?= e($money($budget)) ?></small></strong>
            <div><span style="width:<?= e(number_format($budgetUsed,2,'.','')) ?>%"></span></div>
            <p><?= $snapshot ? ($budgetVariance >= 0 ? e($money($budgetVariance)).' remaining' : e($money(abs($budgetVariance))).' over budget') : 'Run a monthly snapshot to compare spending.' ?></p>
            <?php if ($canManage): ?><details class="finance-run"><summary>Run monthly snapshot</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="run_finance_snapshot"><label>Month<input type="month" name="month" value="<?= e($currentMonth) ?>" required></label><button type="submit">Generate snapshot</button></form></details><?php endif; ?>
        </div>
    </section>

    <?php foreach ($flashes as $message): ?><div role="status" class="finance-flash finance-flash--<?= $message['type'] === 'error' ? 'warning' : 'good' ?>"><?= e((string)$message['message']) ?></div><?php endforeach; ?>

    <section class="finance-metrics" aria-label="Household cost metrics">
        <article><span>$</span><div><small>Purchase spending</small><strong><?= $snapshot ? e($money($purchaseSpend)) : '—' ?></strong><p><?= count($purchases) ?> recent purchase records</p></div></article>
        <article class="<?= $wastePercent > (float)$settings['waste_target_percent'] ? 'finance-metric--danger' : '' ?>"><span>↓</span><div><small>Waste value</small><strong><?= $snapshot ? e($money($wasteValue)) : '—' ?></strong><p><?= number_format($wastePercent,1) ?>% of tracked food value</p></div></article>
        <article class="finance-metric--good"><span>↑</span><div><small>Estimated savings</small><strong><?= $snapshot ? e($money($savingsValue)) : '—' ?></strong><p><?= number_format($savingsRate,1) ?>% savings rate</p></div></article>
        <article><span>⌂</span><div><small>Household production</small><strong><?= $snapshot ? e($money($snapshot['household_production_value'])) : '—' ?></strong><p>Harvest replacement-cost estimate</p></div></article>
        <article><span>◇</span><div><small>Preservation value</small><strong><?= $snapshot ? e($money($snapshot['preservation_value'])) : '—' ?></strong><p>Tracked preserved output value</p></div></article>
        <article><span>✓</span><div><small>Action queue</small><strong><?= count($pendingRecommendations) ?></strong><p><?= count($acceptedRecommendations) ?> accepted into planning</p></div></article>
    </section>

    <div class="finance-layout">
        <div class="finance-primary">
            <section class="finance-panel" id="finance-trends">
                <div class="finance-panel__heading"><div><p class="finance-kicker">Monthly operating history</p><h2>Spending, waste &amp; savings</h2></div><span>Monthly trend history · Last <?= count($trends) ?> snapshots</span></div>
                <div class="finance-chart">
                    <?php if ($trends === []): ?><div class="finance-empty"><strong>No monthly history yet</strong><p>Generate a monthly snapshot to begin the cost trend.</p></div><?php endif; ?>
                    <?php $trendMax = 1.0; foreach ($trends as $row) { $trendMax = max($trendMax,(float)$row['purchase_spend'],(float)$row['estimated_savings'],(float)$row['waste_value']); } ?>
                    <?php foreach (array_reverse($trends) as $trend): ?>
                    <article title="<?= e(substr((string)$trend['month_start'],0,7)) ?> · <?= e($money($trend['purchase_spend'])) ?> spending">
                        <div><i style="height:<?= e(number_format(((float)$trend['purchase_spend']/$trendMax)*100,2,'.','')) ?>%"></i><b style="height:<?= e(number_format(((float)$trend['estimated_savings']/$trendMax)*100,2,'.','')) ?>%"></b><em style="height:<?= e(number_format(((float)$trend['waste_value']/$trendMax)*100,2,'.','')) ?>%"></em></div>
                        <small><?= e(date('M',strtotime((string)$trend['month_start']))) ?></small><strong><?= number_format((float)$trend['waste_percent'],1) ?>%</strong>
                    </article>
                    <?php endforeach; ?>
                </div>
                <div class="finance-legend"><span><i></i>Spending</span><span><b></b>Savings</span><span><em></em>Waste</span></div>
            </section>

            <section class="finance-panel" id="finance-purchases">
                <div class="finance-panel__heading finance-panel__heading--toolbar"><div><p class="finance-kicker">Purchase ledger</p><h2>Recent purchases</h2></div><label class="finance-search"><span>⌕</span><input type="search" placeholder="Search purchases" data-finance-search></label></div>
                <div class="finance-tabs" role="tablist" aria-label="Purchase filters"><button type="button" class="active" data-finance-filter="all">All <span><?= count($purchases) ?></span></button><button type="button" data-finance-filter="high">Higher cost</button><button type="button" data-finance-filter="supplier">With supplier</button></div>
                <div class="finance-purchases" data-finance-list>
                    <?php if ($purchases === []): ?><div class="finance-empty"><strong>No purchases recorded</strong><p>Record a purchase to establish unit-cost history.</p></div><?php endif; ?>
                    <?php $avgPurchase = count($purchases) ? array_sum(array_map(static fn(array $r): float => (float)$r['total_cost'],$purchases))/count($purchases) : 0; ?>
                    <?php foreach ($purchases as $purchase): $high=(float)$purchase['total_cost']>$avgPurchase && $avgPurchase>0; $hasSupplier=!empty($purchase['supplier_name']); $searchText=strtolower(implode(' ',[(string)$purchase['item_name'],(string)($purchase['supplier_name']??''),(string)$purchase['unit'],(string)($purchase['receipt_reference']??'')])); ?>
                    <article class="finance-purchase" data-high="<?= $high?'1':'0' ?>" data-supplier="<?= $hasSupplier?'1':'0' ?>" data-search="<?= e($searchText) ?>">
                        <div class="finance-purchase__icon">$</div><div><h3><?= e((string)$purchase['item_name']) ?></h3><p><?= e((string)($purchase['supplier_name'] ?? 'Unspecified supplier')) ?> · <?= e(date('M j, Y',strtotime((string)$purchase['purchased_on']))) ?></p><span><?= e($formatQty($purchase['quantity'])) ?> <?= e((string)$purchase['unit']) ?> · <?= e($money($purchase['unit_cost'])) ?>/<?= e((string)$purchase['unit']) ?></span></div><strong><?= e($money($purchase['total_cost'])) ?></strong>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="finance-panel" id="finance-recommendations">
                <div class="finance-panel__heading"><div><p class="finance-kicker">Evidence-linked actions</p><h2>Cost and waste recommendations</h2></div><span><?= count($criticalRecommendations) ?> high priority</span></div>
                <div class="finance-recommendations">
                    <?php if ($recommendations === []): ?><div class="finance-empty"><strong>No current recommendations</strong><p>Monthly snapshots will surface budget, pricing, and waste actions.</p></div><?php endif; ?>
                    <?php foreach ($recommendations as $rec): ?>
                    <article class="finance-recommendation finance-recommendation--<?= e((string)$rec['priority']) ?>"><div><span><?= e(ucfirst((string)$rec['priority'])) ?></span><small><?= e(ucwords(str_replace('_',' ',(string)$rec['recommendation_type']))) ?></small></div><h3><?= e((string)$rec['title']) ?></h3><p><?= e((string)$rec['rationale']) ?></p><strong><?= e((string)$rec['recommended_action']) ?></strong><?php if (!empty($rec['estimated_savings'])): ?><em>Potential value <?= e($money($rec['estimated_savings'])) ?></em><?php endif; ?><footer><span><?= e(ucfirst((string)$rec['status'])) ?></span><?php if ($canManage): ?><div><?php if ((string)$rec['status'] === 'pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="accept_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$rec['id'] ?>"><button>Create task</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="dismiss_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$rec['id'] ?>"><button class="secondary">Dismiss</button></form><?php elseif ((string)$rec['status'] === 'accepted'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="complete_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$rec['id'] ?>"><button class="secondary">Complete</button></form><?php endif; ?></div><?php endif; ?></footer></article>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="finance-split">
                <section class="finance-panel"><div class="finance-panel__heading"><div><p class="finance-kicker">Loss record</p><h2>Recent waste</h2></div><span><?= count($wasteEvents) ?> events</span></div><div class="finance-waste"><?php if ($wasteEvents===[]): ?><div class="finance-empty"><strong>No waste recorded</strong></div><?php endif; ?><?php foreach ($wasteEvents as $waste): ?><article><span>!</span><div><strong><?= e((string)($waste['item_name'] ?? $waste['prepared_name'] ?? 'Food')) ?></strong><p><?= e(ucwords(str_replace('_',' ',(string)$waste['waste_type']))) ?> · <?= e($formatQty($waste['quantity'])) ?> <?= e((string)$waste['unit']) ?></p><small><?= e(date('M j, Y',strtotime((string)$waste['occurred_on']))) ?><?= $waste['reason'] ? ' · '.e((string)$waste['reason']) : '' ?></small></div><b><?= e($money($waste['estimated_value'])) ?></b></article><?php endforeach; ?></div></section>
                <section class="finance-panel"><div class="finance-panel__heading"><div><p class="finance-kicker">Recipe economics</p><h2>Cost history</h2></div><span><?= count($recipeCosts) ?> snapshots</span></div><div class="finance-recipes"><?php if ($recipeCosts===[]): ?><div class="finance-empty"><strong>No recipe costs yet</strong></div><?php endif; ?><?php foreach ($recipeCosts as $cost): ?><article><div><strong><?= e((string)$cost['recipe_name']) ?></strong><p><?= (int)$cost['missing_price_count'] ?> missing prices · <?= e((string)$cost['as_of_date']) ?></p></div><span><?= $cost['cost_per_serving']!==null ? e($money($cost['cost_per_serving'])).'/serving' : 'Incomplete' ?></span></article><?php endforeach; ?></div></section>
            </div>
        </div>

        <aside class="finance-sidebar">
            <?php if ($canManage): ?><section class="finance-panel finance-controls"><div class="finance-panel__heading"><div><p class="finance-kicker">Financial operations</p><h2>Record &amp; configure</h2></div></div>
                <details><summary>Record purchase</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="record_purchase"><label>Inventory item<select name="inventory_item_id" required><option value="">Choose item</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label><label>Supplier<select name="supplier_id"><option value="">Unspecified</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int)$supplier['id'] ?>"><?= e((string)$supplier['name']) ?></option><?php endforeach; ?></select></label><label>Quantity added<input type="number" min="0.0001" step="0.0001" name="quantity" required></label><label>Total cost<input type="number" min="0" step="0.01" name="total_cost" required></label><label>Purchase date<input type="date" name="purchased_on" value="<?= e($today) ?>" required></label><label>Package quantity<input type="number" min="0.0001" step="0.0001" name="package_quantity"></label><label>Package unit<input name="package_unit" maxlength="30"></label><label>Receipt reference<input name="receipt_reference" maxlength="190"></label><label>Notes<textarea name="notes" maxlength="5000"></textarea></label><button>Record purchase</button></form></details>
                <details><summary>Record food waste</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="record_waste"><label>Inventory item<select name="inventory_item_id"><option value="">None</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label><label>Prepared-food batch<select name="prepared_food_batch_id"><option value="">None</option><?php foreach ($preparedBatches as $batch): ?><option value="<?= (int)$batch['id'] ?>"><?= e((string)$batch['name']) ?> · <?= e((string)$batch['servings_remaining']) ?> servings</option><?php endforeach; ?></select></label><label>Waste type<select name="waste_type"><option value="spoiled">Spoiled</option><option value="composted">Composted</option><option value="discarded">Discarded</option><option value="overproduction">Overproduction</option><option value="trim_loss">Trim loss</option><option value="expired">Expired</option><option value="damaged">Damaged</option><option value="other">Other</option></select></label><label>Quantity<input type="number" min="0.0001" step="0.0001" name="quantity" required></label><label>Date<input type="date" name="occurred_on" value="<?= e($today) ?>" required></label><label>Reason<input name="reason" maxlength="500"></label><p>Choose exactly one source.</p><button>Record waste</button></form></details>
                <details><summary>Calculate recipe cost</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="calculate_recipe_cost"><label>Recipe<select name="recipe_id" required><option value="">Choose recipe</option><?php foreach ($recipes as $recipe): ?><option value="<?= (int)$recipe['id'] ?>"><?= e((string)$recipe['name']) ?> · <?= e((string)$recipe['servings']) ?> servings</option><?php endforeach; ?></select></label><label>Cost date<input type="date" name="as_of_date" value="<?= e($today) ?>" required></label><button>Calculate recipe cost</button></form></details>
                <details><summary>Add supplier</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="create_supplier"><label>Name<input name="name" maxlength="180" required></label><label>Type<select name="supplier_type"><option value="grocery">Grocery</option><option value="farm">Farm</option><option value="warehouse">Warehouse</option><option value="market">Market</option><option value="online">Online</option><option value="restaurant_supply">Restaurant supply</option><option value="other">Other</option></select></label><label>Notes<textarea name="notes" maxlength="5000"></textarea></label><button>Add supplier</button></form></details>
                <details><summary>Budget &amp; waste targets</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="save_settings"><label>Monthly food budget<input type="number" min="0" step="0.01" name="monthly_budget" value="<?= e((string)$settings['monthly_budget']) ?>" required></label><label>Waste target, %<input type="number" min="0" max="100" step="0.01" name="waste_target_percent" value="<?= e((string)$settings['waste_target_percent']) ?>" required></label><label>Monthly savings target<input type="number" min="0" step="0.01" name="savings_target_amount" value="<?= e((string)$settings['savings_target_amount']) ?>" required></label><label>Price increase alert, %<input type="number" min="1" max="500" step="0.01" name="price_increase_alert_percent" value="<?= e((string)$settings['price_increase_alert_percent']) ?>" required></label><button>Save targets</button></form></details>
            </section><?php endif; ?>

            <section class="finance-panel"><div class="finance-panel__heading"><div><p class="finance-kicker">Supplier intelligence</p><h2>Supplier and package-cost comparison</h2></div></div><div class="finance-suppliers"><?php if($supplierComparisons===[]): ?><div class="finance-empty"><strong>No supplier comparisons</strong><p>Record supplier-linked purchases to compare package cost.</p></div><?php endif; ?><?php foreach(array_slice($supplierComparisons,0,12) as $comparison): ?><article><div><strong><?= e((string)$comparison['item_name']) ?></strong><p><?= e((string)($comparison['supplier_name']??'Unspecified')) ?> · <?= (int)$comparison['purchase_count'] ?> purchases</p></div><span><?= e($money($comparison['average_unit_cost'])) ?><small><?= e($money($comparison['lowest_unit_cost'])) ?>–<?= e($money($comparison['highest_unit_cost'])) ?></small></span></article><?php endforeach; ?></div></section>
            <section class="finance-panel finance-insight"><p class="finance-kicker">Household signal</p><h2><?= $wastePercent > (float)$settings['waste_target_percent'] ? 'Waste is above the household target.' : ($budgetVariance < 0 ? 'Spending has crossed the monthly budget.' : 'Food economics are within target.') ?></h2><p><?= e($money($wasteValue)) ?> in tracked waste, <?= e($money($savingsValue)) ?> in estimated savings, and <?= count($criticalRecommendations) ?> high-priority actions.</p><a href="phase7.php">Open daily planning</a></section>
            <section class="finance-panel finance-method"><p class="finance-kicker">Estimate boundary</p><h2>Operational, not accounting</h2><p>Values use recorded purchase totals and compatible-unit weighted costs. Harvest and preservation values avoid known double counting. These are household operating estimates, not tax or financial records.</p></section>
        </aside>
    </div>
</main>
<script src="assets/js/homestead-finance.js" defer></script>
</body>
</html>
