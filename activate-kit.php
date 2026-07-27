<?php

declare(strict_types=1);

header('Referrer-Policy: no-referrer');
require __DIR__ . '/app/bootstrap.php';

use Homestead\StarterKitService;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$service = new StarterKitService($pdo);
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = null;

try {
    $activation = $service->activationByToken($token);
    if (!hash_equals(strtolower((string)$activation['customer_email']), strtolower((string)$user['email']))) {
        throw new RuntimeException('Sign in with the customer email address assigned to this starter-kit order.');
    }
    $statement = $pdo->prepare(
        'SELECT ai.*, i.item_name, i.item_kind, i.fulfillment_type, i.required,
                i.delivery_eligible, i.shipping_eligible, i.supplier_name
         FROM starter_kit_activation_items ai
         JOIN starter_kit_items i ON i.id = ai.starter_kit_item_id
         WHERE ai.starter_kit_activation_id = ? ORDER BY i.sort_order, i.id'
    );
    $statement->execute([(int)$activation['id']]);
    $items = $statement->fetchAll();
    if ($items === []) {
        throw new RuntimeException('This starter kit has no activation items.');
    }
} catch (Throwable $exception) {
    $activation = null;
    $items = [];
    $error = user_error_message($exception, 'This starter-kit activation is unavailable.');
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
        $service->activate($token, $user, $selections);
        flash('success', 'Starter kit activated. Pantry items, shopping needs, recipes, and starter tasks were provisioned.');
        redirect('/phase2.php');
    } catch (Throwable $exception) {
        $error = user_error_message($exception, 'The starter kit could not be activated. Try again.');
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title>Activate Starter Kit · Homestead</title>
    <link rel="icon" href="assets/icons/homestead-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/homestead-public.css?v=20260727-1">
</head>
<body class="activation-page">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="activation-frame">
    <header class="activation-header">
        <a class="site-brand" href="phase3.php" aria-label="Return to Homestead dashboard">
            <span class="brand-seal"><svg viewBox="0 0 48 48" aria-hidden="true">
<circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="1.5"/>
<path d="M24 10v27M24 15c-5-1-8-4-9-8M24 20c5-1 8-4 9-8M24 25c-5-1-8-4-9-8M24 30c5-1 8-4 9-8M18 37h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
</svg></span>
            <span class="brand-word">Homestead</span>
        </a>
        <span class="activation-account"><?= e((string)$user['email']) ?></span>
    </header>

    <main id="main-content">
        <section class="activation-hero">
            <div>
                <p class="gold-kicker">Household onboarding</p>
                <h1><?= is_array($activation) ? e((string)$activation['kit_name']) : 'Starter Kit Activation' ?></h1>
                <p>Review every item before provisioning your pantry, shopping list, recipes, and opening tasks.</p>
            </div>
            <div class="activation-art" aria-hidden="true"></div>
        </section>

        <?php if ($error): ?>
            <div role="alert" class="alert alert-warning activation-alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (is_array($activation)): ?>
            <section class="activation-panel">
                <div class="panel-heading">
                    <div>
                        <p class="gold-kicker"><?= e((string)$activation['kit_type']) ?> kit · Version <?= (int)$activation['version_number'] ?></p>
                        <h2>Confirm kit contents</h2>
                    </div>
                    <span class="sku-chip"><?= e((string)$activation['sku']) ?></span>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="dark-table-wrap" tabindex="0">
                        <table class="dark-table">
                            <thead>
                            <tr>
                                <th scope="col">Item</th>
                                <th scope="col">Fulfillment</th>
                                <th scope="col">Quantity</th>
                                <th scope="col">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): $id = (int)$item['id']; $digital = $item['item_kind'] === 'digital'; ?>
                                <tr>
                                    <td>
                                        <strong><?= e((string)$item['item_name']) ?></strong>
                                        <?php if ($item['required']): ?><span class="required-chip">Required</span><?php endif; ?>
                                        <small><?= e((string)$item['item_kind']) ?><?= $item['supplier_name'] ? ' · ' . e((string)$item['supplier_name']) : '' ?></small>
                                    </td>
                                    <td>
                                        <label class="visually-hidden" for="fulfillment-<?= $id ?>">Fulfillment for <?= e((string)$item['item_name']) ?></label>
                                        <select id="fulfillment-<?= $id ?>" name="fulfillment_type[<?= $id ?>]">
                                            <option value="<?= e((string)$item['selected_fulfillment_type']) ?>"><?= e(str_replace('_', ' ', (string)$item['selected_fulfillment_type'])) ?></option>
                                            <?php if ($item['delivery_eligible'] && $item['selected_fulfillment_type'] !== 'optional_delivery'): ?><option value="optional_delivery">Optional delivery</option><?php endif; ?>
                                            <?php if (!$digital && $item['selected_fulfillment_type'] !== 'shopping_list'): ?><option value="shopping_list">Local shopping</option><?php endif; ?>
                                            <?php if (!$digital && $item['selected_fulfillment_type'] !== 'customer_supplied'): ?><option value="customer_supplied">Already owned</option><?php endif; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <?php if ($digital): ?>
                                            <span>Digital content</span>
                                            <input type="hidden" name="quantity[<?= $id ?>]" value="0">
                                            <input type="hidden" name="unit[<?= $id ?>]" value="">
                                        <?php else: ?>
                                            <div class="quantity-fields">
                                                <label class="visually-hidden" for="quantity-<?= $id ?>">Quantity for <?= e((string)$item['item_name']) ?></label>
                                                <input id="quantity-<?= $id ?>" type="number" step="0.0001" min="0.0001" name="quantity[<?= $id ?>]" value="<?= e((string)$item['confirmed_quantity']) ?>" required>
                                                <label class="visually-hidden" for="unit-<?= $id ?>">Unit for <?= e((string)$item['item_name']) ?></label>
                                                <input id="unit-<?= $id ?>" name="unit[<?= $id ?>]" value="<?= e((string)$item['unit']) ?>" readonly>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <label class="visually-hidden" for="status-<?= $id ?>">Action for <?= e((string)$item['item_name']) ?></label>
                                        <select id="status-<?= $id ?>" name="status[<?= $id ?>]">
                                            <?php if ($digital): ?>
                                                <option value="received">Include digital content</option>
                                            <?php else: ?>
                                                <option value="stocked" <?= $item['selected_fulfillment_type'] === 'customer_supplied' ? 'selected' : '' ?>>Stock now</option>
                                                <option value="shopping" <?= $item['selected_fulfillment_type'] === 'shopping_list' ? 'selected' : '' ?>>Add to shopping list</option>
                                                <?php if ($item['delivery_eligible']): ?><option value="delivery_requested" <?= $item['selected_fulfillment_type'] === 'optional_delivery' ? 'selected' : '' ?>>Request delivery</option><?php endif; ?>
                                                <?php if ($item['shipping_eligible']): ?><option value="pending" <?= $item['selected_fulfillment_type'] === 'shipped' ? 'selected' : '' ?>>Await shipment</option><?php endif; ?>
                                                <?php if (!$item['required']): ?><option value="skipped">Skip</option><?php endif; ?>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="activation-submit">
                        <button class="gold-button" type="submit">Activate Starter Kit</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
