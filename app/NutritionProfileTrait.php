<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use Throwable;

trait NutritionProfileTrait
{
    public function settings(int $householdId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT h.currency_code, ns.*
             FROM households h
             LEFT JOIN household_nutrition_settings ns ON ns.household_id = h.id
             WHERE h.id = ? LIMIT 1'
        );
        $statement->execute([$householdId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('Household nutrition settings are unavailable.');
        }
        return [
            'household_id' => $householdId,
            'assessment_window_days' => (int)($row['assessment_window_days'] ?? 7),
            'minimum_recipe_variety' => (int)($row['minimum_recipe_variety'] ?? 5),
            'minimum_data_completeness_percent' => (float)($row['minimum_data_completeness_percent'] ?? 80),
            'show_optional_targets' => (int)($row['show_optional_targets'] ?? 1),
            'currency_code' => (string)($row['currency_code'] ?? 'USD'),
        ];
    }

    public function saveSettings(int $householdId, int $memberId, array $input): void
    {
        $this->assertActiveMember($householdId, $memberId);
        $window = $this->positiveInt($input['assessment_window_days'] ?? 7, 'Assessment window');
        if ($window > 31) {
            throw new InvalidArgumentException('Assessment window cannot exceed 31 days.');
        }
        $variety = $this->positiveInt($input['minimum_recipe_variety'] ?? 5, 'Minimum recipe variety');
        if ($variety > 100) {
            throw new InvalidArgumentException('Minimum recipe variety cannot exceed 100.');
        }
        $completeness = $this->decimal(
            $input['minimum_data_completeness_percent'] ?? 80,
            0,
            100,
            'Minimum data completeness'
        );
        $showTargets = !empty($input['show_optional_targets']) ? 1 : 0;

        $statement = $this->pdo->prepare(
            'INSERT INTO household_nutrition_settings
             (household_id, assessment_window_days, minimum_recipe_variety,
              minimum_data_completeness_percent, show_optional_targets, updated_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 assessment_window_days = VALUES(assessment_window_days),
                 minimum_recipe_variety = VALUES(minimum_recipe_variety),
                 minimum_data_completeness_percent = VALUES(minimum_data_completeness_percent),
                 show_optional_targets = VALUES(show_optional_targets),
                 updated_by_member_id = VALUES(updated_by_member_id)'
        );
        $statement->execute([$householdId, $window, $variety, $completeness, $showTargets, $memberId]);
    }

    public function saveMemberProfile(int $householdId, int $actorMemberId, array $input): void
    {
        $this->assertActiveMember($householdId, $actorMemberId);
        $memberId = $this->positiveInt($input['household_member_id'] ?? null, 'Household member');
        $this->assertActiveMember($householdId, $memberId);
        $dietaryPattern = $this->nullableText($input['dietary_pattern'] ?? null, 120, 'Dietary pattern');
        $calorieTarget = $this->nullableDecimal($input['calorie_target'] ?? null, 0, 20000, 'Calorie target');
        $proteinTarget = $this->nullableDecimal($input['protein_target_g'] ?? null, 0, 1000, 'Protein target');
        $fiberTarget = $this->nullableDecimal($input['fiber_target_g'] ?? null, 0, 500, 'Fiber target');
        $sodiumLimit = $this->nullableDecimal($input['sodium_limit_mg'] ?? null, 0, 100000, 'Sodium limit');
        $sugarLimit = $this->nullableDecimal($input['added_sugar_limit_g'] ?? null, 0, 1000, 'Added sugar limit');
        $notes = $this->nullableText($input['target_notes'] ?? null, 5000, 'Target notes');

        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $statement = $this->pdo->prepare(
                'INSERT INTO member_nutrition_profiles
                 (household_id, household_member_id, dietary_pattern, calorie_target,
                  protein_target_g, fiber_target_g, sodium_limit_mg, added_sugar_limit_g,
                  target_notes, updated_by_member_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     dietary_pattern = VALUES(dietary_pattern),
                     calorie_target = VALUES(calorie_target),
                     protein_target_g = VALUES(protein_target_g),
                     fiber_target_g = VALUES(fiber_target_g),
                     sodium_limit_mg = VALUES(sodium_limit_mg),
                     added_sugar_limit_g = VALUES(added_sugar_limit_g),
                     target_notes = VALUES(target_notes),
                     updated_by_member_id = VALUES(updated_by_member_id)'
            );
            $statement->execute([
                $householdId,
                $memberId,
                $dietaryPattern,
                $calorieTarget,
                $proteinTarget,
                $fiberTarget,
                $sodiumLimit,
                $sugarLimit,
                $notes,
                $actorMemberId,
            ]);
            $this->pdo->prepare(
                'UPDATE household_members SET dietary_pattern = ? WHERE id = ? AND household_id = ?'
            )->execute([$dietaryPattern, $memberId, $householdId]);
            $this->recordNutritionEvent(
                $householdId,
                null,
                null,
                null,
                $actorMemberId,
                'member_profile_updated',
                null,
                null,
                'Updated planning targets for household member #' . $memberId . '.'
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function saveMemberAllergenRule(int $householdId, int $actorMemberId, array $input): void
    {
        $this->assertActiveMember($householdId, $actorMemberId);
        $memberId = $this->positiveInt($input['household_member_id'] ?? null, 'Household member');
        $this->assertActiveMember($householdId, $memberId);
        $key = $this->allergenKey($input['allergen_key'] ?? null);
        $severity = $this->choice($input['severity'] ?? 'preference', ['preference', 'intolerance', 'allergy'], 'Severity');
        $notes = $this->nullableText($input['notes'] ?? null, 500, 'Allergen notes');
        $active = array_key_exists('active', $input) ? (!empty($input['active']) ? 1 : 0) : 1;

        $statement = $this->pdo->prepare(
            'INSERT INTO member_allergen_rules
             (household_id, household_member_id, allergen_key, severity, notes, active, updated_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 severity = VALUES(severity), notes = VALUES(notes), active = VALUES(active),
                 updated_by_member_id = VALUES(updated_by_member_id)'
        );
        $statement->execute([$householdId, $memberId, $key, $severity, $notes, $active, $actorMemberId]);
    }

    public function saveInventoryNutrition(int $householdId, int $memberId, array $input): void
    {
        $this->assertActiveMember($householdId, $memberId);
        $itemId = $this->positiveInt($input['inventory_item_id'] ?? null, 'Inventory item');
        $item = $this->assertInventoryItem($householdId, $itemId);
        $basisQuantity = $this->decimal($input['basis_quantity'] ?? 1, 0.0001, 999999999, 'Basis quantity');
        $basisUnit = $this->text($input['basis_unit'] ?? $item['unit'], 30, 'Basis unit');
        $confidence = $this->choice($input['confidence'] ?? 'estimated', ['estimated', 'label', 'verified'], 'Confidence');
        $sourceLabel = $this->nullableText($input['source_label'] ?? null, 190, 'Source label');

        $fields = [
            'calories' => [0, 1000000, 'Calories'],
            'protein_g' => [0, 100000, 'Protein'],
            'carbohydrate_g' => [0, 100000, 'Carbohydrate'],
            'fat_g' => [0, 100000, 'Fat'],
            'fiber_g' => [0, 100000, 'Fiber'],
            'total_sugar_g' => [0, 100000, 'Total sugar'],
            'added_sugar_g' => [0, 100000, 'Added sugar'],
            'sodium_mg' => [0, 100000000, 'Sodium'],
        ];
        $values = [];
        foreach ($fields as $name => [$min, $max, $label]) {
            $values[$name] = $this->nullableDecimal($input[$name] ?? null, $min, $max, $label);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO inventory_nutrition_profiles
             (household_id, inventory_item_id, basis_quantity, basis_unit, calories, protein_g,
              carbohydrate_g, fat_g, fiber_g, total_sugar_g, added_sugar_g, sodium_mg,
              source_label, confidence, updated_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 basis_quantity = VALUES(basis_quantity), basis_unit = VALUES(basis_unit),
                 calories = VALUES(calories), protein_g = VALUES(protein_g),
                 carbohydrate_g = VALUES(carbohydrate_g), fat_g = VALUES(fat_g),
                 fiber_g = VALUES(fiber_g), total_sugar_g = VALUES(total_sugar_g),
                 added_sugar_g = VALUES(added_sugar_g), sodium_mg = VALUES(sodium_mg),
                 source_label = VALUES(source_label), confidence = VALUES(confidence),
                 updated_by_member_id = VALUES(updated_by_member_id)'
        );
        $statement->execute([
            $householdId,
            $itemId,
            $basisQuantity,
            $basisUnit,
            $values['calories'],
            $values['protein_g'],
            $values['carbohydrate_g'],
            $values['fat_g'],
            $values['fiber_g'],
            $values['total_sugar_g'],
            $values['added_sugar_g'],
            $values['sodium_mg'],
            $sourceLabel,
            $confidence,
            $memberId,
        ]);
    }

    public function saveInventoryAllergenTag(int $householdId, int $memberId, array $input): void
    {
        $this->assertActiveMember($householdId, $memberId);
        $itemId = $this->positiveInt($input['inventory_item_id'] ?? null, 'Inventory item');
        $this->assertInventoryItem($householdId, $itemId);
        $key = $this->allergenKey($input['allergen_key'] ?? null);
        $presence = $this->choice(
            $input['presence'] ?? 'contains',
            ['contains', 'may_contain', 'shared_facility'],
            'Allergen presence'
        );
        $sourceLabel = $this->nullableText($input['source_label'] ?? null, 190, 'Source label');
        $active = array_key_exists('active', $input) ? (!empty($input['active']) ? 1 : 0) : 1;

        $statement = $this->pdo->prepare(
            'INSERT INTO inventory_allergen_tags
             (household_id, inventory_item_id, allergen_key, presence, source_label, active, updated_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 presence = VALUES(presence), source_label = VALUES(source_label),
                 active = VALUES(active), updated_by_member_id = VALUES(updated_by_member_id)'
        );
        $statement->execute([$householdId, $itemId, $key, $presence, $sourceLabel, $active, $memberId]);
    }
}