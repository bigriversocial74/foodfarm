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

$readinessQuery = $pdo->prepare(
    "SELECT ri.recipe_id,
            COUNT(*) AS ingredient_total,
            SUM(CASE
                WHEN ri.optional = 1 THEN 1
                WHEN ii.id IS NOT NULL AND ii.current_quantity >= ri.quantity THEN 1
                ELSE 0
            END) AS ingredient_ready
     FROM recipe_ingredients ri
     JOIN recipes r ON r.id = ri.recipe_id AND r.household_id = ? AND r.status = 'active'
     LEFT JOIN inventory_items ii
       ON ii.id = ri.inventory_item_id
      AND ii.household_id = r.household_id
      AND ii.status = 'active'
     GROUP BY ri.recipe_id"
);
$readinessQuery->execute([$householdId]);
$readinessByRecipe = [];
foreach ($readinessQuery->fetchAll() as $row) {
    $total = (int)$row['ingredient_total'];
    $ready = (int)$row['ingredient_ready'];
    $readinessByRecipe[(int)$row['recipe_id']] = [
        'total' => $total,
        'ready' => $ready,
        'missing' => max(0, $total - $ready),
        'percent' => $total > 0 ? (int)round(($ready / $total) * 100) : 0,
    ];
}

$categoryCounts = [];
$shoppingGapCount = 0;
$pantryReadyCount = 0;
$gardenFreshCount = 0;
foreach ($recipes as &$recipe) {
    $recipeId = (int)$recipe['id'];
    $readiness = $readinessByRecipe[$recipeId] ?? ['total' => 0, 'ready' => 0, 'missing' => 0, 'percent' => 0];
    $recipe['match_percent'] = $readiness['percent'];
    $recipe['missing_ingredients'] = $readiness['missing'];
    $shoppingGapCount += $readiness['missing'];
    if ($readiness['total'] > 0 && $readiness['percent'] >= 90) {
        $pantryReadyCount++;
    }

    $category = trim((string)($recipe['category'] ?? ''));
    $category = $category !== '' ? $category : 'Uncategorized';
    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
    if (preg_match('/garden|vegetable|fresh|herb|salad|tomato|zucchini|bean/i', $category . ' ' . (string)$recipe['name'])) {
        $gardenFreshCount++;
    }
}
unset($recipe);
arsort($categoryCounts);

