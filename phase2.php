<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use DateTimeImmutable;
use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\positive_decimal;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$householdId = (int)$user['household_id'];
$memberId = (int)$user['member_id'];
$section = (string)($_GET['section'] ?? 'family');
if (!in_array($section, ['family', 'storage', 'inventory', 'ledger'], true)) {
    $section = 'family';
}

$canManageMembers = $auth->can($user, 'members.manage');
$canManageStorage = $auth->can($user, 'storage.manage');
$canViewStorage = $canManageStorage || $auth->can($user, 'storage.view');
$canManageInventory = $auth->can($user, 'inventory.manage');
$canViewInventory = $canManageInventory || $auth->can($user, 'inventory.view');
$canViewPlanning = $auth->can($user, 'tasks.manage') || $auth->can($user, 'tasks.complete');
if (($section === 'storage' && !$canViewStorage) || (in_array($section, ['inventory', 'ledger'], true) && !$canViewInventory)) {
    http_response_code(403);
    exit('You do not have permission to view this household area.');
}

$text = static function (mixed $value, int $max, bool $required = false): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) {
            throw new InvalidArgumentException('A required text value is missing.');
        }
        return null;
    }
    if (mb_strlen($value) > $max) {
        throw new InvalidArgumentException('A text value exceeds its allowed length.');
    }
    return $value;
};
$date = static function (mixed $value, string $field): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($field . ' is invalid.');
    }
    return $value;
};
$temperature = static function (mixed $value): ?float {
    if ($value === '' || $value === null) {
        return null;
    }
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < -100 || (float)$value > 250) {
        throw new InvalidArgumentException('Target temperature must be between -100 and 250.');
    }
    return round((float)$value, 2);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_member') {
            $auth->requirePermission($user, 'members.manage');
            $displayName = $text($_POST['display_name'] ?? '', 120, true);
            $ageGroup = (string)($_POST['age_group'] ?? 'adult');
            $role = (string)($_POST['role'] ?? 'adult_member');
            $activityLevel = (string)($_POST['activity_level'] ?? 'not_set');
            $wellnessVisibility = (string)($_POST['wellness_visibility'] ?? 'private');
            if (!in_array($ageGroup, ['adult', 'teen', 'child', 'guest'], true)
                || !in_array($role, ['administrator', 'adult_member', 'youth_member', 'guest_helper'], true)
                || !in_array($activityLevel, ['not_set', 'mostly_sedentary', 'lightly_active', 'moderately_active', 'very_active', 'physically_demanding'], true)
                || !in_array($wellnessVisibility, ['private', 'authorized_adults', 'household_planning'], true)) {
                throw new InvalidArgumentException('One or more member options are invalid.');
            }
            if (($role === 'youth_member' && !in_array($ageGroup, ['teen', 'child'], true))
                || (in_array($role, ['administrator', 'adult_member'], true) && $ageGroup !== 'adult')
                || ($role === 'guest_helper' && $ageGroup !== 'guest')) {
                throw new InvalidArgumentException('The selected age group and household role do not match.');
            }
            $servingMultiplier = positive_decimal($_POST['serving_multiplier'] ?? 1, 'Serving multiplier', false);
            if ($servingMultiplier > 5) {
                throw new InvalidArgumentException('Serving multiplier must be 5 or less.');
            }
            $height = ($_POST['height_value'] ?? '') === '' ? null : positive_decimal($_POST['height_value'], 'Height', false);
            $weight = ($_POST['weight_value'] ?? '') === '' ? null : positive_decimal($_POST['weight_value'], 'Weight', false);
            $heightUnit = (string)($_POST['height_unit'] ?? 'in');
            $weightUnit = (string)($_POST['weight_unit'] ?? 'lb');
            if (!in_array($heightUnit, ['in', 'cm'], true) || !in_array($weightUnit, ['lb', 'kg'], true)) {
                throw new InvalidArgumentException('Measurement units are invalid.');
            }
            if (($heightUnit === 'in' && $height !== null && $height > 120)
                || ($heightUnit === 'cm' && $height !== null && $height > 305)
                || ($weightUnit === 'lb' && $weight !== null && $weight > 1500)
                || ($weightUnit === 'kg' && $weight !== null && $weight > 680)) {
                throw new InvalidArgumentException('A measurement is outside the supported household-planning range.');
            }
            $statement = $pdo->prepare(
                'INSERT INTO household_members
                 (household_id, display_name, age_group, role, serving_multiplier, dietary_pattern, allergen_notes,
                  height_value, height_unit, weight_value, weight_unit, activity_level, wellness_visibility,
                  wellness_updated_at, joined_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), CURDATE())'
            );
            $statement->execute([
                $householdId, $displayName, $ageGroup, $role, $servingMultiplier,
                $text($_POST['dietary_pattern'] ?? '', 120), $text($_POST['allergen_notes'] ?? '', 5000),
                $height, $heightUnit, $weight, $weightUnit, $activityLevel, $wellnessVisibility,
            ]);
            flash('success', 'Family member added.');
            redirect('/phase2.php?section=family');
        }

        if ($action === 'toggle_member') {
            $auth->requirePermission($user, 'members.manage');
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1 || $id === $memberId) {
                throw new InvalidArgumentException('Choose another non-owner household member.');
            }
            $statement = $pdo->prepare(
                "UPDATE household_members SET status = IF(status = 'active', 'inactive', 'active')
                 WHERE id = ? AND household_id = ? AND role <> 'owner'"
            );
            $statement->execute([$id, $householdId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The member could not be updated.');
            }
            flash('success', 'Member status updated.');
            redirect('/phase2.php?section=family');
        }

        if ($action === 'add_location') {
            $auth->requirePermission($user, 'storage.manage');
            $name = $text($_POST['name'] ?? '', 140, true);
            $locationType = $text($_POST['location_type'] ?? 'shelf', 80, true);
            $parentId = ($_POST['parent_id'] ?? '') === '' ? null : (int)$_POST['parent_id'];
            if ($parentId !== null) {
                $parent = $pdo->prepare('SELECT 1 FROM storage_locations WHERE id = ? AND household_id = ?');
                $parent->execute([$parentId, $householdId]);
                if (!$parent->fetchColumn()) {
                    throw new InvalidArgumentException('The parent location does not belong to this household.');
                }
            }
            $humidity = ($_POST['target_humidity'] ?? '') === '' ? null : positive_decimal($_POST['target_humidity'], 'Humidity');
            if ($humidity !== null && $humidity > 100) {
                throw new InvalidArgumentException('Humidity cannot exceed 100 percent.');
            }
            $statement = $pdo->prepare(
                'INSERT INTO storage_locations
                 (household_id, parent_id, name, location_type, capacity_value, capacity_unit, target_temperature, target_humidity, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $householdId, $parentId, $name, $locationType,
                ($_POST['capacity_value'] ?? '') === '' ? null : positive_decimal($_POST['capacity_value'], 'Capacity'),
                $text($_POST['capacity_unit'] ?? 'items', 30) ?? 'items',
                $temperature($_POST['target_temperature'] ?? ''), $humidity,
                $text($_POST['notes'] ?? '', 5000),
            ]);
            flash('success', 'Storage location added.');
            redirect('/phase2.php?section=storage');
        }

        if ($action === 'add_inventory') {
            $auth->requirePermission($user, 'inventory.manage');
            $name = $text($_POST['name'] ?? '', 180, true);
            $itemType = (string)($_POST['item_type'] ?? 'ingredient');
            $unit = $text($_POST['unit'] ?? 'each', 30, true);
            if (!in_array($itemType, ['ingredient', 'prepared_food', 'preserved_food', 'seed', 'supply'], true)) {
                throw new InvalidArgumentException('The inventory type is invalid.');
            }
            $categoryId = ($_POST['category_id'] ?? '') === '' ? null : (int)$_POST['category_id'];
            $locationId = ($_POST['storage_location_id'] ?? '') === '' ? null : (int)$_POST['storage_location_id'];
            if ($categoryId !== null) {
                $category = $pdo->prepare('SELECT 1 FROM inventory_categories WHERE id = ? AND (household_id IS NULL OR household_id = ?)');
                $category->execute([$categoryId, $householdId]);
                if (!$category->fetchColumn()) {
                    throw new InvalidArgumentException('The inventory category is invalid.');
                }
            }
            if ($locationId !== null) {
                $location = $pdo->prepare('SELECT 1 FROM storage_locations WHERE id = ? AND household_id = ?');
                $location->execute([$locationId, $householdId]);
                if (!$location->fetchColumn()) {
                    throw new InvalidArgumentException('The storage location is invalid.');
                }
            }
            $quantity = positive_decimal($_POST['quantity'] ?? 0, 'Quantity');
            $purchaseCost = ($_POST['purchase_cost'] ?? '') === '' ? null : positive_decimal($_POST['purchase_cost'], 'Purchase cost');
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'INSERT INTO inventory_items
                 (household_id, category_id, storage_location_id, name, item_type, current_quantity, unit,
                  reorder_level, target_stock_level, purchase_cost, best_use_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $householdId, $categoryId, $locationId, $name, $itemType, $quantity, $unit,
                ($_POST['reorder_level'] ?? '') === '' ? null : positive_decimal($_POST['reorder_level'], 'Reorder level'),
                ($_POST['target_stock_level'] ?? '') === '' ? null : positive_decimal($_POST['target_stock_level'], 'Target stock level'),
                $purchaseCost, $date($_POST['best_use_date'] ?? '', 'Best-use date'), $text($_POST['notes'] ?? '', 5000),
            ]);
            $itemId = (int)$pdo->lastInsertId();
            $ledger = $pdo->prepare(
                "INSERT INTO food_ledger_events
                 (household_id, inventory_item_id, member_id, event_type, quantity, unit, destination_location_id, cost_effect, notes)
                 VALUES (?, ?, ?, 'purchased', ?, ?, ?, ?, 'Initial inventory entry')"
            );
            $ledger->execute([$householdId, $itemId, $memberId, $quantity, $unit, $locationId, $purchaseCost]);
            $pdo->commit();
            flash('success', 'Inventory item and opening ledger event added.');
            redirect('/phase2.php?section=inventory');
        }

        if ($action === 'adjust_inventory') {
            $auth->requirePermission($user, 'inventory.manage');
            $itemId = (int)($_POST['id'] ?? 0);
            if (!is_numeric($_POST['delta'] ?? null) || !is_finite((float)$_POST['delta'])) {
                throw new InvalidArgumentException('Enter a numeric inventory adjustment.');
            }
            $delta = round((float)$_POST['delta'], 4);
            if ($itemId < 1 || $delta === 0.0 || abs($delta) > 1000000) {
                throw new InvalidArgumentException('Choose an item and enter a reasonable non-zero adjustment.');
            }
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                "SELECT current_quantity, unit, storage_location_id FROM inventory_items
                 WHERE id = ? AND household_id = ? AND status = 'active' FOR UPDATE"
            );
            $statement->execute([$itemId, $householdId]);
            $item = $statement->fetch();
            if (!is_array($item)) {
                throw new RuntimeException('Inventory item not found.');
            }
            $newQuantity = round((float)$item['current_quantity'] + $delta, 4);
            if ($newQuantity < 0) {
                throw new InvalidArgumentException('The adjustment would create negative inventory.');
            }
            $update = $pdo->prepare('UPDATE inventory_items SET current_quantity = ? WHERE id = ? AND household_id = ? AND current_quantity = ?');
            $update->execute([$newQuantity, $itemId, $householdId, $item['current_quantity']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Inventory changed during the adjustment. Try again.');
            }
            $ledger = $pdo->prepare(
                "INSERT INTO food_ledger_events
                 (household_id, inventory_item_id, member_id, event_type, quantity, unit, source_location_id, destination_location_id, notes)
                 VALUES (?, ?, ?, 'adjusted', ?, ?, ?, ?, ?)"
            );
            $ledger->execute([
                $householdId, $itemId, $memberId, $delta, $item['unit'], $item['storage_location_id'],
                $item['storage_location_id'], $text($_POST['notes'] ?? 'Manual quantity adjustment', 5000) ?? 'Manual quantity adjustment',
            ]);
            $pdo->commit();
            flash('success', 'Inventory adjusted and recorded in the ledger.');
            redirect('/phase2.php?section=inventory');
        }

        throw new InvalidArgumentException('Unknown household action.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/phase2.php?section=' . rawurlencode($section));
    }
}

