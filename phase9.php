<?php

declare(strict_types=1);

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
    <title>Cost, Waste & Savings · Homestead</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to cost and waste intelligence</a>
<main id="main-content" class="page-container">
<header class="page-header">
    <div>
        <p class="eyebrow">Household food economics</p>
        <h1>Cost, Waste & Savings Intelligence</h1>
        <p class="page-description">Connect purchases, pantry value, recipe costs, harvested food, preservation output, waste, budgets, and household tasks without treating estimates as accounting or financial advice.</p>
    </div>
    <div>
        <strong><?= e((string)$user['display_name']) ?></strong><br>
        <a href="/dashboard.php">Dashboard</a> · <a href="/phase8.php">Forecasting</a> · <a href="/phase7.php">Daily planning</a> · <a href="/logout.php">Sign out</a>
    </div>
</header>

<?php foreach ($flashes as $message): ?>
<div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div>
<?php endforeach; ?>

<section class="metrics-grid compact" aria-label="Household cost metrics">
    <article class="metric-card"><div><p>Purchase spending</p><strong><?= $snapshot ? e($money($snapshot['purchase_spend'])) : '—' ?></strong></div></article>
    <article class="metric-card"><div><p>Waste value</p><strong><?= $snapshot ? e($money($snapshot['waste_value'])) : '—' ?></strong></div></article>
    <article class="metric-card"><div><p>Estimated savings</p><strong><?= $snapshot ? e($money($snapshot['estimated_savings'])) : '—' ?></strong></div></article>
    <article class="metric-card"><div><p>Budget variance</p><strong><?= $snapshot ? e($money($snapshot['budget_variance'])) : '—' ?></strong></div></article>
</section>

<?php if ($canManage): ?>
<section class="content-grid" style="margin-top:22px">
    <article class="panel">
        <p class="eyebrow">Monthly intelligence</p>
        <h2>Run cost snapshot</h2>
        <p class="page-description">Uses a source-data watermark. Repeating an unchanged month reuses the existing certified result.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="run_finance_snapshot">
            <label>Month<input class="search-field" type="month" name="month" value="<?= e($currentMonth) ?>" required></label>
            <button class="button primary" type="submit">Generate monthly snapshot</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Household targets</p>
        <h2>Budget and waste settings</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="save_settings">
            <label>Monthly food budget<input class="search-field" type="number" min="0" step="0.01" name="monthly_budget" value="<?= e((string)$settings['monthly_budget']) ?>" required></label>
            <label>Waste target, %<input class="search-field" type="number" min="0" max="100" step="0.01" name="waste_target_percent" value="<?= e((string)$settings['waste_target_percent']) ?>" required></label>
            <label>Monthly savings target<input class="search-field" type="number" min="0" step="0.01" name="savings_target_amount" value="<?= e((string)$settings['savings_target_amount']) ?>" required></label>
            <label>Price increase alert, %<input class="search-field" type="number" min="1" max="500" step="0.01" name="price_increase_alert_percent" value="<?= e((string)$settings['price_increase_alert_percent']) ?>" required></label>
            <button class="button primary" type="submit">Save targets</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Supplier directory</p>
        <h2>Add supplier</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="create_supplier">
            <label>Name<input class="search-field" name="name" maxlength="180" required></label>
            <label>Type<select name="supplier_type"><option value="grocery">Grocery</option><option value="farm">Farm</option><option value="warehouse">Warehouse</option><option value="market">Market</option><option value="online">Online</option><option value="restaurant_supply">Restaurant supply</option><option value="other">Other</option></select></label>
            <label>Notes<textarea name="notes" maxlength="5000"></textarea></label>
            <button class="button primary" type="submit">Add supplier</button>
        </form>
    </article>
</section>

