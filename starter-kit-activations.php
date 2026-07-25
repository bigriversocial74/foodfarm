<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/StarterKitActivationAdminService.php';

use Homestead\StarterKitActivationAdminService;
use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$auth->requirePlatformAdmin($user);
$service = new StarterKitActivationAdminService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'revoke_activation') {
            $service->revokeActivation((int)($_POST['activation_id'] ?? 0));
            flash('success', 'Pending activation revoked.');
        } elseif ($action === 'replace_activation') {
            $token = $service->replaceActivation((int)($_POST['order_id'] ?? 0));
            $_SESSION['replacement_activation_url'] = '/activate-kit.php?token=' . $token;
            flash('success', 'Replacement activation created. The prior unused link was revoked.');
        } elseif ($action === 'cancel_order') {
            $service->cancelOrder((int)($_POST['order_id'] ?? 0));
            flash('success', 'Unactivated order cancelled and its links revoked.');
        } else {
            throw new InvalidArgumentException('Unknown activation operation.');
        }
    } catch (Throwable $exception) {
        flash('error', user_error_message($exception));
    }
    redirect('/starter-kit-activations.php');
}

$orders = $pdo->query(
    "SELECT o.id, o.external_order_id, o.customer_email, o.fulfillment_status, o.activation_status,
            o.purchased_at, v.sku, k.name AS kit_name,
            a.id AS activation_id, a.expires_at, a.activated_at, a.revoked_at
     FROM starter_kit_orders o
     JOIN starter_kit_versions v ON v.id = o.starter_kit_version_id
     JOIN starter_kits k ON k.id = v.starter_kit_id
     LEFT JOIN starter_kit_activations a ON a.id = (
         SELECT a2.id FROM starter_kit_activations a2
         WHERE a2.starter_kit_order_id = o.id ORDER BY a2.id DESC LIMIT 1
     )
     ORDER BY o.id DESC LIMIT 200"
)->fetchAll();
$replacementUrl = $_SESSION['replacement_activation_url'] ?? null;
unset($_SESSION['replacement_activation_url']);
$flashes = consume_flashes();
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Starter Kit Activations · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body>
<a class="skip-link" href="#main-content">Skip to Starter Kit activations</a>
<main id="main-content" class="page-container">
<header class="page-header"><div><p class="eyebrow">Platform operations</p><h1>Starter Kit Activations</h1><p class="page-description">Revoke unused links, issue one-time replacements, cancel unactivated orders, and review customer activation state.</p></div><div class="toolbar"><a class="button secondary" href="/phase5.php">Kit builder</a><a class="button secondary" href="/starter-kit-lifecycle.php">Version lifecycle</a></div></header>
<?php foreach ($flashes as $message): ?><div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<?php if ($replacementUrl): ?><section class="panel" style="margin-bottom:20px"><p class="eyebrow">One-time replacement</p><h2>Activation URL</h2><label>Copy replacement activation URL<input class="search-field" value="<?= e((string)$replacementUrl) ?>" readonly onclick="this.select()"></label><p class="page-description" style="margin-top:10px">The raw token is displayed once. Share it privately with the customer.</p></section><?php endif; ?>
<section class="panel"><h2>Order and activation history</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Kit</th><th scope="col">Fulfillment</th><th scope="col">Activation</th><th scope="col">Latest link</th><th scope="col">Operations</th></tr></thead><tbody><?php if ($orders === []): ?><tr><td colspan="7">No Starter Kit orders have been created.</td></tr><?php endif; ?><?php foreach ($orders as $order): $pending = $order['activation_status'] === 'pending' && !$order['activated_at'] && !$order['revoked_at']; ?><tr><td><strong><?= e((string)($order['external_order_id'] ?: '#' . $order['id'])) ?></strong><br><small><?= e((string)$order['purchased_at']) ?></small></td><td><?= e((string)$order['customer_email']) ?></td><td><?= e((string)$order['kit_name']) ?><br><small><?= e((string)$order['sku']) ?></small></td><td><?= e(str_replace('_', ' ', (string)$order['fulfillment_status'])) ?></td><td><?= e(str_replace('_', ' ', (string)$order['activation_status'])) ?></td><td><?php if ($order['activation_id']): ?>#<?= (int)$order['activation_id'] ?><br><small><?= $order['activated_at'] ? 'Activated ' . e((string)$order['activated_at']) : ($order['revoked_at'] ? 'Revoked ' . e((string)$order['revoked_at']) : 'Expires ' . e((string)$order['expires_at'])) ?></small><?php else: ?>—<?php endif; ?></td><td><div class="toolbar"><?php if ($pending): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke_activation"><input type="hidden" name="activation_id" value="<?= (int)$order['activation_id'] ?>"><button class="button secondary" type="submit">Revoke link</button></form><?php endif; ?><?php if ($order['activation_status'] !== 'activated' && $order['fulfillment_status'] !== 'cancelled'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="replace_activation"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button class="button secondary" type="submit">Replace link</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_order"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button class="button secondary" type="submit">Cancel order</button></form><?php else: ?>Closed<?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>
</main>
</body>
</html>