$membersStatement = $pdo->prepare(
    "SELECT id, display_name, age_group, role, serving_multiplier, activity_level, wellness_visibility, status
     FROM household_members WHERE household_id = ?
     ORDER BY status = 'active' DESC, FIELD(role, 'owner','administrator','adult_member','youth_member','guest_helper'), display_name"
);
$membersStatement->execute([$householdId]);
$members = $membersStatement->fetchAll();
$locationsStatement = $pdo->prepare(
    "SELECT l.*, p.name AS parent_name, COUNT(i.id) AS item_count
     FROM storage_locations l
     LEFT JOIN storage_locations p ON p.id = l.parent_id AND p.household_id = l.household_id
     LEFT JOIN inventory_items i ON i.storage_location_id = l.id AND i.household_id = l.household_id AND i.status = 'active'
     WHERE l.household_id = ? GROUP BY l.id ORDER BY COALESCE(p.name, l.name), l.name"
);
$locationsStatement->execute([$householdId]);
$locations = $locationsStatement->fetchAll();
$categoriesStatement = $pdo->prepare('SELECT id, name FROM inventory_categories WHERE household_id IS NULL OR household_id = ? ORDER BY name');
$categoriesStatement->execute([$householdId]);
$categories = $categoriesStatement->fetchAll();
$inventoryStatement = $pdo->prepare(
    'SELECT i.*, c.name AS category_name, l.name AS location_name
     FROM inventory_items i
     LEFT JOIN inventory_categories c ON c.id = i.category_id AND (c.household_id IS NULL OR c.household_id = i.household_id)
     LEFT JOIN storage_locations l ON l.id = i.storage_location_id AND l.household_id = i.household_id
     WHERE i.household_id = ? ORDER BY i.status = \'active\' DESC, i.name'
);
$inventoryStatement->execute([$householdId]);
$inventory = $inventoryStatement->fetchAll();
$ledgerStatement = $pdo->prepare(
    'SELECT e.*, i.name AS item_name, m.display_name AS member_name
     FROM food_ledger_events e
     LEFT JOIN inventory_items i ON i.id = e.inventory_item_id AND i.household_id = e.household_id
     LEFT JOIN household_members m ON m.id = e.member_id AND m.household_id = e.household_id
     WHERE e.household_id = ? ORDER BY e.occurred_at DESC, e.id DESC LIMIT 100'
);
$ledgerStatement->execute([$householdId]);
$ledger = $ledgerStatement->fetchAll();
$activeInventory = array_values(array_filter(
    $inventory,
    static fn(array $item): bool => (string)($item['status'] ?? '') === 'active'
));
$inventoryCount = count($activeInventory);
$inventoryValue = 0.0;
$lowStockItems = [];
$expiringSoonItems = [];
$recentInventory = $activeInventory;
$categoryUsage = [];
$today = new DateTimeImmutable('today');
$expiryCutoff = $today->modify('+30 days');

