<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/PlanningAutomationService.php';

use Homestead\PlanningAutomationService;
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
$canManage = $auth->can($user, 'tasks.manage');
$canComplete = $auth->can($user, 'tasks.complete');
if (!$canManage && !$canComplete) {
    http_response_code(403);
    exit('You do not have permission to view household shopping.');
}

$service = new PlanningAutomationService($pdo);
if (!isset($_SESSION['shopping_list_action_key']) || !is_string($_SESSION['shopping_list_action_key'])
    || preg_match('/^[a-f0-9]{64}$/', $_SESSION['shopping_list_action_key']) !== 1) {
    $_SESSION['shopping_list_action_key'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $postedActionKey = strtolower(trim((string)($_POST['action_key'] ?? '')));
        if (!hash_equals((string)$_SESSION['shopping_list_action_key'], $postedActionKey)) {
            throw new RuntimeException('This shopping form has expired. Refresh and try again.');
        }

        $auth->requirePermission($user, 'tasks.manage');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'accept_suggestion') {
            $itemId = $service->acceptShoppingSuggestion(
                $householdId,
                $memberId,
                (int)($_POST['suggestion_id'] ?? 0)
            );
            flash('success', 'Shopping suggestion accepted as list item #' . $itemId . '.');
        } elseif ($action === 'dismiss_suggestion') {
            $service->dismissSuggestion(
                $householdId,
                $memberId,
                (int)($_POST['suggestion_id'] ?? 0)
            );
            flash('success', 'Shopping suggestion dismissed.');
        } else {
            throw new InvalidArgumentException('Unknown shopping action.');
        }

        $_SESSION['shopping_list_action_key'] = bin2hex(random_bytes(32));
        redirect('/shopping-list.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/shopping-list.php');
    }
}

$listStatement = $pdo->prepare(
    "SELECT sl.*,
            (SELECT COUNT(*) FROM shopping_list_items count_items WHERE count_items.shopping_list_id = sl.id) AS item_count,
            (SELECT COALESCE(SUM(COALESCE(open_items.estimated_cost, 0)), 0)
             FROM shopping_list_items open_items
             WHERE open_items.shopping_list_id = sl.id AND open_items.purchased = 0 AND open_items.status <> 'purchased') AS open_estimate,
            (SELECT COALESCE(SUM(COALESCE(spent_items.estimated_cost, 0)), 0)
             FROM shopping_list_items spent_items
             WHERE spent_items.shopping_list_id = sl.id AND (spent_items.purchased = 1 OR spent_items.status = 'purchased')) AS spent_estimate
     FROM shopping_lists sl
     WHERE sl.household_id = ? AND sl.status IN ('draft','active','shopping')
     ORDER BY FIELD(sl.status,'shopping','active','draft'), sl.name = 'Automated Restock' DESC, sl.id DESC"
);
$listStatement->execute([$householdId]);
$lists = $listStatement->fetchAll();

$requestedListId = (int)($_GET['list_id'] ?? 0);
$activeList = null;
foreach ($lists as $list) {
    if ($requestedListId > 0 && (int)$list['id'] === $requestedListId) {
        $activeList = $list;
        break;
    }
}
if (!is_array($activeList) && $lists !== []) {
    $activeList = $lists[0];
}

