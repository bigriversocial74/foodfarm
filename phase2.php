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
$flashes = consume_flashes();
$token = csrf_token();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Household Operations · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><a class="skip-link" href="#main-content">Skip to household operations</a><main id="main-content" class="page-container"><header class="page-header"><div><p class="eyebrow">Authenticated household workspace</p><h1>Household Operations</h1><p class="page-description">Family, storage, inventory, and immutable food-ledger workflows.</p></div><div><strong><?= e((string)$user['display_name']) ?></strong><br><a href="/logout.php">Sign out</a></div></header>
<?php foreach ($flashes as $message): ?><div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<nav class="toolbar" aria-label="Household sections"><a class="button secondary" href="?section=family">Family</a><?php if ($canViewStorage): ?><a class="button secondary" href="?section=storage">Storage</a><?php endif; ?><?php if ($canViewInventory): ?><a class="button secondary" href="?section=inventory">Inventory</a><a class="button secondary" href="?section=ledger">Food ledger</a><?php endif; ?></nav>
<?php if ($section === 'family'): ?><section class="content-grid"><article class="panel span-2"><h2>Family members</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Age group</th><th scope="col">Serving</th><th scope="col">Activity</th><th scope="col">Visibility</th><th scope="col">Status</th></tr></thead><tbody><?php foreach ($members as $row): ?><tr><td><strong><?= e((string)$row['display_name']) ?></strong></td><td><?= e(str_replace('_', ' ', (string)$row['role'])) ?></td><td><?= e((string)$row['age_group']) ?></td><td><?= e((string)$row['serving_multiplier']) ?>×</td><td><?= e(str_replace('_', ' ', (string)($row['activity_level'] ?? 'not set'))) ?></td><td><?= e((string)($row['wellness_visibility'] ?? 'private')) ?></td><td><?= e((string)$row['status']) ?><?php if ($canManageMembers && $row['role'] !== 'owner' && (int)$row['id'] !== $memberId): ?><form method="post" style="display:inline;margin-left:8px"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="toggle_member"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="button secondary" type="submit">Toggle</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></article><?php if ($canManageMembers): ?><article class="panel"><h2>Add family profile</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add_member"><label>Name<input class="search-field" name="display_name" maxlength="120" required></label><label>Age group<select name="age_group"><option>adult</option><option>teen</option><option>child</option><option>guest</option></select></label><label>Role<select name="role"><option value="adult_member">Adult member</option><option value="administrator">Administrator</option><option value="youth_member">Youth member</option><option value="guest_helper">Guest helper</option></select></label><label>Serving multiplier<input class="search-field" type="number" step="0.05" min="0.1" max="5" name="serving_multiplier" value="1"></label><label>Dietary pattern<input class="search-field" name="dietary_pattern" maxlength="120"></label><label>Allergen notes<textarea name="allergen_notes" maxlength="5000"></textarea></label><label>Activity<select name="activity_level"><option value="not_set">Not set</option><option value="mostly_sedentary">Mostly sedentary</option><option value="lightly_active">Lightly active</option><option value="moderately_active">Moderately active</option><option value="very_active">Very active</option><option value="physically_demanding">Physically demanding</option></select></label><label>Height<input class="search-field" type="number" step="0.01" min="0" name="height_value"><select name="height_unit"><option value="in">in</option><option value="cm">cm</option></select></label><label>Weight<input class="search-field" type="number" step="0.01" min="0" name="weight_value"><select name="weight_unit"><option value="lb">lb</option><option value="kg">kg</option></select></label><label>Wellness visibility<select name="wellness_visibility"><option value="private">Private</option><option value="authorized_adults">Authorized adults</option><option value="household_planning">Planning without measurements</option></select></label><p class="page-description">Measurements are optional and are not used for medical, diagnostic, calorie, or weight-loss guidance.</p><button class="button primary" type="submit">Add family member</button></form></article><?php endif; ?></section>
<?php elseif ($section === 'storage'): ?><section class="content-grid"><article class="panel span-2"><h2>Storage locations</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Name</th><th scope="col">Parent</th><th scope="col">Type</th><th scope="col">Capacity</th><th scope="col">Environment</th><th scope="col">Items</th></tr></thead><tbody><?php foreach ($locations as $row): ?><tr><td><strong><?= e((string)$row['name']) ?></strong></td><td><?= e((string)($row['parent_name'] ?? '—')) ?></td><td><?= e((string)$row['location_type']) ?></td><td><?= e((string)($row['capacity_value'] ?? '—')) ?> <?= e((string)($row['capacity_unit'] ?? '')) ?></td><td><?= $row['target_temperature'] !== null ? e((string)$row['target_temperature']) . '°' : '—' ?> / <?= $row['target_humidity'] !== null ? e((string)$row['target_humidity']) . '%' : '—' ?></td><td><?= (int)$row['item_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></article><?php if ($canManageStorage): ?><article class="panel"><h2>Add storage location</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add_location"><label>Name<input class="search-field" name="name" maxlength="140" required></label><label>Parent<select name="parent_id"><option value="">Top level</option><?php foreach ($locations as $row): ?><option value="<?= (int)$row['id'] ?>"><?= e((string)$row['name']) ?></option><?php endforeach; ?></select></label><label>Type<input class="search-field" name="location_type" maxlength="80" value="shelf" required></label><label>Capacity<input class="search-field" type="number" step="0.01" min="0" name="capacity_value"></label><label>Unit<input class="search-field" name="capacity_unit" maxlength="30" value="items"></label><label>Temperature<input class="search-field" type="number" step="0.1" min="-100" max="250" name="target_temperature"></label><label>Humidity %<input class="search-field" type="number" step="0.1" min="0" max="100" name="target_humidity"></label><label>Notes<textarea name="notes" maxlength="5000"></textarea></label><button class="button primary" type="submit">Add location</button></form></article><?php endif; ?></section>
<?php elseif ($section === 'inventory'): ?><section class="content-grid"><article class="panel span-2"><h2>Inventory</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Item</th><th scope="col">Category</th><th scope="col">Location</th><th scope="col">Quantity</th><th scope="col">Reorder</th><th scope="col">Best use</th><th scope="col">Adjust</th></tr></thead><tbody><?php foreach ($inventory as $row): ?><tr><td><strong><?= e((string)$row['name']) ?></strong></td><td><?= e((string)($row['category_name'] ?? '—')) ?></td><td><?= e((string)($row['location_name'] ?? 'Unassigned')) ?></td><td><?= e((string)$row['current_quantity']) ?> <?= e((string)$row['unit']) ?></td><td><?= e((string)($row['reorder_level'] ?? '—')) ?></td><td><?= e((string)($row['best_use_date'] ?? '—')) ?></td><td><?php if ($canManageInventory): ?><form method="post" style="display:flex;gap:6px"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="adjust_inventory"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><label class="visually-hidden" for="delta-<?= (int)$row['id'] ?>">Adjustment for <?= e((string)$row['name']) ?></label><input id="delta-<?= (int)$row['id'] ?>" class="search-field" style="width:90px" type="number" step="0.0001" name="delta" required><button class="button secondary" type="submit">Post</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></article><?php if ($canManageInventory): ?><article class="panel"><h2>Add inventory</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add_inventory"><label>Name<input class="search-field" name="name" maxlength="180" required></label><label>Type<select name="item_type"><option value="ingredient">Ingredient</option><option value="prepared_food">Prepared food</option><option value="preserved_food">Preserved food</option><option value="seed">Seed</option><option value="supply">Supply</option></select></label><label>Quantity<input class="search-field" type="number" step="0.0001" min="0" name="quantity" required></label><label>Unit<input class="search-field" name="unit" maxlength="30" value="each" required></label><label>Category<select name="category_id"><option value="">Uncategorized</option><?php foreach ($categories as $row): ?><option value="<?= (int)$row['id'] ?>"><?= e((string)$row['name']) ?></option><?php endforeach; ?></select></label><label>Location<select name="storage_location_id"><option value="">Unassigned</option><?php foreach ($locations as $row): ?><option value="<?= (int)$row['id'] ?>"><?= e((string)$row['name']) ?></option><?php endforeach; ?></select></label><label>Reorder level<input class="search-field" type="number" step="0.0001" min="0" name="reorder_level"></label><label>Target level<input class="search-field" type="number" step="0.0001" min="0" name="target_stock_level"></label><label>Purchase cost<input class="search-field" type="number" step="0.01" min="0" name="purchase_cost"></label><label>Best-use date<input class="search-field" type="date" name="best_use_date"></label><label>Notes<textarea name="notes" maxlength="5000"></textarea></label><button class="button primary" type="submit">Add item and ledger event</button></form></article><?php endif; ?></section>
<?php else: ?><article class="panel"><h2>Food lifecycle ledger</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Occurred</th><th scope="col">Item</th><th scope="col">Event</th><th scope="col">Quantity</th><th scope="col">Member</th><th scope="col">Notes</th></tr></thead><tbody><?php foreach ($ledger as $row): ?><tr><td><?= e((string)$row['occurred_at']) ?></td><td><strong><?= e((string)($row['item_name'] ?? 'Deleted item')) ?></strong></td><td><?= e(str_replace('_', ' ', (string)$row['event_type'])) ?></td><td><?= e((string)$row['quantity']) ?> <?= e((string)$row['unit']) ?></td><td><?= e((string)($row['member_name'] ?? 'System')) ?></td><td><?= e((string)($row['notes'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div></article><?php endif; ?>
</main></body></html>
