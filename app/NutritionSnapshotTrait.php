<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use PDO;
use Throwable;

trait NutritionSnapshotTrait
{
    public function calculateRecipeNutrition(
        int $householdId,
        int $memberId,
        int $recipeId,
        string $asOfDate
    ): array {
        $this->assertActiveMember($householdId, $memberId);
        $recipe = $this->assertRecipe($householdId, $recipeId);
        $date = $this->date($asOfDate, 'Nutrition date')->format('Y-m-d');
        $servings = max(0.01, (float)$recipe['servings']);

        $ingredientStatement = $this->pdo->prepare(
            'SELECT ri.id, ri.inventory_item_id, ri.ingredient_name, ri.quantity, ri.unit,
                    inp.basis_quantity, inp.basis_unit, inp.calories, inp.protein_g,
                    inp.carbohydrate_g, inp.fat_g, inp.fiber_g, inp.total_sugar_g,
                    inp.added_sugar_g, inp.sodium_mg, inp.updated_at AS nutrition_updated_at
             FROM recipe_ingredients ri
             JOIN recipes r ON r.id = ri.recipe_id
             LEFT JOIN inventory_nutrition_profiles inp
               ON inp.inventory_item_id = ri.inventory_item_id AND inp.household_id = r.household_id
             WHERE ri.recipe_id = ? AND r.household_id = ?
             ORDER BY ri.sort_order, ri.id'
        );
        $ingredientStatement->execute([$recipeId, $householdId]);
        $ingredients = $ingredientStatement->fetchAll();
        if ($ingredients === []) {
            throw new InvalidArgumentException('The recipe must have at least one ingredient.');
        }

        $tagStatement = $this->pdo->prepare(
            'SELECT iat.inventory_item_id, iat.allergen_key
             FROM inventory_allergen_tags iat
             JOIN recipe_ingredients ri ON ri.inventory_item_id = iat.inventory_item_id
             JOIN recipes r ON r.id = ri.recipe_id
             WHERE r.id = ? AND r.household_id = ? AND iat.household_id = ? AND iat.active = 1
             ORDER BY iat.inventory_item_id, iat.allergen_key'
        );
        $tagStatement->execute([$recipeId, $householdId, $householdId]);
        $tagsByItem = [];
        foreach ($tagStatement->fetchAll() as $tag) {
            $tagsByItem[(int)$tag['inventory_item_id']][] = (string)$tag['allergen_key'];
        }

        $fingerprint = [
            'model' => self::MODEL_VERSION,
            'recipe_id' => $recipeId,
            'as_of_date' => $date,
            'servings' => $servings,
            'ingredients' => [],
        ];
        foreach ($ingredients as $ingredient) {
            $itemId = $ingredient['inventory_item_id'] !== null ? (int)$ingredient['inventory_item_id'] : null;
            $fingerprint['ingredients'][] = [
                'id' => (int)$ingredient['id'],
                'item_id' => $itemId,
                'quantity' => (string)$ingredient['quantity'],
                'unit' => (string)$ingredient['unit'],
                'basis_quantity' => $ingredient['basis_quantity'],
                'basis_unit' => $ingredient['basis_unit'],
                'nutrition_updated_at' => $ingredient['nutrition_updated_at'],
                'allergens' => $itemId !== null ? ($tagsByItem[$itemId] ?? []) : [],
            ];
        }
        $calculationKey = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));

        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $existing = $this->pdo->prepare(
                'SELECT id, total_calories, calories_per_serving, protein_per_serving_g,
                        fiber_per_serving_g, sodium_per_serving_mg, missing_profile_count,
                        unit_mismatch_count
                 FROM recipe_nutrition_snapshots
                 WHERE household_id = ? AND calculation_key = ? FOR UPDATE'
            );
            $existing->execute([$householdId, $calculationKey]);
            $existingRow = $existing->fetch();
            if (is_array($existingRow)) {
                $this->pdo->commit();
                return [
                    'snapshot_id' => (int)$existingRow['id'],
                    'total_calories' => (float)$existingRow['total_calories'],
                    'calories_per_serving' => (float)($existingRow['calories_per_serving'] ?? 0),
                    'protein_per_serving_g' => (float)($existingRow['protein_per_serving_g'] ?? 0),
                    'fiber_per_serving_g' => (float)($existingRow['fiber_per_serving_g'] ?? 0),
                    'sodium_per_serving_mg' => (float)($existingRow['sodium_per_serving_mg'] ?? 0),
                    'missing_profiles' => (int)$existingRow['missing_profile_count'],
                    'unit_mismatches' => (int)$existingRow['unit_mismatch_count'],
                    'reused' => true,
                ];
            }

            $totals = [
                'calories' => 0.0,
                'protein_g' => 0.0,
                'carbohydrate_g' => 0.0,
                'fat_g' => 0.0,
                'fiber_g' => 0.0,
                'total_sugar_g' => 0.0,
                'added_sugar_g' => 0.0,
                'sodium_mg' => 0.0,
            ];
            $calculatedCount = 0;
            $missingCount = 0;
            $mismatchCount = 0;
            $allergenKeys = [];
            $lines = [];

            foreach ($ingredients as $ingredient) {
                $itemId = $ingredient['inventory_item_id'] !== null ? (int)$ingredient['inventory_item_id'] : null;
                $lineTags = $itemId !== null ? array_values(array_unique($tagsByItem[$itemId] ?? [])) : [];
                $allergenKeys = array_merge($allergenKeys, $lineTags);
                $status = 'calculated';
                $lineValues = [
                    'calories' => null,
                    'protein_g' => null,
                    'fiber_g' => null,
                    'sodium_mg' => null,
                ];

                if ($itemId === null || $ingredient['basis_quantity'] === null) {
                    $status = 'missing_profile';
                    $missingCount++;
                } elseif ((string)$ingredient['basis_unit'] !== (string)$ingredient['unit']) {
                    $status = 'unit_mismatch';
                    $mismatchCount++;
                } else {
                    $ratio = (float)$ingredient['quantity'] / max(0.0001, (float)$ingredient['basis_quantity']);
                    foreach (array_keys($totals) as $field) {
                        $value = $ingredient[$field] !== null ? (float)$ingredient[$field] * $ratio : 0.0;
                        $totals[$field] += $value;
                    }
                    $lineValues = [
                        'calories' => (float)($ingredient['calories'] ?? 0) * $ratio,
                        'protein_g' => (float)($ingredient['protein_g'] ?? 0) * $ratio,
                        'fiber_g' => (float)($ingredient['fiber_g'] ?? 0) * $ratio,
                        'sodium_mg' => (float)($ingredient['sodium_mg'] ?? 0) * $ratio,
                    ];
                    $calculatedCount++;
                }

                $lines[] = [
                    'ingredient' => $ingredient,
                    'status' => $status,
                    'values' => $lineValues,
                    'allergens' => $lineTags,
                ];
            }

            $allergenKeys = array_values(array_unique($allergenKeys));
            sort($allergenKeys);
            $perServing = [
                'calories' => $totals['calories'] / $servings,
                'protein_g' => $totals['protein_g'] / $servings,
                'fiber_g' => $totals['fiber_g'] / $servings,
                'sodium_mg' => $totals['sodium_mg'] / $servings,
            ];

            $insert = $this->pdo->prepare(
                'INSERT INTO recipe_nutrition_snapshots
                 (household_id, recipe_id, calculation_key, as_of_date, servings,
                  total_calories, total_protein_g, total_carbohydrate_g, total_fat_g,
                  total_fiber_g, total_sugar_g, total_added_sugar_g, total_sodium_mg,
                  calories_per_serving, protein_per_serving_g, fiber_per_serving_g,
                  sodium_per_serving_mg, priced_ingredient_count, missing_profile_count,
                  unit_mismatch_count, allergen_keys, generated_by_member_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $householdId,
                $recipeId,
                $calculationKey,
                $date,
                $servings,
                round($totals['calories'], 4),
                round($totals['protein_g'], 4),
                round($totals['carbohydrate_g'], 4),
                round($totals['fat_g'], 4),
                round($totals['fiber_g'], 4),
                round($totals['total_sugar_g'], 4),
                round($totals['added_sugar_g'], 4),
                round($totals['sodium_mg'], 4),
                round($perServing['calories'], 4),
                round($perServing['protein_g'], 4),
                round($perServing['fiber_g'], 4),
                round($perServing['sodium_mg'], 4),
                $calculatedCount,
                $missingCount,
                $mismatchCount,
                json_encode($allergenKeys, JSON_THROW_ON_ERROR),
                $memberId,
            ]);
            $snapshotId = (int)$this->pdo->lastInsertId();

            $lineInsert = $this->pdo->prepare(
                'INSERT INTO recipe_nutrition_snapshot_lines
                 (recipe_nutrition_snapshot_id, household_id, recipe_ingredient_id,
                  inventory_item_id, ingredient_name_snapshot, quantity, unit,
                  calculation_status, calories, protein_g, fiber_g, sodium_mg, allergen_keys)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($lines as $line) {
                $ingredient = $line['ingredient'];
                $lineInsert->execute([
                    $snapshotId,
                    $householdId,
                    (int)$ingredient['id'],
                    $ingredient['inventory_item_id'] !== null ? (int)$ingredient['inventory_item_id'] : null,
                    (string)$ingredient['ingredient_name'],
                    (float)$ingredient['quantity'],
                    (string)$ingredient['unit'],
                    $line['status'],
                    $line['values']['calories'] !== null ? round((float)$line['values']['calories'], 4) : null,
                    $line['values']['protein_g'] !== null ? round((float)$line['values']['protein_g'], 4) : null,
                    $line['values']['fiber_g'] !== null ? round((float)$line['values']['fiber_g'], 4) : null,
                    $line['values']['sodium_mg'] !== null ? round((float)$line['values']['sodium_mg'], 4) : null,
                    json_encode($line['allergens'], JSON_THROW_ON_ERROR),
                ]);
            }

            $this->recordNutritionEvent(
                $householdId,
                null,
                $snapshotId,
                null,
                $memberId,
                'recipe_nutrition_calculated',
                null,
                'completed',
                sprintf('Calculated %s with %d missing profiles and %d unit mismatches.', $recipe['name'], $missingCount, $mismatchCount)
            );
            $this->pdo->commit();

            return [
                'snapshot_id' => $snapshotId,
                'total_calories' => round($totals['calories'], 4),
                'calories_per_serving' => round($perServing['calories'], 4),
                'protein_per_serving_g' => round($perServing['protein_g'], 4),
                'fiber_per_serving_g' => round($perServing['fiber_g'], 4),
                'sodium_per_serving_mg' => round($perServing['sodium_mg'], 4),
                'missing_profiles' => $missingCount,
                'unit_mismatches' => $mismatchCount,
                'reused' => false,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function runMealAssessment(int $householdId, int $memberId, int $mealPlanId): array
    {
        $this->assertActiveMember($householdId, $memberId);
        $plan = $this->assertMealPlan($householdId, $mealPlanId);
        $startsOn = $this->date($plan['starts_on'], 'Meal plan start');
        $endsOn = $this->date($plan['ends_on'], 'Meal plan end');
        if ($endsOn < $startsOn) {
            throw new InvalidArgumentException('Meal plan dates are invalid.');
        }
        $days = (int)$startsOn->diff($endsOn)->format('%a') + 1;
        if ($days > 62) {
            throw new InvalidArgumentException('Nutrition assessments are limited to 62-day meal plans.');
        }

        $watermark = $this->sourceWatermark($householdId, $mealPlanId);
        $runKey = hash('sha256', implode('|', [self::MODEL_VERSION, $householdId, $mealPlanId, $watermark]));
        $settings = $this->settings($householdId);

        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $existing = $this->pdo->prepare(
                'SELECT * FROM meal_nutrition_assessments
                 WHERE household_id = ? AND run_key = ? FOR UPDATE'
            );
            $existing->execute([$householdId, $runKey]);
            $existingRow = $existing->fetch();
            if (is_array($existingRow) && (string)$existingRow['status'] === 'completed') {
                $this->pdo->commit();
                return [
                    'assessment_id' => (int)$existingRow['id'],
                    'household_balance_score' => (float)$existingRow['household_balance_score'],
                    'data_completeness_percent' => (float)$existingRow['data_completeness_percent'],
                    'allergen_conflict_count' => (int)$existingRow['allergen_conflict_count'],
                    'recommendation_count' => (int)$existingRow['recommendation_count'],
                    'reused' => true,
                ];
            }

            $insertAssessment = $this->pdo->prepare(
                'INSERT INTO meal_nutrition_assessments
                 (household_id, meal_plan_id, run_key, source_watermark, model_version,
                  starts_on, ends_on, generated_by_member_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertAssessment->execute([
                $householdId,
                $mealPlanId,
                $runKey,
                $watermark,
                self::MODEL_VERSION,
                $startsOn->format('Y-m-d'),
                $endsOn->format('Y-m-d'),
                $memberId,
            ]);
            $assessmentId = (int)$this->pdo->lastInsertId();

            $memberStatement = $this->pdo->prepare(
                'SELECT hm.id, hm.display_name, hm.serving_multiplier,
                        mnp.calorie_target, mnp.protein_target_g, mnp.fiber_target_g,
                        mnp.sodium_limit_mg, mnp.added_sugar_limit_g
                 FROM household_members hm
                 LEFT JOIN member_nutrition_profiles mnp
                   ON mnp.household_id = hm.household_id AND mnp.household_member_id = hm.id
                 WHERE hm.household_id = ? AND hm.status = "active"
                 ORDER BY hm.id'
            );
            $memberStatement->execute([$householdId]);
            $members = $memberStatement->fetchAll();
            if ($members === []) {
                throw new InvalidArgumentException('At least one active household member is required.');
            }

            $itemStatement = $this->pdo->prepare(
                'SELECT mpi.id, mpi.recipe_id, mpi.meal_date, mpi.meal_type, mpi.planned_servings,
                        r.name AS recipe_name
                 FROM meal_plan_items mpi
                 LEFT JOIN recipes r ON r.id = mpi.recipe_id
                 WHERE mpi.meal_plan_id = ?
                 ORDER BY mpi.meal_date, FIELD(mpi.meal_type, "breakfast", "lunch", "dinner", "snack"), mpi.id'
            );
            $itemStatement->execute([$mealPlanId]);
            $items = $itemStatement->fetchAll();

            $assignmentStatement = $this->pdo->prepare(
                'SELECT mpm.meal_plan_item_id, mpm.household_member_id, mpm.expected_servings
                 FROM meal_plan_members mpm
                 JOIN meal_plan_items mpi ON mpi.id = mpm.meal_plan_item_id
                 WHERE mpi.meal_plan_id = ?'
            );
            $assignmentStatement->execute([$mealPlanId]);
            $assignments = [];
            foreach ($assignmentStatement->fetchAll() as $assignment) {
                $assignments[(int)$assignment['meal_plan_item_id']][(int)$assignment['household_member_id']]
                    = (float)$assignment['expected_servings'];
            }

            $allergenStatement = $this->pdo->prepare(
                'SELECT household_member_id, allergen_key, severity
                 FROM member_allergen_rules
                 WHERE household_id = ? AND active = 1'
            );
            $allergenStatement->execute([$householdId]);
            $rules = [];
            foreach ($allergenStatement->fetchAll() as $rule) {
                $rules[(int)$rule['household_member_id']][(string)$rule['allergen_key']] = (string)$rule['severity'];
            }

            $snapshotStatement = $this->pdo->prepare(
                'SELECT * FROM recipe_nutrition_snapshots
                 WHERE household_id = ? AND recipe_id = ?
                 ORDER BY as_of_date DESC, id DESC LIMIT 1'
            );
            $recipeSnapshots = [];
            foreach ($items as $item) {
                if ($item['recipe_id'] === null) {
                    continue;
                }
                $recipeId = (int)$item['recipe_id'];
                if (array_key_exists($recipeId, $recipeSnapshots)) {
                    continue;
                }
                $snapshotStatement->execute([$householdId, $recipeId]);
                $snapshot = $snapshotStatement->fetch();
                $recipeSnapshots[$recipeId] = is_array($snapshot) ? $snapshot : null;
            }

            $lineInsert = $this->pdo->prepare(
                'INSERT INTO member_nutrition_assessment_lines
                 (meal_nutrition_assessment_id, household_id, household_member_id,
                  planned_meal_count, assessed_meal_count, distinct_recipe_count,
                  total_calories, total_protein_g, total_fiber_g, total_sodium_mg,
                  total_added_sugar_g, calorie_target_coverage_percent,
                  protein_target_coverage_percent, fiber_target_coverage_percent,
                  sodium_limit_usage_percent, allergen_conflict_count,
                  missing_profile_count, balance_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $memberScores = [];
            $totalPlanned = 0;
            $totalAssessed = 0;
            $totalMissing = 0;
            $totalConflicts = 0;
            $recommendationCount = 0;

            foreach ($members as $member) {
                $memberIdValue = (int)$member['id'];
                $planned = 0;
                $assessed = 0;
                $missing = 0;
                $conflicts = 0;
                $distinctRecipes = [];
                $totals = [
                    'calories' => 0.0,
                    'protein_g' => 0.0,
                    'fiber_g' => 0.0,
                    'sodium_mg' => 0.0,
                    'added_sugar_g' => 0.0,
                ];

                foreach ($items as $item) {
                    $itemId = (int)$item['id'];
                    $itemAssignments = $assignments[$itemId] ?? [];
                    if ($itemAssignments !== [] && !array_key_exists($memberIdValue, $itemAssignments)) {
                        continue;
                    }
                    $planned++;
                    $servings = $itemAssignments !== []
                        ? max(0.0, (float)$itemAssignments[$memberIdValue])
                        : max(0.0, (float)$item['planned_servings'] / count($members));
                    if ($item['recipe_id'] === null || $servings <= 0) {
                        $missing++;
                        continue;
                    }
                    $recipeId = (int)$item['recipe_id'];
                    $snapshot = $recipeSnapshots[$recipeId] ?? null;
                    if (!is_array($snapshot)) {
                        $missing++;
                        continue;
                    }
                    $assessed++;
                    $distinctRecipes[$recipeId] = true;
                    $totals['calories'] += (float)($snapshot['calories_per_serving'] ?? 0) * $servings;
                    $totals['protein_g'] += (float)($snapshot['protein_per_serving_g'] ?? 0) * $servings;
                    $totals['fiber_g'] += (float)($snapshot['fiber_per_serving_g'] ?? 0) * $servings;
                    $totals['sodium_mg'] += (float)($snapshot['sodium_per_serving_mg'] ?? 0) * $servings;
                    $totals['added_sugar_g'] += ((float)$snapshot['total_added_sugar_g'] / max(0.01, (float)$snapshot['servings'])) * $servings;

                    $recipeAllergens = json_decode((string)($snapshot['allergen_keys'] ?? '[]'), true);
                    $recipeAllergens = is_array($recipeAllergens) ? array_map('strval', $recipeAllergens) : [];
                    $memberRules = $rules[$memberIdValue] ?? [];
                    foreach ($recipeAllergens as $allergen) {
                        if (isset($memberRules[$allergen])) {
                            $conflicts++;
                        }
                    }
                }

                $calorieCoverage = $this->percentage(
                    $totals['calories'],
                    $member['calorie_target'] !== null ? (float)$member['calorie_target'] * $days : null
                );
                $proteinCoverage = $this->percentage(
                    $totals['protein_g'],
                    $member['protein_target_g'] !== null ? (float)$member['protein_target_g'] * $days : null
                );
                $fiberCoverage = $this->percentage(
                    $totals['fiber_g'],
                    $member['fiber_target_g'] !== null ? (float)$member['fiber_target_g'] * $days : null
                );
                $sodiumUsage = $this->percentage(
                    $totals['sodium_mg'],
                    $member['sodium_limit_mg'] !== null ? (float)$member['sodium_limit_mg'] * $days : null
                );
                $sugarUsage = $this->percentage(
                    $totals['added_sugar_g'],
                    $member['added_sugar_limit_g'] !== null ? (float)$member['added_sugar_limit_g'] * $days : null
                );
                $completeness = $planned > 0 ? ($assessed / $planned) * 100 : 0.0;
                $varietyCoverage = min(100.0, (count($distinctRecipes) / max(1, (int)$settings['minimum_recipe_variety'])) * 100);

                $earned = min(100, $completeness) * 0.40 + $varietyCoverage * 0.20;
                $availableWeight = 0.60;
                if ($calorieCoverage !== null) {
                    $earned += max(0, 100 - abs(100 - min(200, $calorieCoverage))) * 0.10;
                    $availableWeight += 0.10;
                }
                if ($proteinCoverage !== null) {
                    $earned += min(100, $proteinCoverage) * 0.15;
                    $availableWeight += 0.15;
                }
                if ($fiberCoverage !== null) {
                    $earned += min(100, $fiberCoverage) * 0.10;
                    $availableWeight += 0.10;
                }
                if ($sodiumUsage !== null) {
                    $earned += max(0, 100 - max(0, $sodiumUsage - 100)) * 0.05;
                    $availableWeight += 0.05;
                }
                $score = round(min(100, max(0, $earned / $availableWeight)), 2);

                $lineInsert->execute([
                    $assessmentId,
                    $householdId,
                    $memberIdValue,
                    $planned,
                    $assessed,
                    count($distinctRecipes),
                    round($totals['calories'], 4),
                    round($totals['protein_g'], 4),
                    round($totals['fiber_g'], 4),
                    round($totals['sodium_mg'], 4),
                    round($totals['added_sugar_g'], 4),
                    $calorieCoverage,
                    $proteinCoverage,
                    $fiberCoverage,
                    $sodiumUsage,
                    $conflicts,
                    $missing,
                    $score,
                ]);

                $recommendationCount += $this->generateMemberNutritionRecommendations(
                    $householdId,
                    $assessmentId,
                    $member,
                    [
                        'planned' => $planned,
                        'assessed' => $assessed,
                        'missing' => $missing,
                        'conflicts' => $conflicts,
                        'distinct_recipes' => count($distinctRecipes),
                        'completeness' => $completeness,
                        'protein_coverage' => $proteinCoverage,
                        'fiber_coverage' => $fiberCoverage,
                        'sodium_usage' => $sodiumUsage,
                        'sugar_usage' => $sugarUsage,
                    ],
                    $settings,
                    $endsOn->format('Y-m-d')
                );

                $memberScores[] = $score;
                $totalPlanned += $planned;
                $totalAssessed += $assessed;
                $totalMissing += $missing;
                $totalConflicts += $conflicts;
            }

            $householdScore = $memberScores !== [] ? array_sum($memberScores) / count($memberScores) : 0.0;
            $dataCompleteness = $totalPlanned > 0 ? ($totalAssessed / $totalPlanned) * 100 : 0.0;
            $update = $this->pdo->prepare(
                'UPDATE meal_nutrition_assessments
                 SET status = "completed", member_count = ?, planned_meal_count = ?,
                     assessed_meal_count = ?, missing_recipe_profile_count = ?,
                     allergen_conflict_count = ?, recommendation_count = ?,
                     household_balance_score = ?, data_completeness_percent = ?,
                     completed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = "running"'
            );
            $update->execute([
                count($members),
                $totalPlanned,
                $totalAssessed,
                $totalMissing,
                $totalConflicts,
                $recommendationCount,
                round($householdScore, 2),
                round($dataCompleteness, 2),
                $assessmentId,
                $householdId,
            ]);

            $this->recordNutritionEvent(
                $householdId,
                $assessmentId,
                null,
                null,
                $memberId,
                'meal_plan_assessed',
                'running',
                'completed',
                sprintf('Assessed %d planned member-meals with %.2f%% data completeness.', $totalPlanned, $dataCompleteness)
            );
            $this->pdo->commit();

            return [
                'assessment_id' => $assessmentId,
                'household_balance_score' => round($householdScore, 2),
                'data_completeness_percent' => round($dataCompleteness, 2),
                'allergen_conflict_count' => $totalConflicts,
                'recommendation_count' => $recommendationCount,
                'reused' => false,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}