foreach ($activeInventory as $item) {
    $quantity = (float)($item['current_quantity'] ?? 0);
    $purchaseCost = $item['purchase_cost'] === null ? 0.0 : (float)$item['purchase_cost'];
    $inventoryValue += max(0.0, $quantity) * max(0.0, $purchaseCost);

    if ($item['reorder_level'] !== null && $quantity <= (float)$item['reorder_level']) {
        $lowStockItems[] = $item;
    }

    $bestUse = trim((string)($item['best_use_date'] ?? ''));
    if ($bestUse !== '') {
        $bestUseDate = DateTimeImmutable::createFromFormat('!Y-m-d', $bestUse);
        if ($bestUseDate && $bestUseDate >= $today && $bestUseDate <= $expiryCutoff) {
            $expiringSoonItems[] = $item;
        }
    }

    $categoryName = trim((string)($item['category_name'] ?? 'Uncategorized')) ?: 'Uncategorized';
    $categoryUsage[$categoryName] = ($categoryUsage[$categoryName] ?? 0) + 1;
}

usort(
    $recentInventory,
    static fn(array $left, array $right): int => strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''))
);
$recentInventory = array_slice($recentInventory, 0, 8);
arsort($categoryUsage);
$categoryUsage = array_slice($categoryUsage, 0, 6, true);
$locationCards = array_slice($locations, 0, 6);
$shoppingSuggestions = array_slice($lowStockItems, 0, 6);
$inventoryRows = $section === 'inventory' ? $inventory : [];
$sectionMeta = [
    'family' => ['eyebrow' => 'Household profiles', 'title' => 'Family & Household', 'description' => 'Keep household roles, serving plans, activity context, and privacy controls organized.'],
    'storage' => ['eyebrow' => 'Pantry organization', 'title' => 'Storage Locations', 'description' => 'Manage shelves, rooms, containers, capacity, and target storage conditions.'],
    'inventory' => ['eyebrow' => 'Digital pantry', 'title' => 'Pantry Inventory', 'description' => "Track what you have, where it's stored, and when it needs attention."],
    'ledger' => ['eyebrow' => 'Food provenance', 'title' => 'Food Lifecycle Ledger', 'description' => 'Review the immutable household record of purchases, harvests, moves, use, preservation, and waste.'],
];
$currentMeta = $sectionMeta[$section];
$flashes = consume_flashes();
$token = csrf_token();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#080705">
    <title><?= e($currentMeta['title']) ?> · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/homestead-pantry.js?v=20260727-1" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to <?= e(strtolower($currentMeta['title'])) ?></a>
