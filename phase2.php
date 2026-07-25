<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\positive_decimal;
use function Homestead\redirect;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$householdId = (int)$user['household_id'];
$memberId = (int)$user['member_id'];
$section = (string)($_GET['section'] ?? 'family');
$allowedSections = ['family', 'storage', 'inventory', 'ledger'];
if (!in_array($section, $allowedSections, true)) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_member') {
            $auth->requirePermission($user, 'members.manage');
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $ageGroup = (string)($_POST['age_group'] ?? 'adult');
            $role = (string)($_POST['role'] ?? 'adult_member');
            $activityLevel = (string)($_POST['activity_level'] ?? 'not_set');
            $wellnessVisibility = (string)($_POST['wellness_visibility'] ?? 'private');
            if ($displayName === '' || mb_strlen($displayName) > 120) {
                throw new InvalidArgumentException('Enter a family-member name no longer than 120 characters.');
            }
            if (!in_array($ageGroup, ['adult', 'teen', 'child', 'guest'], true)
                || !in_array($role, ['administrator', 'adult_member', 'youth_member', 'guest_helper'], true)
                || !in_array($activityLevel, ['not_set', 'mostly_sedentary', 'lightly_active', 'moderately_active', 'very_active', 'physically_demanding'], true)
                || !in_array($wellnessVisibility, ['private', 'authorized_adults', 'household_planning'], true)) {
                throw new InvalidArgumentException('One or more member options are invalid.');
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

            $statement = $pdo->prepare(
                'INSERT INTO household_members
                 (household_id, display_name, age_group, role, serving_multiplier, dietary_pattern, allergen_notes,
                  height_value, height_unit, weight_value, weight_unit, activity_level, wellness_visibility,
                  wellness_updated_at, joined_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), CURDATE())'
            );
            $statement->execute([
                $householdId,
                $displayName,
                $ageGroup,
                $role,
                $servingMultiplier,
                trim((string)($_POST['dietary_pattern'] ?? '')) ?: null,
                trim((string)($_POST['allergen_notes'] ?? '')) ?: null,
                $height,
                $heightUnit,
                $weight,
                $weightUnit,
                $activityLevel,
                $wellnessVisibility,
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
                "UPDATE household_members
                 SET status = IF(status = 'active', 'inactive', 'active')
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
            $name = trim((string)($_POST['name'] ?? ''));
            $locationType = trim((string)($_POST['location_type'] ?? 'shelf'));
            if ($name === '' || mb_strlen($name) > 160 || $locationType === '' || mb_strlen($locationType) > 80) {
                throw new InvalidArgumentException('Enter a valid storage name and type.');
            }
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
                $householdId,
                $parentId,
                $name,
                $locationType,
                ($_POST['capacity_value'] ?? '') === '' ? null : positive_decimal($_POST['capacity_value'], 'Capacity'),
                trim((string)($_POST['capacity_unit'] ?? 'items')) ?: 'items',
                ($_POST['target_temperature'] ?? '') === '' ? null : (float)$_POST['target_temperature'],
                $humidity,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            flash('success', 'Storage location added.');
            redirect('/phase2.php?section=storage');
        }

        if ($action === 'add_inventory') {
            $auth->requirePermission($user, 'inventory.manage');
            $name = trim((string)($_POST['name'] ?? ''));
            $itemType = (string)($_POST['item_type'] ?? 'ingredient');
            $unit = trim((string)($_POST['unit'] ?? 'each')) ?: 'each';
            if ($name === '' || mb_strlen($name) > 180 || mb_strlen($unit) > 30
                || !in_array($itemType, ['ingredient', 'prepared_food', 'preserved_food', 'seed', 'supply'], true)) {
                throw new InvalidArgumentException('Enter a valid inventory item, type, and unit.');
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
                $householdId,
                $categoryId,
                $locationId,
                $name,
                $itemType,
                $quantity,
                $unit,
                ($_POST['reorder_level'] ?? '') === '' ? null : positive_decimal($_POST['reorder_level'], 'Reorder level'),
                ($_POST['target_stock_level'] ?? '') === '' ? null : positive_decimal($_POST['target_stock_level'], 'Target stock level'),
                $purchaseCost,
                ($_POST['best_use_date'] ?? '') === '' ? null : (string)$_POST['best_use_date'],
                trim((string)($_POST['notes'] ?? '')) ?: null,
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
            if (!is_numeric($_POST['delta'] ?? null)) {
                throw new InvalidArgumentException('Enter a numeric inventory adjustment.');
            }
            $delta = (float)$_POST['delta'];
            if ($itemId < 1 || $delta === 0.0 || abs($delta) > 1000000) {
                throw new InvalidArgumentException('Choose an item and enter a reasonable non-zero adjustment.');
            }
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT current_quantity, unit, storage_location_id
                 FROM inventory_items WHERE id = ? AND household_id = ? AND status = \'active\' FOR UPDATE'
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
            $update = $pdo->prepare('UPDATE inventory_items SET current_quantity = ? WHERE id = ? AND household_id = ?');
            $update->execute([$newQuantity, $itemId, $householdId]);
            $ledger = $pdo->prepare(
                "INSERT INTO food_ledger_events
                 (household_id, inventory_item_id, member_id, event_type, quantity, unit, source_location_id, destination_location_id, notes)
                 VALUES (?, ?, ?, 'adjusted', ?, ?, ?, ?, ?)"
            );
            $ledger->execute([
                $householdId,
                $itemId,
                $memberId,
                $delta,
                $item['unit'],
                $item['storage_location_id'],
                $item['storage_location_id'],
                trim((string)($_POST['notes'] ?? 'Manual quantity adjustment')) ?: 'Manual quantity adjustment',
            ]);
            $pdo->commit();
            flash('success', 'Inventory adjusted and recorded in the ledger.');
            redirect('/phase2.php?section=inventory');
        }

        throw new InvalidArgumentException('Unknown action.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
        redirect('/phase2.php?section=' . urlencode($section));
    }
}

$membersStatement = $pdo->prepare(
    "SELECT id, display_name, age_group, role, serving_multiplier, activity_level, wellness_visibility, status
     FROM household_members
     WHERE household_id = ?
     ORDER BY status = 'active' DESC, FIELD(role, 'owner','administrator','adult_member','youth_member','guest_helper'), display_name"
);
$membersStatement->execute([$householdId]);
$members = $membersStatement->fetchAll();

$locationsStatement = $pdo->prepare(
    "SELECT l.*, p.name AS parent_name, COUNT(i.id) AS item_count
     FROM storage_locations l
     LEFT JOIN storage_locations p ON p.id = l.parent_id AND p.household_id = l.household_id
     LEFT JOIN inventory_items i ON i.storage_location_id = l.id AND i.household_id = l.household_id AND i.status = 'active'
     WHERE l.household_id = ?
     GROUP BY l.id ORDER BY COALESCE(p.name, l.name), l.name"
);
$locationsStatement->execute([$householdId]);
$locations = $locationsStatement->fetchAll();

$categoriesStatement = $pdo->prepare('SELECT id, name FROM inventory_categories WHERE household_id IS NULL OR household_id = ? ORDER BY name');
$categoriesStatement->execute([$householdId]);
$categories = $categoriesStatement->fetchAll();

$inventoryStatement = $pdo->prepare(
    'SELECT i.*, c.name AS category_name, l.name AS location_name
     FROM inventory_items i
     LEFT JOIN inventory_categories c ON c.id = i.category_id
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
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Household Operations · Homestead</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>.phase-wrap{max-width:1500px;margin:auto;padding:28px}.phase-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 22px}.phase-nav a{padding:10px 14px;border:1px solid var(--border);border-radius:999px}.phase-nav a.active{background:var(--gold);color:#181008}.form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.form-grid .full{grid-column:1/-1}.field{display:grid;gap:6px}.field label{font-size:12px;color:var(--muted)}.field input,.field select,.field textarea{width:100%;padding:11px;border-radius:10px;border:1px solid var(--border);background:#15110d;color:var(--cream)}.flash{padding:12px 15px;border-radius:10px;margin-bottom:12px}.flash-success{background:rgba(70,130,80,.2)}.flash-error{background:rgba(180,70,60,.22)}.privacy-note{padding:12px;border-left:3px solid var(--gold);background:rgba(216,178,111,.06);color:var(--muted)}@media(max-width:800px){.form-grid{grid-template-columns:1fr}.phase-wrap{padding:18px}}</style>
</head>
<body>
<main class="phase-wrap">
<header class="page-header"><div><p class="eyebrow">Authenticated household workspace</p><h1>Household Operations</h1><p class="page-description">Family, storage, inventory, and immutable food-ledger workflows.</p></div><div><strong><?= e((string)$user['display_name']) ?></strong><br><a href="/logout.php">Sign out</a></div></header>
<?php foreach ($flashes as $message): ?><div class="flash flash-<?= e((string)$message['type']) ?>"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<nav class="phase-nav">
    <a class="<?= $section === 'family' ? 'active' : '' ?>" href="?section=family">Family Members</a>
    <?php if ($canViewStorage): ?><a class="<?= $section === 'storage' ? 'active' : '' ?>" href="?section=storage">Storage Locations</a><?php endif; ?>
    <?php if ($canViewInventory): ?><a class="<?= $section === 'inventory' ? 'active' : '' ?>" href="?section=inventory">Inventory</a><a class="<?= $section === 'ledger' ? 'active' : '' ?>" href="?section=ledger">Food Ledger</a><?php endif; ?>
</nav>

<?php if ($section === 'family'): ?>
<section class="content-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Household records</p><h2>Family members</h2></div></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>Role</th><th>Age group</th><th>Serving</th><th>Activity</th><th>Visibility</th><th>Status</th></tr></thead><tbody>
<?php foreach ($members as $row): ?><tr><td><strong><?= e((string)$row['display_name']) ?></strong></td><td><?= e(str_replace('_', ' ', (string)$row['role'])) ?></td><td><?= e((string)$row['age_group']) ?></td><td><?= e((string)$row['serving_multiplier']) ?>×</td><td><?= e(str_replace('_', ' ', (string)($row['activity_level'] ?? 'not set'))) ?></td><td><?= e((string)($row['wellness_visibility'] ?? 'private')) ?></td><td><?= e((string)$row['status']) ?><?php if ($canManageMembers && $row['role'] !== 'owner' && (int)$row['id'] !== $memberId): ?><form method="post" style="display:inline;margin-left:8px"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="toggle_member"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="button secondary" type="submit">Toggle</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></article>
<?php if ($canManageMembers): ?><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Add member</p><h2>Profile</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add_member"><div class="field full"><label>Display name</label><input required maxlength="120" name="display_name"></div><div class="field"><label>Age group</label><select name="age_group"><option>adult</option><option>teen</option><option>child</option><option>guest</option></select></div><div class="field"><label>Role</label><select name="role"><option value="adult_member">Adult member</option><option value="administrator">Administrator</option><option value="youth_member">Youth member</option><option value="guest_helper">Guest helper</option></select></div><div class="field"><label>Serving multiplier</label><input type="number" step="0.05" min="0.1" max="5" name="serving_multiplier" value="1.00"></div><div class="field full"><label>Dietary pattern</label><input name="dietary_pattern"></div><div class="field full"><label>Allergen notes</label><textarea name="allergen_notes"></textarea></div><div class="field"><label>Daily activity</label><select name="activity_level"><option value="not_set">Not set</option><option value="mostly_sedentary">Mostly sedentary</option><option value="lightly_active">Lightly active</option><option value="moderately_active">Moderately active</option><option value="very_active">Very active</option><option value="physically_demanding">Physically demanding</option></select></div><div class="field"><label>Height</label><input type="number" step="0.01" min="0" name="height_value"><select name="height_unit"><option value="in">in</option><option value="cm">cm</option></select></div><div class="field"><label>Weight</label><input type="number" step="0.01" min="0" name="weight_value"><select name="weight_unit"><option value="lb">lb</option><option value="kg">kg</option></select></div><div class="field full"><label>Wellness visibility</label><select name="wellness_visibility"><option value="private">Private</option><option value="authorized_adults">Authorized adults</option><option value="household_planning">Planning without measurements</option></select></div><p class="privacy-note full">Measurements are optional and are not used for medical, diagnostic, calorie, or weight-loss guidance.</p><button class="button primary full" type="submit">Add family member</button></form></article><?php endif; ?></section>

<?php elseif ($section === 'storage'): ?>
<section class="content-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Physical organization</p><h2>Storage locations</h2></div></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>Parent</th><th>Type</th><th>Capacity</th><th>Environment</th><th>Items</th></tr></thead><tbody><?php foreach ($locations as $row): ?><tr><td><strong><?= e((string)$row['name']) ?></strong></td><td><?= e((string)($row['parent_name'] ?? '—')) ?></td><td><?= e((string)$row['location_type']) ?></td><td><?= e((string)($row['capacity_value'] ?? '—')) ?> <?= e((string)($row['capacity_unit'] ?? '')) ?></td><td><?= $row['target_temperature'] !== null ? e((string)$row['target_temperature']) . '°' : '—' ?> / <?= $row['target_humidity'] !== null ? e((string)$row['target_humidity']) . '%' : '—' ?></td><td><?= (int)$row['item_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></article><?php if ($canManageStorage): ?><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Add location</p><h2>Storage profile</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add_location"><div class="field full"><label>Name</label><input required maxlength="160" name="name"></div><div class="field full"><label>Parent</label><select name="parent_id"><option value="">Top level</option><?php foreach ($locations as $row): ?><option value="<?= (int)$row['id'] ?>"><?= e((string)$row['name']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Type</label><input maxlength="80" name="location_type" value="shelf"></div><div class="field"><label>Capacity</label><input type="number" step="0.01" min="0" name="capacity_value"></div><div class="field"><label>Unit</label><input maxlength="30" name="capacity_unit" value="items"></div><div class="field"><label>Target temperature</label><input type="number" step="0.1" name="target_temperature"></div><div class="field"><label>Target humidity %</label><input type="number" step="0.1" min="0" max="100" name="target_humidity"></div><div class="field full"><label>Notes</label><textarea name="notes"></textarea></div><button class="button primary full" type="submit">Add location</button></form></article><?php endif; ?></section>

<?php elseif ($section === 'inventory'): ?>
<section class="content-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Current quantities</p><h2>Inventory</h2></div></div><div class="table-wrap"><table><thead><tr><th>Item</th><th>Category</th><th>Location</th><th>Quantity</th><th>Reorder</th><th>Best use</th><th>Adjust</th></tr></thead><tbody><?php foreach ($inventory as $row): ?><tr><td><strong><?= e((string)$row['name']) ?></strong></td><td><?= e((string)($row['category_name'] ?? '—')) ?></td><td><?= e((string)($row['location_name'] ?? 'Unassigned')) ?></td><td><?= e((string)$row['current_quantity']) ?> <?= e((string)$row['unit']) ?></td><td><?= e((string)($row['reorder_level'] ?? '—')) ?></td><td><?= e((string)($row['best_use_date'] ?? '—')) ?></td><td><?php if ($canManageInventory): ?><form method="post" style="display:flex;gap:6px"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="adjust_inventory"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input style="width:80px" type="number" step="0.0001" name="delta" placeholder="+/-"><button class="button secondary" type="submit">Post</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></article><?php if ($canManageInventory): ?><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Add inventory</p><h2>Opening entry</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add_inventory"><div class="field full"><label>Item name</label><input required maxlength="180" name="name"></div><div class="field"><label>Type</label><select name="item_type"><option value="ingredient">Ingredient</option><option value="prepared_food">Prepared food</option><option value="preserved_food">Preserved food</option><option value="seed">Seed</option><option value="supply">Supply</option></select></div><div class="field"><label>Quantity</label><input required type="number" step="0.0001" min="0" name="quantity"></div><div class="field"><label>Unit</label><input maxlength="30" name="unit" value="each"></div><div class="field full"><label>Category</label><select name="category_id"><option value="">Uncategorized</option><?php foreach ($categories as $row): ?><option value="<?= (int)$row['id'] ?>"><?= e((string)$row['name']) ?></option><?php endforeach; ?></select></div><div class="field full"><label>Location</label><select name="storage_location_id"><option value="">Unassigned</option><?php foreach ($locations as $row): ?><option value="<?= (int)$row['id'] ?>"><?= e((string)$row['name']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Reorder level</label><input type="number" step="0.0001" min="0" name="reorder_level"></div><div class="field"><label>Target level</label><input type="number" step="0.0001" min="0" name="target_stock_level"></div><div class="field"><label>Purchase cost</label><input type="number" step="0.01" min="0" name="purchase_cost"></div><div class="field full"><label>Best-use date</label><input type="date" name="best_use_date"></div><div class="field full"><label>Notes</label><textarea name="notes"></textarea></div><button class="button primary full" type="submit">Add item and ledger event</button></form></article><?php endif; ?></section>

<?php else: ?>
<article class="panel"><div class="panel-heading"><div><p class="eyebrow">Immutable event history</p><h2>Food lifecycle ledger</h2></div></div><div class="table-wrap"><table><thead><tr><th>Occurred</th><th>Item</th><th>Event</th><th>Quantity</th><th>Member</th><th>Notes</th></tr></thead><tbody><?php foreach ($ledger as $row): ?><tr><td><?= e((string)$row['occurred_at']) ?></td><td><strong><?= e((string)($row['item_name'] ?? 'Deleted item')) ?></strong></td><td><?= e(str_replace('_', ' ', (string)$row['event_type'])) ?></td><td><?= e((string)$row['quantity']) ?> <?= e((string)$row['unit']) ?></td><td><?= e((string)($row['member_name'] ?? 'System')) ?></td><td><?= e((string)($row['notes'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div></article>
<?php endif; ?>
</main>
</body>
</html>
