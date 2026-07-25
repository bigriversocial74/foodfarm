<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/NutritionService.php';

use Homestead\NutritionService;

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
$service = new NutritionService($pdo);
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

    $pdo->prepare('INSERT INTO households (name, slug, timezone) VALUES (?, ?, ?)')
        ->execute(['Phase 10 Isolation ' . $suffix, 'phase10-isolation-' . $suffix, 'America/Phoenix']);
    $otherHouseholdId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO household_members
         (household_id, display_name, age_group, role, status, joined_at)
         VALUES (?, 'Phase 10 Other', 'adult', 'owner', 'active', CURDATE())"
    )->execute([$otherHouseholdId]);
    $otherMemberId = (int)$pdo->lastInsertId();

    $category = $scalar(
        "SELECT id FROM inventory_categories
         WHERE category_type = 'food' AND (household_id IS NULL OR household_id = ?)
         ORDER BY household_id DESC LIMIT 1",
        [$householdId]
    );
    $itemName = 'Phase 10 Ingredient ' . $suffix;
    $pdo->prepare(
        "INSERT INTO inventory_items
         (household_id, category_id, name, item_type, current_quantity, unit, status)
         VALUES (?, ?, ?, 'ingredient', 10, 'each', 'active')"
    )->execute([$householdId, $category !== false ? (int)$category : null, $itemName]);
    $itemId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO inventory_items
         (household_id, name, item_type, current_quantity, unit, status)
         VALUES (?, ?, 'ingredient', 1, 'each', 'active')"
    )->execute([$otherHouseholdId, 'Other Ingredient ' . $suffix]);
    $otherItemId = (int)$pdo->lastInsertId();

    $service->saveSettings($householdId, $memberId, [
        'assessment_window_days' => 7,
        'minimum_recipe_variety' => 2,
        'minimum_data_completeness_percent' => 80,
        'show_optional_targets' => 1,
    ]);
    $settings = $service->settings($householdId);
    $assert((int)$settings['minimum_recipe_variety'] === 2, 'Nutrition settings should persist.');
    $expectFailure(
        fn() => $service->saveSettings($householdId, $otherMemberId, [
            'assessment_window_days' => 7,
            'minimum_recipe_variety' => 2,
            'minimum_data_completeness_percent' => 80,
        ]),
        'Cross-household members must not change nutrition settings.'
    );

    $service->saveMemberProfile($householdId, $memberId, [
        'household_member_id' => $memberId,
        'dietary_pattern' => 'Household balanced plan',
        'calorie_target' => 500,
        'protein_target_g' => 20,
        'fiber_target_g' => 10,
        'sodium_limit_mg' => 50,
        'added_sugar_limit_g' => 2,
        'target_notes' => 'Integration-only optional targets.',
    ]);
    $assert((string)$scalar(
        'SELECT dietary_pattern FROM member_nutrition_profiles WHERE household_id = ? AND household_member_id = ?',
        [$householdId, $memberId]
    ) === 'Household balanced plan', 'Member nutrition profile should persist.');

    $service->saveMemberAllergenRule($householdId, $memberId, [
        'household_member_id' => $memberId,
        'allergen_key' => 'peanut',
        'severity' => 'allergy',
        'notes' => 'Integration allergen rule',
        'active' => 1,
    ]);
    $assert((int)$scalar(
        'SELECT COUNT(*) FROM member_allergen_rules
         WHERE household_id = ? AND household_member_id = ? AND allergen_key = "peanut" AND active = 1',
        [$householdId, $memberId]
    ) === 1, 'Member allergen rule should persist once.');

    $service->saveInventoryNutrition($householdId, $memberId, [
        'inventory_item_id' => $itemId,
        'basis_quantity' => 1,
        'basis_unit' => 'each',
        'calories' => 100,
        'protein_g' => 5,
        'carbohydrate_g' => 15,
        'fat_g' => 2,
        'fiber_g' => 2,
        'total_sugar_g' => 4,
        'added_sugar_g' => 3,
        'sodium_mg' => 100,
        'source_label' => 'Integration label',
        'confidence' => 'label',
    ]);
    $assert(abs((float)$scalar(
        'SELECT calories FROM inventory_nutrition_profiles WHERE household_id = ? AND inventory_item_id = ?',
        [$householdId, $itemId]
    ) - 100.0) < 0.001, 'Ingredient nutrition should persist.');
    $expectFailure(
        fn() => $service->saveInventoryNutrition($householdId, $memberId, [
            'inventory_item_id' => $otherItemId,
            'basis_quantity' => 1,
            'basis_unit' => 'each',
            'calories' => 1,
        ]),
        'Nutrition profiles must reject inventory from another household.'
    );

    $service->saveInventoryAllergenTag($householdId, $memberId, [
        'inventory_item_id' => $itemId,
        'allergen_key' => 'peanut',
        'presence' => 'contains',
        'source_label' => 'Integration label',
        'active' => 1,
    ]);
    $assert((int)$scalar(
        'SELECT COUNT(*) FROM inventory_allergen_tags
         WHERE household_id = ? AND inventory_item_id = ? AND allergen_key = "peanut" AND active = 1',
        [$householdId, $itemId]
    ) === 1, 'Ingredient allergen tag should persist once.');

    $pdo->prepare(
        "INSERT INTO recipes (household_id, name, servings, status, created_by_member_id)
         VALUES (?, ?, 2, 'active', ?)"
    )->execute([$householdId, 'Phase 10 Recipe ' . $suffix, $memberId]);
    $recipeId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO recipe_ingredients
         (recipe_id, inventory_item_id, ingredient_name, quantity, unit, optional, sort_order)
         VALUES (?, ?, ?, 2, 'each', 0, 1)"
    )->execute([$recipeId, $itemId, $itemName]);

    $recipeSnapshot = $service->calculateRecipeNutrition(
        $householdId,
        $memberId,
        $recipeId,
        $today->format('Y-m-d')
    );
    $assert($recipeSnapshot['reused'] === false, 'First recipe nutrition snapshot should be new.');
    $assert(abs((float)$recipeSnapshot['calories_per_serving'] - 100.0) < 0.001, 'Recipe calories per serving should be calculated.');
    $assert(abs((float)$recipeSnapshot['protein_per_serving_g'] - 5.0) < 0.001, 'Recipe protein per serving should be calculated.');
    $assert((int)$recipeSnapshot['missing_profiles'] === 0, 'Complete recipe should not report missing nutrition profiles.');
    $reusedRecipe = $service->calculateRecipeNutrition(
        $householdId,
        $memberId,
        $recipeId,
        $today->format('Y-m-d')
    );
    $assert($reusedRecipe['reused'] === true, 'Unchanged recipe nutrition should be reused.');
    $expectFailure(
        fn() => $service->calculateRecipeNutrition($otherHouseholdId, $otherMemberId, $recipeId, $today->format('Y-m-d')),
        'Recipe nutrition calculations must be isolated by household.'
    );

    $pdo->prepare(
        "INSERT INTO meal_plans (household_id, name, starts_on, ends_on, status)
         VALUES (?, ?, ?, ?, 'active')"
    )->execute([$householdId, 'Phase 10 Meal Plan ' . $suffix, $today->format('Y-m-d'), $today->format('Y-m-d')]);
    $mealPlanId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO meal_plan_items
         (meal_plan_id, recipe_id, meal_date, meal_type, planned_servings, notes)
         VALUES (?, ?, ?, 'dinner', 1, 'Integration meal')"
    )->execute([$mealPlanId, $recipeId, $today->format('Y-m-d')]);
    $mealPlanItemId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO meal_plan_members
         (meal_plan_item_id, household_member_id, serving_multiplier_snapshot, expected_servings)
         VALUES (?, ?, 1, 1)'
    )->execute([$mealPlanItemId, $memberId]);

    $assessment = $service->runMealAssessment($householdId, $memberId, $mealPlanId);
    $assert($assessment['reused'] === false, 'First meal-plan nutrition assessment should be new.');
    $assert(abs((float)$assessment['data_completeness_percent'] - 100.0) < 0.001, 'Assessment should be fully complete.');
    $assert((int)$assessment['allergen_conflict_count'] >= 1, 'Assessment should detect the active allergen conflict.');
    $assert((int)$assessment['recommendation_count'] >= 4, 'Assessment should create allergen, variety, target, or limit recommendations.');
    $reusedAssessment = $service->runMealAssessment($householdId, $memberId, $mealPlanId);
    $assert($reusedAssessment['reused'] === true, 'Unchanged meal-plan assessment should be reused.');

    $line = $pdo->prepare(
        'SELECT * FROM member_nutrition_assessment_lines
         WHERE meal_nutrition_assessment_id = ? AND household_id = ? AND household_member_id = ?'
    );
    $line->execute([(int)$assessment['assessment_id'], $householdId, $memberId]);
    $lineRow = $line->fetch();
    $assert(is_array($lineRow), 'Member assessment line should be stored.');
    $assert((int)($lineRow['assessed_meal_count'] ?? 0) === 1, 'Member assessment should include the assigned meal.');
    $assert((float)($lineRow['sodium_limit_usage_percent'] ?? 0) > 100, 'Sodium planning limit usage should be calculated.');

    $recommendationId = (int)$scalar(
        "SELECT id FROM nutrition_recommendations
         WHERE household_id = ? AND assessment_id = ? AND status = 'pending'
         ORDER BY FIELD(priority, 'critical','high','medium','low'), id LIMIT 1",
        [$householdId, (int)$assessment['assessment_id']]
    );
    $assert($recommendationId > 0, 'A pending nutrition recommendation should exist.');
    $taskId = $service->acceptRecommendation($householdId, $memberId, $recommendationId);
    $assert($taskId > 0, 'Accepting a nutrition recommendation should create a task.');
    $assert($service->acceptRecommendation($householdId, $memberId, $recommendationId) === $taskId, 'Accepting again should reuse the same task.');
    $assert((int)$scalar(
        'SELECT COUNT(*) FROM task_automation_metadata
         WHERE household_id = ? AND household_task_id = ? AND source_type = "nutrition_recommendation"',
        [$householdId, $taskId]
    ) === 1, 'Nutrition recommendation task provenance should be recorded once.');
    $expectFailure(
        fn() => $service->acceptRecommendation($otherHouseholdId, $otherMemberId, $recommendationId),
        'Nutrition recommendations must be isolated by household.'
    );
    $service->completeRecommendation($householdId, $memberId, $recommendationId);
    $assert((string)$scalar('SELECT status FROM nutrition_recommendations WHERE id = ?', [$recommendationId]) === 'completed', 'Accepted recommendation should complete through the guarded lifecycle.');

    $dashboard = $service->dashboardData($householdId);
    $assert(is_array($dashboard['assessment']), 'Nutrition dashboard should include the latest completed assessment.');
    $assert(count($dashboard['recipes']) >= 1, 'Nutrition dashboard should include recipes.');
    $assert(count($dashboard['members']) >= 1, 'Nutrition dashboard should include active members.');
} catch (Throwable $exception) {
    $failures[] = get_class($exception) . ': ' . $exception->getMessage();
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Phase 10 integration suite passed.\n";