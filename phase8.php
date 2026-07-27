<?php

declare(strict_types=1);

// Compatibility identifier: Forecasting, Seasons & Self-Sufficiency

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/ForecastingService.php';

use Homestead\ForecastingService;
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

$canViewInventory = $auth->can($user, 'inventory.view') || $auth->can($user, 'inventory.manage');
$canViewGarden = $auth->can($user, 'garden.view') || $auth->can($user, 'garden.manage') || $auth->can($user, 'harvest.record');
$canViewPreservation = $auth->can($user, 'preservation.view') || $auth->can($user, 'preservation.manage');
$canViewPlanning = $auth->can($user, 'tasks.manage') || $auth->can($user, 'tasks.complete');
$canView = $canViewInventory || $canViewGarden || $canViewPreservation || $canViewPlanning;
$canManage = $auth->can($user, 'inventory.manage')
    || $auth->can($user, 'garden.manage')
    || $auth->can($user, 'preservation.manage')
    || $auth->can($user, 'tasks.manage');

if (!$canView) {
    http_response_code(403);
    exit('You do not have permission to view household forecasting.');
}

$service = new ForecastingService($pdo);
if (!isset($_SESSION['phase8_action_key']) || !is_string($_SESSION['phase8_action_key'])
    || preg_match('/^[a-f0-9]{64}$/', $_SESSION['phase8_action_key']) !== 1) {
    $_SESSION['phase8_action_key'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $postedActionKey = strtolower(trim((string)($_POST['action_key'] ?? '')));
        if (!hash_equals((string)$_SESSION['phase8_action_key'], $postedActionKey)) {
            throw new RuntimeException('This forecasting form has expired. Refresh and try again.');
        }
        if (!$canManage) {
            throw new RuntimeException('You do not have permission to change forecasting records.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_settings') {
            $service->saveSettings($householdId, $memberId, $_POST);
            flash('success', 'Forecast settings updated.');
        } elseif ($action === 'run_forecast') {
            $result = $service->runForecast(
                $householdId,
                $memberId,
                (string)($_POST['as_of_date'] ?? date('Y-m-d'))
            );
            flash(
                'success',
                $result['reused']
                    ? sprintf(
                        'The current forecast was reused: %.1f resilience, %d shortages, and %d recommendations.',
                        $result['resilience_score'],
                        $result['shortages'],
                        $result['recommendations']
                    )
                    : sprintf(
                        'Forecast complete: %.1f resilience, %d shortages, %d expected harvests, and %d recommendations.',
                        $result['resilience_score'],
                        $result['shortages'],
                        $result['harvests'],
                        $result['recommendations']
                    )
            );
        } elseif ($action === 'create_seasonal_entry') {
            $_POST['action_key'] = $postedActionKey;
            $entryId = $service->createSeasonalEntry($householdId, $memberId, $_POST);
            flash('success', 'Seasonal plan entry #' . $entryId . ' was created.');
        } elseif ($action === 'seasonal_status') {
            $service->updateSeasonalEntry(
                $householdId,
                $memberId,
                (int)($_POST['entry_id'] ?? 0),
                (string)($_POST['status'] ?? '')
            );
            flash('success', 'Seasonal plan status updated.');
        } elseif ($action === 'accept_recommendation') {
            $taskId = $service->acceptRecommendation(
                $householdId,
                $memberId,
                (int)($_POST['recommendation_id'] ?? 0)
            );
            flash('success', 'Recommendation accepted as household task #' . $taskId . '.');
        } elseif ($action === 'dismiss_recommendation') {
            $service->dismissRecommendation(
                $householdId,
                $memberId,
                (int)($_POST['recommendation_id'] ?? 0)
            );
            flash('success', 'Recommendation dismissed.');
        } elseif ($action === 'complete_recommendation') {
            $service->completeRecommendation(
                $householdId,
                $memberId,
                (int)($_POST['recommendation_id'] ?? 0)
            );
            flash('success', 'Recommendation marked complete.');
        } else {
            throw new InvalidArgumentException('Unknown forecasting action.');
        }

        $_SESSION['phase8_action_key'] = bin2hex(random_bytes(32));
        redirect('/phase8.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/phase8.php');
    }
}

$data = $service->dashboardData($householdId);
$settings = $data['settings'];
$snapshot = $data['snapshot'];
$projections = $data['projections'];
$recommendations = $data['recommendations'];
$seasonalEntries = $data['seasonal_entries'];
$trends = $data['trends'];
$members = $data['members'];
$flashes = consume_flashes();
$token = csrf_token();
$actionKey = (string)$_SESSION['phase8_action_key'];
$today = date('Y-m-d');
$nextMonth = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title>Forecasting, Seasons &amp; Self-Sufficiency · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php
$shortages = array_values(array_filter($projections, static fn(array $row): bool => !empty($row['shortage_date'])));
$healthyProjections = array_values(array_filter($projections, static fn(array $row): bool => empty($row['shortage_date'])));
$pendingRecommendations = array_values(array_filter($recommendations, static fn(array $row): bool => (string)$row['status'] === 'pending'));
$acceptedRecommendations = array_values(array_filter($recommendations, static fn(array $row): bool => (string)$row['status'] === 'accepted'));
$criticalRecommendations = array_values(array_filter($recommendations, static fn(array $row): bool => in_array((string)$row['priority'], ['critical', 'high'], true)));
$acceptedSeasonal = array_values(array_filter($seasonalEntries, static fn(array $row): bool => (string)$row['status'] === 'accepted'));
$plannedSeasonal = array_values(array_filter($seasonalEntries, static fn(array $row): bool => (string)$row['status'] === 'planned'));
$nextSeasonal = $seasonalEntries[0] ?? null;
$latestTrend = $trends[0] ?? null;
$previousTrend = $trends[1] ?? null;
$resilienceDelta = ($latestTrend && $previousTrend) ? (float)$latestTrend['resilience_score'] - (float)$previousTrend['resilience_score'] : null;
$formatQuantity = static fn(mixed $value): string => number_format((float)$value, 2, '.', ',');
?>
<a class="skip-link" href="#main-content">Skip to food forecasting</a>
<main id="main-content" class="page-container forecast-page">
    <section class="forecast-hero" aria-labelledby="forecast-title">
        <div class="forecast-hero__copy">
            <p class="forecast-kicker">Seasonal household intelligence</p>
            <h1 id="forecast-title" aria-label="Forecasting, Seasons &amp; Self-Sufficiency">See what is coming.<br><span>Prepare with confidence.</span></h1>
            <p>Turn pantry depletion, meal demand, harvest windows, preservation output, and seasonal work into one practical resilience view.</p>
            <div class="forecast-hero__meta">
                <span><?= $snapshot ? 'Forecast current as of '.e(date('M j, Y', strtotime((string)$snapshot['as_of_date']))) : 'No forecast snapshot yet' ?></span>
                <span><?= count($shortages) ?> projected shortages</span>
                <span><?= count($seasonalEntries) ?> seasonal entries</span>
            </div>
        </div>
        <div class="forecast-hero__score" aria-label="Current resilience score">
            <p>Household resilience</p>
            <strong><?= $snapshot ? e(number_format((float)$snapshot['resilience_score'], 1)) : '—' ?><small><?= $snapshot ? '%' : '' ?></small></strong>
            <div class="forecast-score-ring" style="--score: <?= $snapshot ? e(number_format(min(100, max(0, (float)$snapshot['resilience_score'])), 2, '.', '')) : '0' ?>"><span></span></div>
            <p><?= $resilienceDelta === null ? 'Run forecasts regularly to establish a trend.' : (($resilienceDelta >= 0 ? '+' : '').number_format($resilienceDelta, 1).'% from the previous snapshot') ?></p>
            <?php if ($canManage): ?>
            <details class="forecast-run-control">
                <summary>Run forecast</summary>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                    <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                    <input type="hidden" name="action" value="run_forecast">
                    <label>Forecast date<input type="date" name="as_of_date" value="<?= e($today) ?>" required></label>
                    <button type="submit">Generate forecast</button>
                </form>
            </details>
            <?php endif; ?>
        </div>
    </section>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="forecast-flash forecast-flash--<?= $message['type'] === 'error' ? 'warning' : 'good' ?>"><?= e((string)$message['message']) ?></div>
    <?php endforeach; ?>

    <section class="forecast-metrics" aria-label="Forecast metrics">
        <article><span>◔</span><div><small>Inventory coverage</small><strong><?= $snapshot ? e(number_format((float)$snapshot['inventory_coverage_score'], 1)).'%' : '—' ?></strong><p>Tracked stock against projected demand</p></div></article>
        <article><span>⌂</span><div><small>Production share</small><strong><?= $snapshot ? e(number_format((float)$snapshot['self_sufficiency_score'], 1)).'%' : '—' ?></strong><p>Household-produced tracked food</p></div></article>
        <article><span>◇</span><div><small>Seasonal readiness</small><strong><?= $snapshot ? e(number_format((float)$snapshot['seasonal_readiness_score'], 1)).'%' : '—' ?></strong><p>Planned work aligned to the horizon</p></div></article>
        <article class="<?= count($shortages) ? 'forecast-metric--danger' : 'forecast-metric--good' ?>"><span>!</span><div><small>Projected shortages</small><strong><?= count($shortages) ?></strong><p><?= count($shortages) ? 'Items need action inside the horizon' : 'No dated shortages projected' ?></p></div></article>
        <article><span>✓</span><div><small>Recommendations</small><strong><?= count($recommendations) ?></strong><p><?= count($acceptedRecommendations) ?> accepted into household work</p></div></article>
        <article><span>⌁</span><div><small>Forecast horizon</small><strong><?= (int)$settings['horizon_days'] ?><small> days</small></strong><p><?= (int)$settings['history_days'] ?> days of history considered</p></div></article>
    </section>

    <div class="forecast-layout">
        <div class="forecast-primary">
            <section class="forecast-panel" id="forecast-projections">
                <div class="forecast-panel__heading forecast-panel__heading--toolbar">
                    <div><p class="forecast-kicker">Pantry demand model</p><h2>Coverage outlook</h2></div>
                    <label class="forecast-search"><span>⌕</span><input type="search" placeholder="Search projections" data-forecast-search></label>
                </div>
                <div class="forecast-tabs" role="tablist" aria-label="Projection filters">
                    <button type="button" class="active" data-forecast-filter="all">All <span><?= count($projections) ?></span></button>
                    <button type="button" data-forecast-filter="shortage">Shortages <span><?= count($shortages) ?></span></button>
                    <button type="button" data-forecast-filter="healthy">Covered <span><?= count($healthyProjections) ?></span></button>
                    <button type="button" data-forecast-filter="low">Low confidence</button>
                </div>
                <div class="forecast-projections" data-forecast-list>
                    <?php if ($projections === []): ?><div class="forecast-empty"><strong>No forecast snapshot yet</strong><p>Generate a forecast after inventory and ledger records are available.</p></div><?php endif; ?>
                    <?php foreach ($projections as $projection):
                        $hasShortage = !empty($projection['shortage_date']);
                        $confidence = strtolower((string)$projection['confidence']);
                        $daysOnHand = $projection['days_on_hand'] !== null ? (float)$projection['days_on_hand'] : null;
                        $ending = (float)$projection['projected_ending_quantity'];
                        $current = max(.0001, (float)$projection['current_quantity']);
                        $coveragePct = min(100, max(0, ($ending / $current) * 100));
                        $searchText = strtolower(implode(' ', [(string)$projection['item_name_snapshot'], (string)($projection['category_name_snapshot'] ?? ''), (string)$projection['unit'], $confidence]));
                    ?>
                    <article class="forecast-projection <?= $hasShortage ? 'forecast-projection--shortage' : '' ?>" data-status="<?= $hasShortage ? 'shortage' : 'healthy' ?>" data-confidence="<?= e($confidence) ?>" data-search="<?= e($searchText) ?>">
                        <div class="forecast-projection__mark" aria-hidden="true"><?= $hasShortage ? '!' : '✓' ?></div>
                        <div class="forecast-projection__body">
                            <div class="forecast-projection__title"><div><h3><?= e((string)$projection['item_name_snapshot']) ?></h3><p><?= e((string)($projection['category_name_snapshot'] ?? 'Food inventory')) ?> · <?= e((string)$projection['source_event_count']) ?> evidence events</p></div><span class="forecast-confidence forecast-confidence--<?= e($confidence) ?>"><?= e($confidence) ?> confidence</span></div>
                            <div class="forecast-projection__facts">
                                <span><small>Current</small><strong><?= e($formatQuantity($projection['current_quantity'])) ?> <?= e((string)$projection['unit']) ?></strong></span>
                                <span><small>Daily depletion</small><strong><?= e($formatQuantity($projection['daily_depletion_rate'])) ?> / day</strong></span>
                                <span><small>Expected inflow</small><strong><?= e($formatQuantity((float)$projection['projected_harvest_quantity'] + (float)$projection['projected_preservation_quantity'])) ?> <?= e((string)$projection['unit']) ?></strong></span>
                                <span class="<?= $hasShortage ? 'forecast-fact--urgent' : '' ?>"><small><?= $hasShortage ? 'Shortage date' : 'Days on hand' ?></small><strong><?= $hasShortage ? e(date('M j, Y', strtotime((string)$projection['shortage_date']))) : ($daysOnHand !== null ? e(number_format($daysOnHand, 1)).' days' : 'Building evidence') ?></strong></span>
                            </div>
                            <div class="forecast-coverage"><span style="width: <?= e(number_format($coveragePct, 2, '.', '')) ?>%"></span></div>
                        </div>
                        <div class="forecast-projection__ending"><small>Projected ending</small><strong><?= e($formatQuantity($ending)) ?></strong><span><?= e((string)$projection['unit']) ?></span></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="forecast-panel" id="forecast-recommendations">
                <div class="forecast-panel__heading"><div><p class="forecast-kicker">Evidence-linked actions</p><h2>Forecast recommendations</h2></div><span><?= count($pendingRecommendations) ?> pending</span></div>
                <div class="forecast-recommendations">
                    <?php if ($recommendations === []): ?><div class="forecast-empty"><strong>No pending recommendations</strong><p>The forecast has no unresolved evidence-linked actions.</p></div><?php endif; ?>
                    <?php foreach ($recommendations as $recommendation): ?>
                    <article class="forecast-recommendation forecast-recommendation--<?= e((string)$recommendation['priority']) ?>">
                        <div class="forecast-recommendation__top"><span><?= e(ucfirst((string)$recommendation['priority'])) ?></span><small><?= e(ucwords(str_replace('_',' ',(string)$recommendation['recommendation_type']))) ?></small></div>
                        <h3><?= e((string)$recommendation['title']) ?></h3>
                        <p><?= e((string)$recommendation['rationale']) ?></p>
                        <div class="forecast-recommendation__action"><strong><?= e((string)$recommendation['recommended_action']) ?></strong><?php if ($recommendation['recommended_quantity'] !== null): ?><span><?= e($formatQuantity($recommendation['recommended_quantity'])) ?> <?= e((string)$recommendation['unit']) ?></span><?php endif; ?></div>
                        <footer><span><?= $recommendation['due_on'] ? 'Due '.e(date('M j', strtotime((string)$recommendation['due_on']))) : 'Flexible timing' ?></span><span><?= e(ucfirst((string)$recommendation['status'])) ?><?= $recommendation['related_task_id'] ? ' · Task #'.(int)$recommendation['related_task_id'] : '' ?></span></footer>
                        <?php if ($canManage): ?><div class="forecast-recommendation__buttons">
                            <?php if ((string)$recommendation['status'] === 'pending'): ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="accept_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button type="submit">Create task</button></form>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="dismiss_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button type="submit" class="secondary">Dismiss</button></form>
                            <?php else: ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="complete_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>"><button type="submit" class="secondary">Mark complete</button></form>
                            <?php endif; ?>
                        </div><?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="forecast-panel" id="forecast-trends">
                <div class="forecast-panel__heading"><div><p class="forecast-kicker">Forecast history</p><h2>Resilience trend</h2></div><span>Last <?= count($trends) ?> snapshots</span></div>
                <div class="forecast-trends">
                    <?php if ($trends === []): ?><div class="forecast-empty"><strong>No trend history yet</strong><p>Completed snapshots will build a longitudinal resilience record.</p></div><?php endif; ?>
                    <?php foreach (array_reverse($trends) as $trend): ?>
                    <article title="<?= e((string)$trend['as_of_date']) ?> · <?= e(number_format((float)$trend['resilience_score'],1)) ?>% resilience">
                        <div class="forecast-trend-bars"><i style="height:<?= e(number_format((float)$trend['inventory_coverage_score'],2,'.','')) ?>%"></i><b style="height:<?= e(number_format((float)$trend['self_sufficiency_score'],2,'.','')) ?>%"></b><em style="height:<?= e(number_format((float)$trend['resilience_score'],2,'.','')) ?>%"></em></div>
                        <small><?= e(date('M j', strtotime((string)$trend['as_of_date']))) ?></small>
                        <strong><?= e(number_format((float)$trend['resilience_score'],0)) ?>%</strong>
                    </article>
                    <?php endforeach; ?>
                </div>
                <div class="forecast-legend"><span><i></i>Coverage</span><span><b></b>Production</span><span><em></em>Resilience</span></div>
            </section>
        </div>

        <aside class="forecast-sidebar">
            <section class="forecast-panel forecast-season-panel">
                <div class="forecast-panel__heading"><div><p class="forecast-kicker">Seasonal operating calendar</p><h2>Next 12 months</h2></div><span><?= count($acceptedSeasonal) ?> active</span></div>
                <div class="forecast-season-list">
                    <?php if ($seasonalEntries === []): ?><div class="forecast-empty"><strong>No seasonal entries</strong><p>Add a planting, harvest, preservation, purchase, rotation, or preparation marker.</p></div><?php endif; ?>
                    <?php foreach ($seasonalEntries as $entry): ?>
                    <article class="forecast-season forecast-season--<?= e((string)$entry['entry_type']) ?>">
                        <div class="forecast-season__date"><strong><?= e(date('M', strtotime((string)$entry['starts_on']))) ?></strong><span><?= e(date('j', strtotime((string)$entry['starts_on']))) ?></span></div>
                        <div><p><?= e(ucfirst((string)$entry['entry_type'])) ?> · <?= e(ucfirst((string)$entry['status'])) ?></p><h3><?= e((string)$entry['title']) ?></h3><small><?= e((string)($entry['assignee_name'] ?? 'Household')) ?><?= $entry['expected_quantity'] !== null ? ' · '.e($formatQuantity($entry['expected_quantity'])).' '.e((string)$entry['unit']) : '' ?></small></div>
                        <?php if ($canManage): ?><details><summary>•••</summary><div><?php foreach (['accepted'=>'Accept','completed'=>'Complete','dismissed'=>'Dismiss'] as $status=>$label): ?><?php if ((string)$entry['status'] === 'planned' || ((string)$entry['status'] === 'accepted' && $status !== 'accepted')): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="seasonal_status"><input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>"><input type="hidden" name="status" value="<?= e($status) ?>"><button type="submit"><?= e($label) ?></button></form><?php endif; ?><?php endforeach; ?></div></details><?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($canManage): ?>
            <section class="forecast-panel forecast-controls">
                <div class="forecast-panel__heading"><div><p class="forecast-kicker">Model controls</p><h2>Forecast settings</h2></div></div>
                <details><summary>Adjust forecast model</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="save_settings"><label>Forecast horizon, days<input type="number" min="30" max="365" name="horizon_days" value="<?= (int)$settings['horizon_days'] ?>" required></label><label>History window, days<input type="number" min="30" max="365" name="history_days" value="<?= (int)$settings['history_days'] ?>" required></label><label>Target production share, %<input type="number" min="0" max="100" step="0.1" name="target_self_sufficiency_percent" value="<?= e((string)$settings['target_self_sufficiency_percent']) ?>" required></label><label>Target pantry buffer, days<input type="number" min="1" max="180" name="target_buffer_days" value="<?= (int)$settings['target_buffer_days'] ?>" required></label><button type="submit">Save settings</button></form></details>
                <details><summary>Add seasonal entry</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="create_seasonal_entry"><label>Title<input name="title" maxlength="180" required></label><label>Type<select name="entry_type"><option value="plant">Plant</option><option value="harvest">Harvest</option><option value="preserve">Preserve</option><option value="purchase">Purchase</option><option value="rotate">Rotate</option><option value="prepare">Prepare</option></select></label><label>Crop or item<input name="crop_or_item" maxlength="180"></label><label>Starts on<input type="date" name="starts_on" value="<?= e($nextMonth) ?>" required></label><label>Ends on<input type="date" name="ends_on"></label><label>Expected quantity<input type="number" min="0" step="0.0001" name="expected_quantity"></label><label>Unit<input name="unit" maxlength="30"></label><label>Assign to<select name="assigned_member_id"><option value="">Household</option><?php foreach ($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label><label>Notes<textarea name="notes" maxlength="5000" rows="3"></textarea></label><button type="submit">Add seasonal entry</button></form></details>
            </section>
            <?php endif; ?>

            <section class="forecast-panel forecast-insight">
                <p class="forecast-kicker">Household signal</p>
                <h2><?= count($shortages) ? 'Shortage risk needs attention.' : (count($criticalRecommendations) ? 'High-priority work is waiting.' : 'The current outlook is stable.') ?></h2>
                <p><?= count($shortages) ?> dated shortages, <?= count($criticalRecommendations) ?> high-priority recommendations, and <?= count($plannedSeasonal) ?> seasonal entries still awaiting acceptance.</p>
                <a href="phase7.php">Open daily planning</a>
            </section>
        </aside>
    </div>
</main>
<script src="assets/js/homestead-forecast.js" defer></script>
</body>
</html>
