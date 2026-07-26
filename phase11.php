<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/NotificationService.php';

use Homestead\NotificationService;
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
$canView = $auth->can($user, 'notifications.view') || $auth->can($user, 'notifications.manage');
$canManage = $auth->can($user, 'notifications.manage');
if (!$canView) {
    http_response_code(403);
    exit('You do not have permission to view household notifications.');
}

$service = new NotificationService($pdo);
if (!isset($_SESSION['phase11_action_key']) || !is_string($_SESSION['phase11_action_key'])
    || preg_match('/^[a-f0-9]{64}$/', $_SESSION['phase11_action_key']) !== 1) {
    $_SESSION['phase11_action_key'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $postedActionKey = strtolower(trim((string)($_POST['action_key'] ?? '')));
        if (!hash_equals((string)$_SESSION['phase11_action_key'], $postedActionKey)) {
            throw new RuntimeException('This notification form has expired. Refresh and try again.');
        }
        $action = (string)($_POST['action'] ?? '');

        if (in_array($action, ['save_settings', 'save_member_preferences', 'run_sync'], true) && !$canManage) {
            throw new RuntimeException('You do not have permission to manage household notification automation.');
        }

        if ($action === 'save_settings') {
            $service->saveSettings($householdId, $memberId, $_POST);
            flash('success', 'Household notification settings were updated.');
        } elseif ($action === 'save_member_preferences') {
            $service->saveMemberPreferences($householdId, $memberId, $_POST);
            flash('success', 'Member notification preferences were updated.');
        } elseif ($action === 'run_sync') {
            $result = $service->runSync(
                $householdId,
                $memberId,
                (string)($_POST['as_of_date'] ?? date('Y-m-d'))
            );
            flash(
                'success',
                $result['reused']
                    ? 'The unchanged notification sync was reused.'
                    : sprintf(
                        'Notification sync complete: %d alerts, %d calendar events, and %d expired alerts.',
                        $result['notification_count'],
                        $result['calendar_event_count'],
                        $result['expired_count']
                    )
            );
        } elseif ($action === 'acknowledge_notification') {
            $service->transitionNotification($householdId, $memberId, (int)($_POST['notification_id'] ?? 0), 'acknowledged');
            flash('success', 'Notification acknowledged.');
        } elseif ($action === 'complete_notification') {
            $service->transitionNotification($householdId, $memberId, (int)($_POST['notification_id'] ?? 0), 'completed');
            flash('success', 'Notification completed.');
        } elseif ($action === 'dismiss_notification') {
            $service->transitionNotification($householdId, $memberId, (int)($_POST['notification_id'] ?? 0), 'dismissed');
            flash('success', 'Notification dismissed.');
        } elseif ($action === 'create_task') {
            $taskId = $service->createTaskFromNotification(
                $householdId,
                $memberId,
                (int)($_POST['notification_id'] ?? 0)
            );
            flash('success', 'Notification converted to household task #' . $taskId . '.');
        } elseif ($action === 'generate_digest') {
            $result = $service->generateDigest(
                $householdId,
                $memberId,
                (string)($_POST['cadence'] ?? 'daily'),
                (string)($_POST['as_of_date'] ?? date('Y-m-d'))
            );
            flash(
                'success',
                ($result['reused'] ? 'Existing digest reused: ' : 'Digest generated: ')
                . $result['item_count'] . ' notification(s).'
            );
        } else {
            throw new InvalidArgumentException('Unknown notification action.');
        }

        $_SESSION['phase11_action_key'] = bin2hex(random_bytes(32));
        redirect('/phase11.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/phase11.php');
    }
}

$data = $service->dashboardData($householdId, $memberId);
$settings = $data['settings'];
$preference = $data['preference'];
$members = $data['members'];
$notifications = $data['notifications'];
$calendarEvents = $data['calendar_events'];
$runs = $data['sync_runs'];
$digests = $data['digests'];
$counts = $data['counts'];
$outbox = $data['outbox'];
$categories = $data['categories'];
$enabledCategories = (array)$preference['enabled_categories_list'];
$flashes = consume_flashes();
$token = csrf_token();
$actionKey = (string)$_SESSION['phase11_action_key'];
$today = date('Y-m-d');
$priorityBadge = static fn(string $priority): string => in_array($priority, ['critical', 'high'], true) ? 'warning' : 'good';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Alerts, Notifications & Shared Calendar · Homestead</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to household notifications</a>
<main id="main-content" class="page-container">
<header class="page-header">
    <div>
        <p class="eyebrow">Household attention layer</p>
        <h1>Alerts, Notifications & Shared Calendar</h1>
        <p class="page-description">Bring tasks, shortages, meals, harvest windows, prepared-food dates, finance reviews, and nutrition follow-up into one permission-aware inbox and calendar.</p>
    </div>
    <div>
        <strong><?= e((string)$user['display_name']) ?></strong><br>
        <a href="/dashboard.php">Dashboard</a> · <a href="/phase7.php">Tasks</a> · <a href="/phase10.php">Nutrition</a> · <a href="/phase11-calendar.php">Calendar export</a>
    </div>
