<?php

declare(strict_types=1);

// Compatibility identifier: Nutrition, Dietary Planning & Wellness

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
    <meta name="theme-color" content="#090806">
    <title>Nutrition, Dietary Planning &amp; Wellness · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php
$openRecommendations = array_values(array_filter($recommendations, static fn(array $row): bool => in_array((string)$row['status'], ['pending', 'accepted'], true)));
$criticalRecommendations = array_values(array_filter($openRecommendations, static fn(array $row): bool => in_array((string)$row['priority'], ['critical', 'high'], true)));
$profiledRecipes = array_values(array_filter($recipes, static fn(array $row): bool => !empty($row['nutrition_snapshot_id'])));
$recipesWithGaps = array_values(array_filter($recipes, static fn(array $row): bool => !empty($row['nutrition_snapshot_id']) && ((int)$row['missing_profile_count'] + (int)$row['unit_mismatch_count']) > 0));
$latestTrend = $trends[0] ?? null;
$previousTrend = $trends[1] ?? null;
$balanceDelta = ($latestTrend && $previousTrend) ? (float)$latestTrend['household_balance_score'] - (float)$previousTrend['household_balance_score'] : null;
$score = $assessment ? (float)$assessment['household_balance_score'] : 0.0;
?>
<a class="skip-link" href="#main-content">Skip to nutrition planning</a>
<main id="main-content" class="page-container nutrition-page">
    <section class="nutrition-hero" aria-labelledby="nutrition-title">
        <div class="nutrition-hero__copy">
            <p class="nutrition-kicker">Household food planning</p>
            <h1 id="nutrition-title">Nutrition, Dietary Planning &amp; Wellness</h1>
            <p>Connect meal plans, recipe snapshots, ingredient labels, family preferences, and allergen rules in one practical household planning view.</p>
            <div class="nutrition-hero__meta">
                <span><?= $assessment ? e((string)$assessment['meal_plan_name']) : 'No completed assessment' ?></span>
                <span><?= count($members) ?> household members</span>
                <span><?= count($openRecommendations) ?> open recommendations</span>
            </div>
        </div>
        <div class="nutrition-score-card">
            <p>Household balance score</p>
            <strong><?= $assessment ? e($number($assessment['household_balance_score'])) : '—' ?></strong>
            <div class="nutrition-score-ring" style="--score:<?= e(number_format(min(100,max(0,$score)),2,'.','')) ?>"><span></span></div>
            <small><?= $balanceDelta === null ? 'Complete assessments regularly to establish a trend.' : (($balanceDelta >= 0 ? '+' : '').number_format($balanceDelta,1).' from the previous plan') ?></small>
            <?php if ($canManage): ?><details><summary>Review a meal plan</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="run_meal_assessment"><label>Meal plan<select name="meal_plan_id" required><option value="">Choose plan</option><?php foreach($mealPlans as $plan): ?><option value="<?= (int)$plan['id'] ?>"><?= e((string)$plan['name']) ?> · <?= e((string)$plan['starts_on']) ?>–<?= e((string)$plan['ends_on']) ?></option><?php endforeach; ?></select></label><button type="submit">Run household assessment</button></form></details><?php endif; ?>
        </div>
    </section>

    <?php foreach ($flashes as $message): ?><div role="status" class="nutrition-flash nutrition-flash--<?= $message['type'] === 'error' ? 'warning' : 'good' ?>"><?= e((string)$message['message']) ?></div><?php endforeach; ?>

    <section class="nutrition-boundary" aria-labelledby="nutrition-boundary-title"><span>i</span><div><p class="nutrition-kicker">Safety boundary</p><h2 id="nutrition-boundary-title">Planning support, not clinical guidance</h2><p>Scores and optional household targets are planning aids—not diagnosis, treatment, or medical advice. Verify allergy labels against packaging, suppliers, preparation surfaces, and appropriate professional guidance.</p></div></section>

    <section class="nutrition-metrics" aria-label="Nutrition planning metrics">
        <article><span>◎</span><div><small>Household balance</small><strong><?= $assessment ? e($number($assessment['household_balance_score'])) : '—' ?></strong><p>Latest assessed meal plan</p></div></article>
        <article><span>◫</span><div><small>Data completeness</small><strong><?= $assessment ? e($number($assessment['data_completeness_percent'])).'%' : '—' ?></strong><p><?= count($profiledRecipes) ?> of <?= count($recipes) ?> recipes profiled</p></div></article>
        <article class="<?= $assessment && (int)$assessment['allergen_conflict_count'] > 0 ? 'nutrition-metric--danger' : 'nutrition-metric--good' ?>"><span>!</span><div><small>Allergen conflicts</small><strong><?= $assessment ? (int)$assessment['allergen_conflict_count'] : '—' ?></strong><p><?= count($memberAllergens) ?> active member rules</p></div></article>
        <article><span>✓</span><div><small>Open recommendations</small><strong><?= count($openRecommendations) ?></strong><p><?= count($criticalRecommendations) ?> high priority</p></div></article>
        <article><span>◇</span><div><small>Recipe intelligence</small><strong><?= count($profiledRecipes) ?></strong><p><?= count($recipesWithGaps) ?> snapshots need profile work</p></div></article>
        <article><span>⌁</span><div><small>Assessment window</small><strong><?= (int)$settings['assessment_window_days'] ?><small> days</small></strong><p>Minimum <?= (int)$settings['minimum_recipe_variety'] ?> recipe variety</p></div></article>
    </section>

    <div class="nutrition-layout">
        <div class="nutrition-primary">
            <section class="nutrition-panel" id="latest-plan">
                <div class="nutrition-panel__heading"><div><p class="nutrition-kicker">Member assessment</p><h2>Latest household plan</h2></div><?php if($assessment): ?><span><?= e((string)$assessment['starts_on']) ?>–<?= e((string)$assessment['ends_on']) ?></span><?php endif; ?></div>
                <?php if($assessment === null): ?><div class="nutrition-empty"><strong>No completed nutrition assessment</strong><p>Review a meal plan to create household and member planning scores.</p></div><?php else: ?>
                <div class="nutrition-members">
                    <?php foreach($assessmentLines as $line): $memberScore=(float)$line['balance_score']; ?>
                    <article class="nutrition-member <?= (int)$line['allergen_conflict_count'] > 0 ? 'nutrition-member--warning' : '' ?>">
                        <header><div><h3><?= e((string)$line['display_name']) ?></h3><p><?= e((string)($line['dietary_pattern'] ?? 'No dietary pattern')) ?> · <?= (int)$line['assessed_meal_count'] ?>/<?= (int)$line['planned_meal_count'] ?> meals assessed</p></div><strong><?= e($number($memberScore)) ?></strong></header>
                        <div class="nutrition-member__bars">
                            <label><span>Calories</span><i><b style="width:<?= e(number_format(min(100,max(0,(float)$line['calorie_target_coverage_percent'])),2,'.','')) ?>%"></b></i><em><?= e($number($line['calorie_target_coverage_percent'])) ?>%</em></label>
                            <label><span>Protein</span><i><b style="width:<?= e(number_format(min(100,max(0,(float)$line['protein_target_coverage_percent'])),2,'.','')) ?>%"></b></i><em><?= e($number($line['protein_target_coverage_percent'])) ?>%</em></label>
                            <label><span>Fiber</span><i><b style="width:<?= e(number_format(min(100,max(0,(float)$line['fiber_target_coverage_percent'])),2,'.','')) ?>%"></b></i><em><?= e($number($line['fiber_target_coverage_percent'])) ?>%</em></label>
                            <label><span>Sodium use</span><i><b style="width:<?= e(number_format(min(100,max(0,(float)$line['sodium_limit_usage_percent'])),2,'.','')) ?>%"></b></i><em><?= e($number($line['sodium_limit_usage_percent'])) ?>%</em></label>
                        </div>
                        <footer><span><?= (int)$line['distinct_recipe_count'] ?> distinct recipes</span><span><?= (int)$line['allergen_conflict_count'] ?> conflicts</span></footer>
                    </article>
                    <?php endforeach; ?>
                </div><?php endif; ?>
            </section>

            <section class="nutrition-panel" id="nutrition-recommendations">
                <div class="nutrition-panel__heading"><div><p class="nutrition-kicker">Action queue</p><h2>Nutrition recommendations</h2></div><span><?= count($openRecommendations) ?> open</span></div>
                <div class="nutrition-recommendations">
                    <?php if($recommendations===[]): ?><div class="nutrition-empty"><strong>No recommendations yet</strong><p>Meal-plan assessments will generate evidence-linked household actions.</p></div><?php endif; ?>
                    <?php foreach($recommendations as $recommendation): ?>
                    <article class="nutrition-recommendation nutrition-recommendation--<?= e((string)$recommendation['priority']) ?>">
                        <div><span><?= e(ucfirst((string)$recommendation['priority'])) ?></span><small><?= e((string)($recommendation['display_name'] ?? 'Household')) ?></small></div>
                        <h3><?= e((string)$recommendation['title']) ?></h3><p><?= e((string)$recommendation['rationale']) ?></p><strong><?= e((string)$recommendation['recommended_action']) ?></strong>
                        <footer><span><?= e(ucfirst((string)$recommendation['status'])) ?></span><?php if($canManage): ?><div><?php if((string)$recommendation['status']==='pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button name="action" value="accept_recommendation" type="submit">Create task</button><button name="action" value="dismiss_recommendation" type="submit" class="secondary">Dismiss</button></form><?php elseif((string)$recommendation['status']==='accepted'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button name="action" value="complete_recommendation" type="submit" class="secondary">Mark complete</button></form><?php endif; ?></div><?php endif; ?></footer>
                    </article><?php endforeach; ?>
                </div>
            </section>

            <section class="nutrition-panel" id="recipe-intelligence">
                <div class="nutrition-panel__heading nutrition-panel__heading--toolbar"><div><p class="nutrition-kicker">Recipe intelligence</p><h2>Nutrition snapshots</h2></div><label class="nutrition-search"><span>⌕</span><input type="search" placeholder="Search recipes" data-nutrition-search></label></div>
                <div class="nutrition-recipe-grid" data-nutrition-list>
                    <?php foreach($recipes as $recipe): $allergens=json_decode((string)($recipe['allergen_keys']??'[]'),true); $gaps=$recipe['nutrition_snapshot_id']?((int)$recipe['missing_profile_count']+(int)$recipe['unit_mismatch_count']):null; ?>
                    <article data-search="<?= e(strtolower((string)$recipe['name'].' '.(is_array($allergens)?implode(' ',$allergens):''))) ?>"><header><h3><?= e((string)$recipe['name']) ?></h3><span><?= $recipe['nutrition_snapshot_id'] ? ($gaps===0?'Profiled':'Needs data') : 'Not calculated' ?></span></header><div><strong><?= e($number($recipe['calories_per_serving'],0)) ?><small> cal</small></strong><span><?= e($number($recipe['protein_per_serving_g'])) ?>g protein</span><span><?= e($number($recipe['fiber_per_serving_g'])) ?>g fiber</span><span><?= e($number($recipe['sodium_per_serving_mg'],0)) ?>mg sodium</span></div><footer><?= e(is_array($allergens)&&$allergens!==[]?implode(', ',$allergens):'No allergen tags') ?></footer></article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="nutrition-panel"><div class="nutrition-panel__heading"><div><p class="nutrition-kicker">Assessment history</p><h2>Recent trend</h2></div></div><div class="nutrition-trends"><?php if($trends===[]): ?><div class="nutrition-empty"><strong>No assessment history</strong><p>Repeated plan reviews will build a household trend.</p></div><?php endif; ?><?php foreach(array_reverse($trends) as $trend): ?><article><div class="nutrition-trend-bars"><i style="height:<?= e(number_format((float)$trend['data_completeness_percent'],2,'.','')) ?>%"></i><b style="height:<?= e(number_format((float)$trend['household_balance_score'],2,'.','')) ?>%"></b></div><small><?= e(date('M j',strtotime((string)$trend['starts_on']))) ?></small><span><?= e($number($trend['household_balance_score'])) ?></span></article><?php endforeach; ?></div></section>
        </div>

        <aside class="nutrition-sidebar">
            <section class="nutrition-panel"><div class="nutrition-panel__heading"><div><p class="nutrition-kicker">Family rules</p><h2>Allergens &amp; preferences</h2></div></div><div class="nutrition-rule-list"><?php if($memberAllergens===[]): ?><div class="nutrition-empty"><strong>No active member rules</strong><p>Add household preferences, intolerances, or allergy rules.</p></div><?php endif; ?><?php foreach($memberAllergens as $rule): ?><article><div><strong><?= e((string)$rule['display_name']) ?></strong><p><?= e((string)$rule['allergen_key']) ?></p></div><span><?= e(ucfirst((string)$rule['severity'])) ?></span></article><?php endforeach; ?></div></section>
            <section class="nutrition-panel"><div class="nutrition-panel__heading"><div><p class="nutrition-kicker">Ingredient safety</p><h2>Active allergen labels</h2></div></div><div class="nutrition-rule-list"><?php if($inventoryAllergens===[]): ?><div class="nutrition-empty"><strong>No ingredient tags</strong><p>Add package-label and facility warnings.</p></div><?php endif; ?><?php foreach(array_slice($inventoryAllergens,0,18) as $tag): ?><article><div><strong><?= e((string)$tag['inventory_item_name']) ?></strong><p><?= e((string)$tag['allergen_key']) ?></p></div><span><?= e(ucwords(str_replace('_',' ',(string)$tag['presence']))) ?></span></article><?php endforeach; ?></div></section>

            <?php if($canManage): ?><section class="nutrition-panel nutrition-controls"><div class="nutrition-panel__heading"><div><p class="nutrition-kicker">Planning controls</p><h2>Configure intelligence</h2></div></div>
                <details><summary>Assessment settings</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="save_settings"><label>Assessment window, days<input type="number" min="1" max="31" name="assessment_window_days" value="<?= (int)$settings['assessment_window_days'] ?>" required></label><label>Minimum recipe variety<input type="number" min="1" max="100" name="minimum_recipe_variety" value="<?= (int)$settings['minimum_recipe_variety'] ?>" required></label><label>Minimum data completeness, %<input type="number" min="0" max="100" step="0.01" name="minimum_data_completeness_percent" value="<?= e((string)$settings['minimum_data_completeness_percent']) ?>" required></label><label class="check"><input type="checkbox" name="show_optional_targets" value="1" <?= (int)$settings['show_optional_targets']===1?'checked':'' ?>> Show optional target comparisons</label><button type="submit">Save settings</button></form></details>
                <details><summary>Calculate recipe snapshot</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="calculate_recipe_nutrition"><label>Recipe<select name="recipe_id" required><option value="">Choose recipe</option><?php foreach($recipes as $recipe): ?><option value="<?= (int)$recipe['id'] ?>"><?= e((string)$recipe['name']) ?></option><?php endforeach; ?></select></label><label>As-of date<input type="date" name="as_of_date" value="<?= e($today) ?>" required></label><button type="submit">Calculate nutrition</button></form></details>
                <details><summary>Member planning profile</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="save_member_profile"><label>Member<select name="household_member_id" required><option value="">Choose member</option><?php foreach($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label><label>Dietary pattern<input name="dietary_pattern" maxlength="120"></label><div class="nutrition-form-pair"><label>Calories<input type="number" min="0" step="0.01" name="calorie_target"></label><label>Protein, g<input type="number" min="0" step="0.01" name="protein_target_g"></label><label>Fiber, g<input type="number" min="0" step="0.01" name="fiber_target_g"></label><label>Sodium, mg<input type="number" min="0" step="0.01" name="sodium_limit_mg"></label></div><label>Added sugar, g<input type="number" min="0" step="0.01" name="added_sugar_limit_g"></label><label>Notes<textarea name="target_notes" maxlength="5000"></textarea></label><button type="submit">Save member profile</button></form></details>
                <details><summary>Member allergen rule</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="save_member_allergen"><label>Member<select name="household_member_id" required><option value="">Choose member</option><?php foreach($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label><label>Allergen key<input name="allergen_key" maxlength="80" required></label><label>Severity<select name="severity"><option value="preference">Preference</option><option value="intolerance">Intolerance</option><option value="allergy">Allergy</option></select></label><label>Notes<textarea name="notes" maxlength="500"></textarea></label><input type="hidden" name="active" value="1"><button type="submit">Save member rule</button></form></details>
                <details><summary>Ingredient nutrition profile</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="save_inventory_nutrition"><label>Inventory item<select name="inventory_item_id" required><option value="">Choose item</option><?php foreach($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?> · <?= e((string)$item['unit']) ?></option><?php endforeach; ?></select></label><div class="nutrition-form-pair"><label>Basis quantity<input type="number" min="0.0001" step="0.0001" name="basis_quantity" value="1" required></label><label>Basis unit<input name="basis_unit" maxlength="30" required></label><label>Calories<input type="number" min="0" step="0.0001" name="calories"></label><label>Protein, g<input type="number" min="0" step="0.0001" name="protein_g"></label><label>Carbohydrate, g<input type="number" min="0" step="0.0001" name="carbohydrate_g"></label><label>Fat, g<input type="number" min="0" step="0.0001" name="fat_g"></label><label>Fiber, g<input type="number" min="0" step="0.0001" name="fiber_g"></label><label>Total sugar, g<input type="number" min="0" step="0.0001" name="total_sugar_g"></label><label>Added sugar, g<input type="number" min="0" step="0.0001" name="added_sugar_g"></label><label>Sodium, mg<input type="number" min="0" step="0.0001" name="sodium_mg"></label></div><label>Source label<input name="source_label" maxlength="190"></label><label>Confidence<select name="confidence"><option value="estimated">Estimated</option><option value="label">Package label</option><option value="verified">Household verified</option></select></label><button type="submit">Save nutrition profile</button></form></details>
                <details><summary>Ingredient allergen tag</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="save_inventory_allergen"><label>Inventory item<select name="inventory_item_id" required><option value="">Choose item</option><?php foreach($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['name']) ?></option><?php endforeach; ?></select></label><label>Allergen key<input name="allergen_key" maxlength="80" required></label><label>Presence<select name="presence"><option value="contains">Contains</option><option value="may_contain">May contain</option><option value="shared_facility">Shared facility</option></select></label><label>Source label<input name="source_label" maxlength="190"></label><input type="hidden" name="active" value="1"><button type="submit">Save allergen tag</button></form></details>
            </section><?php endif; ?>
        </aside>
    </div>
</main>
<script src="assets/js/homestead-nutrition.js" defer></script>
</body>
</html>