<section class="content-grid" style="margin-top:22px">
    <article class="panel">
        <p class="eyebrow">Purchase and stock</p>
        <h2>Record purchase</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="record_purchase">
            <label>Inventory item<select name="inventory_item_id" required><option value="">Choose item</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label>
            <label>Supplier<select name="supplier_id"><option value="">Unspecified</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int)$supplier['id'] ?>"><?= e((string)$supplier['name']) ?></option><?php endforeach; ?></select></label>
            <label>Quantity added<input class="search-field" type="number" min="0.0001" step="0.0001" name="quantity" required></label>
            <label>Total cost<input class="search-field" type="number" min="0" step="0.01" name="total_cost" required></label>
            <label>Purchase date<input class="search-field" type="date" name="purchased_on" value="<?= e($today) ?>" required></label>
            <label>Package quantity<input class="search-field" type="number" min="0.0001" step="0.0001" name="package_quantity"></label>
            <label>Package unit<input class="search-field" name="package_unit" maxlength="30"></label>
            <label>Receipt reference<input class="search-field" name="receipt_reference" maxlength="190"></label>
            <label>Notes<textarea name="notes" maxlength="5000"></textarea></label>
            <button class="button primary" type="submit">Record purchase</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Spoilage and loss</p>
        <h2>Record waste</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="record_waste">
            <label>Inventory item<select name="inventory_item_id"><option value="">None</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label>
            <label>Prepared-food batch<select name="prepared_food_batch_id"><option value="">None</option><?php foreach ($preparedBatches as $batch): ?><option value="<?= (int)$batch['id'] ?>"><?= e((string)$batch['name']) ?> · <?= e((string)$batch['servings_remaining']) ?> servings</option><?php endforeach; ?></select></label>
            <label>Waste type<select name="waste_type"><option value="spoiled">Spoiled</option><option value="composted">Composted</option><option value="discarded">Discarded</option><option value="overproduction">Overproduction</option><option value="trim_loss">Trim loss</option><option value="expired">Expired</option><option value="damaged">Damaged</option><option value="other">Other</option></select></label>
            <label>Quantity<input class="search-field" type="number" min="0.0001" step="0.0001" name="quantity" required></label>
            <label>Date<input class="search-field" type="date" name="occurred_on" value="<?= e($today) ?>" required></label>
            <label>Reason<input class="search-field" name="reason" maxlength="500"></label>
            <p class="page-description">Choose exactly one source: an inventory item or a prepared-food batch.</p>
            <button class="button primary" type="submit">Record waste</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Meal economics</p>
        <h2>Calculate recipe cost</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="calculate_recipe_cost">
            <label>Recipe<select name="recipe_id" required><option value="">Choose recipe</option><?php foreach ($recipes as $recipe): ?><option value="<?= (int)$recipe['id'] ?>"><?= e((string)$recipe['name']) ?> · <?= e((string)$recipe['servings']) ?> servings</option><?php endforeach; ?></select></label>
            <label>Cost date<input class="search-field" type="date" name="as_of_date" value="<?= e($today) ?>" required></label>
            <button class="button primary" type="submit">Calculate recipe cost</button>
        </form>
    </article>
</section>
<?php endif; ?>

<section class="panel" style="margin-top:22px">
    <div class="panel-heading"><div><p class="eyebrow">Evidence-linked actions</p><h2>Cost and waste recommendations</h2></div><?php if ($snapshot): ?><small><?= e((string)$snapshot['month_start']) ?> through <?= e((string)$snapshot['month_end']) ?></small><?php endif; ?></div>
    <?php if ($recommendations === []): ?><p class="page-description">No finance recommendations yet. Record purchases or waste and run a monthly snapshot.</p><?php endif; ?>
    <?php foreach ($recommendations as $recommendation): ?>
    <div class="member-card" style="margin-bottom:12px">
        <strong><?= e((string)$recommendation['title']) ?></strong>
        <span class="status" style="display:inline-block;margin-left:8px"><?= e((string)$recommendation['priority']) ?> · <?= e((string)$recommendation['status']) ?></span>
        <p><?= e((string)$recommendation['rationale']) ?></p>
        <p class="page-description"><?= e((string)$recommendation['recommended_action']) ?><?php if ($recommendation['estimated_impact'] !== null): ?> · estimated impact <?= e($money($recommendation['estimated_impact'])) ?><?php endif; ?></p>
        <?php if ($canManage): ?><div class="toolbar">
            <?php if ($recommendation['status'] === 'pending'): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="accept_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button class="button primary" type="submit">Create task</button></form>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="dismiss_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button class="button secondary" type="submit">Dismiss</button></form>
            <?php elseif ($recommendation['status'] === 'accepted'): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="complete_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button class="button primary" type="submit">Mark complete</button></form>
            <?php endif; ?>
        </div><?php endif; ?>
    </div>
    <?php endforeach; ?>
</section>

