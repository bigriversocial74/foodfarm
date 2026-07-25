<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class RecipeService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createRecipe(int $householdId, int $memberId, array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $servings = (float)($data['servings'] ?? 0);
        if ($name === '' || $servings <= 0) {
            throw new InvalidArgumentException('Recipe name and servings are required.');
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO recipes
             (household_id, name, category, servings, yield_quantity, yield_unit, prep_minutes, cook_minutes, rest_minutes, instructions, notes, created_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $householdId,
            $name,
            trim((string)($data['category'] ?? '')) ?: null,
            $servings,
            $this->nullableFloat($data['yield_quantity'] ?? null),
            trim((string)($data['yield_unit'] ?? '')) ?: null,
            $this->nullableInt($data['prep_minutes'] ?? null),
            $this->nullableInt($data['cook_minutes'] ?? null),
            $this->nullableInt($data['rest_minutes'] ?? null),
            trim((string)($data['instructions'] ?? '')) ?: null,
            trim((string)($data['notes'] ?? '')) ?: null,
            $memberId,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function addIngredient(int $householdId, int $recipeId, array $data): void
    {
        $recipe = $this->ownedRecipe($householdId, $recipeId);
        $quantity = (float)($data['quantity'] ?? 0);
        $unit = trim((string)($data['unit'] ?? ''));
        $name = trim((string)($data['ingredient_name'] ?? ''));
        $inventoryItemId = (int)($data['inventory_item_id'] ?? 0) ?: null;

        if ($quantity <= 0 || $unit === '' || $name === '') {
            throw new InvalidArgumentException('Ingredient name, quantity, and unit are required.');
        }

        if ($inventoryItemId !== null) {
            $statement = $this->pdo->prepare('SELECT id FROM inventory_items WHERE id = ? AND household_id = ? LIMIT 1');
            $statement->execute([$inventoryItemId, $householdId]);
            if (!$statement->fetchColumn()) {
                throw new RuntimeException('The selected inventory item does not belong to this household.');
            }
        }

        $sort = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM recipe_ingredients WHERE recipe_id = ?');
        $sort->execute([$recipe['id']]);

        $statement = $this->pdo->prepare(
            'INSERT INTO recipe_ingredients (recipe_id, inventory_item_id, ingredient_name, quantity, unit, optional, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $recipeId,
            $inventoryItemId,
            $name,
            $quantity,
            $unit,
            !empty($data['optional']) ? 1 : 0,
            (int)$sort->fetchColumn(),
        ]);
    }

    public function createMealPlan(int $householdId, string $name, string $startsOn, string $endsOn): int
    {
        if (trim($name) === '' || $startsOn === '' || $endsOn === '' || $endsOn < $startsOn) {
            throw new InvalidArgumentException('Enter a valid meal-plan name and date range.');
        }
        $statement = $this->pdo->prepare("INSERT INTO meal_plans (household_id, name, starts_on, ends_on, status) VALUES (?, ?, ?, ?, 'active')");
        $statement->execute([$householdId, trim($name), $startsOn, $endsOn]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addMeal(int $householdId, array $data): int
    {
        $planId = (int)($data['meal_plan_id'] ?? 0);
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $memberIds = array_values(array_unique(array_map('intval', (array)($data['member_ids'] ?? []))));
        if ($planId < 1 || $recipeId < 1 || $memberIds === []) {
            throw new InvalidArgumentException('Choose a meal plan, recipe, and at least one family member.');
        }

        $plan = $this->pdo->prepare('SELECT id FROM meal_plans WHERE id = ? AND household_id = ? LIMIT 1');
        $plan->execute([$planId, $householdId]);
        if (!$plan->fetchColumn()) {
            throw new RuntimeException('Meal plan not found.');
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
            $statement = $this->pdo->prepare(
                "INSERT INTO meal_plan_items (meal_plan_id, recipe_id, meal_date, meal_type, planned_servings, participating_member_ids, notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'planned')"
            );
            $statement->execute([
                $planId,
                $recipeId,
                (string)$data['meal_date'],
                (string)$data['meal_type'],
                $plannedServings,
                json_encode($memberIds),
                trim((string)($data['notes'] ?? '')) ?: null,
            ]);
            $mealItemId = (int)$this->pdo->lastInsertId();

            $insertMember = $this->pdo->prepare(
                'INSERT INTO meal_plan_members (meal_plan_item_id, household_member_id, serving_multiplier_snapshot, expected_servings) VALUES (?, ?, ?, ?)'
            );
            foreach ($members as $member) {
                $multiplier = (float)$member['serving_multiplier'];
                $insertMember->execute([$mealItemId, $member['id'], $multiplier, $multiplier]);
            }
            $this->pdo->commit();
            return $mealItemId;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function completeRecipe(int $householdId, int $memberId, array $data): int
    {
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $recipe = $this->ownedRecipe($householdId, $recipeId);
        $scale = (float)($data['scale_factor'] ?? 1);
        $actualServings = (float)($data['actual_servings'] ?? 0);
        $locationId = (int)($data['storage_location_id'] ?? 0) ?: null;
        $useByDate = trim((string)($data['use_by_date'] ?? '')) ?: null;
        $storageMethod = (string)($data['storage_method'] ?? 'refrigerated');
        $memberIds = array_values(array_unique(array_map('intval', (array)($data['intended_member_ids'] ?? []))));

        if ($scale <= 0 || $actualServings <= 0) {
            throw new InvalidArgumentException('Scale and actual servings must be greater than zero.');
        }

        $ingredients = $this->pdo->prepare(
            'SELECT ri.*, ii.current_quantity, ii.household_id AS item_household_id, ii.storage_location_id
             FROM recipe_ingredients ri
             LEFT JOIN inventory_items ii ON ii.id = ri.inventory_item_id
             WHERE ri.recipe_id = ? ORDER BY ri.sort_order, ri.id'
        );
        $ingredients->execute([$recipeId]);
        $rows = $ingredients->fetchAll();
        if ($rows === []) {
            throw new RuntimeException('Add at least one ingredient before completing this recipe.');
        }

        foreach ($rows as $ingredient) {
            $required = (float)$ingredient['quantity'] * $scale;
            if ((int)$ingredient['optional'] === 0 && empty($ingredient['inventory_item_id'])) {
                throw new RuntimeException('Required ingredient "' . $ingredient['ingredient_name'] . '" is not linked to inventory.');
            }
            if (!empty($ingredient['inventory_item_id'])) {
                if ((int)$ingredient['item_household_id'] !== $householdId) {
                    throw new RuntimeException('An ingredient is linked outside this household.');
                }
                if ((float)$ingredient['current_quantity'] + 0.00001 < $required && (int)$ingredient['optional'] === 0) {
                    throw new RuntimeException('Not enough ' . $ingredient['ingredient_name'] . ' in inventory.');
                }
            }
        }

        $this->pdo->beginTransaction();
        try {
            $runInsert = $this->pdo->prepare(
                "INSERT INTO recipe_runs (household_id, recipe_id, prepared_by_member_id, scale_factor, planned_servings, actual_servings, status, started_at, completed_at, notes)
                 VALUES (?, ?, ?, ?, ?, ?, 'completed', UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)"
            );
            $runInsert->execute([
                $householdId,
                $recipeId,
                $memberId,
                $scale,
                (float)$recipe['servings'] * $scale,
                $actualServings,
                trim((string)($data['notes'] ?? '')) ?: null,
            ]);
            $runId = (int)$this->pdo->lastInsertId();

            $runIngredient = $this->pdo->prepare(
                "INSERT INTO recipe_run_ingredients (recipe_run_id, recipe_ingredient_id, inventory_item_id, required_quantity, consumed_quantity, unit, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $updateInventory = $this->pdo->prepare('UPDATE inventory_items SET current_quantity = current_quantity - ? WHERE id = ? AND household_id = ?');
            $ledger = $this->pdo->prepare(
                "INSERT INTO food_ledger_events (household_id, inventory_item_id, member_id, event_type, quantity, unit, source_location_id, related_type, related_id, notes)
                 VALUES (?, ?, ?, 'used_in_recipe', ?, ?, ?, 'recipe_run', ?, ?)"
            );

            foreach ($rows as $ingredient) {
                $required = round((float)$ingredient['quantity'] * $scale, 4);
                $consumed = 0.0;
                $status = 'missing';
                if (!empty($ingredient['inventory_item_id'])) {
                    $available = (float)$ingredient['current_quantity'];
                    $consumed = min($available, $required);
                    $status = $consumed >= $required ? 'consumed' : 'missing';
                    if ($consumed > 0) {
                        $updateInventory->execute([$consumed, $ingredient['inventory_item_id'], $householdId]);
                        $ledger->execute([
                            $householdId,
                            $ingredient['inventory_item_id'],
                            $memberId,
                            -$consumed,
                            $ingredient['unit'],
                            $ingredient['storage_location_id'],
                            $runId,
                            'Used for ' . $recipe['name'],
                        ]);
                    }
                }
                $runIngredient->execute([$runId, $ingredient['id'], $ingredient['inventory_item_id'], $required, $consumed, $ingredient['unit'], $status]);
            }

            $category = $this->pdo->prepare("SELECT id FROM inventory_categories WHERE (household_id = ? OR household_id IS NULL) AND category_type = 'prepared_food' ORDER BY household_id DESC LIMIT 1");
            $category->execute([$householdId]);
            $categoryId = (int)$category->fetchColumn() ?: null;

            $itemInsert = $this->pdo->prepare(
                "INSERT INTO inventory_items (household_id, category_id, storage_location_id, name, item_type, current_quantity, unit, best_use_date, status, metadata, notes)
                 VALUES (?, ?, ?, ?, 'prepared_food', ?, 'servings', ?, 'active', ?, ?)"
            );
            $itemInsert->execute([
                $householdId,
                $categoryId,
                $locationId,
                $recipe['name'],
                $actualServings,
                $useByDate,
                json_encode(['recipe_run_id' => $runId, 'storage_method' => $storageMethod]),
                trim((string)($data['reheating_notes'] ?? '')) ?: null,
            ]);
            $inventoryItemId = (int)$this->pdo->lastInsertId();

            $batch = $this->pdo->prepare(
                "INSERT INTO prepared_food_batches
                 (household_id, recipe_run_id, inventory_item_id, prepared_by_member_id, name, servings_produced, servings_remaining, storage_location_id, use_by_date, storage_method, reheating_notes, intended_member_ids)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $batch->execute([
                $householdId,
                $runId,
                $inventoryItemId,
                $memberId,
                $recipe['name'],
                $actualServings,
                $actualServings,
                $locationId,
                $useByDate,
                $storageMethod,
                trim((string)($data['reheating_notes'] ?? '')) ?: null,
                json_encode($memberIds),
            ]);
            $batchId = (int)$this->pdo->lastInsertId();

            $eventType = $storageMethod === 'frozen' ? 'frozen' : ($storageMethod === 'refrigerated' ? 'refrigerated' : 'prepared');
            $createLedger = $this->pdo->prepare(
                'INSERT INTO food_ledger_events (household_id, inventory_item_id, member_id, event_type, quantity, unit, destination_location_id, related_type, related_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
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

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float)$value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : max(0, (int)$value);
    }
}
