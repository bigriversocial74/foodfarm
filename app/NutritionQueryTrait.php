<?php

declare(strict_types=1);

namespace Homestead;

trait NutritionQueryTrait
{
    public function dashboardData(int $householdId): array
    {
        $settings = $this->settings($householdId);

        $assessmentStatement = $this->pdo->prepare(
            'SELECT mna.*, mp.name AS meal_plan_name
             FROM meal_nutrition_assessments mna
             JOIN meal_plans mp ON mp.id = mna.meal_plan_id
             WHERE mna.household_id = ? AND mna.status = "completed"
             ORDER BY mna.completed_at DESC, mna.id DESC LIMIT 1'
        );
        $assessmentStatement->execute([$householdId]);
        $assessment = $assessmentStatement->fetch();
        $assessment = is_array($assessment) ? $assessment : null;

        $lines = [];
        if ($assessment !== null) {
            $lineStatement = $this->pdo->prepare(
                'SELECT mnal.*, hm.display_name, mnp.dietary_pattern
                 FROM member_nutrition_assessment_lines mnal
                 JOIN household_members hm ON hm.id = mnal.household_member_id
                 LEFT JOIN member_nutrition_profiles mnp
                   ON mnp.household_id = mnal.household_id
                  AND mnp.household_member_id = mnal.household_member_id
                 WHERE mnal.meal_nutrition_assessment_id = ? AND mnal.household_id = ?
                 ORDER BY hm.display_name'
            );
            $lineStatement->execute([(int)$assessment['id'], $householdId]);
            $lines = $lineStatement->fetchAll();
        }

        $recommendationStatement = $this->pdo->prepare(
            'SELECT nr.*, hm.display_name
             FROM nutrition_recommendations nr
             LEFT JOIN household_members hm ON hm.id = nr.household_member_id
             WHERE nr.household_id = ?
             ORDER BY FIELD(nr.status, "pending", "accepted", "completed", "dismissed"),
                      FIELD(nr.priority, "critical", "high", "medium", "low"), nr.created_at DESC
             LIMIT 40'
        );
        $recommendationStatement->execute([$householdId]);
        $recommendations = $recommendationStatement->fetchAll();

        $memberStatement = $this->pdo->prepare(
            'SELECT hm.id, hm.display_name, hm.age_group, hm.role, hm.dietary_pattern,
                    mnp.calorie_target, mnp.protein_target_g, mnp.fiber_target_g,
                    mnp.sodium_limit_mg, mnp.added_sugar_limit_g, mnp.target_notes
             FROM household_members hm
             LEFT JOIN member_nutrition_profiles mnp
               ON mnp.household_id = hm.household_id AND mnp.household_member_id = hm.id
             WHERE hm.household_id = ? AND hm.status = "active"
             ORDER BY FIELD(hm.role, "owner", "administrator", "adult_member", "youth_member", "guest_helper"), hm.display_name'
        );
        $memberStatement->execute([$householdId]);
        $members = $memberStatement->fetchAll();

        $allergenStatement = $this->pdo->prepare(
            'SELECT mar.*, hm.display_name
             FROM member_allergen_rules mar
             JOIN household_members hm ON hm.id = mar.household_member_id
             WHERE mar.household_id = ? AND mar.active = 1
             ORDER BY hm.display_name, FIELD(mar.severity, "allergy", "intolerance", "preference"), mar.allergen_key'
        );
        $allergenStatement->execute([$householdId]);
        $memberAllergens = $allergenStatement->fetchAll();

        $inventoryStatement = $this->pdo->prepare(
            'SELECT ii.id, ii.name, ii.unit, ii.status,
                    inp.basis_quantity, inp.basis_unit, inp.calories, inp.protein_g,
                    inp.carbohydrate_g, inp.fat_g, inp.fiber_g, inp.total_sugar_g,
                    inp.added_sugar_g, inp.sodium_mg, inp.source_label, inp.confidence
             FROM inventory_items ii
             LEFT JOIN inventory_nutrition_profiles inp
               ON inp.household_id = ii.household_id AND inp.inventory_item_id = ii.id
             WHERE ii.household_id = ? AND ii.status <> "archived"
             ORDER BY ii.name'
        );
        $inventoryStatement->execute([$householdId]);
        $inventoryItems = $inventoryStatement->fetchAll();

        $inventoryAllergenStatement = $this->pdo->prepare(
            'SELECT iat.*, ii.name AS inventory_item_name
             FROM inventory_allergen_tags iat
             JOIN inventory_items ii ON ii.id = iat.inventory_item_id
             WHERE iat.household_id = ? AND iat.active = 1
             ORDER BY ii.name, iat.allergen_key'
        );
        $inventoryAllergenStatement->execute([$householdId]);
        $inventoryAllergens = $inventoryAllergenStatement->fetchAll();

        $recipeStatement = $this->pdo->prepare(
            'SELECT r.id, r.name, r.servings, r.status,
                    rns.id AS nutrition_snapshot_id, rns.as_of_date,
                    rns.calories_per_serving, rns.protein_per_serving_g,
                    rns.fiber_per_serving_g, rns.sodium_per_serving_mg,
                    rns.missing_profile_count, rns.unit_mismatch_count, rns.allergen_keys
             FROM recipes r
             LEFT JOIN recipe_nutrition_snapshots rns ON rns.id = (
                 SELECT rns2.id FROM recipe_nutrition_snapshots rns2
                 WHERE rns2.household_id = r.household_id AND rns2.recipe_id = r.id
                 ORDER BY rns2.as_of_date DESC, rns2.id DESC LIMIT 1
             )
             WHERE r.household_id = ? AND r.status <> "archived"
             ORDER BY r.name'
        );
        $recipeStatement->execute([$householdId]);
        $recipes = $recipeStatement->fetchAll();

        $mealPlanStatement = $this->pdo->prepare(
            'SELECT mp.id, mp.name, mp.starts_on, mp.ends_on, mp.status,
                    COUNT(mpi.id) AS meal_count
             FROM meal_plans mp
             LEFT JOIN meal_plan_items mpi ON mpi.meal_plan_id = mp.id
             WHERE mp.household_id = ? AND mp.status <> "archived"
             GROUP BY mp.id, mp.name, mp.starts_on, mp.ends_on, mp.status
             ORDER BY mp.starts_on DESC, mp.id DESC
             LIMIT 30'
        );
        $mealPlanStatement->execute([$householdId]);
        $mealPlans = $mealPlanStatement->fetchAll();

        $trendStatement = $this->pdo->prepare(
            'SELECT id, meal_plan_id, starts_on, ends_on, household_balance_score,
                    data_completeness_percent, allergen_conflict_count, recommendation_count,
                    completed_at
             FROM meal_nutrition_assessments
             WHERE household_id = ? AND status = "completed"
             ORDER BY completed_at DESC, id DESC LIMIT 12'
        );
        $trendStatement->execute([$householdId]);
        $trends = $trendStatement->fetchAll();

        return [
            'settings' => $settings,
            'assessment' => $assessment,
            'assessment_lines' => $lines,
            'recommendations' => $recommendations,
            'members' => $members,
            'member_allergens' => $memberAllergens,
            'inventory_items' => $inventoryItems,
            'inventory_allergens' => $inventoryAllergens,
            'recipes' => $recipes,
            'meal_plans' => $mealPlans,
            'trends' => $trends,
        ];
    }
}