$items = [];
if (is_array($activeList)) {
    $itemsStatement = $pdo->prepare(
        "SELECT sli.*, ii.current_quantity, ii.reorder_level, ii.target_stock_level,
                COALESCE(ic.name, CASE sli.source_type
                    WHEN 'garden' THEN 'Garden Supplies'
                    WHEN 'preservation' THEN 'Preservation Supplies'
                    WHEN 'recipe' THEN 'Weekly Groceries'
                    WHEN 'starter_kit' THEN 'Starter Kit'
                    ELSE 'Pantry Restock'
                END) AS category_name
         FROM shopping_list_items sli
         JOIN shopping_lists sl ON sl.id = sli.shopping_list_id
         LEFT JOIN inventory_items ii ON ii.id = sli.inventory_item_id AND ii.household_id = sl.household_id
         LEFT JOIN inventory_categories ic ON ic.id = ii.category_id
         WHERE sli.shopping_list_id = ? AND sl.household_id = ?
         ORDER BY sli.purchased ASC,
                  FIELD(sli.priority,'critical','high','medium','low'),
                  category_name, sli.item_name, sli.id"
    );
    $itemsStatement->execute([(int)$activeList['id'], $householdId]);
    $items = $itemsStatement->fetchAll();
}

$planningData = $service->dashboardData($householdId, $memberId, $canManage);
$suggestions = array_values(array_filter(
    $planningData['suggestions'],
    static fn(array $suggestion): bool => (string)($suggestion['suggestion_type'] ?? '') === 'shopping'
));

$recipeStatement = $pdo->prepare(
    "SELECT DISTINCT r.id, r.name, mpi.meal_date, mpi.meal_type
     FROM meal_plan_items mpi
     JOIN meal_plans mp ON mp.id = mpi.meal_plan_id
     JOIN recipes r ON r.id = mpi.recipe_id AND r.household_id = mp.household_id
     WHERE mp.household_id = ?
       AND mp.status IN ('draft','active')
       AND mpi.meal_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 21 DAY)
     ORDER BY mpi.meal_date, FIELD(mpi.meal_type,'breakfast','lunch','dinner','snack'), r.name
     LIMIT 6"
);
$recipeStatement->execute([$householdId]);
$plannedRecipes = $recipeStatement->fetchAll();

