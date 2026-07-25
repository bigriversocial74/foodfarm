<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

trait CostWasteSnapshotTrait
{
    public function calculateRecipeCost(
        int $householdId,
        int $memberId,
        int $recipeId,
        string $asOfDate
    ): array {
        $this->assertActiveMember($householdId, $memberId);
        $date = $this->date($asOfDate, 'Cost date')->format('Y-m-d');
        $recipeQuery = $this->pdo->prepare(
            "SELECT id, name, servings FROM recipes
             WHERE id = ? AND household_id = ? AND status <> 'archived'"
        );
        $recipeQuery->execute([$recipeId, $householdId]);
        $recipe = $recipeQuery->fetch();
        if (!is_array($recipe)) {
            throw new InvalidArgumentException('Recipe was not found in this household.');
        }
        $servings = (float)$recipe['servings'];
        if ($servings <= 0) {
            throw new InvalidArgumentException('Recipe servings must be greater than zero.');
        }

        $ingredientQuery = $this->pdo->prepare(
            'SELECT ri.id, ri.inventory_item_id, ri.ingredient_name, ri.quantity, ri.unit,
                    icb.unit AS cost_unit, icb.weighted_unit_cost, icb.source_purchase_id
             FROM recipe_ingredients ri
             LEFT JOIN inventory_items i
               ON i.id = ri.inventory_item_id AND i.household_id = ?
             LEFT JOIN inventory_cost_basis icb
               ON icb.household_id = ? AND icb.inventory_item_id = i.id
             WHERE ri.recipe_id = ? ORDER BY ri.sort_order, ri.id'
        );
        $ingredientQuery->execute([$householdId, $householdId, $recipeId]);
        $ingredients = $ingredientQuery->fetchAll();
        $calculationKey = hash('sha256', json_encode([
            'household_id' => $householdId,
            'recipe_id' => $recipeId,
            'date' => $date,
            'servings' => $servings,
            'ingredients' => $ingredients,
            'model' => self::MODEL_VERSION,
        ], JSON_THROW_ON_ERROR));

        $existing = $this->pdo->prepare(
            'SELECT * FROM recipe_cost_snapshots
             WHERE household_id = ? AND calculation_key = ? LIMIT 1'
        );
        $existing->execute([$householdId, $calculationKey]);
        $existingRow = $existing->fetch();
        if (is_array($existingRow)) {
            return $this->recipeCostResult($existingRow, true);
        }

        $this->pdo->beginTransaction();
        try {
            $snapshot = $this->pdo->prepare(
                'INSERT INTO recipe_cost_snapshots
                 (household_id, recipe_id, calculation_key, as_of_date, servings,
                  total_cost, cost_per_serving, priced_ingredient_count, missing_price_count,
                  generated_by_member_id)
                 VALUES (?, ?, ?, ?, ?, 0, NULL, 0, 0, ?)'
            );
            $snapshot->execute([$householdId, $recipeId, $calculationKey, $date, $servings, $memberId]);
            $snapshotId = (int)$this->pdo->lastInsertId();

            $total = 0.0;
            $priced = 0;
            $missing = 0;
            $lineInsert = $this->pdo->prepare(
                'INSERT INTO recipe_cost_snapshot_lines
                 (recipe_cost_snapshot_id, household_id, recipe_ingredient_id, inventory_item_id,
                  ingredient_name_snapshot, quantity, unit, unit_cost, extended_cost,
                  pricing_status, source_purchase_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($ingredients as $ingredient) {
                $status = 'missing';
                $unitCost = null;
                $extended = null;
                if ($ingredient['inventory_item_id'] !== null && $ingredient['weighted_unit_cost'] !== null) {
                    if ((string)$ingredient['cost_unit'] === (string)$ingredient['unit']) {
                        $unitCost = max(0.0, (float)$ingredient['weighted_unit_cost']);
                        $extended = round((float)$ingredient['quantity'] * $unitCost, 4);
                        $total += $extended;
                        $priced++;
                        $status = 'priced';
                    } else {
                        $status = 'unit_mismatch';
                        $missing++;
                    }
                } else {
                    $missing++;
                }
                $lineInsert->execute([
                    $snapshotId,
                    $householdId,
                    (int)$ingredient['id'],
                    $ingredient['inventory_item_id'] !== null ? (int)$ingredient['inventory_item_id'] : null,
                    (string)$ingredient['ingredient_name'],
                    (float)$ingredient['quantity'],
                    (string)$ingredient['unit'],
                    $unitCost,
                    $extended,
                    $status,
                    $ingredient['source_purchase_id'] !== null ? (int)$ingredient['source_purchase_id'] : null,
                ]);
            }

            $costPerServing = $missing === 0 ? round($total / $servings, 4) : null;
            $update = $this->pdo->prepare(
                'UPDATE recipe_cost_snapshots
                 SET total_cost = ?, cost_per_serving = ?, priced_ingredient_count = ?, missing_price_count = ?
                 WHERE id = ? AND household_id = ?'
            );
            $update->execute([round($total, 2), $costPerServing, $priced, $missing, $snapshotId, $householdId]);
            $this->recordEvent(
                $householdId,
                null,
                null,
                null,
                null,
                $memberId,
                'recipe_cost_calculated',
                null,
                null,
                sprintf('Costed recipe #%d with %d priced and %d unpriced ingredients.', $recipeId, $priced, $missing)
            );
            $this->pdo->commit();

            $created = $this->pdo->prepare(
                'SELECT * FROM recipe_cost_snapshots WHERE id = ? AND household_id = ?'
            );
            $created->execute([$snapshotId, $householdId]);
            $row = $created->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('The recipe cost snapshot could not be read.');
            }
            return $this->recipeCostResult($row, false);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function runFinanceSnapshot(int $householdId, int $memberId, string $month): array
    {
        $this->assertActiveMember($householdId, $memberId);
        [$monthStart, $monthEnd] = $this->monthBounds($month);
        $settings = $this->settings($householdId);
        $watermark = $this->sourceWatermark($householdId, $monthStart, $monthEnd);
        $runKey = hash('sha256', implode('|', [
            'phase9-finance',
            $householdId,
            $monthStart,
            $monthEnd,
            $watermark,
            self::MODEL_VERSION,
        ]));

        $existing = $this->pdo->prepare(
            "SELECT * FROM household_finance_snapshots
             WHERE household_id = ? AND run_key = ? AND status = 'completed' LIMIT 1"
        );
        $existing->execute([$householdId, $runKey]);
        $existingRow = $existing->fetch();
        if (is_array($existingRow)) {
            return $this->financeSnapshotResult($existingRow, true);
        }

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO household_finance_snapshots
                 (household_id, month_start, month_end, run_key, source_watermark, model_version,
                  status, budget_amount, generated_by_member_id)
                 VALUES (?, ?, ?, ?, ?, ?, "running", ?, ?)'
            );
            $insert->execute([
                $householdId,
                $monthStart,
                $monthEnd,
                $runKey,
                $watermark,
                self::MODEL_VERSION,
                (float)$settings['monthly_budget'],
                $memberId,
            ]);
            $snapshotId = (int)$this->pdo->lastInsertId();

            $purchase = $this->pdo->prepare(
                'SELECT COALESCE(SUM(total_cost), 0) AS spend, COUNT(*) AS purchase_count
                 FROM food_purchase_records
                 WHERE household_id = ? AND purchased_on BETWEEN ? AND ?'
            );
            $purchase->execute([$householdId, $monthStart, $monthEnd]);
            $purchaseRow = $purchase->fetch() ?: ['spend' => 0, 'purchase_count' => 0];
            $purchaseSpend = round((float)$purchaseRow['spend'], 2);
            $purchaseCount = (int)$purchaseRow['purchase_count'];

            $waste = $this->pdo->prepare(
                'SELECT COALESCE(SUM(estimated_value), 0) AS waste_value, COUNT(*) AS waste_count
                 FROM food_waste_events
                 WHERE household_id = ? AND occurred_on BETWEEN ? AND ?'
            );
            $waste->execute([$householdId, $monthStart, $monthEnd]);
            $wasteRow = $waste->fetch() ?: ['waste_value' => 0, 'waste_count' => 0];
            $wasteValue = round((float)$wasteRow['waste_value'], 2);
            $wasteCount = (int)$wasteRow['waste_count'];

            $production = $this->pdo->prepare(
                "SELECT COALESCE(SUM(h.quantity * icb.weighted_unit_cost), 0)
                 FROM harvests h
                 JOIN plantings p ON p.id = h.planting_id
                 JOIN garden_zones z ON z.id = p.garden_zone_id
                 LEFT JOIN inventory_cost_basis icb
                   ON icb.household_id = z.household_id
                  AND icb.inventory_item_id = h.inventory_item_id
                  AND icb.unit = h.unit
                 WHERE z.household_id = ?
                   AND DATE(h.harvested_at) BETWEEN ? AND ?
                   AND h.destination <> 'preservation'"
            );
            $production->execute([$householdId, $monthStart, $monthEnd]);
            $productionValue = round((float)$production->fetchColumn(), 2);

            $preservation = $this->pdo->prepare(
                "SELECT COALESCE(SUM(pb.yield_quantity * icb.weighted_unit_cost), 0)
                 FROM preservation_batches pb
                 LEFT JOIN inventory_cost_basis icb
                   ON icb.household_id = pb.household_id
                  AND icb.inventory_item_id = pb.output_inventory_item_id
                  AND icb.unit = pb.yield_unit
                 WHERE pb.household_id = ?
                   AND DATE(COALESCE(pb.completed_at, pb.started_at, pb.created_at)) BETWEEN ? AND ?
                   AND pb.status IN ('processed','cooling','labeled','stored','opened','finished')"
            );
            $preservation->execute([$householdId, $monthStart, $monthEnd]);
            $preservationValue = round((float)$preservation->fetchColumn(), 2);

            $budget = (float)$settings['monthly_budget'];
            $estimatedSavings = round(max(0.0, $productionValue + $preservationValue - $wasteValue), 2);
            $budgetVariance = round($budget - $purchaseSpend, 2);
            $trackedValue = max(0.01, $purchaseSpend + $productionValue + $preservationValue);
            $wastePercent = round(min(100, ($wasteValue / $trackedValue) * 100), 2);
            $savingsRate = round(min(100, ($estimatedSavings / max(0.01, $purchaseSpend + $estimatedSavings)) * 100), 2);

            $recommendationCount = $this->generateFinanceRecommendations(
                $snapshotId,
                $householdId,
                $monthStart,
                $monthEnd,
                $settings,
                $purchaseSpend,
                $wasteValue,
                $wastePercent,
                $budgetVariance
            );

            $update = $this->pdo->prepare(
                'UPDATE household_finance_snapshots
                 SET status = "completed", purchase_spend = ?, waste_value = ?,
                     household_production_value = ?, preservation_value = ?, estimated_savings = ?,
                     budget_variance = ?, waste_percent = ?, savings_rate_percent = ?,
                     purchase_count = ?, waste_event_count = ?, recommendation_count = ?,
                     completed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = "running"'
            );
            $update->execute([
                $purchaseSpend,
                $wasteValue,
                $productionValue,
                $preservationValue,
                $estimatedSavings,
                $budgetVariance,
                $wastePercent,
                $savingsRate,
                $purchaseCount,
                $wasteCount,
                $recommendationCount,
                $snapshotId,
                $householdId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The finance snapshot could not be finalized.');
            }
            $this->recordEvent(
                $householdId,
                $snapshotId,
                null,
                null,
                null,
                $memberId,
                'finance_snapshot_completed',
                'running',
                'completed',
                sprintf('Tracked %.2f spend, %.2f waste value, and %.2f estimated savings.', $purchaseSpend, $wasteValue, $estimatedSavings)
            );
            $this->pdo->commit();

            $completed = $this->pdo->prepare(
                'SELECT * FROM household_finance_snapshots WHERE id = ? AND household_id = ?'
            );
            $completed->execute([$snapshotId, $householdId]);
            $row = $completed->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('The completed finance snapshot could not be read.');
            }
            return $this->financeSnapshotResult($row, false);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function generateFinanceRecommendations(
        int $snapshotId,
        int $householdId,
        string $monthStart,
        string $monthEnd,
        array $settings,
        float $purchaseSpend,
        float $wasteValue,
        float $wastePercent,
        float $budgetVariance
    ): int {
        $count = 0;
        if ($budgetVariance < 0) {
            $count += $this->insertFinanceRecommendation(
                $snapshotId,
                $householdId,
                null,
                'budget_alert',
                'Monthly food spending is over budget',
                sprintf('Recorded purchases exceed the monthly budget by %.2f.', abs($budgetVariance)),
                'Review optional purchases, supplier choices, and upcoming meal plans before the next shopping trip.',
                abs($budgetVariance),
                'high',
                $monthEnd
            );
        }
        if ($wastePercent > (float)$settings['waste_target_percent']) {
            $count += $this->insertFinanceRecommendation(
                $snapshotId,
                $householdId,
                null,
                'reduce_waste',
                'Tracked food waste is above target',
                sprintf('Waste value is %.2f%% of tracked food value, above the %.2f%% target.', $wastePercent, (float)$settings['waste_target_percent']),
                'Review the highest-value waste events and schedule use-first or freezing tasks for at-risk food.',
                $wasteValue,
                $wastePercent >= 15 ? 'critical' : 'high',
                $monthEnd
            );
        }

        $priceIncrease = $this->pdo->prepare(
            'SELECT current_purchase.inventory_item_id, i.name,
                    current_purchase.unit_cost AS current_cost,
                    previous_purchase.unit_cost AS previous_cost,
                    ((current_purchase.unit_cost - previous_purchase.unit_cost) / NULLIF(previous_purchase.unit_cost, 0)) * 100 AS increase_percent
             FROM food_purchase_records current_purchase
             JOIN inventory_items i
               ON i.id = current_purchase.inventory_item_id AND i.household_id = current_purchase.household_id
             JOIN food_purchase_records previous_purchase
               ON previous_purchase.id = (
                    SELECT fp2.id FROM food_purchase_records fp2
                    WHERE fp2.household_id = current_purchase.household_id
                      AND fp2.inventory_item_id = current_purchase.inventory_item_id
                      AND (fp2.purchased_on < current_purchase.purchased_on
                           OR (fp2.purchased_on = current_purchase.purchased_on AND fp2.id < current_purchase.id))
                    ORDER BY fp2.purchased_on DESC, fp2.id DESC LIMIT 1
               )
             WHERE current_purchase.household_id = ?
               AND current_purchase.purchased_on BETWEEN ? AND ?
               AND previous_purchase.unit_cost > 0
             ORDER BY increase_percent DESC LIMIT 10'
        );
        $priceIncrease->execute([$householdId, $monthStart, $monthEnd]);
        foreach ($priceIncrease->fetchAll() as $row) {
            $increase = (float)$row['increase_percent'];
            if ($increase < (float)$settings['price_increase_alert_percent']) {
                continue;
            }
            $count += $this->insertFinanceRecommendation(
                $snapshotId,
                $householdId,
                (int)$row['inventory_item_id'],
                'price_increase',
                (string)$row['name'] . ' price increased',
                sprintf('The latest recorded unit cost increased by %.1f%%.', $increase),
                'Compare suppliers, package sizes, and planned usage before purchasing this item again.',
                null,
                $increase >= 30 ? 'high' : 'medium',
                $monthEnd
            );
        }

        $useFirst = $this->pdo->prepare(
            "SELECT i.id, i.name, i.current_quantity, i.unit, i.best_use_date,
                    i.current_quantity * COALESCE(icb.weighted_unit_cost, 0) AS at_risk_value
             FROM inventory_items i
             LEFT JOIN inventory_cost_basis icb
               ON icb.household_id = i.household_id AND icb.inventory_item_id = i.id
             WHERE i.household_id = ? AND i.status = 'active' AND i.current_quantity > 0
               AND i.best_use_date IS NOT NULL
               AND i.best_use_date <= DATE_ADD(?, INTERVAL 7 DAY)
             ORDER BY at_risk_value DESC, i.best_use_date LIMIT 10"
        );
        $useFirst->execute([$householdId, $monthEnd]);
        foreach ($useFirst->fetchAll() as $row) {
            $count += $this->insertFinanceRecommendation(
                $snapshotId,
                $householdId,
                (int)$row['id'],
                'use_first',
                'Use ' . (string)$row['name'] . ' before value is lost',
                sprintf('%.4f %s is tracked with a best-use date of %s.', (float)$row['current_quantity'], (string)$row['unit'], (string)$row['best_use_date']),
                'Plan a recipe, freeze, preserve, donate, or otherwise use this item before the best-use date.',
                round((float)$row['at_risk_value'], 2),
                'high',
                (string)$row['best_use_date']
            );
        }

        if ($purchaseSpend > 0 && $count === 0) {
            $count += $this->insertFinanceRecommendation(
                $snapshotId,
                $householdId,
                null,
                'review_data',
                'Review this month’s food-cost data',
                'No urgent budget, waste, price, or use-first condition was detected in the recorded data.',
                'Review missing cost-basis records and confirm that purchases and waste events are being captured consistently.',
                null,
                'low',
                $monthEnd
            );
        }
        return $count;
    }

    private function insertFinanceRecommendation(
        int $snapshotId,
        int $householdId,
        ?int $itemId,
        string $type,
        string $title,
        string $rationale,
        string $action,
        ?float $impact,
        string $priority,
        ?string $dueOn
    ): int {
        $generationKey = hash('sha256', implode('|', [
            'phase9-recommendation',
            $householdId,
            $snapshotId,
            $itemId ?? 0,
            $type,
            $title,
        ]));
        $statement = $this->pdo->prepare(
            'INSERT INTO finance_recommendations
             (household_id, snapshot_id, inventory_item_id, recommendation_type, generation_key,
              title, rationale, recommended_action, estimated_impact, priority, due_on, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute([
            $householdId,
            $snapshotId,
            $itemId,
            $type,
            $generationKey,
            $title,
            $rationale,
            $action,
            $impact,
            $priority,
            $dueOn,
        ]);
        return $statement->rowCount() > 0 ? 1 : 0;
    }

    private function recipeCostResult(array $row, bool $reused): array
    {
        return [
            'snapshot_id' => (int)$row['id'],
            'recipe_id' => (int)$row['recipe_id'],
            'total_cost' => (float)$row['total_cost'],
            'cost_per_serving' => $row['cost_per_serving'] !== null ? (float)$row['cost_per_serving'] : null,
            'priced_ingredients' => (int)$row['priced_ingredient_count'],
            'missing_prices' => (int)$row['missing_price_count'],
            'reused' => $reused,
        ];
    }

    private function financeSnapshotResult(array $row, bool $reused): array
    {
        return [
            'snapshot_id' => (int)$row['id'],
            'month_start' => (string)$row['month_start'],
            'purchase_spend' => (float)$row['purchase_spend'],
            'waste_value' => (float)$row['waste_value'],
            'production_value' => (float)$row['household_production_value'],
            'preservation_value' => (float)$row['preservation_value'],
            'estimated_savings' => (float)$row['estimated_savings'],
            'budget_variance' => (float)$row['budget_variance'],
            'waste_percent' => (float)$row['waste_percent'],
            'savings_rate_percent' => (float)$row['savings_rate_percent'],
            'recommendations' => (int)$row['recommendation_count'],
            'reused' => $reused,
        ];
    }
}