<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

trait NutritionSupportTrait
{
    private function assertActiveMember(int $householdId, int $memberId): void
    {
        if ($householdId < 1 || $memberId < 1) {
            throw new InvalidArgumentException('A valid household member is required.');
        }
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM household_members
             WHERE id = ? AND household_id = ? AND status = 'active'"
        );
        $statement->execute([$memberId, $householdId]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new InvalidArgumentException('The household member is not active in this household.');
        }
    }

    private function lockHousehold(int $householdId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM households WHERE id = ? FOR UPDATE');
        $statement->execute([$householdId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('The household is unavailable.');
        }
    }

    private function assertInventoryItem(int $householdId, int $itemId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, unit, status FROM inventory_items WHERE id = ? AND household_id = ? LIMIT 1'
        );
        $statement->execute([$itemId, $householdId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('Inventory item was not found in this household.');
        }
        return $row;
    }

    private function assertRecipe(int $householdId, int $recipeId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, servings, status FROM recipes WHERE id = ? AND household_id = ? LIMIT 1'
        );
        $statement->execute([$recipeId, $householdId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('Recipe was not found in this household.');
        }
        return $row;
    }

    private function assertMealPlan(int $householdId, int $mealPlanId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, starts_on, ends_on, status FROM meal_plans
             WHERE id = ? AND household_id = ? LIMIT 1'
        );
        $statement->execute([$mealPlanId, $householdId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('Meal plan was not found in this household.');
        }
        return $row;
    }

    private function sourceWatermark(int $householdId, int $mealPlanId): string
    {
        $queries = [
            'plan' => [
                'SELECT COUNT(*), COALESCE(MAX(created_at), "1970-01-01"), 0
                 FROM meal_plans WHERE id = ? AND household_id = ?',
                [$mealPlanId, $householdId],
            ],
            'items' => [
                'SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(MAX(meal_date), "1970-01-01")
                 FROM meal_plan_items WHERE meal_plan_id = ?',
                [$mealPlanId],
            ],
            'members' => [
                'SELECT COUNT(*), COALESCE(MAX(mpm.id), 0), COALESCE(MAX(mpm.expected_servings), 0)
                 FROM meal_plan_members mpm
                 JOIN meal_plan_items mpi ON mpi.id = mpm.meal_plan_item_id
                 WHERE mpi.meal_plan_id = ?',
                [$mealPlanId],
            ],
            'profiles' => [
                'SELECT COUNT(*), COALESCE(MAX(updated_at), "1970-01-01"), 0
                 FROM member_nutrition_profiles WHERE household_id = ?',
                [$householdId],
            ],
            'allergens' => [
                'SELECT COUNT(*), COALESCE(MAX(updated_at), "1970-01-01"), 0
                 FROM member_allergen_rules WHERE household_id = ? AND active = 1',
                [$householdId],
            ],
            'recipe_snapshots' => [
                'SELECT COUNT(*), COALESCE(MAX(rns.id), 0), COALESCE(MAX(rns.created_at), "1970-01-01")
                 FROM recipe_nutrition_snapshots rns
                 JOIN meal_plan_items mpi ON mpi.recipe_id = rns.recipe_id
                 WHERE rns.household_id = ? AND mpi.meal_plan_id = ?',
                [$householdId, $mealPlanId],
            ],
            'settings' => [
                'SELECT COUNT(*), COALESCE(MAX(updated_at), "1970-01-01"), 0
                 FROM household_nutrition_settings WHERE household_id = ?',
                [$householdId],
            ],
        ];

        $parts = [];
        foreach ($queries as $name => [$sql, $params]) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $parts[$name] = $statement->fetch(PDO::FETCH_NUM) ?: [];
        }
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function recordNutritionEvent(
        int $householdId,
        ?int $assessmentId,
        ?int $recipeSnapshotId,
        ?int $recommendationId,
        ?int $memberId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $notes
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO nutrition_lifecycle_events
             (household_id, assessment_id, recipe_nutrition_snapshot_id, recommendation_id,
              member_id, event_type, from_status, to_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $householdId,
            $assessmentId,
            $recipeSnapshotId,
            $recommendationId,
            $memberId,
            $eventType,
            $fromStatus,
            $toStatus,
            $notes,
        ]);
    }

    private function date(mixed $value, string $label): DateTimeImmutable
    {
        $text = trim((string)$value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException($label . ' must be a valid date.');
        }
        return $date;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($int === false) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return (int)$int;
    }

    private function nullableDecimal(mixed $value, float $min, float $max, string $label): ?float
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->decimal($value, $min, $max, $label);
    }

    private function decimal(mixed $value, float $min, float $max, string $label): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($label . ' must be numeric.');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < $min || $number > $max) {
            throw new InvalidArgumentException($label . ' is outside the allowed range.');
        }
        return $number;
    }

    private function choice(mixed $value, array $allowed, string $label): string
    {
        $choice = trim((string)$value);
        if (!in_array($choice, $allowed, true)) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
        return $choice;
    }

    private function text(mixed $value, int $maxLength, string $label): string
    {
        $text = trim((string)$value);
        if ($text === '' || mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException($label . ' is required and must be at most ' . $maxLength . ' characters.');
        }
        return $text;
    }

    private function nullableText(mixed $value, int $maxLength, string $label): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException($label . ' must be at most ' . $maxLength . ' characters.');
        }
        return $text;
    }

    private function allergenKey(mixed $value): string
    {
        $key = strtolower(trim((string)$value));
        $key = preg_replace('/[^a-z0-9_-]+/', '-', $key) ?? '';
        $key = trim($key, '-');
        if ($key === '' || strlen($key) > 80) {
            throw new InvalidArgumentException('Allergen key is invalid.');
        }
        return $key;
    }

    private function percentage(?float $actual, ?float $target): ?float
    {
        if ($actual === null || $target === null || $target <= 0) {
            return null;
        }
        return round(($actual / $target) * 100, 2);
    }
}