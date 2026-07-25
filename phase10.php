<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/NutritionService.php';

use Homestead\NutritionService;
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
$canView = $auth->can($user, 'nutrition.view') || $auth->can($user, 'nutrition.manage');
$canManage = $auth->can($user, 'nutrition.manage');
if (!$canView) {
    http_response_code(403);
    exit('You do not have permission to view household nutrition planning.');
}

$service = new NutritionService($pdo);
if (!isset($_SESSION['phase10_action_key']) || !is_string($_SESSION['phase10_action_key'])
    || preg_match('/^[a-f0-9]{64}$/', $_SESSION['phase10_action_key']) !== 1) {
    $_SESSION['phase10_action_key'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $postedActionKey = strtolower(trim((string)($_POST['action_key'] ?? '')));
        if (!hash_equals((string)$_SESSION['phase10_action_key'], $postedActionKey)) {
            throw new RuntimeException('This nutrition planning form has expired. Refresh and try again.');
        }
        if (!$canManage) {
            throw new RuntimeException('You do not have permission to change household nutrition records.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_settings') {
            $service->saveSettings($householdId, $memberId, $_POST);
            flash('success', 'Household nutrition planning settings were updated.');
        } elseif ($action === 'save_member_profile') {
            $service->saveMemberProfile($householdId, $memberId, $_POST);
            flash('success', 'Member nutrition planning profile was updated.');
        } elseif ($action === 'save_member_allergen') {
            $service->saveMemberAllergenRule($householdId, $memberId, $_POST);
            flash('success', 'Member allergen or dietary rule was saved.');
        } elseif ($action === 'save_inventory_nutrition') {
            $service->saveInventoryNutrition($householdId, $memberId, $_POST);
            flash('success', 'Ingredient nutrition profile was saved.');
        } elseif ($action === 'save_inventory_allergen') {
            $service->saveInventoryAllergenTag($householdId, $memberId, $_POST);
            flash('success', 'Ingredient allergen tag was saved.');
        } elseif ($action === 'calculate_recipe_nutrition') {
            $result = $service->calculateRecipeNutrition(
                $householdId,
                $memberId,
                (int)($_POST['recipe_id'] ?? 0),
                (string)($_POST['as_of_date'] ?? date('Y-m-d'))
            );
            flash(
                'success',
                $result['reused']
                    ? 'The unchanged recipe nutrition snapshot was reused.'
                    : sprintf(
                        'Recipe snapshot saved at %.0f calories, %.1fg protein, and %.1fg fiber per serving. Missing profiles: %d; unit mismatches: %d.',
                        $result['calories_per_serving'],
                        $result['protein_per_serving_g'],
                        $result['fiber_per_serving_g'],
                        $result['missing_profiles'],
                        $result['unit_mismatches']
                    )
            );
        } elseif ($action === 'run_meal_assessment') {
            $result = $service->runMealAssessment(
                $householdId,
                $memberId,
                (int)($_POST['meal_plan_id'] ?? 0)
            );
            flash(
                'success',
                $result['reused']
                    ? 'The unchanged meal-plan assessment was reused.'
                    : sprintf(
                        'Meal-plan assessment complete: %.1f balance score, %.1f%% data completeness, %d allergen conflicts, and %d recommendations.',
                        $result['household_balance_score'],
                        $result['data_completeness_percent'],
                        $result['allergen_conflict_count'],
                        $result['recommendation_count']
                    )
            );
        } elseif ($action === 'accept_recommendation') {
            $taskId = $service->acceptRecommendation(
                $householdId,
                $memberId,
                (int)($_POST['recommendation_id'] ?? 0)
            );
            flash('success', 'Nutrition recommendation accepted as household task #' . $taskId . '.');
        } elseif ($action === 'dismiss_recommendation') {
            $service->dismissRecommendation(
                $householdId,
                $memberId,
                (int)($_POST['recommendation_id'] ?? 0)
            );
            flash('success', 'Nutrition recommendation dismissed.');
        } elseif ($action === 'complete_recommendation') {
            $service->completeRecommendation(
                $householdId,
                $memberId,
                (int)($_POST['recommendation_id'] ?? 0)
            );
            flash('success', 'Nutrition recommendation marked complete.');
        } else {
            throw new InvalidArgumentException('Unknown nutrition planning action.');
        }

        $_SESSION['phase10_action_key'] = bin2hex(random_bytes(32));
        redirect('/phase10.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/phase10.php');
    }
}

$data = $service->dashboardData($householdId);
$settings = $data['settings'];
$assessment = $data['assessment'];
$assessmentLines = $data['assessment_lines'];
$recommendations = $data['recommendations'];
$members = $data['members'];
$memberAllergens = $data['member_allergens'];
$inventoryItems = $data['inventory_items'];
$inventoryAllergens = $data['inventory_allergens'];
$recipes = $data['recipes'];
$mealPlans = $data['meal_plans'];
$trends = $data['trends'];
$flashes = consume_flashes();
$token = csrf_token();
$actionKey = (string)$_SESSION['phase10_action_key'];
$today = date('Y-m-d');
$number = static fn(mixed $value, int $decimals = 1): string => $value === null ? '—' : number_format((float)$value, $decimals);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Nutrition, Dietary Planning & Wellness · Homestead</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to nutrition planning</a>
<main id="main-content" class="page-container">
<header class="page-header">
    <div>
        <p class="eyebrow">Household food planning</p>
        <h1>Nutrition, Dietary Planning & Wellness</h1>
        <p class="page-description">Connect household recipes, ingredient labels, meal plans, family preferences, and allergen rules. Scores and targets are user-entered planning aids—not diagnosis, treatment, or medical advice.</p>
    </div>
    <div>
        <strong><?= e((string)$user['display_name']) ?></strong><br>
        <a href="/dashboard.php">Dashboard</a> · <a href="/phase9.php">Costs & waste</a> · <a href="/phase7.php">Daily planning</a> · <a href="/logout.php">Sign out</a>
    </div>
</header>

<?php foreach ($flashes as $message): ?>
<div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div>
<?php endforeach; ?>

<section class="metrics-grid compact" aria-label="Nutrition planning metrics">
    <article class="metric-card"><div><p>Household balance score</p><strong><?= $assessment ? e($number($assessment['household_balance_score'])) : '—' ?></strong></div></article>
    <article class="metric-card"><div><p>Data completeness</p><strong><?= $assessment ? e($number($assessment['data_completeness_percent'])) . '%' : '—' ?></strong></div></article>
    <article class="metric-card"><div><p>Allergen conflicts</p><strong><?= $assessment ? (int)$assessment['allergen_conflict_count'] : '—' ?></strong></div></article>
    <article class="metric-card"><div><p>Open recommendations</p><strong><?= count(array_filter($recommendations, static fn(array $row): bool => in_array($row['status'], ['pending', 'accepted'], true))) ?></strong></div></article>
</section>

<section class="panel" style="margin-top:22px">
    <p class="eyebrow">Safety boundary</p>
    <h2>Planning support, not clinical guidance</h2>
    <p class="page-description">Homestead stores household-entered label data and optional planning targets. Allergy tags must be verified against packaging, suppliers, preparation surfaces, and professional guidance appropriate to the household.</p>
</section>

<?php if ($canManage): ?>
<section class="content-grid" style="margin-top:22px">
    <article class="panel">
        <p class="eyebrow">Assessment</p>
        <h2>Review a meal plan</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="run_meal_assessment">
            <label>Meal plan<select name="meal_plan_id" required><option value="">Choose plan</option><?php foreach ($mealPlans as $plan): ?><option value="<?= (int)$plan['id'] ?>"><?= e((string)$plan['name']) ?> · <?= e((string)$plan['starts_on']) ?>–<?= e((string)$plan['ends_on']) ?> · <?= (int)$plan['meal_count'] ?> meals</option><?php endforeach; ?></select></label>
            <button class="button primary" type="submit">Run household assessment</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Household settings</p>
        <h2>Assessment thresholds</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="save_settings">
            <label>Assessment window, days<input class="search-field" type="number" min="1" max="31" name="assessment_window_days" value="<?= (int)$settings['assessment_window_days'] ?>" required></label>
            <label>Minimum recipe variety<input class="search-field" type="number" min="1" max="100" name="minimum_recipe_variety" value="<?= (int)$settings['minimum_recipe_variety'] ?>" required></label>
            <label>Minimum data completeness, %<input class="search-field" type="number" min="0" max="100" step="0.01" name="minimum_data_completeness_percent" value="<?= e((string)$settings['minimum_data_completeness_percent']) ?>" required></label>
            <label><input type="checkbox" name="show_optional_targets" value="1" <?= (int)$settings['show_optional_targets'] === 1 ? 'checked' : '' ?>> Show optional target comparisons</label>
            <button class="button primary" type="submit">Save assessment settings</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Recipe calculation</p>
        <h2>Calculate nutrition snapshot</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="calculate_recipe_nutrition">
            <label>Recipe<select name="recipe_id" required><option value="">Choose recipe</option><?php foreach ($recipes as $recipe): ?><option value="<?= (int)$recipe['id'] ?>"><?= e((string)$recipe['name']) ?></option><?php endforeach; ?></select></label>
            <label>As-of date<input class="search-field" type="date" name="as_of_date" value="<?= e($today) ?>" required></label>
            <button class="button primary" type="submit">Calculate recipe nutrition</button>
        </form>
    </article>
</section>

<section class="content-grid" style="margin-top:22px">
    <article class="panel">
        <p class="eyebrow">Family profiles</p>
        <h2>Optional planning targets</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="save_member_profile">
            <label>Member<select name="household_member_id" required><option value="">Choose member</option><?php foreach ($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label>
            <label>Dietary pattern<input class="search-field" name="dietary_pattern" maxlength="120" placeholder="Household preference or pattern"></label>
            <label>Daily calorie target<input class="search-field" type="number" min="0" step="0.01" name="calorie_target"></label>
            <label>Daily protein target, g<input class="search-field" type="number" min="0" step="0.01" name="protein_target_g"></label>
            <label>Daily fiber target, g<input class="search-field" type="number" min="0" step="0.01" name="fiber_target_g"></label>
            <label>Daily sodium planning limit, mg<input class="search-field" type="number" min="0" step="0.01" name="sodium_limit_mg"></label>
            <label>Daily added-sugar planning limit, g<input class="search-field" type="number" min="0" step="0.01" name="added_sugar_limit_g"></label>
            <label>Notes<textarea name="target_notes" maxlength="5000"></textarea></label>
            <button class="button primary" type="submit">Save member profile</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Allergen controls</p>
        <h2>Member rule</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="save_member_allergen">
            <label>Member<select name="household_member_id" required><option value="">Choose member</option><?php foreach ($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label>
            <label>Allergen key<input class="search-field" name="allergen_key" maxlength="80" placeholder="peanut, dairy, gluten" required></label>
            <label>Severity<select name="severity"><option value="preference">Preference</option><option value="intolerance">Intolerance</option><option value="allergy">Allergy</option></select></label>
            <label>Notes<textarea name="notes" maxlength="500"></textarea></label>
            <input type="hidden" name="active" value="1">
            <button class="button primary" type="submit">Save member rule</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Ingredient labels</p>
        <h2>Nutrition profile</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="save_inventory_nutrition">
            <label>Inventory item<select name="inventory_item_id" required><option value="">Choose item</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label>
            <label>Basis quantity<input class="search-field" type="number" min="0.0001" step="0.0001" name="basis_quantity" value="1" required></label>
            <label>Basis unit<input class="search-field" name="basis_unit" maxlength="30" required></label>
            <label>Calories<input class="search-field" type="number" min="0" step="0.0001" name="calories"></label>
            <label>Protein, g<input class="search-field" type="number" min="0" step="0.0001" name="protein_g"></label>
            <label>Carbohydrate, g<input class="search-field" type="number" min="0" step="0.0001" name="carbohydrate_g"></label>
            <label>Fat, g<input class="search-field" type="number" min="0" step="0.0001" name="fat_g"></label>
            <label>Fiber, g<input class="search-field" type="number" min="0" step="0.0001" name="fiber_g"></label>
            <label>Total sugar, g<input class="search-field" type="number" min="0" step="0.0001" name="total_sugar_g"></label>
            <label>Added sugar, g<input class="search-field" type="number" min="0" step="0.0001" name="added_sugar_g"></label>
            <label>Sodium, mg<input class="search-field" type="number" min="0" step="0.0001" name="sodium_mg"></label>
            <label>Source label<input class="search-field" name="source_label" maxlength="190"></label>
            <label>Confidence<select name="confidence"><option value="estimated">Estimated</option><option value="label">Package label</option><option value="verified">Household verified</option></select></label>
            <button class="button primary" type="submit">Save nutrition profile</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Ingredient safety</p>
        <h2>Allergen tag</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="save_inventory_allergen">
            <label>Inventory item<select name="inventory_item_id" required><option value="">Choose item</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?></option><?php endforeach; ?></select></label>
            <label>Allergen key<input class="search-field" name="allergen_key" maxlength="80" required></label>
            <label>Presence<select name="presence"><option value="contains">Contains</option><option value="may_contain">May contain</option><option value="shared_facility">Shared facility</option></select></label>
            <label>Source label<input class="search-field" name="source_label" maxlength="190"></label>
            <input type="hidden" name="active" value="1">
            <button class="button primary" type="submit">Save allergen tag</button>
        </form>
    </article>
</section>
<?php endif; ?>

<section class="content-grid" style="margin-top:22px">
    <article class="panel span-2">
        <div class="panel-heading"><div><p class="eyebrow">Member assessment</p><h2>Latest household plan</h2></div></div>
        <?php if ($assessment === null): ?><p>No completed nutrition assessment yet.</p><?php else: ?>
        <p class="page-description"><?= e((string)$assessment['meal_plan_name']) ?> · <?= e((string)$assessment['starts_on']) ?>–<?= e((string)$assessment['ends_on']) ?></p>
        <div class="table-wrap"><table><thead><tr><th>Member</th><th>Meals</th><th>Variety</th><th>Calories</th><th>Protein</th><th>Fiber</th><th>Sodium use</th><th>Conflicts</th><th>Score</th></tr></thead><tbody>
        <?php foreach ($assessmentLines as $line): ?><tr><td><strong><?= e((string)$line['display_name']) ?></strong><br><small><?= e((string)($line['dietary_pattern'] ?? 'No pattern')) ?></small></td><td><?= (int)$line['assessed_meal_count'] ?>/<?= (int)$line['planned_meal_count'] ?></td><td><?= (int)$line['distinct_recipe_count'] ?></td><td><?= e($number($line['calorie_target_coverage_percent'])) ?>%</td><td><?= e($number($line['protein_target_coverage_percent'])) ?>%</td><td><?= e($number($line['fiber_target_coverage_percent'])) ?>%</td><td><?= e($number($line['sodium_limit_usage_percent'])) ?>%</td><td><?= (int)$line['allergen_conflict_count'] ?></td><td><?= e($number($line['balance_score'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>

    <article class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Family rules</p><h2>Allergens & preferences</h2></div></div>
        <?php if ($memberAllergens === []): ?><p>No active member rules.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Member</th><th>Rule</th><th>Severity</th></tr></thead><tbody><?php foreach ($memberAllergens as $rule): ?><tr><td><?= e((string)$rule['display_name']) ?></td><td><?= e((string)$rule['allergen_key']) ?></td><td><?= e((string)$rule['severity']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
</section>

<section class="content-grid" style="margin-top:22px">
    <article class="panel span-2">
        <div class="panel-heading"><div><p class="eyebrow">Recipe intelligence</p><h2>Nutrition snapshots</h2></div></div>
        <div class="table-wrap"><table><thead><tr><th>Recipe</th><th>Calories</th><th>Protein</th><th>Fiber</th><th>Sodium</th><th>Missing</th><th>Allergens</th></tr></thead><tbody>
        <?php foreach ($recipes as $recipe): $allergens = json_decode((string)($recipe['allergen_keys'] ?? '[]'), true); ?><tr><td><?= e((string)$recipe['name']) ?></td><td><?= e($number($recipe['calories_per_serving'], 0)) ?></td><td><?= e($number($recipe['protein_per_serving_g'])) ?>g</td><td><?= e($number($recipe['fiber_per_serving_g'])) ?>g</td><td><?= e($number($recipe['sodium_per_serving_mg'], 0)) ?>mg</td><td><?= $recipe['nutrition_snapshot_id'] ? ((int)$recipe['missing_profile_count'] + (int)$recipe['unit_mismatch_count']) : '—' ?></td><td><?= e(is_array($allergens) && $allergens !== [] ? implode(', ', $allergens) : '—') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>

    <article class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Ingredient tags</p><h2>Active allergen labels</h2></div></div>
        <?php if ($inventoryAllergens === []): ?><p>No ingredient allergen tags.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Item</th><th>Tag</th><th>Presence</th></tr></thead><tbody><?php foreach ($inventoryAllergens as $tag): ?><tr><td><?= e((string)$tag['inventory_item_name']) ?></td><td><?= e((string)$tag['allergen_key']) ?></td><td><?= e(str_replace('_', ' ', (string)$tag['presence'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
</section>

<section class="panel" style="margin-top:22px">
    <div class="panel-heading"><div><p class="eyebrow">Action queue</p><h2>Nutrition recommendations</h2></div></div>
    <?php if ($recommendations === []): ?><p>No recommendations yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Priority</th><th>Member</th><th>Recommendation</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($recommendations as $recommendation): ?><tr><td><?= e((string)$recommendation['priority']) ?></td><td><?= e((string)($recommendation['display_name'] ?? 'Household')) ?></td><td><strong><?= e((string)$recommendation['title']) ?></strong><br><small><?= e((string)$recommendation['rationale']) ?></small><br><?= e((string)$recommendation['recommended_action']) ?></td><td><?= e((string)$recommendation['status']) ?></td><td><?php if ($canManage && $recommendation['status'] === 'pending'): ?><form method="post" class="toolbar"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button class="button primary" name="action" value="accept_recommendation" type="submit">Create task</button><button class="button secondary" name="action" value="dismiss_recommendation" type="submit">Dismiss</button></form><?php elseif ($canManage && $recommendation['status'] === 'accepted'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button class="button secondary" name="action" value="complete_recommendation" type="submit">Mark complete</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<section class="panel" style="margin-top:22px">
    <div class="panel-heading"><div><p class="eyebrow">Assessment history</p><h2>Recent trend</h2></div></div>
    <?php if ($trends === []): ?><p>No assessment history.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Window</th><th>Balance</th><th>Completeness</th><th>Conflicts</th><th>Recommendations</th></tr></thead><tbody><?php foreach ($trends as $trend): ?><tr><td><?= e((string)$trend['starts_on']) ?>–<?= e((string)$trend['ends_on']) ?></td><td><?= e($number($trend['household_balance_score'])) ?></td><td><?= e($number($trend['data_completeness_percent'])) ?>%</td><td><?= (int)$trend['allergen_conflict_count'] ?></td><td><?= (int)$trend['recommendation_count'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
</main>
</body>
</html>