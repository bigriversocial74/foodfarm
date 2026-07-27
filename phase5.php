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

$activeKitCount = count(array_filter($kits, static fn(array $kit): bool => $kit['status'] !== 'retired'));
$retiredKitCount = count($kits) - $activeKitCount;
$draftCount = count($draftVersions);
$publishedCount = count($publishedVersions);
$totalCatalogItems = array_sum(array_map(static fn(array $kit): int => (int)$kit['item_count'], $kits));
$pendingActivationCount = count(array_filter($orders, static fn(array $order): bool => !in_array((string)$order['activation_status'], ['activated', 'completed'], true)));
$fulfillmentMix = [];
foreach ($items as $item) {
    $key = (string)$item['fulfillment_type'];
    $fulfillmentMix[$key] = ($fulfillmentMix[$key] ?? 0) + 1;
}
arsort($fulfillmentMix);
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Starter Kits · Homestead</title></head>
<body>
<a class="skip-link" href="#main-content">Skip to starter-kit administration</a>
<main id="main-content" class="page-container kits-page">
    <section class="kits-hero" aria-labelledby="kits-title">
        <div class="kits-hero__copy">
            <p class="kits-kicker">Platform product operations</p>
            <h1 id="kits-title">Build the kit.<br><span>Protect the promise.</span></h1>
            <p>Create customer-ready starter systems with immutable versions, validated contents, household-authored recipes, onboarding tasks, and traceable activations.</p>
            <div class="kits-hero__links"><a href="starter-kit-lifecycle.php">Version lifecycle</a><a href="phase4.php">Recipe library</a><a href="phase3.php">Family access</a></div>
        </div>
        <aside class="kits-readiness" aria-label="Catalog readiness">
            <p class="kits-kicker">Catalog readiness</p>
            <strong><?= $publishedCount ?></strong><span>published versions ready for activation</span>
            <div class="kits-readiness__bar"><span style="width:<?= min(100, $publishedCount > 0 ? round(($publishedCount / max(1, count($versions))) * 100) : 0) ?>%"></span></div>
            <small><?= $draftCount ?> drafts remain editable · <?= $totalCatalogItems ?> catalog items</small>
        </aside>
    </section>

    <?php foreach ($flashes as $message): ?><div role="status" class="kits-flash kits-flash--<?= $message['type'] === 'error' ? 'warning' : 'good' ?>"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
    <?php if ($activationUrl): ?><section class="kits-activation-link" aria-labelledby="activation-link-title"><div><p class="kits-kicker">One-time customer link</p><h2 id="activation-link-title">Activation URL created</h2><p>Copy this link now. The raw token is displayed once and is not stored.</p></div><div class="kits-copy-field"><input id="kit-activation-url" value="<?= e((string)$activationUrl) ?>" readonly><button type="button" data-copy-target="kit-activation-url">Copy link</button></div></section><?php endif; ?>

    <section class="kits-metrics" aria-label="Starter-kit metrics">
        <article><span>◇</span><div><small>Kit families</small><strong><?= count($kits) ?></strong><p><?= $activeKitCount ?> active · <?= $retiredKitCount ?> retired</p></div></article>
        <article><span>◫</span><div><small>Draft versions</small><strong><?= $draftCount ?></strong><p>editable build pipeline</p></div></article>
        <article class="kits-metric--green"><span>◆</span><div><small>Published</small><strong><?= $publishedCount ?></strong><p>immutable releases</p></div></article>
        <article><span>▦</span><div><small>Catalog items</small><strong><?= $totalCatalogItems ?></strong><p>across all kit families</p></div></article>
        <article class="kits-metric--blue"><span>◎</span><div><small>Orders</small><strong><?= count($orders) ?></strong><p>latest ownership records</p></div></article>
        <article class="kits-metric--gold"><span>↗</span><div><small>Pending activation</small><strong><?= $pendingActivationCount ?></strong><p>customer follow-through</p></div></article>
    </section>

    <section class="kits-layout">
        <div class="kits-main">
            <section class="kits-panel" aria-labelledby="portfolio-heading">
                <header class="kits-panel__heading kits-panel__heading--toolbar"><div><p class="kits-kicker">Product portfolio</p><h2 id="portfolio-heading">Kit definitions</h2></div><label class="kits-search"><span>⌕</span><input type="search" data-kit-search placeholder="Search kits, slugs, types"></label></header>
                <div class="kits-tabs" role="tablist" aria-label="Kit filters"><button type="button" class="active" data-kit-filter="all">All <span><?= count($kits) ?></span></button><button type="button" data-kit-filter="draft">Draft</button><button type="button" data-kit-filter="published">Published</button><button type="button" data-kit-filter="retired">Retired</button></div>
                <div class="kits-card-grid" data-kit-list>
                    <?php foreach ($kits as $kit): ?>
                        <article class="kit-card" data-kit-card data-status="<?= e((string)$kit['status']) ?>" data-search="<?= e(strtolower((string)$kit['name'].' '.(string)$kit['slug'].' '.(string)$kit['kit_type'].' '.(string)$kit['category'])) ?>">
                            <div class="kit-card__top"><span class="kit-card__icon"><?= $kit['kit_type'] === 'specialized' ? '✦' : '◇' ?></span><span class="kit-status kit-status--<?= e((string)$kit['status']) ?>"><?= e((string)$kit['status']) ?></span></div>
                            <h3><?= e((string)$kit['name']) ?></h3><p><?= e((string)($kit['description'] ?: 'No description supplied.')) ?></p>
                            <div class="kit-card__facts"><span><small>Slug</small><strong><?= e((string)$kit['slug']) ?></strong></span><span><small>Type</small><strong><?= e(ucfirst((string)$kit['kit_type'])) ?></strong></span><span><small>Versions</small><strong><?= (int)$kit['version_count'] ?></strong></span><span><small>Items</small><strong><?= (int)$kit['item_count'] ?></strong></span></div>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($kits === []): ?><div class="kits-empty"><strong>No starter kits yet</strong><p>Create the first draft kit from the operations panel.</p></div><?php endif; ?>
                </div>
            </section>

            <section class="kits-panel" aria-labelledby="pipeline-heading">
                <header class="kits-panel__heading"><div><p class="kits-kicker">Immutable release control</p><h2 id="pipeline-heading">Version pipeline</h2></div><a href="starter-kit-lifecycle.php">Open lifecycle</a></header>
                <div class="kits-version-list">
                    <?php foreach (array_slice($versions, 0, 24) as $version): ?>
                        <article class="kits-version"><span class="kits-version__mark kits-version__mark--<?= e((string)$version['status']) ?>"><?= $version['status'] === 'published' ? '◆' : '◇' ?></span><div><strong><?= e((string)$version['kit_name']) ?></strong><p><?= e((string)$version['sku']) ?> · Version <?= (int)$version['version_number'] ?> · <?= e(ucfirst((string)$version['kit_type'])) ?></p></div><span class="kit-status kit-status--<?= e((string)$version['status']) ?>"><?= e((string)$version['status']) ?></span><div class="kits-version__price"><strong><?= $version['price'] !== null ? e((string)$version['currency_code']).' '.number_format((float)$version['price'], 2) : 'Unpriced' ?></strong><small><?= $version['published_at'] ? 'Published '.e(date('M j, Y', strtotime((string)$version['published_at']))) : 'Still editable' ?></small></div></article>
                    <?php endforeach; ?>
                    <?php if ($versions === []): ?><div class="kits-empty"><strong>No versions created</strong><p>Create a version after defining a kit family.</p></div><?php endif; ?>
                </div>
            </section>

            <section class="kits-panel" aria-labelledby="fulfillment-heading">
                <header class="kits-panel__heading"><div><p class="kits-kicker">Customer delivery map</p><h2 id="fulfillment-heading">Fulfillment contents</h2></div><span><?= count($items) ?> recent items</span></header>
                <div class="kits-item-list">
                    <?php foreach (array_slice($items, 0, 40) as $item): ?><article class="kits-item"><span class="kits-item__kind"><?= e(substr(ucfirst((string)$item['item_kind']),0,1)) ?></span><div><strong><?= e((string)$item['item_name']) ?></strong><p><?= e((string)$item['kit_name']) ?> · <?= e((string)$item['sku']) ?></p></div><span><?= e(str_replace('_',' ',(string)$item['fulfillment_type'])) ?></span><strong><?= e((string)$item['default_quantity']) ?> <?= e((string)$item['unit']) ?></strong></article><?php endforeach; ?>
                    <?php if ($items === []): ?><div class="kits-empty"><strong>No fulfillment items</strong><p>Add contents to a draft version.</p></div><?php endif; ?>
                </div>
            </section>

            <section class="kits-panel" aria-labelledby="ownership-heading">
                <header class="kits-panel__heading"><div><p class="kits-kicker">Customer ownership</p><h2 id="ownership-heading">Orders & activations</h2></div><span><?= count($orders) ?> recent records</span></header>
                <div class="kits-order-list">
                    <?php foreach ($orders as $order): ?><article class="kits-order"><span class="kits-order__mark">◎</span><div><strong><?= e((string)($order['external_order_id'] ?: '#'.$order['id'])) ?></strong><p><?= e((string)$order['customer_email']) ?></p></div><div><strong><?= e((string)$order['kit_name']) ?></strong><p><?= e((string)$order['sku']) ?></p></div><span class="kit-status"><?= e(str_replace('_',' ',(string)$order['fulfillment_status'])) ?></span><span class="kit-status kit-status--<?= e((string)$order['activation_status']) ?>"><?= e(str_replace('_',' ',(string)$order['activation_status'])) ?></span></article><?php endforeach; ?>
                    <?php if ($orders === []): ?><div class="kits-empty"><strong>No customer activations</strong><p>Published versions can be activated from the operations panel.</p></div><?php endif; ?>
                </div>
            </section>
        </div>

        <aside class="kits-sidebar" aria-label="Starter-kit operations">
            <section class="kits-panel"><header class="kits-panel__heading"><div><p class="kits-kicker">Build operations</p><h2>Create & release</h2></div></header><div class="kits-operation-list">
                <details open><summary>Create draft kit <span>+</span></summary><form method="post" class="kits-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_kit"><label>Name<input name="name" maxlength="180" required></label><label>Slug<input name="slug" maxlength="190" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required></label><div class="kits-form__split"><label>Type<select name="kit_type"><option value="basic">Basic</option><option value="specialized">Specialized</option></select></label><label>Category<input name="category" maxlength="100"></label></div><label>Description<textarea name="description" maxlength="5000"></textarea></label><button type="submit">Create draft kit</button></form></details>
                <details><summary>Create draft version <span>+</span></summary><form method="post" class="kits-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_version"><label>Kit<select name="starter_kit_id" required><?php foreach($kits as $kit): if($kit['status']==='retired') continue; ?><option value="<?= (int)$kit['id'] ?>"><?= e((string)$kit['name']) ?></option><?php endforeach; ?></select></label><div class="kits-form__split"><label>Version<input type="number" min="1" max="100000" name="version_number" required></label><label>SKU<input name="sku" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._-]{1,99}" required></label></div><div class="kits-form__split"><label>Price<input type="number" min="0" step="0.01" name="price"></label><label>Currency<input name="currency_code" value="USD" maxlength="3" pattern="[A-Za-z]{3}"></label></div><button type="submit">Create version</button></form></details>
                <details><summary>Publish immutable version <span>+</span></summary><form method="post" class="kits-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="publish_version"><p>Publication validates contents, snapshots recipes, and permanently freezes the version.</p><label>Draft version<select name="starter_kit_version_id" required><?php foreach($draftVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><button type="submit">Validate and publish</button></form></details>
            </div></section>

            <section class="kits-panel"><header class="kits-panel__heading"><div><p class="kits-kicker">Draft contents</p><h2>Assemble version</h2></div></header><div class="kits-operation-list">
                <details><summary>Add fulfillment item <span>+</span></summary><form method="post" class="kits-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_item"><label>Draft version<select name="starter_kit_version_id" required><?php foreach($draftVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Item name<input name="item_name" maxlength="180" required></label><div class="kits-form__split"><label>Kind<select name="item_kind"><option>ingredient</option><option>equipment</option><option>supply</option><option>seed</option><option>digital</option></select></label><label>Fulfillment<select name="fulfillment_type"><option value="shopping_list">Shopping list</option><option value="shipped">Shipped</option><option value="optional_delivery">Optional delivery</option><option value="digital_only">Digital only</option><option value="customer_supplied">Customer supplied</option></select></label></div><div class="kits-form__split"><label>Quantity<input type="number" step="0.0001" min="0" name="default_quantity"></label><label>Unit<input name="unit" maxlength="30"></label></div><label>Inventory category<select name="inventory_category_id"><option value="">None</option><?php foreach($categories as $category): ?><option value="<?= (int)$category['id'] ?>"><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label><label>Suggested storage<input name="suggested_storage_type" maxlength="80"></label><div class="kits-form__split"><label>Reorder level<input type="number" step="0.0001" min="0" name="reorder_level"></label><label>Estimated price<input type="number" step="0.01" min="0" name="estimated_price"></label></div><label>Supplier<input name="supplier_name" maxlength="180"></label><div class="kits-checks"><label><input type="checkbox" name="required" value="1" checked> Required</label><label><input type="checkbox" name="shipping_eligible" value="1"> Shipping</label><label><input type="checkbox" name="delivery_eligible" value="1"> Delivery</label></div><button type="submit">Add item</button></form></details>
                <details><summary>Attach starter recipe <span>+</span></summary><form method="post" class="kits-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="attach_recipe"><label>Draft version<select name="starter_kit_version_id" required><?php foreach($draftVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Household recipe<select name="recipe_id" required><?php foreach($recipes as $recipe): ?><option value="<?= (int)$recipe['id'] ?>"><?= e((string)$recipe['name']) ?></option><?php endforeach; ?></select></label><button type="submit">Attach recipe</button></form></details>
                <details><summary>Add starter task <span>+</span></summary><form method="post" class="kits-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_task"><label>Draft version<select name="starter_kit_version_id" required><?php foreach($draftVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Task title<input name="title" maxlength="180" required></label><div class="kits-form__split"><label>Area<input name="area" maxlength="80"></label><label>Due after days<input type="number" min="-365" max="3650" name="due_offset_days" value="0"></label></div><label>Recurrence rule<input name="recurring_rule" maxlength="190"></label><label>Instructions<textarea name="instructions" maxlength="5000"></textarea></label><button type="submit">Add task</button></form></details>
            </div></section>

            <section class="kits-panel"><header class="kits-panel__heading"><div><p class="kits-kicker">Customer handoff</p><h2>Create activation</h2></div></header><form method="post" class="kits-form kits-form--padded"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_order"><label>Published version<select name="starter_kit_version_id" required><?php foreach($publishedVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>Customer email<input type="email" maxlength="190" name="customer_email" required></label><label>External order ID<input maxlength="190" name="external_order_id"></label><button type="submit">Create activation</button></form></section>

            <section class="kits-panel"><header class="kits-panel__heading"><div><p class="kits-kicker">Delivery composition</p><h2>Fulfillment mix</h2></div></header><div class="kits-mix"><?php $mixTotal=max(1,array_sum($fulfillmentMix)); foreach($fulfillmentMix as $type=>$count): ?><div><span><?= e(ucwords(str_replace('_',' ',$type))) ?><b><?= $count ?></b></span><div><i style="width:<?= round(($count/$mixTotal)*100) ?>%"></i></div></div><?php endforeach; ?><?php if($fulfillmentMix===[]): ?><p>No item mix available yet.</p><?php endif; ?></div></section>
        </aside>
    </section>
</main>
<script src="assets/js/homestead-kits.js" defer></script>
</body></html>
