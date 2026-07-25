<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use Homestead\StarterKitService;
use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
if (!in_array((string)$user['role'], ['owner', 'administrator'], true)) {
    http_response_code(403);
    exit('Starter kits are available only to household administrators.');
}

$service = new StarterKitService($pdo);
$activationUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_kit') {
            $service->createKit($_POST, (int)$user['id']);
            flash('success', 'Starter kit created.');
        } elseif ($action === 'create_version') {
            $service->createVersion((int)($_POST['starter_kit_id'] ?? 0), $_POST);
            flash('success', 'Kit version created.');
        } elseif ($action === 'add_item') {
            $service->addItem((int)($_POST['starter_kit_version_id'] ?? 0), $_POST);
            flash('success', 'Kit item added.');
        } elseif ($action === 'create_order') {
            $result = $service->createOrderAndActivation(
                (int)($_POST['starter_kit_version_id'] ?? 0),
                (string)($_POST['customer_email'] ?? ''),
                trim((string)($_POST['external_order_id'] ?? '')) ?: null
            );
            $_SESSION['starter_kit_activation_url'] = '/activate-kit.php?token=' . $result['token'];
            flash('success', 'Order and one-time activation URL created.');
        }
        redirect('/phase5.php');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect('/phase5.php');
    }
}

$activationUrl = $_SESSION['starter_kit_activation_url'] ?? null;
unset($_SESSION['starter_kit_activation_url']);
$flashes = consume_flashes();
$kits = $pdo->query("SELECT k.*, COUNT(DISTINCT v.id) AS version_count, COUNT(i.id) AS item_count FROM starter_kits k LEFT JOIN starter_kit_versions v ON v.starter_kit_id=k.id LEFT JOIN starter_kit_items i ON i.starter_kit_version_id=v.id GROUP BY k.id ORDER BY k.id DESC")->fetchAll();
$versions = $pdo->query("SELECT v.*, k.name AS kit_name, k.kit_type FROM starter_kit_versions v JOIN starter_kits k ON k.id=v.starter_kit_id ORDER BY v.id DESC")->fetchAll();
$items = $pdo->query("SELECT i.*, v.sku, k.name AS kit_name FROM starter_kit_items i JOIN starter_kit_versions v ON v.id=i.starter_kit_version_id JOIN starter_kits k ON k.id=v.starter_kit_id ORDER BY i.id DESC LIMIT 100")->fetchAll();
$orders = $pdo->query("SELECT o.*, v.sku, k.name AS kit_name FROM starter_kit_orders o JOIN starter_kit_versions v ON v.id=o.starter_kit_version_id JOIN starter_kits k ON k.id=v.starter_kit_id ORDER BY o.id DESC LIMIT 50")->fetchAll();
$categories = $pdo->query("SELECT id,name FROM inventory_categories ORDER BY name")->fetchAll();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Starter Kits · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><main class="page-container">
<header class="page-header"><div><p class="eyebrow">Phase 5 administration</p><h1>Starter Kits</h1><p class="page-description">Build basic and specialized kits from shipped goods, local shopping items, delivery options, customer-supplied items, and digital setup content.</p></div><div><a class="button secondary" href="/phase4.php">Recipes</a> <a class="button secondary" href="/phase3.php">Access</a></div></header>
<?php foreach ($flashes as $message): ?><div class="status status-<?= $message['type']==='error'?'warning':'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<?php if ($activationUrl): ?><section class="panel" style="margin-bottom:20px"><p class="eyebrow">One-time customer link</p><h2>Activation URL</h2><input class="search-field" value="<?= e((string)$activationUrl) ?>" readonly style="width:100%"><p class="page-description">Copy this link now. The raw token is not stored.</p></section><?php endif; ?>

<section class="content-grid">
<article class="panel"><div class="panel-heading"><div><p class="eyebrow">Definition</p><h2>Create kit</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_kit"><label>Name<input class="search-field" name="name" required></label><label>Slug<input class="search-field" name="slug" required></label><label>Type<select name="kit_type"><option value="basic">Basic</option><option value="specialized">Specialized</option></select></label><label>Category<input class="search-field" name="category"></label><label>Status<select name="status"><option value="draft">Draft</option><option value="published">Published</option></select></label><label>Description<textarea name="description"></textarea></label><button class="button primary">Create kit</button></form></article>

