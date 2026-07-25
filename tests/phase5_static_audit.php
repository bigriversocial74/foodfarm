<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/app/StarterKitService.php');
$auth = (string)file_get_contents($root . '/app/Auth.php');
$admin = (string)file_get_contents($root . '/phase5.php');
$activation = (string)file_get_contents($root . '/activate-kit.php');
$migration = (string)file_get_contents($root . '/database/phase5_hardening.sql');

$checks = [
    'platform admin guard' => str_contains($admin, 'requirePlatformAdmin'),
    'purchaser email binding' => str_contains($service, 'hash_equals') && str_contains($activation, 'customer_email'),
    'activation row lock' => str_contains($service, 'FOR UPDATE'),
    'single-use activation update' => str_contains($service, 'activated_at IS NULL') && str_contains($service, 'rowCount() !== 1'),
    'draft-only mutation boundary' => str_contains($service, 'draftVersion'),
    'explicit validated publication' => str_contains($service, 'publishVersion') && str_contains($service, 'validatePublishableItem'),
    'published version immutability' => str_contains($service, 'Published and retired kit versions are immutable'),
    'required item enforcement' => str_contains($service, "status === 'skipped'"),
    'delivery eligibility enforcement' => str_contains($service, 'is not eligible for delivery'),
    'shipping eligibility enforcement' => str_contains($service, 'is not eligible for shipping'),
    'configured units cannot be changed during activation' => str_contains($service, 'must use the configured unit'),
    'digital fulfillment is constrained' => str_contains($service, 'Digital items must remain digital-only'),
    'admin recipe listing is household scoped' => str_contains($admin, "WHERE household_id=? AND status='active'"),
    'recipe attachment verifies source household' => str_contains($service, 'r.household_id = ?'),
    'admin categories are platform scoped' => str_contains($admin, 'WHERE household_id IS NULL'),
    'recipe provisioning' => str_contains($service, 'provisionRecipes'),
    'task provisioning' => str_contains($service, 'provisionTasks'),
    'starter-kit shopping provenance' => str_contains($migration, "'starter_kit'") && str_contains($service, "'starter_kit'"),
    'session household binding' => str_contains($auth, 'hm.id = ? AND hm.household_id = ?'),
    'activation page suppresses referrer token leakage' => str_contains($activation, 'Referrer-Policy: no-referrer'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}

if ($failed !== []) {
    fwrite(STDERR, 'Phase 5 audit failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Phase 5 static security and tenant-isolation audit passed.' . PHP_EOL;
