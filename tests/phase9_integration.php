<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/CostWasteService.php';

use Homestead\CostWasteService;

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: 'homestead'
    ),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$service = new CostWasteService($pdo);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$expectFailure = static function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (Throwable) {
        // Expected.
    }
};
$scalar = static function (string $sql, array $params = []) use ($pdo): mixed {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
};

try {
    $ownerQuery = $pdo->prepare(
        "SELECT hm.id AS member_id, hm.household_id
         FROM users u JOIN household_members hm ON hm.user_id = u.id
         WHERE LOWER(u.email) = LOWER(?) AND hm.role = 'owner' AND hm.status = 'active' LIMIT 1"
    );
    $ownerQuery->execute([getenv('HOMESTEAD_OWNER_EMAIL') ?: 'owner@example.test']);
    $owner = $ownerQuery->fetch();
    if (!is_array($owner)) {
        throw new RuntimeException('The CI owner account was not found.');
    }
    $householdId = (int)$owner['household_id'];
    $memberId = (int)$owner['member_id'];
    $suffix = bin2hex(random_bytes(5));
    $today = new DateTimeImmutable('today');
    $month = $today->format('Y-m');

    $pdo->prepare('INSERT INTO households (name, slug, timezone) VALUES (?, ?, ?)')
        ->execute(['Phase 9 Isolation ' . $suffix, 'phase9-isolation-' . $suffix, 'America/Phoenix']);
    $otherHouseholdId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO household_members
         (household_id, display_name, age_group, role, status, joined_at)
         VALUES (?, 'Phase 9 Other', 'adult', 'owner', 'active', CURDATE())"
    )->execute([$otherHouseholdId]);
    $otherMemberId = (int)$pdo->lastInsertId();

    $category = $scalar(
        "SELECT id FROM inventory_categories
         WHERE category_type = 'food' AND (household_id IS NULL OR household_id = ?)
         ORDER BY household_id DESC LIMIT 1",
        [$householdId]
    );
    $itemName = 'Phase 9 Flour ' . $suffix;
    $pdo->prepare(
        "INSERT INTO inventory_items
         (household_id, category_id, name, item_type, current_quantity, unit, best_use_date, status)
         VALUES (?, ?, ?, 'ingredient', 0, 'lb', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'active')"
    )->execute([$householdId, $category !== false ? (int)$category : null, $itemName]);
    $itemId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO inventory_items
         (household_id, name, item_type, current_quantity, unit, status)
         VALUES (?, ?, 'ingredient', 0, 'lb', 'active')"
    )->execute([$otherHouseholdId, 'Other Flour ' . $suffix]);
    $otherItemId = (int)$pdo->lastInsertId();

    $supplierId = $service->createSupplier($householdId, $memberId, [
        'name' => 'Phase 9 Market ' . $suffix,
        'supplier_type' => 'market',
        'notes' => 'Integration supplier',
    ]);
    $assert($supplierId > 0, 'Supplier should be created.');
    $expectFailure(
        fn() => $service->createSupplier($householdId, $otherMemberId, [
            'name' => 'Cross Household Supplier',
            'supplier_type' => 'grocery',
        ]),
        'Cross-household members must not create suppliers.'
    );

    $service->saveSettings($householdId, $memberId, [
        'monthly_budget' => 50,
        'waste_target_percent' => 2,
        'savings_target_amount' => 25,
        'price_increase_alert_percent' => 15,
    ]);
    $settings = $service->settings($householdId);
    $assert(abs((float)$settings['monthly_budget'] - 50.0) < 0.001, 'Finance settings should persist.');
    $expectFailure(
        fn() => $service->saveSettings($householdId, $otherMemberId, [
            'monthly_budget' => 50,
            'waste_target_percent' => 2,
            'savings_target_amount' => 25,
            'price_increase_alert_percent' => 15,
        ]),
        'Cross-household members must not change finance settings.'
    );

    $purchaseKey1 = hash('sha256', 'phase9-purchase-1-' . $suffix);
    $purchase1 = $service->recordPurchase($householdId, $memberId, [
        'inventory_item_id' => $itemId,
        'supplier_id' => $supplierId,
        'quantity' => 10,
        'total_cost' => 20,
        'purchased_on' => $today->format('Y-m-d'),
        'package_quantity' => 10,
        'package_unit' => 'lb',
        'receipt_reference' => 'receipt-1-' . $suffix,
        'action_key' => $purchaseKey1,
    ]);
    $assert($purchase1['reused'] === false, 'First purchase should be new.');
    $assert(abs((float)$purchase1['unit_cost'] - 2.0) < 0.000001, 'First purchase unit cost should be calculated.');

    $reusedPurchase = $service->recordPurchase($householdId, $memberId, [
        'inventory_item_id' => $itemId,
        'supplier_id' => $supplierId,
        'quantity' => 10,
        'total_cost' => 20,
        'purchased_on' => $today->format('Y-m-d'),
        'package_quantity' => 10,
        'package_unit' => 'lb',
        'action_key' => $purchaseKey1,
    ]);
    $assert($reusedPurchase['reused'] === true, 'Identical purchase action should be reused.');
    $expectFailure(
        fn() => $service->recordPurchase($householdId, $memberId, [
            'inventory_item_id' => $itemId,
            'supplier_id' => $supplierId,
            'quantity' => 11,
            'total_cost' => 20,
            'purchased_on' => $today->format('Y-m-d'),
            'package_quantity' => 10,
            'package_unit' => 'lb',
            'action_key' => $purchaseKey1,
        ]),
        'A purchase action key must reject changed details.'
    );
    $expectFailure(
        fn() => $service->recordPurchase($householdId, $memberId, [
            'inventory_item_id' => $otherItemId,
            'quantity' => 1,
            'total_cost' => 1,
            'purchased_on' => $today->format('Y-m-d'),
            'action_key' => hash('sha256', 'cross-item-' . $suffix),
        ]),
        'Purchases must reject inventory from another household.'
    );

    $purchase2 = $service->recordPurchase($householdId, $memberId, [
        'inventory_item_id' => $itemId,
        'supplier_id' => $supplierId,
        'quantity' => 10,
        'total_cost' => 40,
        'purchased_on' => $today->format('Y-m-d'),
        'action_key' => hash('sha256', 'phase9-purchase-2-' . $suffix),
    ]);
    $assert(abs((float)$purchase2['unit_cost'] - 4.0) < 0.000001, 'Second purchase unit cost should be calculated.');
    $weighted = (float)$scalar(
        'SELECT weighted_unit_cost FROM inventory_cost_basis WHERE household_id = ? AND inventory_item_id = ?',
        [$householdId, $itemId]
    );
    $assert(abs($weighted - 3.0) < 0.000001, 'Weighted cost basis should be 3.00 per unit.');
    $assert(abs((float)$scalar('SELECT current_quantity FROM inventory_items WHERE id = ?', [$itemId]) - 20.0) < 0.0001, 'Purchases should increase inventory exactly once.');

    $pdo->prepare(
        "INSERT INTO recipes (household_id, name, servings, status, created_by_member_id)
         VALUES (?, ?, 4, 'active', ?)"
    )->execute([$householdId, 'Phase 9 Bread ' . $suffix, $memberId]);
    $recipeId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO recipe_ingredients
         (recipe_id, inventory_item_id, ingredient_name, quantity, unit, optional, sort_order)
         VALUES (?, ?, ?, 2, 'lb', 0, 1)"
    )->execute([$recipeId, $itemId, $itemName]);

    $recipeCost = $service->calculateRecipeCost(
        $householdId,
        $memberId,
        $recipeId,
        $today->format('Y-m-d')
    );
    $assert(abs((float)$recipeCost['total_cost'] - 6.0) < 0.001, 'Recipe cost should use the weighted unit cost.');
    $assert(abs((float)$recipeCost['cost_per_serving'] - 1.5) < 0.001, 'Recipe cost per serving should be calculated.');
    $reusedRecipeCost = $service->calculateRecipeCost(
        $householdId,
        $memberId,
        $recipeId,
        $today->format('Y-m-d')
    );
    $assert($reusedRecipeCost['reused'] === true, 'Unchanged recipe cost should be reused.');

    $wasteKey = hash('sha256', 'phase9-waste-' . $suffix);
    $waste = $service->recordWaste($householdId, $memberId, [
        'inventory_item_id' => $itemId,
        'waste_type' => 'spoiled',
        'quantity' => 2,
        'occurred_on' => $today->format('Y-m-d'),
        'reason' => 'Integration spoilage',
        'action_key' => $wasteKey,
    ]);
    $assert($waste['reused'] === false, 'First waste event should be new.');
    $assert(abs((float)$waste['estimated_value'] - 6.0) < 0.001, 'Waste value should use the weighted cost basis.');
    $assert(abs((float)$scalar('SELECT current_quantity FROM inventory_items WHERE id = ?', [$itemId]) - 18.0) < 0.0001, 'Waste should deduct inventory once.');
    $reusedWaste = $service->recordWaste($householdId, $memberId, [
        'inventory_item_id' => $itemId,
        'waste_type' => 'spoiled',
        'quantity' => 2,
        'occurred_on' => $today->format('Y-m-d'),
        'action_key' => $wasteKey,
    ]);
    $assert($reusedWaste['reused'] === true, 'Identical waste action should be reused.');
    $assert(abs((float)$scalar('SELECT current_quantity FROM inventory_items WHERE id = ?', [$itemId]) - 18.0) < 0.0001, 'Reused waste must not deduct inventory again.');

    $snapshot = $service->runFinanceSnapshot($householdId, $memberId, $month);
    $assert($snapshot['reused'] === false, 'First finance snapshot should be new.');
    $assert(abs((float)$snapshot['purchase_spend'] - 60.0) < 0.001, 'Monthly spending should total purchase records.');
    $assert(abs((float)$snapshot['waste_value'] - 6.0) < 0.001, 'Monthly waste should total waste events.');
    $assert((float)$snapshot['budget_variance'] < 0, 'Snapshot should report the budget overrun.');
    $assert((int)$snapshot['recommendations'] >= 2, 'Snapshot should create budget, waste, price, or use-first recommendations.');
    $reusedSnapshot = $service->runFinanceSnapshot($householdId, $memberId, $month);
    $assert($reusedSnapshot['reused'] === true, 'Unchanged monthly snapshot should be reused.');

    $recommendationId = (int)$scalar(
        "SELECT id FROM finance_recommendations
         WHERE household_id = ? AND snapshot_id = ? AND status = 'pending'
         ORDER BY id LIMIT 1",
        [$householdId, (int)$snapshot['snapshot_id']]
    );
    $assert($recommendationId > 0, 'A pending finance recommendation should exist.');
    $taskId = $service->acceptRecommendation($householdId, $memberId, $recommendationId);
    $assert($taskId > 0, 'Accepting a recommendation should create a task.');
    $assert($service->acceptRecommendation($householdId, $memberId, $recommendationId) === $taskId, 'Accepting again should reuse the same task.');
    $assert((int)$scalar(
        'SELECT COUNT(*) FROM task_automation_metadata WHERE household_id = ? AND household_task_id = ? AND source_type = "finance_recommendation"',
        [$householdId, $taskId]
    ) === 1, 'Finance recommendation task provenance should be recorded once.');
    $expectFailure(
        fn() => $service->acceptRecommendation($otherHouseholdId, $otherMemberId, $recommendationId),
        'Recommendations must be isolated by household.'
    );
    $service->completeRecommendation($householdId, $memberId, $recommendationId);
    $assert((string)$scalar('SELECT status FROM finance_recommendations WHERE id = ?', [$recommendationId]) === 'completed', 'Accepted recommendation should complete through a guarded transition.');

    $purchaseEvents = (int)$scalar(
        'SELECT COUNT(*) FROM food_ledger_events WHERE household_id = ? AND related_type = "food_purchase"',
        [$householdId]
    );
    $wasteLedgerEvents = (int)$scalar(
        'SELECT COUNT(*) FROM food_ledger_events WHERE household_id = ? AND related_type = "food_waste"',
        [$householdId]
    );
    $assert($purchaseEvents >= 2, 'Purchases should create ledger provenance.');
    $assert($wasteLedgerEvents >= 1, 'Waste should create ledger provenance.');

    if ($failures !== []) {
        throw new RuntimeException(implode("\n", $failures));
    }
    echo "Phase 9 integration passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}