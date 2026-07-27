<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/PlanningAutomationService.php';

use Homestead\PlanningAutomationService;
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
$canManage = $auth->can($user, 'tasks.manage');
$canComplete = $auth->can($user, 'tasks.complete');
if (!$canManage && !$canComplete) {
    http_response_code(403);
    exit('You do not have permission to view household planning.');
}

$service = new PlanningAutomationService($pdo);
if (!isset($_SESSION['phase7_action_key']) || !is_string($_SESSION['phase7_action_key'])
    || preg_match('/^[a-f0-9]{64}$/', $_SESSION['phase7_action_key']) !== 1) {
    $_SESSION['phase7_action_key'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $postedActionKey = strtolower(trim((string)($_POST['action_key'] ?? '')));
        if (!hash_equals((string)$_SESSION['phase7_action_key'], $postedActionKey)) {
            throw new RuntimeException('This planning form has expired. Refresh and try again.');
        }
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'run_cycle') {
            $auth->requirePermission($user, 'tasks.manage');
            $result = $service->runPlanningCycle(
                $householdId,
                $memberId,
                (string)($_POST['plan_date'] ?? date('Y-m-d')),
                $postedActionKey
            );
            flash(
                'success',
                $result['reused']
                    ? sprintf('The %s plan already exists with %d tasks and %d suggestions.', $_POST['plan_date'], $result['tasks'], $result['suggestions'])
                    : sprintf('Daily plan created with %d tasks and %d shopping suggestions.', $result['tasks'], $result['suggestions'])
            );
        } elseif ($action === 'create_task') {
            $auth->requirePermission($user, 'tasks.manage');
            $taskId = $service->createManualTask($householdId, $memberId, $_POST);
            flash('success', 'Task #' . $taskId . ' was added to the household plan.');
        } elseif ($action === 'create_template') {
            $auth->requirePermission($user, 'tasks.manage');
            $templateId = $service->createRecurringTemplate($householdId, $memberId, $_POST);
            flash('success', 'Recurring task template #' . $templateId . ' was created.');
        } elseif ($action === 'toggle_template') {
            $auth->requirePermission($user, 'tasks.manage');
            $service->toggleRecurringTemplate($householdId, $memberId, (int)($_POST['template_id'] ?? 0));
            flash('success', 'Recurring task status updated.');
        } elseif ($action === 'start_task') {
            $auth->requirePermission($user, 'tasks.complete');
            $service->startTask($householdId, $memberId, (int)($_POST['task_id'] ?? 0), $canManage);
            flash('success', 'Task started.');
        } elseif ($action === 'complete_task') {
            $auth->requirePermission($user, 'tasks.complete');
            $service->completeTask(
                $householdId,
                $memberId,
                (int)($_POST['task_id'] ?? 0),
                (string)($_POST['completion_notes'] ?? ''),
                $canManage
            );
            flash('success', 'Task completed and recorded.');
        } elseif ($action === 'snooze_task') {
            $auth->requirePermission($user, 'tasks.complete');
            $service->snoozeTask(
                $householdId,
                $memberId,
                (int)($_POST['task_id'] ?? 0),
                (string)($_POST['due_at'] ?? ''),
                $canManage
            );
            flash('success', 'Task snoozed.');
        } elseif ($action === 'cancel_task') {
            $auth->requirePermission($user, 'tasks.manage');
            $service->cancelTask(
                $householdId,
                $memberId,
                (int)($_POST['task_id'] ?? 0),
                (string)($_POST['notes'] ?? '')
            );
            flash('success', 'Task cancelled.');
        } elseif ($action === 'reopen_task') {
            $auth->requirePermission($user, 'tasks.manage');
            $service->reopenTask($householdId, $memberId, (int)($_POST['task_id'] ?? 0));
            flash('success', 'Task reopened.');
        } elseif ($action === 'accept_suggestion') {
            $auth->requirePermission($user, 'tasks.manage');
            $itemId = $service->acceptShoppingSuggestion($householdId, $memberId, (int)($_POST['suggestion_id'] ?? 0));
            flash('success', 'Shopping suggestion accepted as list item #' . $itemId . '.');
        } elseif ($action === 'dismiss_suggestion') {
            $auth->requirePermission($user, 'tasks.manage');
            $service->dismissSuggestion($householdId, $memberId, (int)($_POST['suggestion_id'] ?? 0));
            flash('success', 'Suggestion dismissed.');
        } else {
            throw new InvalidArgumentException('Unknown planning action.');
        }

        $_SESSION['phase7_action_key'] = bin2hex(random_bytes(32));
        redirect('/phase7.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/phase7.php');
    }
}