<section class="content-grid" style="margin-top:22px">
    <article class="panel span-2">
        <h2>Recent purchases</h2>
        <div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Date</th><th scope="col">Item</th><th scope="col">Supplier</th><th scope="col">Quantity</th><th scope="col">Total</th><th scope="col">Unit cost</th></tr></thead><tbody>
        <?php if ($purchases === []): ?><tr><td colspan="6">No purchase records yet.</td></tr><?php endif; ?>
        <?php foreach ($purchases as $purchase): ?><tr><td><?= e((string)$purchase['purchased_on']) ?></td><td><?= e((string)$purchase['item_name']) ?></td><td><?= e((string)($purchase['supplier_name'] ?? 'Unspecified')) ?></td><td><?= e((string)$purchase['quantity']) ?> <?= e((string)$purchase['unit']) ?></td><td><?= e($money($purchase['total_cost'])) ?></td><td><?= e($money($purchase['unit_cost'])) ?>/<?= e((string)$purchase['unit']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>

    <article class="panel">
        <h2>Recent waste</h2>
        <?php if ($wasteEvents === []): ?><p class="page-description">No waste events yet.</p><?php endif; ?>
        <?php foreach ($wasteEvents as $waste): ?><div class="member-card" style="margin-bottom:10px"><strong><?= e((string)($waste['item_name'] ?? $waste['prepared_name'] ?? 'Food')) ?></strong><br><span class="page-description"><?= e(str_replace('_', ' ', (string)$waste['waste_type'])) ?> · <?= e((string)$waste['quantity']) ?> <?= e((string)$waste['unit']) ?> · <?= e($money($waste['estimated_value'])) ?> · <?= e((string)$waste['occurred_on']) ?></span></div><?php endforeach; ?>
    </article>
</section>

<section class="content-grid" style="margin-top:22px">
    <article class="panel">
        <h2>Recipe cost history</h2>
        <?php if ($recipeCosts === []): ?><p class="page-description">No recipe cost snapshots yet.</p><?php endif; ?>
        <?php foreach ($recipeCosts as $cost): ?><div class="member-card" style="margin-bottom:10px"><strong><?= e((string)$cost['recipe_name']) ?></strong><br><span class="page-description"><?= e($money($cost['total_cost'])) ?> total · <?= $cost['cost_per_serving'] !== null ? e($money($cost['cost_per_serving'])) . ' per serving' : 'incomplete pricing' ?> · <?= (int)$cost['missing_price_count'] ?> missing · <?= e((string)$cost['as_of_date']) ?></span></div><?php endforeach; ?>
    </article>

    <article class="panel span-2">
        <h2>Supplier and package-cost comparison</h2>
        <div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Item</th><th scope="col">Supplier</th><th scope="col">Purchases</th><th scope="col">Average unit cost</th><th scope="col">Range</th><th scope="col">Latest</th></tr></thead><tbody>
        <?php if ($supplierComparisons === []): ?><tr><td colspan="6">Record purchases with suppliers to compare unit costs.</td></tr><?php endif; ?>
        <?php foreach ($supplierComparisons as $comparison): ?><tr><td><?= e((string)$comparison['item_name']) ?></td><td><?= e((string)($comparison['supplier_name'] ?? 'Unspecified')) ?></td><td><?= (int)$comparison['purchase_count'] ?></td><td><?= e($money($comparison['average_unit_cost'])) ?></td><td><?= e($money($comparison['lowest_unit_cost'])) ?>–<?= e($money($comparison['highest_unit_cost'])) ?></td><td><?= e((string)$comparison['last_purchased_on']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
</section>

<section class="panel" style="margin-top:22px">
    <h2>Monthly trend history</h2>
    <div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Month</th><th scope="col">Budget</th><th scope="col">Spending</th><th scope="col">Waste</th><th scope="col">Household production</th><th scope="col">Preservation</th><th scope="col">Estimated savings</th><th scope="col">Waste rate</th></tr></thead><tbody>
    <?php if ($trends === []): ?><tr><td colspan="8">No monthly snapshots yet.</td></tr><?php endif; ?>
    <?php foreach ($trends as $trend): ?><tr><td><?= e(substr((string)$trend['month_start'], 0, 7)) ?></td><td><?= e($money($trend['budget_amount'])) ?></td><td><?= e($money($trend['purchase_spend'])) ?></td><td><?= e($money($trend['waste_value'])) ?></td><td><?= e($money($trend['household_production_value'])) ?></td><td><?= e($money($trend['preservation_value'])) ?></td><td><?= e($money($trend['estimated_savings'])) ?></td><td><?= number_format((float)$trend['waste_percent'], 1) ?>%</td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="panel" style="margin-top:22px">
    <h2>How estimates are calculated</h2>
    <p class="page-description">Purchase spending uses recorded purchase totals. Inventory and recipe values use the weighted unit cost from compatible-unit purchase records. Household-production value uses harvest quantities with replacement-cost data and excludes harvests sent directly to preservation so those outputs are not counted twice. Waste value uses the cost basis available when the event is recorded. These are operational estimates, not tax, accounting, nutrition, or financial records.</p>
</section>
</main>
</body>
</html>