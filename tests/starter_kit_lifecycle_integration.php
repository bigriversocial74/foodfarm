<?php

declare(strict_types=1);

use Homestead\StarterKitAdminService;
use Homestead\StarterKitService;

require dirname(__DIR__) . '/app/StarterKitService.php';
require dirname(__DIR__) . '/app/StarterKitAdminService.php';

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

$userId = (int)$pdo->query("SELECT id FROM users WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
if ($userId < 1) {
    fwrite(STDERR, "A bootstrapped owner is required.\n");
    exit(1);
}

$kitService = new StarterKitService($pdo);
$adminService = new StarterKitAdminService($pdo);
$kitId = $kitService->createKit([
    'name' => 'Lifecycle Test Kit',
    'slug' => 'lifecycle-test-kit',
    'kit_type' => 'specialized',
    'category' => 'testing',
], $userId);
$versionId = $kitService->createVersion($kitId, [
    'version_number' => 1,
    'sku' => 'LIFECYCLE-V1',
    'price' => 20,
    'currency_code' => 'USD',
]);
$kitService->addItem($versionId, [
    'item_name' => 'Lifecycle Item',
    'item_kind' => 'supply',
    'fulfillment_type' => 'customer_supplied',
    'default_quantity' => 1,
    'unit' => 'each',
    'required' => 1,
]);
$kitService->addTask($versionId, ['title' => 'Lifecycle task', 'due_offset_days' => 2]);
$kitService->publishVersion($versionId);

$copyId = $adminService->duplicateVersion($versionId, 2, 'LIFECYCLE-V2');
$copy = $pdo->query('SELECT starter_kit_id, status FROM starter_kit_versions WHERE id = ' . $copyId)->fetch();
$itemCount = (int)$pdo->query('SELECT COUNT(*) FROM starter_kit_items WHERE starter_kit_version_id = ' . $copyId)->fetchColumn();
$taskCount = (int)$pdo->query('SELECT COUNT(*) FROM starter_kit_tasks WHERE starter_kit_version_id = ' . $copyId)->fetchColumn();
if (!is_array($copy) || (int)$copy['starter_kit_id'] !== $kitId || $copy['status'] !== 'draft' || $itemCount !== 1 || $taskCount !== 1) {
    fwrite(STDERR, "Version duplication did not preserve the expected draft contents.\n");
    exit(1);
}
echo "[PASS] immutable version duplicates into a complete draft\n";

$adminService->retireVersion($versionId);
$status = (string)$pdo->query('SELECT status FROM starter_kit_versions WHERE id = ' . $versionId)->fetchColumn();
if ($status !== 'retired') {
    fwrite(STDERR, "Version retirement failed.\n");
    exit(1);
}
echo "[PASS] individual version retirement\n";

$adminService->retireKit($kitId);
$kitStatus = (string)$pdo->query('SELECT status FROM starter_kits WHERE id = ' . $kitId)->fetchColumn();
$activeVersions = (int)$pdo->query("SELECT COUNT(*) FROM starter_kit_versions WHERE starter_kit_id = {$kitId} AND status <> 'retired'")->fetchColumn();
if ($kitStatus !== 'retired' || $activeVersions !== 0) {
    fwrite(STDERR, "Kit-family retirement failed.\n");
    exit(1);
}
echo "[PASS] kit-family retirement closes all versions\n";

echo "Starter Kit lifecycle integration suite passed.\n";
