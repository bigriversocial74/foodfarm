<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use Homestead\StarterKitService;
use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$auth->requirePlatformAdmin($user);
$service = new StarterKitService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');
        match ($action) {
            'create_kit' => $service->createKit($_POST, (int)$user['id']),
            'create_version' => $service->createVersion((int)($_POST['starter_kit_id'] ?? 0), $_POST),
            'publish_version' => $service->publishVersion((int)($_POST['starter_kit_version_id'] ?? 0)),
            'add_item' => $service->addItem((int)($_POST['starter_kit_version_id'] ?? 0), $_POST),
            'attach_recipe' => $service->attachRecipe(
                (int)($_POST['starter_kit_version_id'] ?? 0),
                (int)($_POST['recipe_id'] ?? 0),
                (int)$user['household_id']
            ),
            'add_task' => $service->addTask((int)($_POST['starter_kit_version_id'] ?? 0), $_POST),
            'create_order' => (function () use ($service): void {
                $result = $service->createOrderAndActivation(
                    (int)($_POST['starter_kit_version_id'] ?? 0),
                    (string)($_POST['customer_email'] ?? ''),
                    trim((string)($_POST['external_order_id'] ?? '')) ?: null
                );
                $_SESSION['starter_kit_activation_url'] = '/activate-kit.php?token=' . $result['token'];
            })(),
            default => throw new InvalidArgumentException('Unknown starter-kit action.'),
        };
        flash('success', 'Starter-kit changes saved.');
    } catch (Throwable $exception) {
        flash('error', user_error_message($exception));
    }
    redirect('/phase5.php');
}

