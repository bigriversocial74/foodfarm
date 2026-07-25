<?php

declare(strict_types=1);

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
    <title>Forecasting & Self-Sufficiency · Homestead</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to food forecasting</a>
<main id="main-content" class="page-container">
    <header class="page-header">
        <div>
            <p class="eyebrow">Seasonal household intelligence</p>
            <h1>Forecasting, Seasons & Self-Sufficiency</h1>
            <p class="page-description">Project pantry coverage, planned demand, harvest windows, preservation output, rotation risk, and household-produced food without pretending quantities are calories or medical guidance.</p>
        </div>
        <div>
            <strong><?= e((string)$user['display_name']) ?></strong><br>
            <a href="/dashboard.php">Dashboard</a> · <a href="/phase7.php">Daily planning</a> · <a href="/phase6.php">Grow & preserve</a> · <a href="/logout.php">Sign out</a>
        </div>
    </header>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div>
    <?php endforeach; ?>

    <section class="metrics-grid compact" aria-label="Forecast metrics">
        <article class="metric-card"><div><p>Resilience score</p><strong><?= $snapshot ? number_format((float)$snapshot['resilience_score'], 1) . '%' : '—' ?></strong></div></article>
        <article class="metric-card"><div><p>Tracked production share</p><strong><?= $snapshot ? number_format((float)$snapshot['self_sufficiency_score'], 1) . '%' : '—' ?></strong></div></article>
        <article class="metric-card"><div><p>Inventory coverage</p><strong><?= $snapshot ? number_format((float)$snapshot['inventory_coverage_score'], 1) . '%' : '—' ?></strong></div></article>
        <article class="metric-card"><div><p>Projected shortages</p><strong><?= $snapshot ? (int)$snapshot['projected_shortage_count'] : '—' ?></strong></div></article>
    </section>

    <?php if ($canManage): ?>
    <section class="content-grid" style="margin-top:22px">
        <article class="panel">
            <p class="eyebrow">Refresh intelligence</p>
            <h2>Run forecast</h2>
            <p class="page-description">Uses the current household settings and a source-data watermark. Repeating an unchanged forecast reuses the certified snapshot instead of creating duplicates.</p>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                <input type="hidden" name="action" value="run_forecast">
                <label>Forecast date<input class="search-field" type="date" name="as_of_date" value="<?= e($today) ?>" required></label>
                <button class="button primary" type="submit">Generate forecast</button>
            </form>
        </article>

        <article class="panel">
            <p class="eyebrow">Household targets</p>
            <h2>Forecast settings</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                <input type="hidden" name="action" value="save_settings">
                <label>Forecast horizon, days<input class="search-field" type="number" min="30" max="365" name="horizon_days" value="<?= (int)$settings['horizon_days'] ?>" required></label>
                <label>History window, days<input class="search-field" type="number" min="30" max="365" name="history_days" value="<?= (int)$settings['history_days'] ?>" required></label>
                <label>Target tracked production share, %<input class="search-field" type="number" min="0" max="100" step="0.1" name="target_self_sufficiency_percent" value="<?= e((string)$settings['target_self_sufficiency_percent']) ?>" required></label>
                <label>Target pantry buffer, days<input class="search-field" type="number" min="1" max="180" name="target_buffer_days" value="<?= (int)$settings['target_buffer_days'] ?>" required></label>
                <button class="button primary" type="submit">Save settings</button>
            </form>
        </article>

        <article class="panel">
            <p class="eyebrow">Add a season marker</p>
            <h2>Manual seasonal entry</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                <input type="hidden" name="action" value="create_seasonal_entry">
                <label>Title<input class="search-field" name="title" maxlength="180" required></label>
                <label>Type<select name="entry_type"><option value="plant">Plant</option><option value="harvest">Harvest</option><option value="preserve">Preserve</option><option value="purchase">Purchase</option><option value="rotate">Rotate</option><option value="prepare">Prepare</option></select></label>
                <label>Crop or item<input class="search-field" name="crop_or_item" maxlength="180"></label>
                <label>Starts on<input class="search-field" type="date" name="starts_on" value="<?= e($nextMonth) ?>" required></label>
                <label>Ends on<input class="search-field" type="date" name="ends_on"></label>
                <label>Expected quantity<input class="search-field" type="number" min="0" step="0.0001" name="expected_quantity"></label>
                <label>Unit<input class="search-field" name="unit" maxlength="30"></label>
                <label>Assign to<select name="assigned_member_id"><option value="">Household</option><?php foreach ($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label>
                <label>Notes<textarea name="notes" maxlength="5000"></textarea></label>
                <button class="button primary" type="submit">Add seasonal entry</button>
            </form>
        </article>
    </section>
    <?php endif; ?>

    <section class="panel" style="margin-top:22px">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">Pantry demand model</p>
                <h2>Item projections</h2>
            </div>
            <?php if ($snapshot): ?><small>As of <?= e((string)$snapshot['as_of_date']) ?> · <?= (int)$snapshot['horizon_days'] ?>-day horizon · <?= e((string)$snapshot['model_version']) ?></small><?php endif; ?>
        </div>
        <p class="page-description">Daily depletion includes tracked consumption, recipe use, spoilage, and discards. Planned recipe demand is used when it exceeds the historical baseline. Harvest estimates require matching household harvest history and units.</p>
        <div class="table-wrap" tabindex="0">
            <table>
                <thead><tr><th scope="col">Item</th><th scope="col">Now</th><th scope="col">Daily depletion</th><th scope="col">Planned demand</th><th scope="col">Projected inflow</th><th scope="col">Ending</th><th scope="col">Days on hand</th><th scope="col">Shortage</th><th scope="col">Confidence</th></tr></thead>
                <tbody>
                <?php if ($projections === []): ?><tr><td colspan="9">No forecast snapshot yet. Run a forecast after inventory and ledger data are available.</td></tr><?php endif; ?>
                <?php foreach ($projections as $projection): ?>
                    <tr>
                        <td><strong><?= e((string)$projection['item_name_snapshot']) ?></strong><br><small><?= e((string)($projection['category_name_snapshot'] ?? 'Food inventory')) ?></small></td>
                        <td><?= e((string)$projection['current_quantity']) ?> <?= e((string)$projection['unit']) ?></td>
                        <td><?= e((string)$projection['daily_depletion_rate']) ?> <?= e((string)$projection['unit']) ?>/day</td>
                        <td><?= e((string)$projection['projected_consumption_quantity']) ?> <?= e((string)$projection['unit']) ?></td>
                        <td><?= number_format((float)$projection['projected_harvest_quantity'] + (float)$projection['projected_preservation_quantity'], 2) ?> <?= e((string)$projection['unit']) ?></td>
                        <td><?= e((string)$projection['projected_ending_quantity']) ?> <?= e((string)$projection['unit']) ?></td>
                        <td><?= $projection['days_on_hand'] !== null ? e((string)$projection['days_on_hand']) : 'Not enough data' ?></td>
                        <td><?= e((string)($projection['shortage_date'] ?? 'None projected')) ?></td>
                        <td><?= e((string)$projection['confidence']) ?> · <?= (int)$projection['source_event_count'] ?> events</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="content-grid" style="margin-top:22px">
        <article class="panel span-2">
            <div class="panel-heading"><div><p class="eyebrow">Evidence-linked actions</p><h2>Forecast recommendations</h2></div></div>
            <div class="table-wrap" tabindex="0"><table>
                <thead><tr><th scope="col">Recommendation</th><th scope="col">Why</th><th scope="col">Suggested action</th><th scope="col">Due</th><th scope="col">Priority</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                <tbody>
                <?php if ($recommendations === []): ?><tr><td colspan="7">No pending or accepted recommendations.</td></tr><?php endif; ?>
                <?php foreach ($recommendations as $recommendation): ?>
                    <tr>
                        <td><strong><?= e((string)$recommendation['title']) ?></strong><br><small><?= e(str_replace('_', ' ', (string)$recommendation['recommendation_type'])) ?></small></td>
                        <td><?= e((string)$recommendation['rationale']) ?></td>
                        <td><?= e((string)$recommendation['recommended_action']) ?><?php if ($recommendation['recommended_quantity'] !== null): ?><br><small><?= e((string)$recommendation['recommended_quantity']) ?> <?= e((string)$recommendation['unit']) ?></small><?php endif; ?></td>
                        <td><?= e((string)($recommendation['due_on'] ?? 'Flexible')) ?></td>
                        <td><?= e((string)$recommendation['priority']) ?></td>
                        <td><?= e((string)$recommendation['status']) ?><?php if ($recommendation['related_task_id']): ?><br><small>Task #<?= (int)$recommendation['related_task_id'] ?> · <?= e((string)($recommendation['task_status'] ?? 'unknown')) ?></small><?php endif; ?></td>
                        <td>
                            <?php if ($canManage && $recommendation['status'] === 'pending'): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="accept_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>">
                                <button class="button primary" type="submit">Create task</button>
                            </form>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="dismiss_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>">
                                <button class="button secondary" type="submit">Dismiss</button>
                            </form>
                            <?php elseif ($canManage && $recommendation['status'] === 'accepted'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="complete_recommendation"><input type="hidden" name="recommendation_id" value="<?= (int)$recommendation['id'] ?>">
                                <button class="button secondary" type="submit">Mark complete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </article>

        <article class="panel">
            <div class="panel-heading"><div><p class="eyebrow">Seasonal operating calendar</p><h2>Next 12 months</h2></div></div>
            <?php if ($seasonalEntries === []): ?><p class="page-description">No seasonal entries yet.</p><?php endif; ?>
            <?php foreach ($seasonalEntries as $entry): ?>
                <div class="member-card" style="margin-bottom:10px">
                    <strong><?= e((string)$entry['title']) ?></strong><br>
                    <small><?= e((string)$entry['starts_on']) ?><?= $entry['ends_on'] ? ' → ' . e((string)$entry['ends_on']) : '' ?> · <?= e((string)$entry['entry_type']) ?> · <?= e((string)$entry['status']) ?> · <?= e((string)($entry['assignee_name'] ?? 'Household')) ?></small>
                    <?php if ($entry['expected_quantity'] !== null): ?><br><small><?= e((string)$entry['expected_quantity']) ?> <?= e((string)$entry['unit']) ?></small><?php endif; ?>
                    <?php if ($canManage): ?><div style="margin-top:8px">
                        <?php foreach (['accepted' => 'Accept', 'completed' => 'Complete', 'dismissed' => 'Dismiss'] as $status => $label): ?>
                            <?php if ($entry['status'] === 'planned' || ($entry['status'] === 'accepted' && $status !== 'accepted')): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="seasonal_status"><input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
                                <button class="button secondary" type="submit"><?= e($label) ?></button>
                            </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </article>
    </section>

    <section class="panel" style="margin-top:22px">
        <div class="panel-heading"><div><p class="eyebrow">Forecast history</p><h2>Snapshot trend</h2></div></div>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th scope="col">As of</th><th scope="col">Horizon</th><th scope="col">Coverage</th><th scope="col">Production share</th><th scope="col">Seasonal readiness</th><th scope="col">Resilience</th><th scope="col">Shortages</th><th scope="col">Recommendations</th></tr></thead>
            <tbody>
            <?php if ($trends === []): ?><tr><td colspan="8">No completed forecast snapshots.</td></tr><?php endif; ?>
            <?php foreach ($trends as $trend): ?><tr>
                <td><?= e((string)$trend['as_of_date']) ?></td><td><?= (int)$trend['horizon_days'] ?> days</td>
                <td><?= number_format((float)$trend['inventory_coverage_score'], 1) ?>%</td>
                <td><?= number_format((float)$trend['self_sufficiency_score'], 1) ?>%</td>
                <td><?= number_format((float)$trend['seasonal_readiness_score'], 1) ?>%</td>
                <td><?= number_format((float)$trend['resilience_score'], 1) ?>%</td>
                <td><?= (int)$trend['projected_shortage_count'] ?></td>
                <td><?= (int)$trend['recommendation_count'] ?></td>
            </tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</main>
</body>
</html>
