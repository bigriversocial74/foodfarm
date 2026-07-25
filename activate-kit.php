<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use Homestead\StarterKitService;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$service = new StarterKitService($pdo);
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = null;

try {
    $activation = $service->activationByToken($token);
    $statement = $pdo->prepare(
        'SELECT ai.*, i.item_name, i.item_kind, i.fulfillment_type, i.required, i.delivery_eligible, i.shipping_eligible, i.supplier_name
         FROM starter_kit_activation_items ai
         JOIN starter_kit_items i ON i.id = ai.starter_kit_item_id
         WHERE ai.starter_kit_activation_id = ? ORDER BY i.sort_order, i.id'
    );
    $statement->execute([(int)$activation['id']]);
    $items = $statement->fetchAll();
} catch (Throwable $exception) {
    $activation = null;
    $items = [];
    $error = $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($activation)) {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $selections = [];
        foreach ($items as $item) {
            $id = (int)$item['id'];
            $selections[$id] = [
                'status' => (string)($_POST['status'][$id] ?? 'pending'),
                'fulfillment_type' => (string)($_POST['fulfillment_type'][$id] ?? $item['selected_fulfillment_type']),
                'quantity' => $_POST['quantity'][$id] ?? $item['confirmed_quantity'],
                'unit' => $_POST['unit'][$id] ?? $item['unit'],
            ];
        }
        $service->activate($token, (int)$user['household_id'], (int)$user['member_id'], $selections);
        flash('success', 'Starter kit activated. Confirmed items were stocked and remaining items were added to your shopping or delivery workflow.');
        redirect('/phase2.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activate Starter Kit · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><main class="page-container" style="max-width:1100px">
<header class="page-header"><div><p class="eyebrow">Household onboarding</p><h1><?= is_array($activation) ? e((string)$activation['kit_name']) : 'Starter Kit Activation' ?></h1><p class="page-description">Review each item before Homestead stocks your digital pantry or creates local shopping and delivery requests.</p></div></header>
<?php if ($error): ?><div class="status status-warning" style="display:block;margin-bottom:18px"><?= e($error) ?></div><?php endif; ?>
<?php if (is_array($activation)): ?>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow"><?= e((string)$activation['kit_type']) ?> kit · Version <?= (int)$activation['version_number'] ?></p><h2>Confirm kit contents</h2></div><span><?= e((string)$activation['sku']) ?></span></div>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><div class="table-wrap"><table><thead><tr><th>Item</th><th>Fulfillment</th><th>Quantity</th><th>Action</th></tr></thead><tbody>
<?php foreach($items as $item): $id=(int)$item['id']; ?><tr><td><strong><?= e((string)$item['item_name']) ?></strong><br><small><?= e((string)$item['item_kind']) ?><?= $item['supplier_name']?' · '.e((string)$item['supplier_name']):'' ?></small></td><td><select name="fulfillment_type[<?= $id ?>]"><option value="shipped" <?= $item['selected_fulfillment_type']==='shipped'?'selected':'' ?>>Shipped</option><option value="shopping_list" <?= $item['selected_fulfillment_type']==='shopping_list'?'selected':'' ?>>Local shopping</option><option value="optional_delivery" <?= $item['selected_fulfillment_type']==='optional_delivery'?'selected':'' ?>>Optional delivery</option><option value="digital_only" <?= $item['selected_fulfillment_type']==='digital_only'?'selected':'' ?>>Digital only</option><option value="customer_supplied" <?= $item['selected_fulfillment_type']==='customer_supplied'?'selected':'' ?>>Already owned</option></select></td><td><input class="search-field" style="width:100px" type="number" step="0.0001" min="0" name="quantity[<?= $id ?>]" value="<?= e((string)$item['confirmed_quantity']) ?>"> <input class="search-field" style="width:90px" name="unit[<?= $id ?>]" value="<?= e((string)$item['unit']) ?>"></td><td><select name="status[<?= $id ?>]"><option value="stocked">Stock now</option><option value="shopping" <?= $item['selected_fulfillment_type']==='shopping_list'?'selected':'' ?>>Add to shopping list</option><option value="delivery_requested" <?= $item['selected_fulfillment_type']==='optional_delivery'?'selected':'' ?>>Request delivery</option><option value="pending" <?= in_array($item['selected_fulfillment_type'],['shipped','digital_only'],true)?'selected':'' ?>>Keep pending</option><option value="skipped">Skip</option></select></td></tr><?php endforeach; ?>
</tbody></table></div><div style="margin-top:18px"><button class="button primary" type="submit">Activate Starter Kit</button></div></form></section>
<?php endif; ?>
</main></body></html>
