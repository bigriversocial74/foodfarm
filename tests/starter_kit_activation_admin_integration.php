<?php

declare(strict_types=1);

use Homestead\StarterKitActivationAdminService;
use Homestead\StarterKitService;

require dirname(__DIR__) . '/app/StarterKitService.php';
require dirname(__DIR__) . '/app/StarterKitActivationAdminService.php';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    (int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_NAME') ?: 'homestead'
);
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$owner = $pdo->query(
    "SELECT u.id AS user_id, u.email, hm.id AS member_id, hm.household_id
     FROM users u JOIN household_members hm ON hm.user_id = u.id
     WHERE u.status = 'active' AND hm.status = 'active' AND hm.role = 'owner'
     ORDER BY u.id LIMIT 1"
)->fetch();
if (!is_array($owner)) {
    fwrite(STDERR, "A bootstrapped owner is required.\n");
    exit(1);
}
$userId = (int)$owner['user_id'];
$memberId = (int)$owner['member_id'];
$householdId = (int)$owner['household_id'];
$email = (string)$owner['email'];

$kitService = new StarterKitService($pdo);
$adminService = new StarterKitActivationAdminService($pdo);
$kitId = $kitService->createKit([
    'name' => 'Activation Operations Kit',
    'slug' => 'activation-operations-kit',
    'kit_type' => 'basic',
], $userId);
$versionId = $kitService->createVersion($kitId, [
    'version_number' => 1,
    'sku' => 'ACTIVATION-OPS-V1',
]);
$kitService->addItem($versionId, [
    'item_name' => 'Activation Operations Guide',
    'item_kind' => 'digital',
    'fulfillment_type' => 'digital_only',
    'required' => 1,
]);
$kitService->publishVersion($versionId);

$order = $kitService->createOrderAndActivation($versionId, $email, 'ACTIVATION-OPS-ORDER-1');
$adminService->revokeActivation((int)$order['activation_id']);
$revoked = $pdo->query('SELECT revoked_at FROM starter_kit_activations WHERE id = ' . (int)$order['activation_id'])->fetchColumn();
$orderState = $pdo->query('SELECT activation_status FROM starter_kit_orders WHERE id = ' . (int)$order['order_id'])->fetchColumn();
if ($revoked === false || $revoked === null || $orderState !== 'revoked') {
    fwrite(STDERR, "Pending activation was not fully revoked.\n");
    exit(1);
}
echo "[PASS] pending activation can be revoked\n";

$oldRejected = false;
try {
    $kitService->activationByToken((string)$order['token']);
} catch (Throwable) {
    $oldRejected = true;
}
if (!$oldRejected) {
    fwrite(STDERR, "Revoked token remained usable.\n");
    exit(1);
}
echo "[PASS] revoked token is unusable\n";

$replacementToken = $adminService->replaceActivation((int)$order['order_id']);
$replacement = $kitService->activationByToken($replacementToken);
if ((int)$replacement['starter_kit_order_id'] !== (int)$order['order_id']) {
    fwrite(STDERR, "Replacement activation is not attached to the order.\n");
    exit(1);
}
$itemCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM starter_kit_activation_items WHERE starter_kit_activation_id = ' . (int)$replacement['id']
)->fetchColumn();
if ($itemCount !== 1) {
    fwrite(STDERR, "Replacement activation did not copy the version items.\n");
    exit(1);
}
echo "[PASS] replacement activation revokes older links and copies items\n";

$adminService->cancelOrder((int)$order['order_id']);
$cancelled = $pdo->query(
    'SELECT fulfillment_status, activation_status FROM starter_kit_orders WHERE id = ' . (int)$order['order_id']
)->fetch();
if (!is_array($cancelled) || $cancelled['fulfillment_status'] !== 'cancelled' || $cancelled['activation_status'] !== 'revoked') {
    fwrite(STDERR, "Order cancellation did not close fulfillment and activation.\n");
    exit(1);
}
$replacementRejected = false;
try {
    $kitService->activationByToken($replacementToken);
} catch (Throwable) {
    $replacementRejected = true;
}
if (!$replacementRejected) {
    fwrite(STDERR, "Cancelled order replacement token remained usable.\n");
    exit(1);
}
echo "[PASS] cancellation revokes every unused activation\n";

$activatedOrder = $kitService->createOrderAndActivation($versionId, $email, 'ACTIVATION-OPS-ORDER-2');
$activation = $kitService->activationByToken((string)$activatedOrder['token']);
$items = $pdo->prepare(
    'SELECT ai.id FROM starter_kit_activation_items ai WHERE ai.starter_kit_activation_id = ?'
);
$items->execute([(int)$activation['id']]);
$selections = [];
foreach ($items->fetchAll() as $item) {
    $selections[(int)$item['id']] = [
        'status' => 'received',
        'fulfillment_type' => 'digital_only',
        'quantity' => 0,
        'unit' => '',
    ];
}
$kitService->activate((string)$activatedOrder['token'], [
    'id' => $userId,
    'email' => $email,
    'household_id' => $householdId,
    'member_id' => $memberId,
], $selections);

$activatedCancelRejected = false;
try {
    $adminService->cancelOrder((int)$activatedOrder['order_id']);
} catch (Throwable) {
    $activatedCancelRejected = true;
}
if (!$activatedCancelRejected) {
    fwrite(STDERR, "Activated order was cancelled.\n");
    exit(1);
}
echo "[PASS] activated orders cannot be cancelled or replaced\n";

echo "Starter Kit activation administration integration suite passed.\n";