<main id="main-content" class="page-container homestead-operations">
    <header class="operations-hero<?= $section === 'inventory' ? ' operations-hero--pantry' : '' ?>">
        <div class="operations-hero__copy">
            <p class="operations-kicker"><?= e($currentMeta['eyebrow']) ?></p>
            <h1><?= e($currentMeta['title']) ?></h1>
            <p><?= e($currentMeta['description']) ?></p>
        </div>
        <?php if ($section === 'inventory'): ?>
            <div class="operations-hero__image" aria-hidden="true"></div>
        <?php endif; ?>
    </header>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="operations-alert operations-alert--<?= $message['type'] === 'error' ? 'warning' : 'success' ?>"><?= e((string)$message['message']) ?></div>
    <?php endforeach; ?>

    <nav class="operations-tabs" aria-label="Household operations sections">
        <a class="<?= $section === 'family' ? 'is-active' : '' ?>" href="?section=family">Family</a>
        <?php if ($canViewStorage): ?><a class="<?= $section === 'storage' ? 'is-active' : '' ?>" href="?section=storage">Storage</a><?php endif; ?>
        <?php if ($canViewInventory): ?>
            <a class="<?= $section === 'inventory' ? 'is-active' : '' ?>" href="?section=inventory">Inventory</a>
            <a class="<?= $section === 'ledger' ? 'is-active' : '' ?>" href="?section=ledger">Food ledger</a>
        <?php endif; ?>
    </nav>

    <?php if ($section === 'inventory'): ?>
        <section class="pantry-metrics" aria-label="Pantry inventory summary">
            <article class="pantry-metric">
                <span>Total items</span>
                <strong><?= number_format($inventoryCount) ?></strong>
                <small>$<?= number_format($inventoryValue, 2) ?> recorded value</small>
            </article>
            <article class="pantry-metric pantry-metric--attention">
                <span>Low stock alerts</span>
                <strong><?= number_format(count($lowStockItems)) ?></strong>
                <a href="#inventory-table">View low stock →</a>
            </article>
            <article class="pantry-metric">
                <span>Expiring soon</span>
                <strong><?= number_format(count($expiringSoonItems)) ?></strong>
                <small>Within 30 days</small>
            </article>
            <article class="pantry-metric">
                <span>Recently added</span>
                <strong><?= number_format(count($recentInventory)) ?></strong>
                <small>Latest active records</small>
            </article>
        </section>

        <div class="pantry-layout">
            <section class="pantry-main-card" aria-labelledby="inventory-heading">
                <div class="pantry-card-heading">
                    <div>
                        <p class="operations-kicker">Household stock</p>
                        <h2 id="inventory-heading">Inventory</h2>
                    </div>
                    <?php if ($canManageInventory): ?>
                        <details class="pantry-add-drawer">
                            <summary>Add inventory</summary>
                            <div class="pantry-add-drawer__panel">
                                <form method="post" class="operations-form operations-form--grid">
                                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                    <input type="hidden" name="action" value="add_inventory">
                                    <label><span>Name</span><input name="name" maxlength="180" required></label>
                                    <label><span>Type</span><select name="item_type"><option value="ingredient">Ingredient</option><option value="prepared_food">Prepared food</option><option value="preserved_food">Preserved food</option><option value="seed">Seed</option><option value="supply">Supply</option></select></label>
                                    <label><span>Quantity</span><input type="number" step="0.0001" min="0" name="quantity" required></label>
                                    <label><span>Unit</span><input name="unit" maxlength="30" value="each" required></label>
                                    <label><span>Category</span><select name="category_id"><option value="">Uncategorized</option><?php foreach ($categories as $row): ?><option value="<?= (int)$row['id'] ?>"><?= e((string)$row['name']) ?></option><?php endforeach; ?></select></label>
                                    <label><span>Location</span><select name="storage_location_id"><option value="">Unassigned</option><?php foreach ($locations as $row): ?><option value="<?= (int)$row['id'] ?>"><?= e((string)$row['name']) ?></option><?php endforeach; ?></select></label>
                                    <label><span>Reorder level</span><input type="number" step="0.0001" min="0" name="reorder_level"></label>
                                    <label><span>Target level</span><input type="number" step="0.0001" min="0" name="target_stock_level"></label>
                                    <label><span>Purchase cost</span><input type="number" step="0.01" min="0" name="purchase_cost"></label>
                                    <label><span>Best-use date</span><input type="date" name="best_use_date"></label>
                                    <label class="operations-form__wide"><span>Notes</span><textarea name="notes" maxlength="5000"></textarea></label>
                                    <button class="operations-button operations-button--primary operations-form__wide" type="submit">Add item and ledger event</button>
                                </form>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>

                <div class="pantry-category-tabs" role="group" aria-label="Filter inventory by category">
                    <button class="is-active" type="button" data-pantry-filter="all">All items</button>
                    <?php foreach (array_slice(array_keys($categoryUsage), 0, 5) as $categoryName): ?>
                        <button type="button" data-pantry-filter="<?= e(strtolower($categoryName)) ?>"><?= e($categoryName) ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="pantry-search-row">
                    <label class="pantry-search">
                        <span class="visually-hidden">Search inventory</span>
                        <span aria-hidden="true">⌕</span>
                        <input type="search" placeholder="Search inventory..." data-pantry-search>
                    </label>
                    <span class="pantry-result-count" data-pantry-count><?= number_format(count($inventoryRows)) ?> items</span>
                </div>

                <div class="pantry-table-wrap" id="inventory-table" tabindex="0">
                    <table class="pantry-table">
                        <thead><tr><th scope="col">Item</th><th scope="col">Location</th><th scope="col">Quantity</th><th scope="col">Status</th><th scope="col">Reorder level</th><th scope="col">Adjust</th></tr></thead>
                        <tbody>
                        <?php if ($inventoryRows === []): ?><tr><td colspan="6" class="pantry-empty">No inventory records yet.</td></tr><?php endif; ?>
                        <?php foreach ($inventoryRows as $row):
                            $quantity = (float)$row['current_quantity'];
                            $isLow = $row['reorder_level'] !== null && $quantity <= (float)$row['reorder_level'];
                            $isActive = (string)$row['status'] === 'active';
                            $statusLabel = !$isActive ? ucfirst((string)$row['status']) : ($isLow ? 'Low' : 'Good');
                            $statusClass = !$isActive ? 'is-muted' : ($isLow ? 'is-low' : 'is-good');
                            $categoryKey = strtolower(trim((string)($row['category_name'] ?? 'Uncategorized')) ?: 'uncategorized');
                            $searchText = strtolower(implode(' ', [(string)$row['name'], (string)($row['category_name'] ?? ''), (string)($row['location_name'] ?? ''), $statusLabel]));
                        ?>
                            <tr data-inventory-row data-category="<?= e($categoryKey) ?>" data-search="<?= e($searchText) ?>">
                                <td><span class="pantry-item-mark" aria-hidden="true">▦</span><span><strong><?= e((string)$row['name']) ?></strong><small><?= e((string)($row['category_name'] ?? 'Uncategorized')) ?></small></span></td>
                                <td><span class="pantry-location-pin" aria-hidden="true">⌖</span><?= e((string)($row['location_name'] ?? 'Unassigned')) ?></td>
                                <td><strong><?= e((string)$row['current_quantity']) ?></strong> <?= e((string)$row['unit']) ?></td>
                                <td><span class="pantry-status <?= $statusClass ?>"><?= e($statusLabel) ?></span><?php if ($row['best_use_date']): ?><small class="pantry-best-use">Best use <?= e((string)$row['best_use_date']) ?></small><?php endif; ?></td>
                                <td><?= e((string)($row['reorder_level'] ?? '—')) ?><?= $row['reorder_level'] !== null ? ' ' . e((string)$row['unit']) : '' ?></td>
                                <td>
                                <?php if ($canManageInventory && $isActive): ?>
                                    <form method="post" class="pantry-adjust-form">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                        <input type="hidden" name="action" value="adjust_inventory">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <label class="visually-hidden" for="delta-<?= (int)$row['id'] ?>">Adjustment for <?= e((string)$row['name']) ?></label>
                                        <input id="delta-<?= (int)$row['id'] ?>" type="number" step="0.0001" name="delta" placeholder="±" required>
                                        <button type="submit">Post</button>
                                    </form>
                                <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="pantry-insights" aria-label="Inventory insights">
                <article class="pantry-insight-card pantry-value-card">
                    <p class="operations-kicker">Inventory overview</p>
                    <h2>Recorded value</h2>
                    <strong>$<?= number_format($inventoryValue, 2) ?></strong>
                    <div class="pantry-sparkline" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
                    <small>Based on recorded quantities and purchase costs.</small>
                </article>

                <article class="pantry-insight-card">
                    <div class="pantry-card-heading"><div><p class="operations-kicker">Stock mix</p><h2>Inventory usage</h2></div></div>
                    <div class="pantry-usage-list">
                        <?php if ($categoryUsage === []): ?><p class="pantry-empty">Add categorized items to see the stock mix.</p><?php endif; ?>
                        <?php foreach ($categoryUsage as $categoryName => $count): $percentage = $inventoryCount > 0 ? (int)round(($count / $inventoryCount) * 100) : 0; ?>
                            <div><span><strong><?= e($categoryName) ?></strong><em><?= $percentage ?>%</em></span><i><b style="width:<?= $percentage ?>%"></b></i></div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="pantry-insight-card">
                    <p class="operations-kicker">Replenishment</p>
                    <h2>Shopping suggestions</h2>
                    <div class="pantry-suggestion-list">
                        <?php if ($shoppingSuggestions === []): ?><p class="pantry-empty">Nothing is currently at or below its reorder level.</p><?php endif; ?>
                        <?php foreach ($shoppingSuggestions as $item):
                            $needed = max(0.0, (float)$item['reorder_level'] - (float)$item['current_quantity']);
                        ?>
                            <div><span><strong><?= e((string)$item['name']) ?></strong><small><?= e(number_format($needed > 0 ? $needed : 1, 2, '.', '')) ?> <?= e((string)$item['unit']) ?></small></span><a href="#inventory-table" aria-label="Review <?= e((string)$item['name']) ?> inventory">+</a></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($canViewPlanning): ?><a class="operations-button operations-button--secondary operations-button--full" href="phase7.php">View planning & shopping</a><?php endif; ?>
                </article>
            </aside>
        </div>

        <section class="pantry-locations-card">
            <div class="pantry-card-heading">
                <div><p class="operations-kicker">Organization</p><h2>Storage locations</h2></div>
                <?php if ($canViewStorage): ?><a href="?section=storage">View all locations →</a><?php endif; ?>
            </div>
            <div class="pantry-location-grid">
                <?php if ($locationCards === []): ?><p class="pantry-empty">No storage locations have been created.</p><?php endif; ?>
                <?php foreach ($locationCards as $location): ?>
                    <article><span aria-hidden="true">▤</span><strong><?= e((string)$location['name']) ?></strong><small><?= (int)$location['item_count'] ?> items</small></article>
                <?php endforeach; ?>
            </div>
        </section>

    <?php elseif ($section === 'storage'): ?>
        <div class="operations-two-column">
            <section class="operations-card operations-card--wide">
                <div class="pantry-card-heading"><div><p class="operations-kicker">Configured spaces</p><h2>Storage locations</h2></div><strong><?= count($locations) ?> locations</strong></div>
                <div class="storage-card-grid">
                    <?php if ($locations === []): ?><p class="pantry-empty">No storage locations have been created.</p><?php endif; ?>
                    <?php foreach ($locations as $row): ?>
                        <article class="storage-card">
                            <span class="storage-card__icon" aria-hidden="true">▤</span>
                            <div><h3><?= e((string)$row['name']) ?></h3><p><?= e((string)($row['parent_name'] ?? 'Top level')) ?> · <?= e((string)$row['location_type']) ?></p></div>
                            <strong><?= (int)$row['item_count'] ?> items</strong>
                            <dl><div><dt>Capacity</dt><dd><?= e((string)($row['capacity_value'] ?? '—')) ?> <?= e((string)($row['capacity_unit'] ?? '')) ?></dd></div><div><dt>Temperature</dt><dd><?= $row['target_temperature'] !== null ? e((string)$row['target_temperature']) . '°' : '—' ?></dd></div><div><dt>Humidity</dt><dd><?= $row['target_humidity'] !== null ? e((string)$row['target_humidity']) . '%' : '—' ?></dd></div></dl>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php if ($canManageStorage): ?>
                <aside class="operations-card">
                    <p class="operations-kicker">New space</p><h2>Add storage location</h2>
                    <form method="post" class="operations-form">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add_location">
                        <label><span>Name</span><input name="name" maxlength="140" required></label>
                        <label><span>Parent</span><select name="parent_id"><option value="">Top level</option><?php foreach ($locations as $row): ?><option value="<?= (int)$row['id'] ?>"><?= e((string)$row['name']) ?></option><?php endforeach; ?></select></label>
                        <label><span>Type</span><input name="location_type" maxlength="80" value="shelf" required></label>
                        <div class="operations-form__pair"><label><span>Capacity</span><input type="number" step="0.01" min="0" name="capacity_value"></label><label><span>Unit</span><input name="capacity_unit" maxlength="30" value="items"></label></div>
                        <div class="operations-form__pair"><label><span>Temperature</span><input type="number" step="0.1" min="-100" max="250" name="target_temperature"></label><label><span>Humidity %</span><input type="number" step="0.1" min="0" max="100" name="target_humidity"></label></div>
                        <label><span>Notes</span><textarea name="notes" maxlength="5000"></textarea></label>
                        <button class="operations-button operations-button--primary" type="submit">Add location</button>
                    </form>
                </aside>
            <?php endif; ?>
        </div>

    <?php elseif ($section === 'family'): ?>
        <div class="operations-two-column">
            <section class="operations-card operations-card--wide">
                <div class="pantry-card-heading"><div><p class="operations-kicker">Household roster</p><h2>Family members</h2></div><strong><?= count($members) ?> profiles</strong></div>
                <div class="family-card-grid">
                    <?php foreach ($members as $row): ?>
                        <article class="family-card">
                            <span class="family-card__avatar" aria-hidden="true"><?= e(strtoupper(substr((string)$row['display_name'], 0, 1))) ?></span>
                            <div><h3><?= e((string)$row['display_name']) ?></h3><p><?= e(ucwords(str_replace('_', ' ', (string)$row['role']))) ?> · <?= e((string)$row['age_group']) ?></p><small><?= e(str_replace('_', ' ', (string)($row['activity_level'] ?? 'not set'))) ?> · <?= e((string)($row['wellness_visibility'] ?? 'private')) ?></small></div>
                            <span class="pantry-status <?= (string)$row['status'] === 'active' ? 'is-good' : 'is-muted' ?>"><?= e(ucfirst((string)$row['status'])) ?></span>
                            <?php if ($canManageMembers && $row['role'] !== 'owner' && (int)$row['id'] !== $memberId): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="toggle_member"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="operations-button operations-button--secondary" type="submit">Toggle status</button></form><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php if ($canManageMembers): ?>
                <aside class="operations-card">
                    <p class="operations-kicker">Household profile</p><h2>Add family member</h2>
                    <form method="post" class="operations-form">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add_member">
                        <label><span>Name</span><input name="display_name" maxlength="120" required></label>
                        <div class="operations-form__pair"><label><span>Age group</span><select name="age_group"><option>adult</option><option>teen</option><option>child</option><option>guest</option></select></label><label><span>Role</span><select name="role"><option value="adult_member">Adult member</option><option value="administrator">Administrator</option><option value="youth_member">Youth member</option><option value="guest_helper">Guest helper</option></select></label></div>
                        <label><span>Serving multiplier</span><input type="number" step="0.05" min="0.1" max="5" name="serving_multiplier" value="1"></label>
                        <label><span>Dietary pattern</span><input name="dietary_pattern" maxlength="120"></label>
                        <label><span>Allergen notes</span><textarea name="allergen_notes" maxlength="5000"></textarea></label>
                        <label><span>Activity</span><select name="activity_level"><option value="not_set">Not set</option><option value="mostly_sedentary">Mostly sedentary</option><option value="lightly_active">Lightly active</option><option value="moderately_active">Moderately active</option><option value="very_active">Very active</option><option value="physically_demanding">Physically demanding</option></select></label>
                        <div class="operations-form__pair"><label><span>Height</span><input type="number" step="0.01" min="0" name="height_value"><select name="height_unit"><option value="in">in</option><option value="cm">cm</option></select></label><label><span>Weight</span><input type="number" step="0.01" min="0" name="weight_value"><select name="weight_unit"><option value="lb">lb</option><option value="kg">kg</option></select></label></div>
                        <label><span>Wellness visibility</span><select name="wellness_visibility"><option value="private">Private</option><option value="authorized_adults">Authorized adults</option><option value="household_planning">Planning without measurements</option></select></label>
                        <p class="operations-note">Measurements are optional and are not used for medical, diagnostic, calorie, or weight-loss guidance.</p>
                        <button class="operations-button operations-button--primary" type="submit">Add family member</button>
                    </form>
                </aside>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <section class="operations-card operations-card--ledger">
            <div class="pantry-card-heading"><div><p class="operations-kicker">Immutable history</p><h2>Food lifecycle ledger</h2></div><strong>Latest <?= count($ledger) ?> events</strong></div>
            <div class="pantry-table-wrap" tabindex="0">
                <table class="pantry-table pantry-table--ledger"><thead><tr><th scope="col">Occurred</th><th scope="col">Item</th><th scope="col">Event</th><th scope="col">Quantity</th><th scope="col">Member</th><th scope="col">Notes</th></tr></thead><tbody>
                <?php if ($ledger === []): ?><tr><td colspan="6" class="pantry-empty">No food-ledger activity yet.</td></tr><?php endif; ?>
                <?php foreach ($ledger as $row): ?><tr><td><?= e((string)$row['occurred_at']) ?></td><td><strong><?= e((string)($row['item_name'] ?? 'Deleted item')) ?></strong></td><td><span class="pantry-status is-muted"><?= e(str_replace('_', ' ', (string)$row['event_type'])) ?></span></td><td><?= e((string)$row['quantity']) ?> <?= e((string)$row['unit']) ?></td><td><?= e((string)($row['member_name'] ?? 'System')) ?></td><td><?= e((string)($row['notes'] ?? '')) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