$data = $service->dashboardData($householdId, $memberId, $canManage);
$metrics = $data['metrics'];
$tasks = $data['tasks'];
$suggestions = $data['suggestions'];
$templates = $data['templates'];
$cycles = $data['cycles'];
$members = $data['members'];
$flashes = consume_flashes();
$token = csrf_token();
$actionKey = (string)$_SESSION['phase7_action_key'];
$tomorrow = (new DateTimeImmutable('tomorrow 09:00'))->format('Y-m-d\TH:i');


$today = new DateTimeImmutable('today');
$now = new DateTimeImmutable('now');
$sourceLabels = [
    'manual' => 'Manual',
    'recurring' => 'Recurring',
    'low_stock' => 'Pantry',
    'meal' => 'Meal plan',
    'harvest' => 'Garden',
    'preservation' => 'Preservation',
    'prepared_food' => 'Prepared food',
];
$statusLabels = [
    'planned' => 'Planned',
    'ready' => 'Ready',
    'in_progress' => 'In progress',
];
$taskStatusCounts = ['planned' => 0, 'ready' => 0, 'in_progress' => 0];
$taskSourceCounts = [];
$memberWorkload = [];
$scheduledMinutes = 0;
$unscheduledCount = 0;
foreach ($tasks as $task) {
    $taskStatus = (string)($task['status'] ?? 'planned');
    if (array_key_exists($taskStatus, $taskStatusCounts)) {
        $taskStatusCounts[$taskStatus]++;
    }
    $taskSource = (string)($task['source_type'] ?? $task['related_type'] ?? 'manual');
    $taskSourceCounts[$taskSource] = ($taskSourceCounts[$taskSource] ?? 0) + 1;
    $assignee = trim((string)($task['assignee_name'] ?? '')) ?: 'Household';
    $memberWorkload[$assignee] = ($memberWorkload[$assignee] ?? 0) + 1;
    if ($task['estimated_minutes'] !== null) {
        $scheduledMinutes += (int)$task['estimated_minutes'];
    }
    if (empty($task['due_at'])) {
        $unscheduledCount++;
    }
}
arsort($memberWorkload);
$enabledTemplateCount = 0;
foreach ($templates as $template) {
    if ((int)($template['enabled'] ?? 0) === 1) {
        $enabledTemplateCount++;
    }
}
$lastCycle = $cycles[0] ?? null;
$planningCoverage = count($tasks) > 0
    ? min(100, (int)round(((count($tasks) - $unscheduledCount) / count($tasks)) * 100))
    : 100;
