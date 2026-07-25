<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class RecipeService
{
    private const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack'];
    private const STORAGE_METHODS = ['refrigerated', 'frozen', 'counter', 'shelf_stable'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createRecipe(int $householdId, int $memberId, array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $servings = $this->positiveFloat($data['servings'] ?? null, 'Servings');
        if ($name === '' || mb_strlen($name) > 180) {
            throw new InvalidArgumentException('Enter a recipe name up to 180 characters.');
        }
        $this->assertMember($householdId, $memberId);

        $statement = $this->pdo->prepare(
            "INSERT INTO recipes
             (household_id, name, category, servings, yield_quantity, yield_unit, prep_minutes, cook_minutes, rest_minutes, instructions, notes, created_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $householdId,
            $name,
            $this->nullableText($data['category'] ?? null, 100),
            $servings,
            $this->nullableFloat($data['yield_quantity'] ?? null),
            $this->nullableText($data['yield_unit'] ?? null, 30),
            $this->nullableInt($data['prep_minutes'] ?? null),
            $this->nullableInt($data['cook_minutes'] ?? null),
            $this->nullableInt($data['rest_minutes'] ?? null),
            $this->nullableText($data['instructions'] ?? null),
            $this->nullableText($data['notes'] ?? null),
            $memberId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addIngredient(int $householdId, int $recipeId, array $data): void
    {
        $recipe = $this->ownedRecipe($householdId, $recipeId);
        $quantity = $this->positiveFloat($data['quantity'] ?? null, 'Ingredient quantity');
        $unit = $this->normalizeUnit((string)($data['unit'] ?? ''));
        $name = trim((string)($data['ingredient_name'] ?? ''));
        $inventoryItemId = (int)($data['inventory_item_id'] ?? 0) ?: null;
        if ($name === '' || mb_strlen($name) > 180) {
            throw new InvalidArgumentException('Enter an ingredient name up to 180 characters.');
        }

        if ($inventoryItemId !== null) {
            $statement = $this->pdo->prepare('SELECT id, unit FROM inventory_items WHERE id = ? AND household_id = ? AND status = \'active\' LIMIT 1');
            $statement->execute([$inventoryItemId, $householdId]);
            $item = $statement->fetch();
            if (!is_array($item)) {
                throw new RuntimeException('The selected inventory item is unavailable.');
            }
            if ($this->normalizeUnit((string)$item['unit']) !== $unit) {
                throw new InvalidArgumentException('Recipe and inventory units must match. Convert the quantity before linking this item.');
            }
        }

        $sort = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM recipe_ingredients WHERE recipe_id = ?');
        $sort->execute([(int)$recipe['id']]);
        $statement = $this->pdo->prepare('INSERT INTO recipe_ingredients (recipe_id, inventory_item_id, ingredient_name, quantity, unit, optional, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$recipeId, $inventoryItemId, $name, $quantity, $unit, !empty($data['optional']) ? 1 : 0, (int)$sort->fetchColumn()]);
    }

    public function createMealPlan(int $householdId, string $name, string $startsOn, string $endsOn): int
    {
        $name = trim($name);
        $start = $this->date($startsOn, 'Start date');
        $end = $this->date($endsOn, 'End date');
        if ($name === '' || mb_strlen($name) > 160 || $end < $start) {
            throw new InvalidArgumentException('Enter a valid meal-plan name and date range.');
        }
        if ($start->diff($end)->days > 366) {
            throw new InvalidArgumentException('Meal plans may cover no more than 366 days.');
        }
        $statement = $this->pdo->prepare("INSERT INTO meal_plans (household_id, name, starts_on, ends_on, status) VALUES (?, ?, ?, ?, 'active')");
        $statement->execute([$householdId, $name, $start->format('Y-m-d'), $end->format('Y-m-d')]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addMeal(int $householdId, array $data): int
    {
        $planId = (int)($data['meal_plan_id'] ?? 0);
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $mealDate = $this->date((string)($data['meal_date'] ?? ''), 'Meal date');
        $mealType = (string)($data['meal_type'] ?? '');
        $memberIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['member_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        if ($planId < 1 || $recipeId < 1 || $memberIds === [] || !in_array($mealType, self::MEAL_TYPES, true)) {
            throw new InvalidArgumentException('Choose a meal plan, recipe, meal type, and at least one family member.');
        }

        $plan = $this->pdo->prepare('SELECT id, starts_on, ends_on, status FROM meal_plans WHERE id = ? AND household_id = ? LIMIT 1');
        $plan->execute([$planId, $householdId]);
        $planRow = $plan->fetch();
        if (!is_array($planRow) || !in_array((string)$planRow['status'], ['draft', 'active'], true)) {
            throw new RuntimeException('Meal plan is unavailable.');
        }
        if ($mealDate < new DateTimeImmutable((string)$planRow['starts_on']) || $mealDate > new DateTimeImmutable((string)$planRow['ends_on'])) {
            throw new InvalidArgumentException('Meal date must fall inside the selected meal plan.');
        }
        $this->ownedRecipe($householdId, $recipeId);

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $memberQuery = $this->pdo->prepare("SELECT id, serving_multiplier FROM household_members WHERE household_id = ? AND status = 'active' AND id IN ($placeholders)");
        $memberQuery->execute(array_merge([$householdId], $memberIds));
        $members = $memberQuery->fetchAll();
        if (count($members) !== count($memberIds)) {
            throw new RuntimeException('One or more selected family members are unavailable.');
        }

        $plannedServings = array_reduce($members, static fn(float $sum, array $member): float => $sum + (float)$member['serving_multiplier'], 0.0);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare("INSERT INTO meal_plan_items (meal_plan_id, recipe_id, meal_date, meal_type, planned_servings, participating_member_ids, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'planned')");
            $statement->execute([$planId, $recipeId, $mealDate->format('Y-m-d'), $mealType, $plannedServings, json_encode($memberIds, JSON_THROW_ON_ERROR), $this->nullableText($data['notes'] ?? null)]);
            $mealItemId = (int)$this->pdo->lastInsertId();
            $insertMember = $this->pdo->prepare('INSERT INTO meal_plan_members (meal_plan_item_id, household_member_id, serving_multiplier_snapshot, expected_servings) VALUES (?, ?, ?, ?)');
            foreach ($members as $member) {
                $multiplier = (float)$member['serving_multiplier'];
                $insertMember->execute([$mealItemId, $member['id'], $multiplier, $multiplier]);
            }
            $this->pdo->commit();
            return $mealItemId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function completeRecipe(int $householdId, int $memberId, array $data): int
    {
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $scale = $this->positiveFloat($data['scale_factor'] ?? null, 'Scale factor');
        $actualServings = $this->positiveFloat($data['actual_servings'] ?? null, 'Actual servings');
        $locationId = (int)($data['storage_location_id'] ?? 0) ?: null;
        $storageMethod = (string)($data['storage_method'] ?? '');
        $completionKey = strtolower(trim((string)($data['completion_key'] ?? '')));
        $memberIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['intended_member_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        if (!in_array($storageMethod, self::STORAGE_METHODS, true) || !preg_match('/^[a-f0-9]{64}$/', $completionKey)) {
            throw new InvalidArgumentException('Invalid storage method or completion request.');
        }
        $useByDate = ($data['use_by_date'] ?? '') === '' ? null : $this->date((string)$data['use_by_date'], 'Use-by date')->format('Y-m-d');

        $this->pdo->beginTransaction();
        try {
            $recipeStatement = $this->pdo->prepare('SELECT * FROM recipes WHERE id = ? AND household_id = ? AND status = \'active\' FOR UPDATE');
            $recipeStatement->execute([$recipeId, $householdId]);
            $recipe = $recipeStatement->fetch();
            if (!is_array($recipe)) {
                throw new RuntimeException('Recipe not found.');
            }
            $this->assertMember($householdId, $memberId);
            if ($locationId !== null) {
                $this->assertLocation($householdId, $locationId);
            }
            if ($memberIds !== []) {
                $this->assertMembers($householdId, $memberIds);
            }

            $existing = $this->pdo->prepare('SELECT id FROM recipe_runs WHERE household_id = ? AND completion_key = ? LIMIT 1');
            $existing->execute([$householdId, $completionKey]);
            if ($existing->fetchColumn()) {
                throw new RuntimeException('This recipe completion was already posted.');
            }

            $ingredients = $this->pdo->prepare(
                'SELECT ri.*, ii.current_quantity, ii.household_id AS item_household_id, ii.storage_location_id, ii.unit AS inventory_unit
                 FROM recipe_ingredients ri
                 LEFT JOIN inventory_items ii ON ii.id = ri.inventory_item_id
                 WHERE ri.recipe_id = ? ORDER BY ri.sort_order, ri.id FOR UPDATE'
            );
            $ingredients->execute([$recipeId]);
            $rows = $ingredients->fetchAll();
            if ($rows === []) {
                throw new RuntimeException('Add at least one ingredient before completing this recipe.');
            }

            foreach ($rows as $ingredient) {
                $required = round((float)$ingredient['quantity'] * $scale, 4);
                if ((int)$ingredient['optional'] === 0 && empty($ingredient['inventory_item_id'])) {
                    throw new RuntimeException('Required ingredient "' . $ingredient['ingredient_name'] . '" is not linked to inventory.');
                }
                if (!empty($ingredient['inventory_item_id'])) {
                    if ((int)$ingredient['item_household_id'] !== $householdId) {
                        throw new RuntimeException('An ingredient is linked outside this household.');
                    }
                    if ($this->normalizeUnit((string)$ingredient['unit']) !== $this->normalizeUnit((string)$ingredient['inventory_unit'])) {
                        throw new RuntimeException('Unit mismatch for ' . $ingredient['ingredient_name'] . '.');
                    }
                    if ((float)$ingredient['current_quantity'] + 0.00001 < $required && (int)$ingredient['optional'] === 0) {
                        throw new RuntimeException('Not enough ' . $ingredient['ingredient_name'] . ' in inventory.');
                    }
                }
            }

            $runInsert = $this->pdo->prepare("INSERT INTO recipe_runs (household_id, recipe_id, prepared_by_member_id, completion_key, scale_factor, planned_servings, actual_servings, status, started_at, completed_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)");
            $runInsert->execute([$householdId, $recipeId, $memberId, $completionKey, $scale, (float)$recipe['servings'] * $scale, $actualServings, $this->nullableText($data['notes'] ?? null)]);
            $runId = (int)$this->pdo->lastInsertId();

            $runIngredient = $this->pdo->prepare("INSERT INTO recipe_run_ingredients (recipe_run_id, recipe_ingredient_id, inventory_item_id, required_quantity, consumed_quantity, unit, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $updateInventory = $this->pdo->prepare('UPDATE inventory_items SET current_quantity = current_quantity - ? WHERE id = ? AND household_id = ? AND current_quantity >= ?');
            $ledger = $this->pdo->prepare("INSERT INTO food_ledger_events (household_id, inventory_item_id, member_id, event_type, quantity, unit, source_location_id, related_type, related_id, notes) VALUES (?, ?, ?, 'used_in_recipe', ?, ?, ?, 'recipe_run', ?, ?)");

            foreach ($rows as $ingredient) {
                $required = round((float)$ingredient['quantity'] * $scale, 4);
                $consumed = 0.0;
                $status = 'missing';
                if (!empty($ingredient['inventory_item_id'])) {
                    $available = (float)$ingredient['current_quantity'];
                    $consumed = min($available, $required);
                    $status = $consumed + 0.00001 >= $required ? 'consumed' : 'missing';
                    if ($consumed > 0) {
                        $updateInventory->execute([$consumed, $ingredient['inventory_item_id'], $householdId, $consumed]);
                        if ($updateInventory->rowCount() !== 1) {
                            throw new RuntimeException('Inventory changed while completing the recipe. Please review quantities and try again.');
                        }
                        $ledger->execute([$householdId, $ingredient['inventory_item_id'], $memberId, -$consumed, $ingredient['inventory_unit'], $ingredient['storage_location_id'], $runId, 'Used for ' . $recipe['name']]);
                    }
                }
                $runIngredient->execute([$runId, $ingredient['id'], $ingredient['inventory_item_id'], $required, $consumed, $ingredient['unit'], $status]);
            }

            $category = $this->pdo->prepare("SELECT id FROM inventory_categories WHERE (household_id = ? OR household_id IS NULL) AND category_type = 'prepared_food' ORDER BY household_id DESC LIMIT 1");
            $category->execute([$householdId]);
            $categoryId = (int)$category->fetchColumn() ?: null;
            $itemInsert = $this->pdo->prepare("INSERT INTO inventory_items (household_id, category_id, storage_location_id, name, item_type, current_quantity, unit, best_use_date, status, metadata, notes) VALUES (?, ?, ?, ?, 'prepared_food', ?, 'servings', ?, 'active', ?, ?)");
            $itemInsert->execute([$householdId, $categoryId, $locationId, $recipe['name'], $actualServings, $useByDate, json_encode(['recipe_run_id' => $runId, 'storage_method' => $storageMethod], JSON_THROW_ON_ERROR), $this->nullableText($data['reheating_notes'] ?? null)]);
            $inventoryItemId = (int)$this->pdo->lastInsertId();

            $batch = $this->pdo->prepare("INSERT INTO prepared_food_batches (household_id, recipe_run_id, inventory_item_id, prepared_by_member_id, name, servings_produced, servings_remaining, storage_location_id, use_by_date, storage_method, reheating_notes, intended_member_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $batch->execute([$householdId, $runId, $inventoryItemId, $memberId, $recipe['name'], $actualServings, $actualServings, $locationId, $useByDate, $storageMethod, $this->nullableText($data['reheating_notes'] ?? null), json_encode($memberIds, JSON_THROW_ON_ERROR)]);
            $batchId = (int)$this->pdo->lastInsertId();

            $eventType = $storageMethod === 'frozen' ? 'frozen' : ($storageMethod === 'refrigerated' ? 'refrigerated' : 'prepared');
            $createLedger = $this->pdo->prepare('INSERT INTO food_ledger_events (household_id, inventory_item_id, member_id, event_type, quantity, unit, destination_location_id, related_type, related_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $createLedger->execute([$householdId, $inventoryItemId, $memberId, $eventType, $actualServings, 'servings', $locationId, 'prepared_food_batch', $batchId, 'Prepared from recipe ' . $recipe['name']]);

            $this->pdo->commit();
            return $batchId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function ownedRecipe(int $householdId, int $recipeId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM recipes WHERE id = ? AND household_id = ? LIMIT 1');
        $statement->execute([$recipeId, $householdId]);
        $recipe = $statement->fetch();
        if (!is_array($recipe)) {
            throw new RuntimeException('Recipe not found.');
        }
        return $recipe;
    }

    private function assertMember(int $householdId, int $memberId): void
    {
        $statement = $this->pdo->prepare("SELECT id FROM household_members WHERE id = ? AND household_id = ? AND status = 'active' LIMIT 1");
        $statement->execute([$memberId, $householdId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('Household member is unavailable.');
        }
    }

    private function assertMembers(int $householdId, array $memberIds): void
    {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM household_members WHERE household_id = ? AND status = 'active' AND id IN ($placeholders)");
        $statement->execute(array_merge([$householdId], $memberIds));
        if ((int)$statement->fetchColumn() !== count($memberIds)) {
            throw new RuntimeException('One or more intended family members are unavailable.');
        }
    }

    private function assertLocation(int $householdId, int $locationId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM storage_locations WHERE id = ? AND household_id = ? LIMIT 1');
        $statement->execute([$locationId, $householdId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('Storage location is unavailable.');
        }
    }

    private function date(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($field . ' is invalid.');
        }
        return $date;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));
        if ($unit === '' || mb_strlen($unit) > 30 || !preg_match('/^[a-z0-9 .\/-]+$/', $unit)) {
            throw new InvalidArgumentException('Enter a valid unit up to 30 characters.');
        }
        return $unit;
    }

    private function positiveFloat(mixed $value, string $field): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) {
            throw new InvalidArgumentException($field . ' must be greater than zero.');
        }
        return round((float)$value, 4);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
            throw new InvalidArgumentException('Enter a valid non-negative number.');
        }
        return round((float)$value, 4);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : max(0, (int)$value);
    }

    private function nullableText(mixed $value, int $max = 5000): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new InvalidArgumentException('Text exceeds the allowed length.');
        }
        return $text;
    }
}
