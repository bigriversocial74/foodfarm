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
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Planning & Household Automation · Homestead</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to household planning</a>
<main id="main-content" class="page-container">
    <header class="page-header">
        <div>
            <p class="eyebrow">Daily household operating system</p>
            <h1>Planning, Tasks & Automation</h1>
            <p class="page-description">Turn meals, pantry shortages, harvest windows, preservation work, leftovers, and recurring responsibilities into one coordinated daily plan.</p>
        </div>
        <div>
            <strong><?= e((string)$user['display_name']) ?></strong><br>
            <a href="/dashboard.php">Dashboard</a> · <a href="/phase6.php">Grow & preserve</a> · <a href="/logout.php">Sign out</a>
        </div>
    </header>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div>
    <?php endforeach; ?>

    <section class="metrics-grid compact" aria-label="Planning metrics">
        <article class="metric-card"><div><p>Overdue</p><strong><?= (int)($metrics['overdue'] ?? 0) ?></strong></div></article>
        <article class="metric-card"><div><p>Due today</p><strong><?= (int)($metrics['today'] ?? 0) ?></strong></div></article>
        <article class="metric-card"><div><p>Next 7 days</p><strong><?= (int)($metrics['next_seven'] ?? 0) ?></strong></div></article>
        <article class="metric-card"><div><p>Completed this week</p><strong><?= (int)($metrics['completed_week'] ?? 0) ?></strong></div></article>
    </section>

    <?php if ($canManage): ?>
    <section class="content-grid">
        <article class="panel">
            <p class="eyebrow">Generate today</p>
            <h2>Run planning cycle</h2>
            <p class="page-description">Scans recurring work, low stock, meal plans, harvest windows, preservation batches, and prepared-food use-by dates. Each date is generated once.</p>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                <input type="hidden" name="action" value="run_cycle">
                <label>Plan date<input class="search-field" type="date" name="plan_date" value="<?= e(date('Y-m-d')) ?>" required></label>
                <button class="button primary" type="submit">Generate daily plan</button>
            </form>
        </article>

        <article class="panel">
            <p class="eyebrow">One-time work</p>
            <h2>Add task</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                <input type="hidden" name="action" value="create_task">
                <label>Title<input class="search-field" name="title" maxlength="180" required></label>
                <label>Description<textarea name="description" maxlength="5000"></textarea></label>
                <label>Assign to<select name="assigned_member_id"><option value="">Unassigned household task</option><?php foreach ($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?> · <?= e(str_replace('_', ' ', (string)$member['role'])) ?></option><?php endforeach; ?></select></label>
                <label>Due<input class="search-field" type="datetime-local" name="due_at" value="<?= e($tomorrow) ?>"></label>
                <label>Priority<select name="priority"><option>low</option><option selected>medium</option><option>high</option><option>critical</option></select></label>
                <label>Estimated minutes<input class="search-field" type="number" min="1" max="1440" name="estimated_minutes"></label>
                <button class="button primary" type="submit">Add task</button>
            </form>
        </article>

        <article class="panel">
            <p class="eyebrow">Repeat automatically</p>
            <h2>Recurring task</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
                <input type="hidden" name="action" value="create_template">
                <label>Title<input class="search-field" name="title" maxlength="180" required></label>
                <label>Description<textarea name="description" maxlength="5000"></textarea></label>
                <label>Assign to<select name="assigned_member_id"><option value="">Unassigned household task</option><?php foreach ($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label>
                <label>Cadence<select name="cadence"><option value="daily">Daily</option><option value="weekly" selected>Weekly</option><option value="monthly">Monthly</option></select></label>
                <label>Starts on<input class="search-field" type="date" name="starts_on" value="<?= e(date('Y-m-d')) ?>" required></label>
                <label>Due time<input class="search-field" type="time" name="due_time" value="09:00" required></label>
                <label>Priority<select name="priority"><option>low</option><option selected>medium</option><option>high</option><option>critical</option></select></label>
                <label>Estimated minutes<input class="search-field" type="number" min="1" max="1440" name="estimated_minutes"></label>
                <button class="button primary" type="submit">Create recurring task</button>
            </form>
        </article>
    </section>
    <?php endif; ?>

    <section class="panel" style="margin-top:22px">
        <div class="panel-heading"><div><p class="eyebrow">Today and ahead</p><h2>Active household tasks</h2></div></div>
        <div class="table-wrap" tabindex="0">
            <table>
                <thead><tr><th scope="col">Task</th><th scope="col">Source</th><th scope="col">Assigned</th><th scope="col">Due</th><th scope="col">Priority</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                <?php if ($tasks === []): ?><tr><td colspan="7">No active tasks. Run a planning cycle or add a task.</td></tr><?php endif; ?>
                <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td><strong><?= e((string)$task['title']) ?></strong><?php if ($task['description']): ?><br><small><?= e((string)$task['description']) ?></small><?php endif; ?><?php if ($task['estimated_minutes']): ?><br><small><?= (int)$task['estimated_minutes'] ?> min</small><?php endif; ?></td>
                        <td><?= e(str_replace('_', ' ', (string)($task['source_type'] ?? $task['related_type'] ?? 'manual'))) ?></td>
                        <td><?= e((string)($task['assignee_name'] ?? 'Household')) ?></td>
                        <td><?= e((string)($task['due_at'] ?? 'Unscheduled')) ?></td>
                        <td><?= e((string)$task['priority']) ?></td>
                        <td><?= e(str_replace('_', ' ', (string)$task['status'])) ?></td>
                        <td>
                            <?php if ($canComplete): ?>
                                <?php if ($task['status'] !== 'in_progress'): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="start_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                    <button class="button secondary" type="submit">Start</button>
                                </form>
                                <?php endif; ?>
                                <details style="margin-top:6px"><summary>Complete</summary><form method="post" class="form-grid" style="margin-top:8px"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="complete_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><label>Notes<textarea name="completion_notes" maxlength="5000"></textarea></label><button class="button primary" type="submit">Complete task</button></form></details>
                                <details style="margin-top:6px"><summary>Snooze</summary><form method="post" class="form-grid" style="margin-top:8px"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="snooze_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><label>New due time<input class="search-field" type="datetime-local" name="due_at" value="<?= e($tomorrow) ?>" required></label><button class="button secondary" type="submit">Snooze</button></form></details>
                            <?php endif; ?>
                            <?php if ($canManage): ?>
                            <form method="post" style="display:inline;margin-top:6px"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="cancel_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><button class="button secondary" type="submit">Cancel</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($canManage): ?>
    <section class="content-grid" style="margin-top:22px">
        <article class="panel span-2">
            <div class="panel-heading"><div><p class="eyebrow">Inventory intelligence</p><h2>Shopping suggestions</h2></div></div>
            <div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Suggestion</th><th scope="col">Reason</th><th scope="col">Quantity</th><th scope="col">Priority</th><th scope="col">Action</th></tr></thead><tbody>
            <?php if ($suggestions === []): ?><tr><td colspan="5">No pending shopping suggestions.</td></tr><?php endif; ?>
            <?php foreach ($suggestions as $suggestion): ?><tr><td><strong><?= e((string)$suggestion['title']) ?></strong></td><td><?= e((string)$suggestion['rationale']) ?></td><td><?= e((string)($suggestion['recommended_quantity'] ?? '—')) ?> <?= e((string)($suggestion['unit'] ?? '')) ?></td><td><?= e((string)$suggestion['priority']) ?></td><td><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="accept_suggestion"><input type="hidden" name="suggestion_id" value="<?= (int)$suggestion['id'] ?>"><button class="button primary" type="submit">Add to shopping</button></form> <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="dismiss_suggestion"><input type="hidden" name="suggestion_id" value="<?= (int)$suggestion['id'] ?>"><button class="button secondary" type="submit">Dismiss</button></form></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </article>

        <article class="panel">
            <div class="panel-heading"><div><p class="eyebrow">Automation</p><h2>Recurring templates</h2></div></div>
            <?php if ($templates === []): ?><p>No recurring task templates.</p><?php endif; ?>
            <?php foreach ($templates as $template): ?><div class="member-card" style="margin-bottom:10px"><strong><?= e((string)$template['title']) ?></strong><br><small><?= e((string)$template['cadence']) ?> · <?= e((string)$template['due_time']) ?> · <?= e((string)($template['assignee_name'] ?? 'Household')) ?> · <?= (int)$template['enabled'] === 1 ? 'enabled' : 'paused' ?></small><form method="post" style="margin-top:8px"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="toggle_template"><input type="hidden" name="template_id" value="<?= (int)$template['id'] ?>"><button class="button secondary" type="submit"><?= (int)$template['enabled'] === 1 ? 'Pause' : 'Enable' ?></button></form></div><?php endforeach; ?>
        </article>
    </section>
    <?php endif; ?>

    <section class="panel" style="margin-top:22px">
        <div class="panel-heading"><div><p class="eyebrow">Audit trail</p><h2>Recent planning cycles</h2></div></div>
        <div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Plan date</th><th scope="col">Tasks</th><th scope="col">Suggestions</th><th scope="col">Status</th><th scope="col">Initiated by</th><th scope="col">Completed</th></tr></thead><tbody><?php if ($cycles === []): ?><tr><td colspan="6">No planning cycles yet.</td></tr><?php endif; ?><?php foreach ($cycles as $cycle): ?><tr><td><?= e((string)$cycle['plan_date']) ?></td><td><?= (int)$cycle['generated_task_count'] ?></td><td><?= (int)$cycle['generated_suggestion_count'] ?></td><td><?= e((string)$cycle['status']) ?></td><td><?= e((string)$cycle['initiated_by']) ?></td><td><?= e((string)($cycle['completed_at'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div>
    </section>
</main>
</body>
</html>