$harvestStatement = $pdo->prepare(
    "SELECT p.id, p.crop_name AS name, p.expected_harvest_start AS event_date, 'harvest' AS event_type,
            p.growth_stage AS detail
     FROM plantings p
     JOIN garden_zones gz ON gz.id = p.garden_zone_id
     WHERE gz.household_id = ?
       AND p.growth_stage NOT IN ('completed','failed')
       AND p.expected_harvest_start IS NOT NULL
       AND p.expected_harvest_start <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     UNION ALL
     SELECT pb.id, pb.name, COALESCE(pb.best_use_date, DATE(pb.created_at)) AS event_date,
            'preservation' AS event_type, pb.method AS detail
     FROM preservation_batches pb
     WHERE pb.household_id = ?
       AND (pb.status IN ('planned','prepared') OR pb.best_use_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
     ORDER BY event_date
     LIMIT 8"
);
$harvestStatement->execute([$householdId, $householdId]);
$upcomingFoodWork = $harvestStatement->fetchAll();

$priorityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
$estimatedTotal = 0.0;
$spentEstimate = 0.0;
$openItemCount = 0;
$vendorRollups = [];
$categoryGroups = [];
foreach ($items as $item) {
    $priority = (string)($item['priority'] ?? 'medium');
    if (array_key_exists($priority, $priorityCounts)) {
        $priorityCounts[$priority]++;
    }
    $cost = (float)($item['estimated_cost'] ?? 0);
    $estimatedTotal += $cost;
    $isPurchased = (int)($item['purchased'] ?? 0) === 1 || (string)($item['status'] ?? '') === 'purchased';
    if ($isPurchased) {
        $spentEstimate += $cost;
    } else {
        $openItemCount++;
    }
    $supplier = trim((string)($item['supplier'] ?? ''));
    if ($supplier !== '') {
        if (!isset($vendorRollups[$supplier])) {
            $vendorRollups[$supplier] = ['name' => $supplier, 'items' => 0, 'estimate' => 0.0];
        }
        $vendorRollups[$supplier]['items']++;
        $vendorRollups[$supplier]['estimate'] += $cost;
    }
    $categoryName = trim((string)($item['category_name'] ?? 'Other')) ?: 'Other';
    if (!isset($categoryGroups[$categoryName])) {
        $categoryGroups[$categoryName] = 0;
    }
    $categoryGroups[$categoryName]++;
}

uasort($vendorRollups, static function (array $left, array $right): int {
    return $right['estimate'] <=> $left['estimate'];
});
arsort($categoryGroups);

$budget = is_array($activeList) && $activeList['budget_amount'] !== null
    ? (float)$activeList['budget_amount']
    : 0.0;
$budgetRemaining = max(0.0, $budget - $spentEstimate);
$budgetPercent = $budget > 0 ? min(100, (int)round(($spentEstimate / $budget) * 100)) : 0;
$token = csrf_token();
$actionKey = (string)$_SESSION['shopping_list_action_key'];
$flashes = consume_flashes();

function shopping_source_tab(string $sourceType): string
{
    return match ($sourceType) {
        'garden' => 'garden',
        'preservation' => 'preservation',
        'recipe' => 'groceries',
        default => 'restock',
    };
}

function shopping_priority_label(string $priority): string
{
    return $priority === 'critical' ? 'Urgent' : ucfirst($priority);
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Shopping List · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to shopping list</a>
<main id="main-content" class="page-container shopping-page">
    <header class="shopping-hero">
        <div class="shopping-hero-copy">
            <p class="eyebrow">Household supply planning</p>
            <h1>Shopping List</h1>
            <p>Plan ahead and shop smart for a well-stocked homestead.</p>
            <div class="shopping-hero-meta">
                <span><?= $openItemCount ?> open items</span>
                <span><?= count($suggestions) ?> smart suggestions</span>
                <span><?= count($lists) ?> active list<?= count($lists) === 1 ? '' : 's' ?></span>
            </div>
        </div>
        <div class="shopping-hero-art" aria-hidden="true"></div>
    </header>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?> shopping-flash">
            <?= e((string)$message['message']) ?>
        </div>
    <?php endforeach; ?>

    <?php if ($lists !== []): ?>
        <nav class="shopping-list-switcher" aria-label="Shopping lists">
            <?php foreach ($lists as $list): ?>
                <a class="<?= is_array($activeList) && (int)$list['id'] === (int)$activeList['id'] ? 'active' : '' ?>"
                   href="shopping-list.php?list_id=<?= (int)$list['id'] ?>">
                    <?= e((string)$list['name']) ?>
                    <small><?= (int)$list['item_count'] ?> items</small>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="shopping-tabs" role="tablist" aria-label="Shopping categories">
        <button type="button" class="active" data-shopping-tab="restock">Pantry Restock</button>
        <button type="button" data-shopping-tab="garden">Garden Supplies</button>
        <button type="button" data-shopping-tab="preservation">Preservation Supplies</button>
        <button type="button" data-shopping-tab="groceries">Weekly Groceries</button>
        <button type="button" data-shopping-tab="all">All Items</button>
    </div>

    <section class="shopping-layout">
        <div class="shopping-main-column">
            <article class="shopping-list-card">
                <div class="shopping-card-heading">
                    <div>
                        <p class="eyebrow">Current household list</p>
                        <h2><?= is_array($activeList) ? e((string)$activeList['name']) : 'Shopping List' ?></h2>
                        <p><?= is_array($activeList) ? e(ucfirst((string)$activeList['status'])) . ' list generated from household activity.' : 'No active shopping list has been created yet.' ?></p>
                    </div>
                    <div class="shopping-toolbar">
                        <label class="shopping-search">
                            <span class="visually-hidden">Search list items</span>
                            <input type="search" placeholder="Search items" data-shopping-search>
                        </label>
                        <a class="button secondary" href="phase7.php">Optimize in Planning</a>
                    </div>
                </div>

                <div class="shopping-table-wrap" tabindex="0">
                    <table class="shopping-table">
                        <thead>
                        <tr>
                            <th scope="col">Status</th>
                            <th scope="col">Item</th>
                            <th scope="col">Category</th>
                            <th scope="col">Quantity</th>
                            <th scope="col">Priority</th>
                            <th scope="col">Source</th>
                            <th scope="col">Est. Cost</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($items === []): ?>
                            <tr><td colspan="7" class="shopping-empty">No shopping items are recorded. Run a planning cycle to generate low-stock suggestions.</td></tr>
                        <?php endif; ?>
                        <?php $lastGroupKey = null; ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $category = (string)$item['category_name'];
                            $sourceType = (string)$item['source_type'];
                            $sourceTab = shopping_source_tab($sourceType);
                            $priority = (string)$item['priority'];
                            $groupKey = $sourceTab . '-' . substr(hash('sha256', $sourceTab . '|' . $category), 0, 10);
                            $purchased = (int)$item['purchased'] === 1 || (string)($item['status'] ?? '') === 'purchased';
                            $searchText = strtolower(implode(' ', [
                                (string)$item['item_name'], $category, $priority, $sourceType,
                                (string)($item['supplier'] ?? ''),
                            ]));
                            ?>
                            <?php if ($groupKey !== $lastGroupKey): ?>
                                <tr class="shopping-group-row" data-shopping-group="<?= e($sourceTab) ?>" data-shopping-group-key="<?= e($groupKey) ?>">
                                    <th colspan="7" scope="colgroup"><?= e($category) ?></th>
                                </tr>
                                <?php $lastGroupKey = $groupKey; ?>
                            <?php endif; ?>
                            <tr data-shopping-item data-shopping-source="<?= e($sourceTab) ?>" data-shopping-group-key="<?= e($groupKey) ?>" data-shopping-search-text="<?= e($searchText) ?>" class="<?= $purchased ? 'is-purchased' : '' ?>">
                                <td><input type="checkbox" aria-label="<?= $purchased ? 'Purchased' : 'Needed' ?>" <?= $purchased ? 'checked' : '' ?> disabled></td>
                                <td>
                                    <strong><?= e((string)$item['item_name']) ?></strong>
                                    <?php if (!empty($item['notes'])): ?><small><?= e((string)$item['notes']) ?></small><?php endif; ?>
                                </td>
                                <td><?= e($category) ?></td>
                                <td><?= e(rtrim(rtrim(number_format((float)$item['quantity'], 4, '.', ''), '0'), '.')) ?> <?= e((string)$item['unit']) ?></td>
                                <td><span class="priority-chip priority-<?= e($priority) ?>"><?= e(shopping_priority_label($priority)) ?></span></td>
                                <td>
                                    <?= e(str_replace('_', ' ', $sourceType)) ?>
                                    <?php if (!empty($item['supplier'])): ?><small><?= e((string)$item['supplier']) ?></small><?php endif; ?>
                                </td>
                                <td><?= $item['estimated_cost'] !== null ? '$' . number_format((float)$item['estimated_cost'], 2) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <footer class="shopping-list-footer">
                    <span data-shopping-count><?= count($items) ?> items shown</span>
                    <span><?= $openItemCount ?> still needed</span>
                </footer>
            </article>

            <article class="smart-suggestions-card">
                <div class="shopping-card-heading compact">
                    <div>
                        <p class="eyebrow">Household intelligence</p>
                        <h2>Smart Suggestions</h2>
                        <p>Recommendations based on pantry, meal, garden, and preservation activity.</p>
                    </div>
                    <a href="phase7.php" class="text-link">Refresh in Planning →</a>
                </div>
                <div class="suggestion-grid">
                    <section class="suggestion-panel">
                        <div class="suggestion-panel-heading">
                            <div><span>◫</span><h3>Low Stock Alerts</h3></div>
                            <a href="phase2.php?section=inventory">Pantry →</a>
                        </div>
                        <?php if ($suggestions === []): ?><p class="suggestion-empty">No pending low-stock suggestions.</p><?php endif; ?>
                        <?php foreach (array_slice($suggestions, 0, 4) as $suggestion): ?>
                            <div class="suggestion-item">
                                <div>
                                    <strong><?= e((string)($suggestion['inventory_name'] ?? $suggestion['title'])) ?></strong>
                                    <small><?= e((string)$suggestion['rationale']) ?></small>
                                </div>
                                <?php if ($canManage): ?>
                                    <div class="suggestion-actions">
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                                            <input type="hidden" name="action" value="accept_suggestion">
                                            <input type="hidden" name="suggestion_id" value="<?= (int)$suggestion['id'] ?>">
                                            <button type="submit" class="icon-add" aria-label="Add <?= e((string)$suggestion['title']) ?> to shopping list">+</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                                            <input type="hidden" name="action" value="dismiss_suggestion">
                                            <input type="hidden" name="suggestion_id" value="<?= (int)$suggestion['id'] ?>">
                                            <button type="submit" class="icon-dismiss" aria-label="Dismiss <?= e((string)$suggestion['title']) ?>">×</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>

                    <section class="suggestion-panel">
                        <div class="suggestion-panel-heading">
                            <div><span>◉</span><h3>Planned Recipes</h3></div>
                            <a href="phase4.php#meal-planning">Meals →</a>
                        </div>
                        <?php if ($plannedRecipes === []): ?><p class="suggestion-empty">No recipes scheduled in the next three weeks.</p><?php endif; ?>
                        <?php foreach ($plannedRecipes as $recipe): ?>
                            <div class="suggestion-item read-only">
                                <div>
                                    <strong><?= e((string)$recipe['name']) ?></strong>
                                    <small><?= e((string)$recipe['meal_date']) ?> · <?= e((string)$recipe['meal_type']) ?></small>
                                </div>
                                <span class="suggestion-mark">↗</span>
                            </div>
                        <?php endforeach; ?>
                    </section>

                    <section class="suggestion-panel">
                        <div class="suggestion-panel-heading">
                            <div><span>♧</span><h3>Harvest & Preserves</h3></div>
                            <a href="phase6.php?section=preservation">Calendar →</a>
                        </div>
                        <?php if ($upcomingFoodWork === []): ?><p class="suggestion-empty">No upcoming harvest or preservation work.</p><?php endif; ?>
                        <?php foreach (array_slice($upcomingFoodWork, 0, 5) as $event): ?>
                            <div class="suggestion-item read-only">
                                <div>
                                    <strong><?= e((string)$event['name']) ?></strong>
                                    <small><?= e(str_replace('_', ' ', (string)$event['detail'])) ?><?= $event['event_date'] ? ' · ' . e((string)$event['event_date']) : '' ?></small>
                                </div>
                                <span class="suggestion-mark"><?= $event['event_type'] === 'harvest' ? '♧' : '▣' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </section>
                </div>
            </article>
        </div>

        <aside class="shopping-sidebar" aria-label="Shopping summary">
            <section class="shopping-side-card summary-card">
                <p class="eyebrow">Current estimate</p>
                <h2>Shopping Summary</h2>
                <strong class="shopping-total">$<?= number_format($estimatedTotal, 2) ?></strong>
                <span><?= count($items) ?> total items · <?= $openItemCount ?> open</span>
                <dl class="priority-summary">
                    <div><dt><i class="dot critical"></i>Urgent</dt><dd><?= $priorityCounts['critical'] ?></dd></div>
                    <div><dt><i class="dot high"></i>High Priority</dt><dd><?= $priorityCounts['high'] ?></dd></div>
                    <div><dt><i class="dot medium"></i>Medium Priority</dt><dd><?= $priorityCounts['medium'] ?></dd></div>
                    <div><dt><i class="dot low"></i>Low Priority</dt><dd><?= $priorityCounts['low'] ?></dd></div>
                </dl>
                <?php if ($categoryGroups !== []): ?>
                    <div class="category-mini-list">
                        <?php foreach (array_slice($categoryGroups, 0, 4, true) as $category => $count): ?>
                            <span><?= e((string)$category) ?><b><?= (int)$count ?></b></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="shopping-side-card budget-card">
                <p class="eyebrow">Active list allowance</p>
                <h2>Budget Overview</h2>
                <?php if ($budget > 0): ?>
                    <div class="budget-line"><span>Monthly budget</span><strong>$<?= number_format($budget, 2) ?></strong></div>
                    <div class="budget-progress"><span style="width:<?= $budgetPercent ?>%"></span></div>
                    <div class="budget-line"><span>Recorded spend</span><strong>$<?= number_format($spentEstimate, 2) ?></strong></div>
                    <div class="budget-line"><span>Remaining</span><strong>$<?= number_format($budgetRemaining, 2) ?></strong></div>
                <?php else: ?>
                    <p class="side-empty">No budget is recorded for this list. Budget data remains managed by the existing shopping record.</p>
                <?php endif; ?>
            </section>

            <section class="shopping-side-card vendor-card">
                <p class="eyebrow">Where the list points</p>
                <h2>Vendor Suggestions</h2>
                <?php if ($vendorRollups === []): ?><p class="side-empty">No suppliers are attached to the current items.</p><?php endif; ?>
                <?php foreach (array_slice($vendorRollups, 0, 5, true) as $vendor): ?>
                    <div class="vendor-row">
                        <span class="vendor-icon">⌂</span>
                        <div><strong><?= e((string)$vendor['name']) ?></strong><small><?= (int)$vendor['items'] ?> item<?= (int)$vendor['items'] === 1 ? '' : 's' ?></small></div>
                        <b>$<?= number_format((float)$vendor['estimate'], 2) ?></b>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="shopping-side-card quick-add-card">
                <p class="eyebrow">Existing planning suggestions</p>
                <h2>Quick Add</h2>
                <?php if ($canManage && $suggestions !== []): ?>
                    <form method="post" class="quick-add-form">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                        <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                        <input type="hidden" name="action" value="accept_suggestion">
                        <label>
                            <span>Suggested item</span>
                            <select name="suggestion_id" required>
                                <option value="">Choose a suggestion</option>
                                <?php foreach ($suggestions as $suggestion): ?>
                                    <option value="<?= (int)$suggestion['id'] ?>"><?= e((string)$suggestion['title']) ?> · <?= e((string)$suggestion['priority']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="button primary" type="submit">Add to List +</button>
                    </form>
                <?php elseif (!$canManage): ?>
                    <p class="side-empty">A household manager can accept planning suggestions.</p>
                <?php else: ?>
                    <p class="side-empty">Run the household planning cycle to generate suggestions.</p>
                    <a class="button secondary" href="phase7.php">Open Planning</a>
                <?php endif; ?>
            </section>
        </aside>
    </section>

    <footer class="shopping-tip">
        <span class="tip-icon">♧</span>
        <div>
            <p class="eyebrow">Homestead tip</p>
            <?php $topVendor = $vendorRollups !== [] ? reset($vendorRollups) : null; ?>
            <strong><?= is_array($topVendor) ? 'Group the largest supplier order to reduce repeat trips.' : 'Accept low-stock suggestions before the next household shopping trip.' ?></strong>
            <small><?= is_array($topVendor) ? e((string)$topVendor['name']) . ' currently represents $' . number_format((float)$topVendor['estimate'], 2) . ' of the list.' : 'Planning suggestions are generated from reorder levels and current inventory.' ?></small>
        </div>
        <a href="phase7.php">Open household planning →</a>
    </footer>
</main>
<script src="assets/js/homestead-list.js?v=20260727-1" defer></script>
</body>
</html>
