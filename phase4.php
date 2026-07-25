<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use Homestead\RecipeService;
use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$householdId = (int)$user['household_id'];
$memberId = (int)$user['member_id'];
$service = new RecipeService($pdo);
$canViewRecipes = $auth->can($user, 'recipes.view') || $auth->can($user, 'recipes.manage') || $auth->can($user, 'recipes.complete');
$canManageRecipes = $auth->can($user, 'recipes.manage');
$canManageMeals = $auth->can($user, 'meals.manage');
$canCompleteRecipes = $auth->can($user, 'recipes.complete');
if (!$canViewRecipes && !$canManageMeals) {
    http_response_code(403);
    exit('You do not have permission to view recipes or meal plans.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_recipe') {
            $auth->requirePermission($user, 'recipes.manage');
            $recipeId = $service->createRecipe($householdId, $memberId, $_POST);
            flash('success', 'Recipe created. Add ingredients next.');
            redirect('/phase4.php?recipe=' . $recipeId);
        }
        if ($action === 'add_ingredient') {
            $auth->requirePermission($user, 'recipes.manage');
            $recipeId = (int)($_POST['recipe_id'] ?? 0);
            $service->addIngredient($householdId, $recipeId, $_POST);
            flash('success', 'Ingredient added.');
            redirect('/phase4.php?recipe=' . $recipeId);
        }
        if ($action === 'create_meal_plan') {
            $auth->requirePermission($user, 'meals.manage');
            $service->createMealPlan(
                $householdId,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['starts_on'] ?? ''),
                (string)($_POST['ends_on'] ?? '')
            );
            flash('success', 'Meal plan created.');
            redirect('/phase4.php#meal-planning');
        }
        if ($action === 'add_meal') {
            $auth->requirePermission($user, 'meals.manage');
            $service->addMeal($householdId, $_POST);
            flash('success', 'Meal added with family serving calculations.');
            redirect('/phase4.php#meal-planning');
        }
        if ($action === 'complete_recipe') {
            $auth->requirePermission($user, 'recipes.complete');
            $batchId = $service->completeRecipe($householdId, $memberId, $_POST);
            unset($_SESSION['recipe_completion_key']);
            flash('success', 'Recipe completed. Ingredients were deducted and prepared-food batch #' . $batchId . ' was created.');
            redirect('/phase4.php#prepared-food');
        }
        throw new InvalidArgumentException('Unknown recipe action.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/phase4.php');
    }
}

$recipesStatement = $pdo->prepare(
    "SELECT r.*, hm.display_name AS creator_name, COUNT(ri.id) AS ingredient_count
     FROM recipes r
     LEFT JOIN household_members hm ON hm.id = r.created_by_member_id AND hm.household_id = r.household_id
     LEFT JOIN recipe_ingredients ri ON ri.recipe_id = r.id
     WHERE r.household_id = ? AND r.status = 'active'
     GROUP BY r.id ORDER BY r.updated_at DESC, r.id DESC"
);
$recipesStatement->execute([$householdId]);
$recipes = $recipesStatement->fetchAll();

$selectedRecipeId = (int)($_GET['recipe'] ?? ($recipes[0]['id'] ?? 0));
$selectedRecipe = null;
$selectedIngredients = [];
if ($selectedRecipeId > 0) {
    $recipeQuery = $pdo->prepare("SELECT * FROM recipes WHERE id = ? AND household_id = ? AND status = 'active' LIMIT 1");
    $recipeQuery->execute([$selectedRecipeId, $householdId]);
    $selectedRecipe = $recipeQuery->fetch() ?: null;
    if (is_array($selectedRecipe)) {
        $ingredientQuery = $pdo->prepare(
            'SELECT ri.*, ii.name AS inventory_name, ii.current_quantity, ii.unit AS inventory_unit
             FROM recipe_ingredients ri
             LEFT JOIN inventory_items ii ON ii.id = ri.inventory_item_id AND ii.household_id = ?
             WHERE ri.recipe_id = ? ORDER BY ri.sort_order, ri.id'
        );
        $ingredientQuery->execute([$householdId, $selectedRecipeId]);
        $selectedIngredients = $ingredientQuery->fetchAll();
    }
}