<article class="panel"><div class="panel-heading"><div><p class="eyebrow">Immutable history</p><h2>Create version</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_version"><label>Kit<select name="starter_kit_id" required><?php foreach($kits as $kit): ?><option value="<?= (int)$kit['id'] ?>"><?= e((string)$kit['name']) ?></option><?php endforeach; ?></select></label><label>Version<input class="search-field" type="number" min="1" name="version_number" value="1" required></label><label>SKU<input class="search-field" name="sku" required></label><label>Price<input class="search-field" type="number" min="0" step="0.01" name="price"></label><label>Status<select name="status"><option value="draft">Draft</option><option value="published">Published</option></select></label><button class="button primary">Create version</button></form></article>

<article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Physical and digital contents</p><h2>Add kit item</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_item"><label>Version<select name="starter_kit_version_id" required><?php foreach($versions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Item name<input class="search-field" name="item_name" required></label><label>Kind<select name="item_kind"><option>ingredient</option><option>equipment</option><option>supply</option><option>seed</option><option>digital</option></select></label><label>Fulfillment<select name="fulfillment_type"><option value="shipped">Shipped</option><option value="shopping_list">Shopping list</option><option value="optional_delivery">Optional delivery</option><option value="digital_only">Digital only</option><option value="customer_supplied">Customer supplied</option></select></label><label>Quantity<input class="search-field" type="number" step="0.0001" min="0" name="default_quantity"></label><label>Unit<input class="search-field" name="unit"></label><label>Inventory category<select name="inventory_category_id"><option value="">None</option><?php foreach($categories as $category): ?><option value="<?= (int)$category['id'] ?>"><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label><label>Suggested storage type<input class="search-field" name="suggested_storage_type" placeholder="pantry, refrigerator, freezer"></label><label>Reorder level<input class="search-field" type="number" step="0.0001" min="0" name="reorder_level"></label><label>Estimated price<input class="search-field" type="number" step="0.01" min="0" name="estimated_price"></label><label>Supplier<input class="search-field" name="supplier_name"></label><label><input type="checkbox" name="required" value="1" checked> Required</label><label><input type="checkbox" name="shipping_eligible" value="1"> Shipping eligible</label><label><input type="checkbox" name="delivery_eligible" value="1"> Delivery eligible</label><button class="button primary">Add item</button></form></article>

<article class="panel"><div class="panel-heading"><div><p class="eyebrow">Customer purchase</p><h2>Create order activation</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_order"><label>Published version<select name="starter_kit_version_id" required><?php foreach($versions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Customer email<input class="search-field" type="email" name="customer_email" required></label><label>External order ID<input class="search-field" name="external_order_id"></label><button class="button primary">Create activation</button></form></article>
</section>

<section class="panel" style="margin-top:20px"><div class="panel-heading"><div><p class="eyebrow">Catalog</p><h2>Kit definitions</h2></div></div><div class="table-wrap"><table><thead><tr><th>Kit</th><th>Type</th><th>Status</th><th>Versions</th><th>Items</th></tr></thead><tbody><?php foreach($kits as $kit): ?><tr><td><strong><?= e((string)$kit['name']) ?></strong><br><small><?= e((string)$kit['slug']) ?></small></td><td><?= e((string)$kit['kit_type']) ?></td><td><?= e((string)$kit['status']) ?></td><td><?= (int)$kit['version_count'] ?></td><td><?= (int)$kit['item_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel" style="margin-top:20px"><div class="panel-heading"><div><p class="eyebrow">Fulfillment map</p><h2>Kit items</h2></div></div><div class="table-wrap"><table><thead><tr><th>Kit</th><th>Item</th><th>Fulfillment</th><th>Quantity</th><th>Delivery</th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><?= e((string)$item['kit_name']) ?><br><small><?= e((string)$item['sku']) ?></small></td><td><?= e((string)$item['item_name']) ?></td><td><?= e(str_replace('_',' ',(string)$item['fulfillment_type'])) ?></td><td><?= e((string)$item['default_quantity']) ?> <?= e((string)$item['unit']) ?></td><td><?= $item['delivery_eligible']?'Eligible':'—' ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel" style="margin-top:20px"><div class="panel-heading"><div><p class="eyebrow">Orders</p><h2>Starter kit ownership</h2></div></div><div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Kit</th><th>Fulfillment</th><th>Activation</th></tr></thead><tbody><?php foreach($orders as $order): ?><tr><td><?= e((string)($order['external_order_id'] ?: '#'.$order['id'])) ?></td><td><?= e((string)$order['customer_email']) ?></td><td><?= e((string)$order['kit_name']) ?><br><small><?= e((string)$order['sku']) ?></small></td><td><?= e((string)$order['fulfillment_status']) ?></td><td><?= e((string)$order['activation_status']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main></body></html>