</header>

<?php foreach ($flashes as $message): ?>
<div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div>
<?php endforeach; ?>

<section class="metrics-grid compact" aria-label="Household notification metrics">
    <article class="metric-card"><div><p>Unread</p><strong><?= (int)($counts['unread_count'] ?? 0) ?></strong></div></article>
    <article class="metric-card"><div><p>Urgent</p><strong><?= (int)($counts['urgent_count'] ?? 0) ?></strong></div></article>
    <article class="metric-card"><div><p>Acknowledged</p><strong><?= (int)($counts['acknowledged_count'] ?? 0) ?></strong></div></article>
    <article class="metric-card"><div><p>Linked tasks</p><strong><?= (int)($counts['task_link_count'] ?? 0) ?></strong></div></article>
</section>

<section class="panel" style="margin-top:22px">
    <p class="eyebrow">Delivery boundary</p>
    <h2>In-app first, adapter-ready outside the app</h2>
    <p class="page-description">The household inbox and calendar are active. Email and web-push records are only queued when both the household adapter and member preference are enabled. This phase does not send through an external provider by itself, and sensitive wellness previews remain hidden unless a member explicitly allows them.</p>
</section>

<?php if ($canManage): ?>
<section class="content-grid" style="margin-top:22px">
    <article class="panel">
        <p class="eyebrow">Automation</p>
        <h2>Sync household signals</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="run_sync">
            <label>As-of date<input class="search-field" type="date" name="as_of_date" value="<?= e($today) ?>" required></label>
            <button class="button primary" type="submit">Run notification sync</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Household settings</p>
        <h2>Timing and delivery adapters</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="save_settings">
            <label>Task due-soon days<input class="search-field" type="number" min="1" max="30" name="due_soon_days" value="<?= (int)$settings['due_soon_days'] ?>" required></label>
            <label>Forecast alert days<input class="search-field" type="number" min="1" max="90" name="forecast_alert_days" value="<?= (int)$settings['forecast_alert_days'] ?>" required></label>
            <label>Prepared-food alert days<input class="search-field" type="number" min="1" max="30" name="prepared_food_alert_days" value="<?= (int)$settings['prepared_food_alert_days'] ?>" required></label>
            <label>Digest cadence<select name="digest_cadence"><option value="off" <?= $settings['digest_cadence'] === 'off' ? 'selected' : '' ?>>Off</option><option value="daily" <?= $settings['digest_cadence'] === 'daily' ? 'selected' : '' ?>>Daily</option><option value="weekly" <?= $settings['digest_cadence'] === 'weekly' ? 'selected' : '' ?>>Weekly</option></select></label>
            <label>Digest hour<input class="search-field" type="number" min="0" max="23" name="digest_hour" value="<?= (int)$settings['digest_hour'] ?>"></label>
            <label>Quiet start<input class="search-field" type="time" name="quiet_start" value="<?= e(substr((string)($settings['quiet_start'] ?? ''), 0, 5)) ?>"></label>
            <label>Quiet end<input class="search-field" type="time" name="quiet_end" value="<?= e(substr((string)($settings['quiet_end'] ?? ''), 0, 5)) ?>"></label>
            <label><input type="checkbox" name="email_adapter_enabled" value="1" <?= (int)$settings['email_adapter_enabled'] === 1 ? 'checked' : '' ?>> Enable email outbox adapter</label>
            <label><input type="checkbox" name="web_push_adapter_enabled" value="1" <?= (int)$settings['web_push_adapter_enabled'] === 1 ? 'checked' : '' ?>> Enable web-push outbox adapter</label>
            <button class="button primary" type="submit">Save household settings</button>
        </form>
    </article>

    <article class="panel">
        <p class="eyebrow">Member preferences</p>
        <h2>Channels, categories, and privacy</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="save_member_preferences">
            <label>Member<select name="household_member_id" required><?php foreach ($members as $member): ?><option value="<?= (int)$member['id'] ?>" <?= (int)$member['id'] === $memberId ? 'selected' : '' ?>><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label>
            <label>Minimum priority<select name="minimum_priority"><?php foreach ($data['priorities'] as $priority): ?><option value="<?= e($priority) ?>" <?= $preference['minimum_priority'] === $priority ? 'selected' : '' ?>><?= e(ucfirst($priority)) ?></option><?php endforeach; ?></select></label>
            <label>Digest preference<select name="digest_cadence"><option value="inherit">Use household setting</option><option value="off">Off</option><option value="daily">Daily</option><option value="weekly">Weekly</option></select></label>
            <div><strong>Categories</strong><?php foreach ($categories as $category): ?><label><input type="checkbox" name="enabled_categories[]" value="<?= e($category) ?>" <?= in_array($category, $enabledCategories, true) ? 'checked' : '' ?>> <?= e(str_replace('_', ' ', ucfirst($category))) ?></label><?php endforeach; ?></div>
            <label><input type="checkbox" name="email_enabled" value="1" <?= (int)$preference['email_enabled'] === 1 ? 'checked' : '' ?>> Email</label>
            <label><input type="checkbox" name="web_push_enabled" value="1" <?= (int)$preference['web_push_enabled'] === 1 ? 'checked' : '' ?>> Web push</label>
            <label><input type="checkbox" name="allow_sensitive_previews" value="1" <?= (int)$preference['allow_sensitive_previews'] === 1 ? 'checked' : '' ?>> Allow sensitive preview text outside Homestead</label>
            <button class="button primary" type="submit">Save member preferences</button>
        </form>
    </article>