$inventoryQuery = $pdo->prepare(
    "SELECT id, name, current_quantity, unit FROM inventory_items
     WHERE household_id = ? AND status = 'active' AND item_type IN ('ingredient','preserved_food') ORDER BY name"
);
$inventoryQuery->execute([$householdId]);
$inventoryItems = $inventoryQuery->fetchAll();
$membersQuery = $pdo->prepare(
    "SELECT id, display_name, role, serving_multiplier FROM household_members
     WHERE household_id = ? AND status = 'active'
     ORDER BY FIELD(role,'owner','administrator','adult_member','youth_member','guest_helper'), display_name"
);
$membersQuery->execute([$householdId]);
$members = $membersQuery->fetchAll();
$locationsQuery = $pdo->prepare('SELECT id, name FROM storage_locations WHERE household_id = ? ORDER BY name');
$locationsQuery->execute([$householdId]);
$locations = $locationsQuery->fetchAll();
$plansQuery = $pdo->prepare(
    "SELECT mp.*, COUNT(mpi.id) AS meal_count FROM meal_plans mp
     LEFT JOIN meal_plan_items mpi ON mpi.meal_plan_id = mp.id
     WHERE mp.household_id = ? AND mp.status IN ('draft','active')
     GROUP BY mp.id ORDER BY mp.starts_on DESC"
);
$plansQuery->execute([$householdId]);
$mealPlans = $plansQuery->fetchAll();
$mealItemsQuery = $pdo->prepare(
    "SELECT mpi.*, mp.name AS plan_name, r.name AS recipe_name,
            GROUP_CONCAT(hm.display_name ORDER BY hm.display_name SEPARATOR ', ') AS member_names
     FROM meal_plan_items mpi
     JOIN meal_plans mp ON mp.id = mpi.meal_plan_id
     LEFT JOIN recipes r ON r.id = mpi.recipe_id AND r.household_id = mp.household_id
     LEFT JOIN meal_plan_members mpm ON mpm.meal_plan_item_id = mpi.id
     LEFT JOIN household_members hm ON hm.id = mpm.household_member_id AND hm.household_id = mp.household_id
     WHERE mp.household_id = ? GROUP BY mpi.id
     ORDER BY mpi.meal_date, FIELD(mpi.meal_type,'breakfast','lunch','dinner','snack')"
);
$mealItemsQuery->execute([$householdId]);
$mealItems = $mealItemsQuery->fetchAll();
$preparedQuery = $pdo->prepare(
    "SELECT pfb.*, hm.display_name AS prepared_by, sl.name AS location_name
     FROM prepared_food_batches pfb
     LEFT JOIN household_members hm ON hm.id = pfb.prepared_by_member_id AND hm.household_id = pfb.household_id
     LEFT JOIN storage_locations sl ON sl.id = pfb.storage_location_id AND sl.household_id = pfb.household_id
     WHERE pfb.household_id = ? ORDER BY pfb.prepared_at DESC LIMIT 50"
);
$preparedQuery->execute([$householdId]);
$preparedFoods = $preparedQuery->fetchAll();
$flashes = consume_flashes();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recipes & Meal Planning · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><a class="skip-link" href="#main-content">Skip to recipes</a><main id="main-content" class="page-container">
<header class="page-header"><div><p class="eyebrow">Connected food workflows</p><h1>Recipes & Meal Planning</h1><p class="page-description">Connect pantry ingredients, family servings, scheduled meals, cooking runs, and prepared-food inventory.</p></div><div><a class="button secondary" href="/phase2.php">Household data</a> <a class="button secondary" href="/account.php">Account</a></div></header>
<?php foreach ($flashes as $message): ?><div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<section class="metrics-grid compact"><article class="metric-card"><div><p>Active recipes</p><strong><?= count($recipes) ?></strong></div></article><article class="metric-card"><div><p>Meal plans</p><strong><?= count($mealPlans) ?></strong></div></article><article class="metric-card"><div><p>Scheduled meals</p><strong><?= count($mealItems) ?></strong></div></article><article class="metric-card"><div><p>Prepared foods</p><strong><?= count($preparedFoods) ?></strong></div></article></section>
<section class="content-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Recipe library</p><h2>Household recipes</h2></div></div><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Recipe</th><th scope="col">Category</th><th scope="col">Servings</th><th scope="col">Ingredients</th><th scope="col">Creator</th></tr></thead><tbody><?php if ($recipes === []): ?><tr><td colspan="5">No recipes yet.</td></tr><?php endif; ?><?php foreach ($recipes as $recipe): ?><tr><td><a href="/phase4.php?recipe=<?= (int)$recipe['id'] ?>"><strong><?= e((string)$recipe['name']) ?></strong></a></td><td><?= e((string)($recipe['category'] ?: 'Uncategorized')) ?></td><td><?= e((string)$recipe['servings']) ?></td><td><?= (int)$recipe['ingredient_count'] ?></td><td><?= e((string)($recipe['creator_name'] ?: 'Household')) ?></td></tr><?php endforeach; ?></tbody></table></div></article>
<?php if ($canManageRecipes): ?><article class="panel"><h2>Create recipe</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_recipe"><label>Name<input class="search-field" name="name" maxlength="180" required></label><label>Category<input class="search-field" name="category" maxlength="100"></label><label>Base servings<input class="search-field" type="number" step="0.25" min="0.25" name="servings" value="4" required></label><label>Yield quantity<input class="search-field" type="number" step="0.01" min="0" name="yield_quantity"></label><label>Yield unit<input class="search-field" name="yield_unit" maxlength="30"></label><label>Prep minutes<input class="search-field" type="number" min="0" name="prep_minutes"></label><label>Cook minutes<input class="search-field" type="number" min="0" name="cook_minutes"></label><label>Rest minutes<input class="search-field" type="number" min="0" name="rest_minutes"></label><label>Instructions<textarea name="instructions" maxlength="5000"></textarea></label><button class="button primary" type="submit">Create recipe</button></form></article><?php endif; ?></section>
<?php if (is_array($selectedRecipe)): ?><section class="content-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Selected recipe</p><h2><?= e((string)$selectedRecipe['name']) ?></h2></div></div><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Ingredient</th><th scope="col">Required</th><th scope="col">Inventory</th><th scope="col">Available</th></tr></thead><tbody><?php if ($selectedIngredients === []): ?><tr><td colspan="4">No ingredients linked yet.</td></tr><?php endif; ?><?php foreach ($selectedIngredients as $ingredient): ?><tr><td><strong><?= e((string)$ingredient['ingredient_name']) ?></strong><?= (int)$ingredient['optional'] === 1 ? ' · optional' : '' ?></td><td><?= e((string)$ingredient['quantity']) ?> <?= e((string)$ingredient['unit']) ?></td><td><?= e((string)($ingredient['inventory_name'] ?: 'Not linked')) ?></td><td><?= $ingredient['inventory_name'] ? e((string)$ingredient['current_quantity'] . ' ' . (string)$ingredient['inventory_unit']) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></article>
<?php if ($canManageRecipes): ?><article class="panel"><h2>Add ingredient</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_ingredient"><input type="hidden" name="recipe_id" value="<?= (int)$selectedRecipe['id'] ?>"><label>Ingredient name<input class="search-field" name="ingredient_name" maxlength="180" required></label><label>Quantity<input class="search-field" type="number" step="0.0001" min="0.0001" name="quantity" required></label><label>Unit<input class="search-field" name="unit" maxlength="30" required></label><label>Inventory item<select name="inventory_item_id"><option value="">Not linked</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label><label><input type="checkbox" name="optional" value="1"> Optional ingredient</label><button class="button primary" type="submit">Add ingredient</button></form></article><?php endif; ?></section>
<?php if ($canCompleteRecipes): ?><section class="panel"><h2>Complete <?= e((string)$selectedRecipe['name']) ?></h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complete_recipe"><input type="hidden" name="recipe_id" value="<?= (int)$selectedRecipe['id'] ?>"><label>Scale factor<input class="search-field" type="number" step="0.25" min="0.25" name="scale_factor" value="1" required></label><label>Actual servings<input class="search-field" type="number" step="0.25" min="0.25" name="actual_servings" value="<?= e((string)$selectedRecipe['servings']) ?>" required></label><label>Storage method<select name="storage_method"><option value="refrigerated">Refrigerated</option><option value="frozen">Frozen</option><option value="counter">Counter</option><option value="shelf_stable">Shelf stable</option></select></label><label>Storage location<select name="storage_location_id"><option value="">Unassigned</option><?php foreach ($locations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?></option><?php endforeach; ?></select></label><label>Use-by date<input class="search-field" type="date" name="use_by_date" min="<?= e(gmdate('Y-m-d')) ?>"></label><label>Reheating notes<input class="search-field" name="reheating_notes" maxlength="5000"></label><fieldset><legend>Intended family members</legend><?php foreach ($members as $member): ?><label><input type="checkbox" name="intended_member_ids[]" value="<?= (int)$member['id'] ?>" checked> <?= e((string)$member['display_name']) ?> · ×<?= e((string)$member['serving_multiplier']) ?></label><?php endforeach; ?></fieldset><label>Completion notes<textarea name="notes" maxlength="5000"></textarea></label><button class="button primary" type="submit">Deduct ingredients and create prepared food</button></form></section><?php endif; ?><?php endif; ?>
<section class="content-grid" id="meal-planning"><?php if ($canManageMeals): ?><article class="panel"><h2>Create meal plan</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_meal_plan"><label>Name<input class="search-field" name="name" maxlength="160" required></label><label>Starts<input class="search-field" type="date" name="starts_on" required></label><label>Ends<input class="search-field" type="date" name="ends_on" required></label><button class="button primary" type="submit">Create plan</button></form></article><article class="panel span-2"><h2>Add scheduled meal</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_meal"><label>Meal plan<select name="meal_plan_id" required><option value="">Choose plan</option><?php foreach ($mealPlans as $plan): ?><option value="<?= (int)$plan['id'] ?>"><?= e((string)$plan['name']) ?> · <?= e((string)$plan['starts_on']) ?>–<?= e((string)$plan['ends_on']) ?></option><?php endforeach; ?></select></label><label>Recipe<select name="recipe_id" required><option value="">Choose recipe</option><?php foreach ($recipes as $recipe): ?><option value="<?= (int)$recipe['id'] ?>"><?= e((string)$recipe['name']) ?></option><?php endforeach; ?></select></label><label>Date<input class="search-field" type="date" name="meal_date" required></label><label>Meal type<select name="meal_type"><option value="breakfast">Breakfast</option><option value="lunch">Lunch</option><option value="dinner">Dinner</option><option value="snack">Snack</option></select></label><label>Notes<input class="search-field" name="notes" maxlength="5000"></label><fieldset><legend>Who is eating?</legend><?php foreach ($members as $member): ?><label><input type="checkbox" name="member_ids[]" value="<?= (int)$member['id'] ?>" checked> <?= e((string)$member['display_name']) ?> · ×<?= e((string)$member['serving_multiplier']) ?></label><?php endforeach; ?></fieldset><button class="button primary" type="submit">Add meal</button></form></article><?php endif; ?><article class="panel span-3"><h2>Upcoming household meals</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Date</th><th scope="col">Meal</th><th scope="col">Recipe</th><th scope="col">Family members</th><th scope="col">Servings</th><th scope="col">Status</th></tr></thead><tbody><?php if ($mealItems === []): ?><tr><td colspan="6">No meals scheduled yet.</td></tr><?php endif; ?><?php foreach ($mealItems as $meal): ?><tr><td><?= e((string)$meal['meal_date']) ?></td><td><?= e(ucfirst((string)$meal['meal_type'])) ?></td><td><strong><?= e((string)($meal['recipe_name'] ?: 'Open meal')) ?></strong><br><small><?= e((string)$meal['plan_name']) ?></small></td><td><?= e((string)($meal['member_names'] ?: 'No members')) ?></td><td><?= e((string)$meal['planned_servings']) ?></td><td><?= e(ucfirst((string)$meal['status'])) ?></td></tr><?php endforeach; ?></tbody></table></div></article></section>
<section class="panel" id="prepared-food"><h2>Prepared food and leftovers</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Food</th><th scope="col">Prepared by</th><th scope="col">Produced</th><th scope="col">Remaining</th><th scope="col">Storage</th><th scope="col">Use by</th><th scope="col">Status</th></tr></thead><tbody><?php if ($preparedFoods === []): ?><tr><td colspan="7">Complete a recipe to create the first prepared-food batch.</td></tr><?php endif; ?><?php foreach ($preparedFoods as $batch): ?><tr><td><strong><?= e((string)$batch['name']) ?></strong></td><td><?= e((string)($batch['prepared_by'] ?: 'Household')) ?></td><td><?= e((string)$batch['servings_produced']) ?></td><td><?= e((string)$batch['servings_remaining']) ?></td><td><?= e(ucwords(str_replace('_',' ',(string)$batch['storage_method']))) ?><?= $batch['location_name'] ? ' · ' . e((string)$batch['location_name']) : '' ?></td><td><?= e((string)($batch['use_by_date'] ?: 'Not set')) ?></td><td><?= e(ucfirst((string)$batch['status'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main></body></html>