$activationUrl = $_SESSION['starter_kit_activation_url'] ?? null;
unset($_SESSION['starter_kit_activation_url']);
$flashes = consume_flashes();
$kits = $pdo->query(
    'SELECT k.*, COUNT(DISTINCT v.id) AS version_count, COUNT(i.id) AS item_count
     FROM starter_kits k
     LEFT JOIN starter_kit_versions v ON v.starter_kit_id = k.id
     LEFT JOIN starter_kit_items i ON i.starter_kit_version_id = v.id
     GROUP BY k.id ORDER BY k.id DESC'
)->fetchAll();
$versions = $pdo->query(
    'SELECT v.*, k.name AS kit_name, k.kit_type
     FROM starter_kit_versions v JOIN starter_kits k ON k.id = v.starter_kit_id
     ORDER BY v.id DESC'
)->fetchAll();
$draftVersions = array_values(array_filter($versions, static fn(array $version): bool => $version['status'] === 'draft'));
$publishedVersions = array_values(array_filter($versions, static fn(array $version): bool => $version['status'] === 'published'));
$items = $pdo->query(
    'SELECT i.*, v.sku, v.status AS version_status, k.name AS kit_name
     FROM starter_kit_items i
     JOIN starter_kit_versions v ON v.id = i.starter_kit_version_id
     JOIN starter_kits k ON k.id = v.starter_kit_id
     ORDER BY i.id DESC LIMIT 100'
)->fetchAll();
$orders = $pdo->query(
    'SELECT o.*, v.sku, k.name AS kit_name
     FROM starter_kit_orders o
     JOIN starter_kit_versions v ON v.id = o.starter_kit_version_id
     JOIN starter_kits k ON k.id = v.starter_kit_id
     ORDER BY o.id DESC LIMIT 50'
)->fetchAll();
$categories = $pdo->query('SELECT id, name FROM inventory_categories WHERE household_id IS NULL ORDER BY name')->fetchAll();
$recipesStatement = $pdo->prepare("SELECT id, name FROM recipes WHERE household_id = ? AND status = 'active' ORDER BY name LIMIT 250");
$recipesStatement->execute([(int)$user['household_id']]);
$recipes = $recipesStatement->fetchAll();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Starter Kits · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><a class="skip-link" href="#main-content">Skip to starter-kit administration</a><main id="main-content" class="page-container">
<header class="page-header"><div><p class="eyebrow">Platform administration</p><h1>Starter Kits</h1><p class="page-description">Build drafts, attach household-authored recipes, validate contents, publish immutable versions, and create customer activations.</p></div><div><a class="button secondary" href="/phase4.php">Recipes</a> <a class="button secondary" href="/phase3.php">Access</a></div></header>
<?php foreach ($flashes as $message): ?><div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<?php if ($activationUrl): ?><section class="panel" style="margin-bottom:20px"><p class="eyebrow">One-time customer link</p><h2>Activation URL</h2><label>Copy activation URL<input class="search-field" value="<?= e((string)$activationUrl) ?>" readonly onclick="this.select()"></label><p class="page-description">Copy this link now. The raw token is displayed once and is not stored.</p></section><?php endif; ?>
<section class="content-grid">
<article class="panel"><h2>Create draft kit</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_kit"><label>Name<input class="search-field" name="name" maxlength="180" required></label><label>Slug<input class="search-field" name="slug" maxlength="190" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required></label><label>Type<select name="kit_type"><option value="basic">Basic</option><option value="specialized">Specialized</option></select></label><label>Category<input class="search-field" name="category" maxlength="100"></label><label>Description<textarea name="description" maxlength="5000"></textarea></label><button class="button primary" type="submit">Create draft kit</button></form></article>
<article class="panel"><h2>Create draft version</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_version"><label>Kit<select name="starter_kit_id" required><?php foreach($kits as $kit): if($kit['status']==='retired') continue; ?><option value="<?= (int)$kit['id'] ?>"><?= e((string)$kit['name']) ?></option><?php endforeach; ?></select></label><label>Version<input class="search-field" type="number" min="1" max="100000" name="version_number" required></label><label>SKU<input class="search-field" name="sku" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._-]{1,99}" required></label><label>Price<input class="search-field" type="number" min="0" step="0.01" name="price"></label><label>Currency<input class="search-field" name="currency_code" value="USD" maxlength="3" pattern="[A-Za-z]{3}"></label><button class="button primary" type="submit">Create draft version</button></form></article>
<article class="panel"><h2>Publish immutable version</h2><p class="page-description">Publication validates every item and permanently freezes the version.</p><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="publish_version"><label>Draft version<select name="starter_kit_version_id" required><?php foreach($draftVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><button class="button primary" type="submit">Validate and publish</button></form></article>
<article class="panel span-2"><h2>Add item to draft version</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_item"><label>Draft version<select name="starter_kit_version_id" required><?php foreach($draftVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Item name<input class="search-field" name="item_name" maxlength="180" required></label><label>Kind<select name="item_kind"><option>ingredient</option><option>equipment</option><option>supply</option><option>seed</option><option>digital</option></select></label><label>Fulfillment<select name="fulfillment_type"><option value="shopping_list">Shopping list</option><option value="shipped">Shipped</option><option value="optional_delivery">Optional delivery</option><option value="digital_only">Digital only</option><option value="customer_supplied">Customer supplied</option></select></label><label>Quantity<input class="search-field" type="number" step="0.0001" min="0" name="default_quantity"></label><label>Unit<input class="search-field" name="unit" maxlength="30"></label><label>Inventory category<select name="inventory_category_id"><option value="">None</option><?php foreach($categories as $category): ?><option value="<?= (int)$category['id'] ?>"><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label><label>Suggested storage<input class="search-field" name="suggested_storage_type" maxlength="80"></label><label>Reorder level<input class="search-field" type="number" step="0.0001" min="0" name="reorder_level"></label><label>Estimated price<input class="search-field" type="number" step="0.01" min="0" name="estimated_price"></label><label>Supplier<input class="search-field" name="supplier_name" maxlength="180"></label><label><input type="checkbox" name="required" value="1" checked> Required</label><label><input type="checkbox" name="shipping_eligible" value="1"> Shipping eligible</label><label><input type="checkbox" name="delivery_eligible" value="1"> Delivery eligible</label><button class="button primary" type="submit">Add item</button></form></article>
<article class="panel"><h2>Attach starter recipe</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="attach_recipe"><label>Draft version<select name="starter_kit_version_id" required><?php foreach($draftVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Household recipe<select name="recipe_id" required><?php foreach($recipes as $recipe): ?><option value="<?= (int)$recipe['id'] ?>"><?= e((string)$recipe['name']) ?></option><?php endforeach; ?></select></label><button class="button primary" type="submit">Attach recipe</button></form></article>
<article class="panel"><h2>Add starter task</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_task"><label>Draft version<select name="starter_kit_version_id" required><?php foreach($draftVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Task title<input class="search-field" name="title" maxlength="180" required></label><label>Area<input class="search-field" name="area" maxlength="80"></label><label>Due after days<input class="search-field" type="number" min="-365" max="3650" name="due_offset_days" value="0"></label><label>Recurrence rule<input class="search-field" name="recurring_rule" maxlength="190"></label><label>Instructions<textarea name="instructions" maxlength="5000"></textarea></label><button class="button primary" type="submit">Add task</button></form></article>
<article class="panel"><h2>Create order activation</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_order"><label>Published version<select name="starter_kit_version_id" required><?php foreach($publishedVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Customer email<input class="search-field" type="email" maxlength="190" name="customer_email" required></label><label>External order ID<input class="search-field" maxlength="190" name="external_order_id"></label><button class="button primary" type="submit">Create activation</button></form></article>
</section>
<section class="panel"><h2>Kit definitions</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Kit</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Versions</th><th scope="col">Items</th></tr></thead><tbody><?php foreach($kits as $kit): ?><tr><td><strong><?= e((string)$kit['name']) ?></strong><br><small><?= e((string)$kit['slug']) ?></small></td><td><?= e((string)$kit['kit_type']) ?></td><td><?= e((string)$kit['status']) ?></td><td><?= (int)$kit['version_count'] ?></td><td><?= (int)$kit['item_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel" style="margin-top:20px"><h2>Fulfillment map</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Kit</th><th scope="col">Item</th><th scope="col">Version</th><th scope="col">Fulfillment</th><th scope="col">Quantity</th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><?= e((string)$item['kit_name']) ?></td><td><?= e((string)$item['item_name']) ?></td><td><?= e((string)$item['sku']) ?> · <?= e((string)$item['version_status']) ?></td><td><?= e(str_replace('_',' ',(string)$item['fulfillment_type'])) ?></td><td><?= e((string)$item['default_quantity']) ?> <?= e((string)$item['unit']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel" style="margin-top:20px"><h2>Starter kit ownership</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Kit</th><th scope="col">Fulfillment</th><th scope="col">Activation</th></tr></thead><tbody><?php foreach($orders as $order): ?><tr><td><?= e((string)($order['external_order_id'] ?: '#'.$order['id'])) ?></td><td><?= e((string)$order['customer_email']) ?></td><td><?= e((string)$order['kit_name']) ?><br><small><?= e((string)$order['sku']) ?></small></td><td><?= e((string)$order['fulfillment_status']) ?></td><td><?= e((string)$order['activation_status']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main></body></html>