$selectedRecipeId = (int)($_GET['recipe'] ?? ($recipes[0]['id'] ?? 0));
$selectedRecipe = null;
$selectedIngredients = [];
if ($selectedRecipeId > 0) {
    $recipeQuery = $pdo->prepare("SELECT * FROM recipes WHERE id = ? AND household_id = ? AND status = 'active' LIMIT 1");
    $recipeQuery->execute([$selectedRecipeId, $householdId]);
    $selectedRecipe = $recipeQuery->fetch() ?: null;
    if (is_array($selectedRecipe)) {
        $selectedReadiness = $readinessByRecipe[$selectedRecipeId] ?? ['total' => 0, 'ready' => 0, 'missing' => 0, 'percent' => 0];
        $selectedRecipe['match_percent'] = $selectedReadiness['percent'];
        $selectedRecipe['missing_ingredients'] = $selectedReadiness['missing'];
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

$imageForRecipe = static function (array $recipe): string {
    $haystack = strtolower((string)($recipe['category'] ?? '') . ' ' . (string)($recipe['name'] ?? ''));
    return match (true) {
        str_contains($haystack, 'preserv'), str_contains($haystack, 'pickle'), str_contains($haystack, 'ferment')
            => 'assets/images/homestead/sheet-05/preservation-jars-wide.png',
        str_contains($haystack, 'garden'), str_contains($haystack, 'vegetable'), str_contains($haystack, 'salad'), str_contains($haystack, 'herb')
            => 'assets/images/homestead/sheet-04/basil-grow-light-closeup.png',
        str_contains($haystack, 'soup'), str_contains($haystack, 'stew'), str_contains($haystack, 'pot')
            => 'assets/images/homestead/sheet-05/fermentation-crock.png',
        str_contains($haystack, 'bread'), str_contains($haystack, 'dough'), str_contains($haystack, 'bake')
            => 'assets/images/homestead/sheet-05/dehydrated-food-jars.png',
        default => 'assets/images/homestead/sheet-05/labeled-pickle-jar.png',
    };
};
$recipeMinutes = static function (array $recipe): int {
    return max(0, (int)($recipe['prep_minutes'] ?? 0))
        + max(0, (int)($recipe['cook_minutes'] ?? 0))
        + max(0, (int)($recipe['rest_minutes'] ?? 0));
};
$categorySlug = static function (string $value): string {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    return trim($slug, '-');
};

$matchingRecipes = $recipes;
usort($matchingRecipes, static function (array $left, array $right): int {
    $match = (int)$right['match_percent'] <=> (int)$left['match_percent'];
    return $match !== 0 ? $match : strcmp((string)$left['name'], (string)$right['name']);
});
$matchingRecipes = array_slice($matchingRecipes, 0, 5);
$seasonalRecipes = array_slice($matchingRecipes !== [] ? $matchingRecipes : $recipes, 0, 4);

$lowStockItems = $inventoryItems;
usort($lowStockItems, static fn(array $left, array $right): int => (float)$left['current_quantity'] <=> (float)$right['current_quantity']);
$lowStockItems = array_slice($lowStockItems, 0, 4);
$spotlightItems = $inventoryItems;
usort($spotlightItems, static fn(array $left, array $right): int => (float)$right['current_quantity'] <=> (float)$left['current_quantity']);
$spotlightItems = array_slice($spotlightItems, 0, 4);

$activePlan = $mealPlans[0] ?? null;
$weekStart = new DateTimeImmutable('monday this week');
if (is_array($activePlan) && !empty($activePlan['starts_on'])) {
    $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$activePlan['starts_on']);
    if ($candidate instanceof DateTimeImmutable) {
        $weekStart = $candidate;
    }
} elseif ($mealItems !== []) {
    $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$mealItems[0]['meal_date']);
    if ($candidate instanceof DateTimeImmutable) {
        $weekStart = $candidate;
    }
}
$weekDays = [];
for ($day = 0; $day < 7; $day++) {
    $weekDays[] = $weekStart->modify('+' . $day . ' days');
}
$weekEnd = $weekDays[6];
$mealGrid = [];
foreach ($mealItems as $meal) {
    $date = (string)$meal['meal_date'];
    $type = (string)$meal['meal_type'];
    $mealGrid[$date][$type][] = $meal;
}
$mealTypes = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'snack' => 'Snack'];
$flashes = consume_flashes();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title>Recipes & Meal Planning · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to recipes</a>
<main id="main-content" class="page-container recipe-workspace">
    <header class="recipe-hero">
        <div class="recipe-hero__copy">
            <p class="recipe-kicker">Connected food workflows</p>
            <h1>Recipes &amp; Meal Planning <span aria-hidden="true">❧</span></h1>
            <p>Discover, plan, and cook with real food from your household pantry, garden, and preservation records.</p>
        </div>
        <div class="recipe-hero__art" aria-hidden="true"></div>
    </header>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="recipe-notice recipe-notice--<?= $message['type'] === 'error' ? 'warning' : 'success' ?>">
            <?= e((string)$message['message']) ?>
        </div>
    <?php endforeach; ?>

    <div class="recipe-layout">
        <div class="recipe-main-column">
            <section class="recipe-discovery" aria-labelledby="recipe-discovery-title">
                <h2 class="visually-hidden" id="recipe-discovery-title">Recipe discovery</h2>
                <div class="recipe-search-row">
                    <label class="recipe-search">
                        <span aria-hidden="true">⌕</span>
                        <span class="visually-hidden">Search recipes</span>
                        <input type="search" placeholder="Search recipes, ingredients, or categories…" data-recipe-search>
                    </label>
                    <button class="recipe-filter-button" type="button" data-recipe-filter-toggle aria-expanded="false">☷ Filters</button>
                </div>
                <div class="recipe-saved-filters" data-recipe-filters>
                    <span>Saved filters</span>
                    <button type="button" class="is-active" data-recipe-filter="all">All recipes</button>
                    <button type="button" data-recipe-filter="ready">Pantry ready</button>
                    <?php foreach (array_slice(array_keys($categoryCounts), 0, 4) as $category): ?>
                        <button type="button" data-recipe-filter="<?= e($categorySlug((string)$category)) ?>"><?= e((string)$category) ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="recipe-metrics">
                    <article><span>Suggested for you</span><strong><?= min(8, count($recipes)) ?></strong><small>recipes from your library</small><b aria-hidden="true">❧</b></article>
                    <article><span>Pantry-ready meals</span><strong><?= $pantryReadyCount ?></strong><small>ready with current stock</small><b aria-hidden="true">♨</b></article>
                    <article><span>Shopping gaps</span><strong><?= $shoppingGapCount ?></strong><small>linked ingredients missing</small><b aria-hidden="true">▣</b></article>
                    <article><span>Garden fresh</span><strong><?= $gardenFreshCount ?></strong><small>recipes using fresh produce</small><b aria-hidden="true">♧</b></article>
                </div>
            </section>

            <section class="recipe-section" aria-labelledby="browse-category-title">
                <div class="recipe-section__heading">
                    <div><p>Browse the library</p><h2 id="browse-category-title">Browse by Category</h2></div>
                    <span><?= count($recipes) ?> active recipes</span>
                </div>
                <div class="recipe-category-grid">
                    <?php if ($categoryCounts === []): ?>
                        <div class="recipe-empty">Create the first household recipe to begin organizing the library.</div>
                    <?php endif; ?>
                    <?php foreach (array_slice($categoryCounts, 0, 6, true) as $category => $count):
                        $categoryRecipe = null;
                        foreach ($recipes as $candidate) {
                            $candidateCategory = trim((string)($candidate['category'] ?? '')) ?: 'Uncategorized';
                            if ($candidateCategory === $category) {
                                $categoryRecipe = $candidate;
                                break;
                            }
                        }
                        $categoryImage = $imageForRecipe(is_array($categoryRecipe) ? $categoryRecipe : ['category' => $category, 'name' => $category]);
                    ?>
                        <button class="recipe-category-card" type="button" data-recipe-filter="<?= e($categorySlug((string)$category)) ?>">
                            <span class="recipe-category-card__image" style="background-image:url('<?= e($categoryImage) ?>')"></span>
                            <strong><?= e((string)$category) ?></strong>
                            <small><?= (int)$count ?> recipe<?= (int)$count === 1 ? '' : 's' ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="recipe-section" aria-labelledby="library-title">
                <div class="recipe-section__heading">
                    <div><p>Pantry and garden aware</p><h2 id="library-title">Recipe Library</h2></div>
                    <span data-recipe-result-count><?= count($recipes) ?> shown</span>
                </div>
                <div class="recipe-card-grid" data-recipe-grid>
                    <?php if ($recipes === []): ?>
                        <div class="recipe-empty">No active household recipes yet.</div>
                    <?php endif; ?>
                    <?php foreach ($recipes as $recipe):
                        $category = trim((string)($recipe['category'] ?? '')) ?: 'Uncategorized';
                        $minutes = $recipeMinutes($recipe);
                        $match = (int)$recipe['match_percent'];
                    ?>
                        <article class="recipe-card" data-recipe-card data-category="<?= e($categorySlug($category)) ?>" data-readiness="<?= $match >= 90 ? 'ready' : 'gaps' ?>" data-search="<?= e(strtolower((string)$recipe['name'] . ' ' . $category . ' ' . (string)($recipe['creator_name'] ?? ''))) ?>">
                            <a class="recipe-card__image" href="phase4.php?recipe=<?= (int)$recipe['id'] ?>" style="background-image:url('<?= e($imageForRecipe($recipe)) ?>')" aria-label="Open <?= e((string)$recipe['name']) ?>"></a>
                            <div class="recipe-card__body">
                                <p><?= e($category) ?></p>
                                <h3><a href="phase4.php?recipe=<?= (int)$recipe['id'] ?>"><?= e((string)$recipe['name']) ?></a></h3>
                                <div><span><?= $minutes > 0 ? $minutes . ' min' : e((string)$recipe['servings']) . ' servings' ?></span><strong class="<?= $match >= 90 ? 'is-ready' : '' ?>"><?= $match ?>% match</strong></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="recipe-empty recipe-empty--filtered" data-recipe-empty hidden>No recipes match the current search and filters.</p>
            </section>

            <?php if (is_array($selectedRecipe)): ?>
                <section class="recipe-detail" aria-labelledby="selected-recipe-title">
                    <div class="recipe-detail__hero" style="background-image:linear-gradient(90deg,rgba(8,7,5,.96),rgba(8,7,5,.62),rgba(8,7,5,.18)),url('<?= e($imageForRecipe($selectedRecipe)) ?>')">
                        <p>Selected recipe</p>
                        <h2 id="selected-recipe-title"><?= e((string)$selectedRecipe['name']) ?></h2>
                        <div class="recipe-detail__meta">
                            <span><?= e((string)($selectedRecipe['category'] ?: 'Uncategorized')) ?></span>
                            <span><?= e((string)$selectedRecipe['servings']) ?> servings</span>
                            <span><?= (int)$selectedRecipe['match_percent'] ?>% pantry match</span>
                        </div>
                    </div>
                    <div class="recipe-detail__grid">
                        <article class="recipe-ingredient-panel">
                            <div class="recipe-panel-heading"><h3>Ingredients</h3><span><?= count($selectedIngredients) ?> linked</span></div>
                            <?php if ($selectedIngredients === []): ?>
                                <p class="recipe-empty">No ingredients linked yet.</p>
                            <?php else: ?>
                                <div class="recipe-ingredient-list">
                                    <?php foreach ($selectedIngredients as $ingredient):
                                        $available = $ingredient['inventory_name'] && (float)$ingredient['current_quantity'] >= (float)$ingredient['quantity'];
                                    ?>
                                        <div>
                                            <span class="recipe-ingredient-status <?= $available ? 'is-ready' : '' ?>" aria-hidden="true"></span>
                                            <div><strong><?= e((string)$ingredient['ingredient_name']) ?></strong><small><?= (int)$ingredient['optional'] === 1 ? 'Optional · ' : '' ?><?= e((string)$ingredient['quantity']) ?> <?= e((string)$ingredient['unit']) ?></small></div>
                                            <span><?= $ingredient['inventory_name'] ? e((string)$ingredient['current_quantity'] . ' ' . (string)$ingredient['inventory_unit']) : 'Not linked' ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                        <article class="recipe-instructions-panel">
                            <div class="recipe-panel-heading"><h3>Instructions</h3><span><?= $recipeMinutes($selectedRecipe) > 0 ? $recipeMinutes($selectedRecipe) . ' min total' : 'Household method' ?></span></div>
                            <p><?= nl2br(e((string)($selectedRecipe['instructions'] ?: 'No instructions recorded yet.'))) ?></p>
                        </article>
                    </div>

                    <div class="recipe-action-grid">
                        <?php if ($canManageRecipes): ?>
                            <details class="recipe-action-panel">
                                <summary>Add an ingredient <span>＋</span></summary>
                                <form method="post" class="recipe-form-grid">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="add_ingredient">
                                    <input type="hidden" name="recipe_id" value="<?= (int)$selectedRecipe['id'] ?>">
                                    <label>Ingredient name<input name="ingredient_name" maxlength="180" required></label>
                                    <label>Quantity<input type="number" step="0.0001" min="0.0001" name="quantity" required></label>
                                    <label>Unit<input name="unit" maxlength="30" required></label>
                                    <label class="span-2">Inventory item<select name="inventory_item_id"><option value="">Not linked</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label>
                                    <label class="recipe-checkbox"><input type="checkbox" name="optional" value="1"> Optional ingredient</label>
                                    <button class="recipe-primary-button" type="submit">Add ingredient</button>
                                </form>
                            </details>
                        <?php endif; ?>
                        <?php if ($canCompleteRecipes): ?>
                            <details class="recipe-action-panel">
                                <summary>Cook and record batch <span>＋</span></summary>
                                <form method="post" class="recipe-form-grid">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="complete_recipe">
                                    <input type="hidden" name="recipe_id" value="<?= (int)$selectedRecipe['id'] ?>">
                                    <label>Scale factor<input type="number" step="0.25" min="0.25" name="scale_factor" value="1" required></label>
                                    <label>Actual servings<input type="number" step="0.25" min="0.25" name="actual_servings" value="<?= e((string)$selectedRecipe['servings']) ?>" required></label>
                                    <label>Storage method<select name="storage_method"><option value="refrigerated">Refrigerated</option><option value="frozen">Frozen</option><option value="counter">Counter</option><option value="shelf_stable">Shelf stable</option></select></label>
                                    <label>Storage location<select name="storage_location_id"><option value="">Unassigned</option><?php foreach ($locations as $location): ?><option value="<?= (int)$location['id'] ?>"><?= e((string)$location['name']) ?></option><?php endforeach; ?></select></label>
                                    <label>Use-by date<input type="date" name="use_by_date" min="<?= e(gmdate('Y-m-d')) ?>"></label>
                                    <label>Reheating notes<input name="reheating_notes" maxlength="5000"></label>
                                    <fieldset class="span-2"><legend>Intended family members</legend><div class="recipe-member-options"><?php foreach ($members as $member): ?><label><input type="checkbox" name="intended_member_ids[]" value="<?= (int)$member['id'] ?>" checked> <?= e((string)$member['display_name']) ?> · ×<?= e((string)$member['serving_multiplier']) ?></label><?php endforeach; ?></div></fieldset>
                                    <label class="span-2">Completion notes<textarea name="notes" maxlength="5000"></textarea></label>
                                    <button class="recipe-primary-button span-2" type="submit">Deduct ingredients and create prepared food</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="recipe-planner" id="meal-planning" aria-labelledby="meal-planner-title">
                <div class="recipe-section__heading recipe-planner__heading">
                    <div><p>Household schedule</p><h2 id="meal-planner-title">Weekly Meal Planner</h2><span><?= e($weekStart->format('M j')) ?> – <?= e($weekEnd->format('M j, Y')) ?></span></div>
                    <strong><?= is_array($activePlan) ? e((string)$activePlan['name']) : 'No active plan' ?></strong>
                </div>
                <div class="recipe-week" role="table" aria-label="Weekly household meal plan">
                    <div class="recipe-week__corner" role="columnheader">Meal</div>
                    <?php foreach ($weekDays as $date): ?>
                        <div class="recipe-week__day" role="columnheader"><strong><?= e($date->format('D')) ?></strong><span><?= e($date->format('M j')) ?></span></div>
                    <?php endforeach; ?>
                    <?php foreach ($mealTypes as $mealType => $mealLabel): ?>
                        <div class="recipe-week__meal-label" role="rowheader"><?= e($mealLabel) ?></div>
                        <?php foreach ($weekDays as $date):
                            $dateKey = $date->format('Y-m-d');
                            $scheduled = $mealGrid[$dateKey][$mealType] ?? [];
                            $firstMeal = $scheduled[0] ?? null;
                        ?>
                            <div class="recipe-week__cell <?= $firstMeal ? 'has-meal' : '' ?>" role="cell">
                                <?php if (is_array($firstMeal)): ?>
                                    <strong><?= e((string)($firstMeal['recipe_name'] ?: 'Open meal')) ?></strong>
                                    <span><?= e((string)($firstMeal['member_names'] ?: 'Household')) ?></span>
                                    <?php if (count($scheduled) > 1): ?><small>+<?= count($scheduled) - 1 ?> more</small><?php endif; ?>
                                <?php else: ?>
                                    <span class="recipe-week__empty">—</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($canManageMeals): ?>
                    <div class="recipe-action-grid recipe-planner__actions">
                        <details class="recipe-action-panel">
                            <summary>Create meal plan <span>＋</span></summary>
                            <form method="post" class="recipe-form-grid">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="create_meal_plan">
                                <label class="span-2">Name<input name="name" maxlength="160" required></label>
                                <label>Starts<input type="date" name="starts_on" required></label>
                                <label>Ends<input type="date" name="ends_on" required></label>
                                <button class="recipe-primary-button span-2" type="submit">Create plan</button>
                            </form>
                        </details>
                        <details class="recipe-action-panel">
                            <summary>Add scheduled meal <span>＋</span></summary>
                            <form method="post" class="recipe-form-grid">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="add_meal">
                                <label>Meal plan<select name="meal_plan_id" required><option value="">Choose plan</option><?php foreach ($mealPlans as $plan): ?><option value="<?= (int)$plan['id'] ?>"><?= e((string)$plan['name']) ?> · <?= e((string)$plan['starts_on']) ?>–<?= e((string)$plan['ends_on']) ?></option><?php endforeach; ?></select></label>
                                <label>Recipe<select name="recipe_id" required><option value="">Choose recipe</option><?php foreach ($recipes as $recipe): ?><option value="<?= (int)$recipe['id'] ?>"><?= e((string)$recipe['name']) ?></option><?php endforeach; ?></select></label>
                                <label>Date<input type="date" name="meal_date" required></label>
                                <label>Meal type<select name="meal_type"><option value="breakfast">Breakfast</option><option value="lunch">Lunch</option><option value="dinner">Dinner</option><option value="snack">Snack</option></select></label>
                                <label class="span-2">Notes<input name="notes" maxlength="5000"></label>
                                <fieldset class="span-2"><legend>Who is eating?</legend><div class="recipe-member-options"><?php foreach ($members as $member): ?><label><input type="checkbox" name="member_ids[]" value="<?= (int)$member['id'] ?>" checked> <?= e((string)$member['display_name']) ?> · ×<?= e((string)$member['serving_multiplier']) ?></label><?php endforeach; ?></div></fieldset>
                                <button class="recipe-primary-button span-2" type="submit">Add meal</button>
                            </form>
                        </details>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($canManageRecipes): ?>
                <section class="recipe-management" aria-labelledby="create-recipe-title">
                    <details class="recipe-action-panel recipe-action-panel--wide">
                        <summary><span><b id="create-recipe-title">Create a household recipe</b><small>Add the recipe first, then connect pantry ingredients.</small></span><i>＋</i></summary>
                        <form method="post" class="recipe-form-grid recipe-form-grid--wide">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="create_recipe">
                            <label>Name<input name="name" maxlength="180" required></label>
                            <label>Category<input name="category" maxlength="100"></label>
                            <label>Base servings<input type="number" step="0.25" min="0.25" name="servings" value="4" required></label>
                            <label>Yield quantity<input type="number" step="0.01" min="0" name="yield_quantity"></label>
                            <label>Yield unit<input name="yield_unit" maxlength="30"></label>
                            <label>Prep minutes<input type="number" min="0" name="prep_minutes"></label>
                            <label>Cook minutes<input type="number" min="0" name="cook_minutes"></label>
                            <label>Rest minutes<input type="number" min="0" name="rest_minutes"></label>
                            <label class="span-2">Instructions<textarea name="instructions" maxlength="5000"></textarea></label>
                            <button class="recipe-primary-button span-2" type="submit">Create recipe</button>
                        </form>
                    </details>
                </section>
            <?php endif; ?>

            <section class="recipe-prepared" id="prepared-food" aria-labelledby="prepared-food-title">
                <div class="recipe-section__heading"><div><p>Cooked inventory</p><h2 id="prepared-food-title">Prepared Food &amp; Leftovers</h2></div><span><?= count($preparedFoods) ?> recent batches</span></div>
                <div class="recipe-table-wrap" tabindex="0">
                    <table>
                        <thead><tr><th scope="col">Food</th><th scope="col">Prepared by</th><th scope="col">Produced</th><th scope="col">Remaining</th><th scope="col">Storage</th><th scope="col">Use by</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                        <?php if ($preparedFoods === []): ?><tr><td colspan="7">Complete a recipe to create the first prepared-food batch.</td></tr><?php endif; ?>
                        <?php foreach ($preparedFoods as $batch): ?><tr><td><strong><?= e((string)$batch['name']) ?></strong></td><td><?= e((string)($batch['prepared_by'] ?: 'Household')) ?></td><td><?= e((string)$batch['servings_produced']) ?></td><td><?= e((string)$batch['servings_remaining']) ?></td><td><?= e(ucwords(str_replace('_', ' ', (string)$batch['storage_method']))) ?><?= $batch['location_name'] ? ' · ' . e((string)$batch['location_name']) : '' ?></td><td><?= e((string)($batch['use_by_date'] ?: 'Not set')) ?></td><td><?= e(ucfirst((string)$batch['status'])) ?></td></tr><?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="recipe-rail" aria-label="Recipe intelligence">
            <section class="recipe-rail-card">
                <div class="recipe-rail-card__heading"><h2>Recipe Match</h2><span aria-hidden="true">◎</span></div>
                <p>Based on linked pantry inventory.</p>
                <div class="recipe-match-list">
                    <?php if ($matchingRecipes === []): ?><span class="recipe-empty">No recipes to match yet.</span><?php endif; ?>
                    <?php foreach ($matchingRecipes as $recipe): ?>
                        <a href="phase4.php?recipe=<?= (int)$recipe['id'] ?>">
                            <span class="recipe-match-list__image" style="background-image:url('<?= e($imageForRecipe($recipe)) ?>')"></span>
                            <span><strong><?= e((string)$recipe['name']) ?></strong><small><?= (int)$recipe['match_percent'] ?>% match</small></span>
                            <em><?= $recipeMinutes($recipe) > 0 ? $recipeMinutes($recipe) . 'm' : e((string)$recipe['servings']) . ' srv' ?></em>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="recipe-rail-card">
                <div class="recipe-rail-card__heading"><h2>Ingredient Spotlight</h2><span aria-hidden="true">♧</span></div>
                <p>High-availability ingredients.</p>
                <div class="recipe-stock-list">
                    <?php if ($spotlightItems === []): ?><span class="recipe-empty">No pantry ingredients recorded.</span><?php endif; ?>
                    <?php foreach ($spotlightItems as $item): ?><div><span aria-hidden="true">●</span><strong><?= e((string)$item['name']) ?></strong><small><?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?> available</small></div><?php endforeach; ?>
                </div>
                <a class="recipe-rail-link" href="phase2.php?section=inventory">View pantry →</a>
            </section>

            <section class="recipe-rail-card">
                <div class="recipe-rail-card__heading"><h2>Pantry Staples</h2><span aria-hidden="true">▦</span></div>
                <p>Lowest recorded quantities.</p>
                <div class="recipe-stock-list recipe-stock-list--low">
                    <?php if ($lowStockItems === []): ?><span class="recipe-empty">No pantry ingredients recorded.</span><?php endif; ?>
                    <?php foreach ($lowStockItems as $item): ?><div><span aria-hidden="true">◆</span><strong><?= e((string)$item['name']) ?></strong><small><?= e((string)$item['current_quantity']) ?> <?= e((string)$item['unit']) ?> remaining</small></div><?php endforeach; ?>
                </div>
                <a class="recipe-rail-link" href="phase7.php">Open planning →</a>
            </section>

            <section class="recipe-rail-card recipe-tip-card">
                <div class="recipe-rail-card__heading"><h2>Cooking Tip</h2><span aria-hidden="true">?</span></div>
                <blockquote>Plan one batch-cooking session around your most pantry-ready recipe, then record leftovers immediately so meal planning stays accurate.</blockquote>
                <small>Homestead Kitchen</small>
            </section>
        </aside>
    </div>
</main>
<script src="assets/js/homestead-recipes.js?v=20260727-1" defer></script>
</body>
</html>