</section>
<?php endif; ?>

<section class="content-grid" style="margin-top:22px">
    <article class="panel span-2">
        <div class="panel-heading"><div><p class="eyebrow">Inbox</p><h2>Household notifications</h2></div></div>
        <?php if ($notifications === []): ?><p class="page-description">No active notifications. Run a sync after household data is available.</p><?php endif; ?>
        <?php foreach ($notifications as $notification): ?>
        <div class="member-card" style="margin-bottom:12px">
            <div class="panel-heading">
                <div>
                    <span class="status status-<?= e($priorityBadge((string)$notification['priority'])) ?>" style="display:inline-block"><?= e((string)$notification['priority']) ?></span>
                    <strong><?= e((string)$notification['title']) ?></strong>
                    <p class="page-description"><?= e((string)($notification['body'] ?? '')) ?></p>
                    <small><?= e(str_replace('_', ' ', (string)$notification['category'])) ?> · <?= e((string)$notification['status']) ?><?php if ($notification['due_at']): ?> · due <?= e((string)$notification['due_at']) ?><?php endif; ?><?php if ($notification['recipient_name']): ?> · <?= e((string)$notification['recipient_name']) ?><?php endif; ?></small>
                </div>
            </div>
            <?php if (in_array($notification['status'], ['unread', 'acknowledged'], true)): ?>
            <div class="toolbar" style="margin-top:10px">
                <?php if ($notification['status'] === 'unread'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="acknowledge_notification"><input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>"><button class="button secondary" type="submit">Acknowledge</button></form><?php endif; ?>
                <?php if (!$notification['related_task_id']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="create_task"><input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>"><button class="button secondary" type="submit">Create task</button></form><?php endif; ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="complete_notification"><input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>"><button class="button primary" type="submit">Complete</button></form>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="dismiss_notification"><input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>"><button class="button secondary" type="submit">Dismiss</button></form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </article>

    <article class="panel">
        <p class="eyebrow">Digest</p>
        <h2>Build a review packet</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action_key" value="<?= e($actionKey) ?>">
            <input type="hidden" name="action" value="generate_digest">
            <label>Cadence<select name="cadence"><option value="daily">Daily</option><option value="weekly">Weekly</option></select></label>
            <label>As-of date<input class="search-field" type="date" name="as_of_date" value="<?= e($today) ?>"></label>
            <button class="button primary" type="submit">Generate digest</button>
        </form>
        <?php foreach ($digests as $digest): ?><div class="member-card" style="margin-top:10px"><strong><?= e(ucfirst((string)$digest['cadence'])) ?> digest</strong><br><span class="page-description"><?= (int)$digest['item_count'] ?> items · <?= e((string)$digest['status']) ?> · <?= e((string)$digest['created_at']) ?></span></div><?php endforeach; ?>
    </article>
</section>

<section class="content-grid" style="margin-top:22px">
    <article class="panel span-2">
        <div class="panel-heading"><div><p class="eyebrow">Shared calendar</p><h2>Upcoming household events</h2></div><a class="button secondary" href="/phase11-calendar.php">Download ICS</a></div>
        <div class="table-wrap"><table><thead><tr><th>Starts</th><th>Event</th><th>Source</th></tr></thead><tbody>
        <?php if ($calendarEvents === []): ?><tr><td colspan="3">No upcoming events.</td></tr><?php endif; ?>
        <?php foreach ($calendarEvents as $event): ?><tr><td><?= e((string)$event['starts_at']) ?></td><td><strong><?= e((string)$event['title']) ?></strong><br><span class="page-description"><?= e((string)($event['description'] ?? '')) ?></span></td><td><?= e(str_replace('_', ' ', (string)$event['source_type'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
    <article class="panel">
        <p class="eyebrow">Automation history</p>
        <h2>Recent syncs</h2>
        <?php foreach ($runs as $run): ?><div class="member-card" style="margin-bottom:10px"><strong><?= e((string)$run['as_of_date']) ?> · <?= e((string)$run['status']) ?></strong><br><span class="page-description"><?= (int)$run['notification_count'] ?> alerts · <?= (int)$run['calendar_event_count'] ?> calendar events · <?= (int)$run['expired_count'] ?> expired</span></div><?php endforeach; ?>
        <h3 style="margin-top:18px">External outbox</h3>
        <?php if ($outbox === []): ?><p class="page-description">No external deliveries queued.</p><?php endif; ?>
        <?php foreach ($outbox as $row): ?><p><?= e((string)$row['channel']) ?> · <?= e((string)$row['status']) ?>: <strong><?= (int)$row['total'] ?></strong></p><?php endforeach; ?>
    </article>
</section>
</main>
</body>
</html>