$formatDue = static function (?string $value) use ($today, $now): array {
    if ($value === null || trim($value) === '') {
        return ['label' => 'Unscheduled', 'detail' => 'No due time', 'class' => 'unscheduled', 'filter' => 'upcoming'];
    }
    try {
        $due = new DateTimeImmutable($value);
    } catch (Throwable) {
        return ['label' => $value, 'detail' => '', 'class' => 'upcoming', 'filter' => 'upcoming'];
    }
    if ($due < $now) {
        return ['label' => 'Overdue', 'detail' => $due->format('M j · g:i A'), 'class' => 'overdue', 'filter' => 'overdue'];
    }
    if ($due->format('Y-m-d') === $today->format('Y-m-d')) {
        return ['label' => 'Today', 'detail' => $due->format('g:i A'), 'class' => 'today', 'filter' => 'today'];
    }
    return ['label' => $due->format('D, M j'), 'detail' => $due->format('g:i A'), 'class' => 'upcoming', 'filter' => 'upcoming'];
};
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title>Planning & Household Automation · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to household planning</a>
<main id="main-content" class="page-container planning-page">
    <section class="planning-hero" aria-labelledby="planning-title">
        <div class="planning-hero__copy">
            <p class="planning-kicker">Daily household operating system</p>
            <h1 id="planning-title" aria-label="Planning, Tasks & Automation">Plan the work.<br><span>Run the household.</span></h1>
            <p>Coordinate meals, pantry shortages, harvest windows, preservation work, leftovers, and recurring responsibilities in one daily command view.</p>
            <div class="planning-hero__meta" aria-label="Current planning state">
                <span><?= count($tasks) ?> active tasks</span>
                <span><?= $enabledTemplateCount ?> automations enabled</span>
                <span><?= count($suggestions) ?> shopping suggestions</span>
            </div>
        </div>
        <?php if ($canManage): ?>
            <form method="post" class="planning-cycle-card">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                <input type="hidden" name="action" value="run_cycle">
                <p class="planning-kicker">Generate the day</p>
                <h2>Run planning cycle</h2>
                <p>Scan recurring work, low stock, meal plans, harvest windows, preservation batches, and prepared-food dates.</p>
                <label>
                    <span>Plan date</span>
                    <input type="date" name="plan_date" value="<?= e(date('Y-m-d')) ?>" required>
                </label>
                <button class="planning-button planning-button--primary" type="submit">Generate daily plan</button>
                <?php if (is_array($lastCycle)): ?>
                    <small>Last run: <?= e((string)$lastCycle['plan_date']) ?> · <?= (int)$lastCycle['generated_task_count'] ?> tasks</small>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </section>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="planning-flash planning-flash--<?= $message['type'] === 'error' ? 'warning' : 'good' ?>"><?= e((string)$message['message']) ?></div>
    <?php endforeach; ?>

    <section class="planning-metrics" aria-label="Planning metrics">
        <article class="planning-metric planning-metric--danger"><span>!</span><div><small>Overdue</small><strong><?= (int)($metrics['overdue'] ?? 0) ?></strong><p>Needs immediate attention</p></div></article>
        <article class="planning-metric planning-metric--gold"><span>◷</span><div><small>Due today</small><strong><?= (int)($metrics['today'] ?? 0) ?></strong><p>Scheduled before midnight</p></div></article>
        <article class="planning-metric"><span>▦</span><div><small>Next 7 days</small><strong><?= (int)($metrics['next_seven'] ?? 0) ?></strong><p>Upcoming household work</p></div></article>
        <article class="planning-metric planning-metric--green"><span>✓</span><div><small>Completed</small><strong><?= (int)($metrics['completed_week'] ?? 0) ?></strong><p>Recorded this week</p></div></article>
        <article class="planning-metric"><span>⌁</span><div><small>Scheduled time</small><strong><?= $scheduledMinutes ?></strong><p>Estimated active minutes</p></div></article>
        <article class="planning-metric"><span>◎</span><div><small>Plan coverage</small><strong><?= $planningCoverage ?>%</strong><p><?= $unscheduledCount ?> unscheduled tasks</p></div></article>
    </section>

    <div class="planning-grid">
        <section class="planning-primary">
            <article class="planning-panel planning-task-board">
                <header class="planning-panel__heading planning-panel__heading--toolbar">
                    <div>
                        <p class="planning-kicker">Today and ahead</p>
                        <h2>Active household tasks</h2>
                    </div>
                    <label class="planning-search">
                        <span aria-hidden="true">⌕</span>
                        <input type="search" data-planning-search placeholder="Search tasks or assignees" aria-label="Search active tasks">
                    </label>
                </header>
                <div class="planning-tabs" role="tablist" aria-label="Task filters">
                    <button type="button" class="active" data-task-filter="all">All <span><?= count($tasks) ?></span></button>
                    <button type="button" data-task-filter="overdue">Overdue <span><?= (int)($metrics['overdue'] ?? 0) ?></span></button>
                    <button type="button" data-task-filter="today">Today <span><?= (int)($metrics['today'] ?? 0) ?></span></button>
                    <button type="button" data-task-filter="upcoming">Upcoming</button>
                    <button type="button" data-task-filter="in_progress">In progress <span><?= $taskStatusCounts['in_progress'] ?></span></button>
                </div>
                <div class="planning-task-list" data-task-list>
                    <?php if ($tasks === []): ?>
                        <div class="planning-empty"><span>✓</span><h3>The active queue is clear.</h3><p>Run a planning cycle or add a one-time task to build the household plan.</p></div>
                    <?php endif; ?>
                    <?php foreach ($tasks as $task):
                        $due = $formatDue($task['due_at'] !== null ? (string)$task['due_at'] : null);
                        $source = (string)($task['source_type'] ?? $task['related_type'] ?? 'manual');
                        $status = (string)($task['status'] ?? 'planned');
                        $assignee = trim((string)($task['assignee_name'] ?? '')) ?: 'Household';
                        $searchText = strtolower(trim((string)$task['title'] . ' ' . (string)($task['description'] ?? '') . ' ' . $assignee . ' ' . $source));
                    ?>
                        <article class="planning-task planning-task--<?= e((string)$due['class']) ?>" data-task-card data-task-filter-value="<?= e((string)$due['filter']) ?> <?= e($status) ?>" data-search-text="<?= e($searchText) ?>">
                            <div class="planning-task__check" aria-hidden="true"><?= $status === 'in_progress' ? '→' : '○' ?></div>
                            <div class="planning-task__body">
                                <div class="planning-task__topline">
                                    <span class="planning-source planning-source--<?= e($source) ?>"><?= e($sourceLabels[$source] ?? ucwords(str_replace('_', ' ', $source))) ?></span>
                                    <span class="planning-priority planning-priority--<?= e((string)$task['priority']) ?>"><?= e(ucfirst((string)$task['priority'])) ?></span>
                                </div>
                                <h3><?= e((string)$task['title']) ?></h3>
                                <?php if (!empty($task['description'])): ?><p><?= e((string)$task['description']) ?></p><?php endif; ?>
                                <div class="planning-task__meta">
                                    <span>♙ <?= e($assignee) ?></span>
                                    <?php if ($task['estimated_minutes'] !== null): ?><span>◷ <?= (int)$task['estimated_minutes'] ?> min</span><?php endif; ?>
                                    <span><?= e($statusLabels[$status] ?? ucwords(str_replace('_', ' ', $status))) ?></span>
                                </div>
                            </div>
                            <div class="planning-task__due planning-task__due--<?= e((string)$due['class']) ?>">
                                <strong><?= e((string)$due['label']) ?></strong>
                                <small><?= e((string)$due['detail']) ?></small>
                            </div>
                            <div class="planning-task__actions">
                                <?php if ($canComplete && $status !== 'in_progress'): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="start_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                        <button class="planning-button planning-button--small" type="submit">Start</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canComplete): ?>
                                    <details class="planning-action-menu">
                                        <summary aria-label="More actions for <?= e((string)$task['title']) ?>">•••</summary>
                                        <div class="planning-action-popover">
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="complete_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                                <label><span>Completion notes</span><textarea name="completion_notes" maxlength="5000" rows="3"></textarea></label>
                                                <button class="planning-button planning-button--primary planning-button--small" type="submit">Complete task</button>
                                            </form>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="snooze_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                                <label><span>Snooze until</span><input type="datetime-local" name="due_at" value="<?= e($tomorrow) ?>" required></label>
                                                <button class="planning-button planning-button--small" type="submit">Snooze</button>
                                            </form>
                                            <?php if ($canManage): ?>
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="cancel_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                                    <input type="hidden" name="notes" value="Cancelled from planning workspace">
                                                    <button class="planning-button planning-button--danger planning-button--small" type="submit">Cancel task</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                <?php elseif ($canManage): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="cancel_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                        <button class="planning-button planning-button--danger planning-button--small" type="submit">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="planning-filter-empty" data-filter-empty hidden>No tasks match this filter.</p>
            </article>

            <?php if ($canManage): ?>
                <article class="planning-panel">
                    <header class="planning-panel__heading">
                        <div><p class="planning-kicker">Inventory intelligence</p><h2>Shopping suggestions</h2></div>
                        <a href="shopping-list.php">Open shopping list →</a>
                    </header>
                    <div class="planning-suggestions">
                        <?php if ($suggestions === []): ?><div class="planning-empty planning-empty--compact"><span>✓</span><p>No pending shopping suggestions.</p></div><?php endif; ?>
                        <?php foreach ($suggestions as $suggestion): ?>
                            <article class="planning-suggestion">
                                <span class="planning-priority planning-priority--<?= e((string)$suggestion['priority']) ?>"><?= e(ucfirst((string)$suggestion['priority'])) ?></span>
                                <div>
                                    <h3><?= e((string)$suggestion['title']) ?></h3>
                                    <p><?= e((string)$suggestion['rationale']) ?></p>
                                    <small><?= e((string)($suggestion['recommended_quantity'] ?? '—')) ?> <?= e((string)($suggestion['unit'] ?? '')) ?> recommended</small>
                                </div>
                                <div class="planning-suggestion__actions">
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="accept_suggestion"><input type="hidden" name="suggestion_id" value="<?= (int)$suggestion['id'] ?>"><button class="planning-button planning-button--primary planning-button--small" type="submit">Add</button></form>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="dismiss_suggestion"><input type="hidden" name="suggestion_id" value="<?= (int)$suggestion['id'] ?>"><button class="planning-button planning-button--small" type="submit">Dismiss</button></form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endif; ?>

            <article class="planning-panel">
                <header class="planning-panel__heading"><div><p class="planning-kicker">Audit trail</p><h2>Recent planning cycles</h2></div></header>
                <div class="planning-cycle-history">
                    <?php if ($cycles === []): ?><div class="planning-empty planning-empty--compact"><p>No planning cycles have been recorded.</p></div><?php endif; ?>
                    <?php foreach ($cycles as $cycle): ?>
                        <article>
                            <div class="planning-cycle-date"><strong><?= e((new DateTimeImmutable((string)$cycle['plan_date']))->format('M j')) ?></strong><small><?= e((new DateTimeImmutable((string)$cycle['plan_date']))->format('D')) ?></small></div>
                            <div><h3><?= (int)$cycle['generated_task_count'] ?> tasks generated</h3><p><?= (int)$cycle['generated_suggestion_count'] ?> shopping suggestions · initiated by <?= e((string)$cycle['initiated_by']) ?></p></div>
                            <span class="planning-cycle-status"><?= e(ucfirst((string)$cycle['status'])) ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>

        <aside class="planning-sidebar">
            <?php if ($canManage): ?>
                <details class="planning-panel planning-create-panel" open>
                    <summary><span><small>One-time work</small><strong>Add household task</strong></span><b>+</b></summary>
                    <form method="post" class="planning-form">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                        <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                        <input type="hidden" name="action" value="create_task">
                        <label><span>Task title</span><input name="title" maxlength="180" placeholder="What needs to be done?" required></label>
                        <label><span>Description</span><textarea name="description" maxlength="5000" rows="3" placeholder="Add context or instructions"></textarea></label>
                        <div class="planning-form__two">
                            <label><span>Assign to</span><select name="assigned_member_id"><option value="">Household</option><?php foreach ($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label>
                            <label><span>Priority</span><select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></label>
                        </div>
                        <div class="planning-form__two">
                            <label><span>Due</span><input type="datetime-local" name="due_at" value="<?= e($tomorrow) ?>"></label>
                            <label><span>Minutes</span><input type="number" min="1" max="1440" name="estimated_minutes" placeholder="30"></label>
                        </div>
                        <button class="planning-button planning-button--primary" type="submit">Add to household plan</button>
                    </form>
                </details>

                <details class="planning-panel planning-create-panel">
                    <summary><span><small>Repeat automatically</small><strong>Create recurring work</strong></span><b>+</b></summary>
                    <form method="post" class="planning-form">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                        <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                        <input type="hidden" name="action" value="create_template">
                        <label><span>Template title</span><input name="title" maxlength="180" required></label>
                        <label><span>Description</span><textarea name="description" maxlength="5000" rows="3"></textarea></label>
                        <div class="planning-form__two">
                            <label><span>Assign to</span><select name="assigned_member_id"><option value="">Household</option><?php foreach ($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label>
                            <label><span>Cadence</span><select name="cadence"><option value="daily">Daily</option><option value="weekly" selected>Weekly</option><option value="monthly">Monthly</option></select></label>
                        </div>
                        <div class="planning-form__two">
                            <label><span>Starts</span><input type="date" name="starts_on" value="<?= e(date('Y-m-d')) ?>" required></label>
                            <label><span>Due time</span><input type="time" name="due_time" value="09:00" required></label>
                        </div>
                        <div class="planning-form__two">
                            <label><span>Priority</span><select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></label>
                            <label><span>Minutes</span><input type="number" min="1" max="1440" name="estimated_minutes"></label>
                        </div>
                        <button class="planning-button planning-button--primary" type="submit">Create automation</button>
                    </form>
                </details>
            <?php endif; ?>

            <article class="planning-panel planning-workload">
                <header class="planning-panel__heading"><div><p class="planning-kicker">Assignments</p><h2>Household workload</h2></div></header>
                <div class="planning-workload__list">
                    <?php if ($memberWorkload === []): ?><p>No active assignments.</p><?php endif; ?>
                    <?php $maxWorkload = $memberWorkload === [] ? 1 : max($memberWorkload); foreach ($memberWorkload as $name => $count): ?>
                        <div><span><strong><?= e((string)$name) ?></strong><small><?= (int)$count ?> active</small></span><i><b style="width:<?= (int)round(($count / $maxWorkload) * 100) ?>%"></b></i></div>
                    <?php endforeach; ?>
                </div>
            </article>

            <?php if ($canManage): ?>
                <article class="planning-panel planning-automations">
                    <header class="planning-panel__heading"><div><p class="planning-kicker">Automation</p><h2>Recurring templates</h2></div><span><?= $enabledTemplateCount ?>/<?= count($templates) ?></span></header>
                    <div class="planning-template-list">
                        <?php if ($templates === []): ?><p>No recurring templates.</p><?php endif; ?>
                        <?php foreach ($templates as $template): ?>
                            <article class="<?= (int)$template['enabled'] === 1 ? 'enabled' : 'paused' ?>">
                                <div><h3><?= e((string)$template['title']) ?></h3><p><?= e(ucfirst((string)$template['cadence'])) ?> · <?= e((string)$template['due_time']) ?> · <?= e((string)($template['assignee_name'] ?? 'Household')) ?></p></div>
                                <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="toggle_template"><input type="hidden" name="template_id" value="<?= (int)$template['id'] ?>"><button class="planning-toggle" type="submit" aria-label="<?= (int)$template['enabled'] === 1 ? 'Pause' : 'Enable' ?> <?= e((string)$template['title']) ?>"><span></span></button></form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endif; ?>

            <article class="planning-panel planning-source-mix">
                <header class="planning-panel__heading"><div><p class="planning-kicker">Generated work</p><h2>Task sources</h2></div></header>
                <div>
                    <?php if ($taskSourceCounts === []): ?><p>No active task sources.</p><?php endif; ?>
                    <?php foreach ($taskSourceCounts as $source => $count): ?>
                        <span><b><?= e($sourceLabels[$source] ?? ucwords(str_replace('_', ' ', (string)$source))) ?></b><strong><?= (int)$count ?></strong></span>
                    <?php endforeach; ?>
                </div>
            </article>
        </aside>
    </div>
</main>
<script src="assets/js/homestead-planning.js?v=20260727-1" defer></script>
</body>
</html>
