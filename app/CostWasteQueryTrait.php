<?php

declare(strict_types=1);

namespace Homestead;

trait CostWasteQueryTrait
{
    public function dashboardData(int $householdId): array
    {
        $snapshotQuery = $this->pdo->prepare(
            'SELECT hfs.*, hm.display_name AS generated_by
             FROM household_finance_snapshots hfs
             LEFT JOIN household_members hm
               ON hm.id = hfs.generated_by_member_id AND hm.household_id = hfs.household_id
             WHERE hfs.household_id = ? AND hfs.status = "completed"
             ORDER BY hfs.month_start DESC, hfs.id DESC LIMIT 1'
        );
        $snapshotQuery->execute([$householdId]);
        $snapshot = $snapshotQuery->fetch();
        $snapshotId = is_array($snapshot) ? (int)$snapshot['id'] : 0;

        $recommendations = [];
        if ($snapshotId > 0) {
            $recommendationQuery = $this->pdo->prepare(
                'SELECT fr.*, i.name AS item_name
                 FROM finance_recommendations fr
                 LEFT JOIN inventory_items i
                   ON i.id = fr.inventory_item_id AND i.household_id = fr.household_id
                 WHERE fr.household_id = ? AND fr.snapshot_id = ?
                 ORDER BY FIELD(fr.priority, "critical", "high", "medium", "low"), fr.id'
            );
            $recommendationQuery->execute([$householdId, $snapshotId]);
            $recommendations = $recommendationQuery->fetchAll();
        }

        $supplierQuery = $this->pdo->prepare(
            'SELECT id, name, supplier_type, status FROM household_suppliers
             WHERE household_id = ? ORDER BY status, name'
        );
        $supplierQuery->execute([$householdId]);

        $inventoryQuery = $this->pdo->prepare(
            'SELECT i.id, i.name, i.current_quantity, i.unit, i.best_use_date,
                    icb.weighted_unit_cost, icb.quantity_costed
             FROM inventory_items i
             LEFT JOIN inventory_cost_basis icb
               ON icb.household_id = i.household_id AND icb.inventory_item_id = i.id
             WHERE i.household_id = ? AND i.status IN ("active", "reserved")
             ORDER BY i.name'
        );
        $inventoryQuery->execute([$householdId]);

        $preparedQuery = $this->pdo->prepare(
            'SELECT id, name, servings_remaining, use_by_date, status
             FROM prepared_food_batches
             WHERE household_id = ? AND status IN ("active", "frozen") AND servings_remaining > 0
             ORDER BY use_by_date, name'
        );
        $preparedQuery->execute([$householdId]);

        $recipeQuery = $this->pdo->prepare(
            'SELECT id, name, servings FROM recipes
             WHERE household_id = ? AND status = "active" ORDER BY name'
        );
        $recipeQuery->execute([$householdId]);

        $purchaseQuery = $this->pdo->prepare(
            'SELECT fpr.*, i.name AS item_name, hs.name AS supplier_name, hm.display_name AS member_name
             FROM food_purchase_records fpr
             JOIN inventory_items i
               ON i.id = fpr.inventory_item_id AND i.household_id = fpr.household_id
             LEFT JOIN household_suppliers hs
               ON hs.id = fpr.supplier_id AND hs.household_id = fpr.household_id
             LEFT JOIN household_members hm
               ON hm.id = fpr.purchased_by_member_id AND hm.household_id = fpr.household_id
             WHERE fpr.household_id = ?
             ORDER BY fpr.purchased_on DESC, fpr.id DESC LIMIT 30'
        );
        $purchaseQuery->execute([$householdId]);

        $wasteQuery = $this->pdo->prepare(
            'SELECT fwe.*, i.name AS item_name, pfb.name AS prepared_name, hm.display_name AS member_name
             FROM food_waste_events fwe
             LEFT JOIN inventory_items i
               ON i.id = fwe.inventory_item_id AND i.household_id = fwe.household_id
             LEFT JOIN prepared_food_batches pfb
               ON pfb.id = fwe.prepared_food_batch_id AND pfb.household_id = fwe.household_id
             LEFT JOIN household_members hm
               ON hm.id = fwe.member_id AND hm.household_id = fwe.household_id
             WHERE fwe.household_id = ?
             ORDER BY fwe.occurred_on DESC, fwe.id DESC LIMIT 30'
        );
        $wasteQuery->execute([$householdId]);

        $recipeCostQuery = $this->pdo->prepare(
            'SELECT rcs.*, r.name AS recipe_name
             FROM recipe_cost_snapshots rcs
             JOIN recipes r ON r.id = rcs.recipe_id AND r.household_id = rcs.household_id
             WHERE rcs.household_id = ?
             ORDER BY rcs.as_of_date DESC, rcs.id DESC LIMIT 20'
        );
        $recipeCostQuery->execute([$householdId]);

        $comparisonQuery = $this->pdo->prepare(
            'SELECT fpr.inventory_item_id, i.name AS item_name, hs.name AS supplier_name,
                    COUNT(*) AS purchase_count, AVG(fpr.unit_cost) AS average_unit_cost,
                    MIN(fpr.unit_cost) AS lowest_unit_cost, MAX(fpr.unit_cost) AS highest_unit_cost,
                    MAX(fpr.purchased_on) AS last_purchased_on
             FROM food_purchase_records fpr
             JOIN inventory_items i
               ON i.id = fpr.inventory_item_id AND i.household_id = fpr.household_id
             LEFT JOIN household_suppliers hs
               ON hs.id = fpr.supplier_id AND hs.household_id = fpr.household_id
             WHERE fpr.household_id = ?
             GROUP BY fpr.inventory_item_id, i.name, fpr.supplier_id, hs.name
             ORDER BY i.name, average_unit_cost LIMIT 60'
        );
        $comparisonQuery->execute([$householdId]);

        $trendQuery = $this->pdo->prepare(
            'SELECT month_start, budget_amount, purchase_spend, waste_value,
                    household_production_value, preservation_value, estimated_savings,
                    budget_variance, waste_percent, savings_rate_percent
             FROM household_finance_snapshots
             WHERE household_id = ? AND status = "completed"
             ORDER BY month_start DESC, id DESC LIMIT 12'
        );
        $trendQuery->execute([$householdId]);

        $memberQuery = $this->pdo->prepare(
            'SELECT id, display_name FROM household_members
             WHERE household_id = ? AND status = "active" ORDER BY display_name'
        );
        $memberQuery->execute([$householdId]);

        return [
            'settings' => $this->settings($householdId),
            'snapshot' => is_array($snapshot) ? $snapshot : null,
            'recommendations' => $recommendations,
            'suppliers' => $supplierQuery->fetchAll(),
            'inventory_items' => $inventoryQuery->fetchAll(),
            'prepared_batches' => $preparedQuery->fetchAll(),
            'recipes' => $recipeQuery->fetchAll(),
            'purchases' => $purchaseQuery->fetchAll(),
            'waste_events' => $wasteQuery->fetchAll(),
            'recipe_costs' => $recipeCostQuery->fetchAll(),
            'supplier_comparisons' => $comparisonQuery->fetchAll(),
            'trends' => $trendQuery->fetchAll(),
            'members' => $memberQuery->fetchAll(),
        ];
    }
}