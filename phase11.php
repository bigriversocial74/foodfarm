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
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#090806"><title>Alerts, Notifications &amp; Shared Calendar · Homestead</title><link rel="stylesheet" href="assets/css/app.css"></head>
<body>
<?php
$urgentNotifications=array_values(array_filter($notifications,static fn(array $row):bool=>in_array((string)$row['priority'],['critical','high'],true)&&in_array((string)$row['status'],['unread','acknowledged'],true)));
$unreadNotifications=array_values(array_filter($notifications,static fn(array $row):bool=>(string)$row['status']==='unread'));
$linkedNotifications=array_values(array_filter($notifications,static fn(array $row):bool=>!empty($row['related_task_id'])));
$outboxTotal=array_sum(array_map(static fn(array $row):int=>(int)$row['total'],$outbox));
$activeDigest=$digests[0]??null;
$latestRun=$runs[0]??null;
?>
<a class="skip-link" href="#main-content">Skip to household notifications</a>
<main id="main-content" class="page-container alerts-page">
<section class="alerts-hero" aria-labelledby="alerts-title"><div class="alerts-hero__copy"><p class="alerts-kicker">Household attention layer</p><h1 id="alerts-title">Alerts, Notifications <?= '&' ?> Shared Calendar</h1><p>Bring tasks, shortages, meals, harvest windows, prepared-food dates, finance reviews, and nutrition follow-up into one permission-aware inbox and calendar.</p><div class="alerts-hero__meta"><span><?= count($unreadNotifications) ?> unread</span><span><?= count($urgentNotifications) ?> urgent</span><span><?= count($calendarEvents) ?> upcoming events</span></div></div><div class="alerts-sync-card"><p>Household signal sync</p><strong><?= $latestRun ? e((string)$latestRun['as_of_date']) : 'Not run' ?></strong><small><?= $latestRun ? (int)$latestRun['notification_count'].' alerts · '.(int)$latestRun['calendar_event_count'].' events' : 'Run a sync to build the attention layer.' ?></small><?php if($canManage): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="run_sync"><label>As-of date<input type="date" name="as_of_date" value="<?= e($today) ?>" required></label><button type="submit">Run notification sync</button></form><?php endif; ?></div></section>
<?php foreach($flashes as $message): ?><div role="status" class="alerts-flash alerts-flash--<?= $message['type']==='error'?'warning':'good' ?>"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<section class="alerts-boundary"><span>↗</span><div><p class="alerts-kicker">Delivery boundary</p><h2>In-app first, adapter-ready outside the app</h2><p>The household inbox and calendar are active. External delivery is queued only when both household adapters and member preferences allow it; sensitive wellness previews remain hidden unless explicitly enabled.</p></div></section>
<section class="alerts-metrics" aria-label="Household notification metrics"><article><span>●</span><div><small>Unread</small><strong><?= (int)($counts['unread_count']??0) ?></strong><p>Needs household attention</p></div></article><article class="<?= count($urgentNotifications)>0?'alerts-metric--danger':'' ?>"><span>!</span><div><small>Urgent</small><strong><?= (int)($counts['urgent_count']??0) ?></strong><p>Critical and high priority</p></div></article><article><span>✓</span><div><small>Acknowledged</small><strong><?= (int)($counts['acknowledged_count']??0) ?></strong><p>Seen but still active</p></div></article><article><span>□</span><div><small>Linked tasks</small><strong><?= (int)($counts['task_link_count']??0) ?></strong><p><?= count($linkedNotifications) ?> visible links</p></div></article><article><span>▦</span><div><small>Calendar events</small><strong><?= count($calendarEvents) ?></strong><p>Upcoming household dates</p></div></article><article><span>⇢</span><div><small>External outbox</small><strong><?= $outboxTotal ?></strong><p>Adapter-ready records</p></div></article></section>
<div class="alerts-layout"><div class="alerts-primary">
<section class="alerts-panel" id="notification-inbox"><div class="alerts-panel__heading alerts-panel__heading--toolbar"><div><p class="alerts-kicker">Inbox</p><h2>Household notifications</h2></div><label class="alerts-search"><span>⌕</span><input type="search" placeholder="Search notifications" data-alert-search></label></div><div class="alerts-tabs" role="tablist"><button class="active" type="button" data-alert-filter="active">Active <span><?= count($notifications) ?></span></button><button type="button" data-alert-filter="unread">Unread <span><?= count($unreadNotifications) ?></span></button><button type="button" data-alert-filter="urgent">Urgent <span><?= count($urgentNotifications) ?></span></button><button type="button" data-alert-filter="calendar">Calendar</button></div><div class="alerts-list" data-alert-list><?php if($notifications===[]): ?><div class="alerts-empty"><strong>No active notifications</strong><p>Run a sync after household data is available.</p></div><?php endif; ?><?php foreach($notifications as $notification): $isUrgent=in_array((string)$notification['priority'],['critical','high'],true); ?><article class="alerts-item alerts-item--<?= e((string)$notification['priority']) ?>" data-status="<?= e((string)$notification['status']) ?>" data-urgent="<?= $isUrgent?'1':'0' ?>" data-search="<?= e(strtolower((string)$notification['title'].' '.(string)($notification['body']??'').' '.(string)$notification['category'].' '.(string)($notification['recipient_name']??''))) ?>"><div class="alerts-item__icon"><?= e(strtoupper(substr((string)$notification['category'],0,1))) ?></div><div class="alerts-item__content"><header><div><span><?= e(ucfirst((string)$notification['priority'])) ?></span><small><?= e(ucwords(str_replace('_',' ',(string)$notification['category']))) ?></small></div><time><?= $notification['due_at'] ? 'Due '.e((string)$notification['due_at']) : e((string)$notification['created_at']) ?></time></header><h3><?= e((string)$notification['title']) ?></h3><p><?= e((string)($notification['body']??'')) ?></p><footer><span><?= e(ucfirst((string)$notification['status'])) ?><?= $notification['recipient_name']?' · '.e((string)$notification['recipient_name']):'' ?><?= $notification['related_task_id']?' · Task #'.(int)$notification['related_task_id']:'' ?></span><?php if(in_array((string)$notification['status'],['unread','acknowledged'],true)): ?><div><?php if((string)$notification['status']==='unread'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="acknowledge_notification"><input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>"><button type="submit" class="secondary">Acknowledge</button></form><?php endif; ?><?php if(!$notification['related_task_id']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="create_task"><input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>"><button type="submit" class="secondary">Create task</button></form><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="complete_notification"><input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>"><button type="submit">Complete</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="dismiss_notification"><input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>"><button type="submit" class="secondary">Dismiss</button></form></div><?php endif; ?></footer></div></article><?php endforeach; ?></div></section>
<section class="alerts-panel" id="shared-calendar"><div class="alerts-panel__heading"><div><p class="alerts-kicker">Shared calendar</p><h2>Upcoming household events</h2></div><a href="phase11-calendar.php">Download ICS</a></div><div class="alerts-calendar"><?php if($calendarEvents===[]): ?><div class="alerts-empty"><strong>No upcoming events</strong><p>Synced tasks and household dates will appear here.</p></div><?php endif; ?><?php foreach($calendarEvents as $event): $eventDate=new DateTimeImmutable((string)$event['starts_at']); ?><article data-alert-calendar><div class="alerts-date"><strong><?= e($eventDate->format('d')) ?></strong><span><?= e($eventDate->format('M')) ?></span></div><div><h3><?= e((string)$event['title']) ?></h3><p><?= e((string)($event['description']??'')) ?></p><small><?= e($eventDate->format('g:i A')) ?> · <?= e(ucwords(str_replace('_',' ',(string)$event['source_type']))) ?></small></div></article><?php endforeach; ?></div></section>
<section class="alerts-panel"><div class="alerts-panel__heading"><div><p class="alerts-kicker">Automation history</p><h2>Recent notification syncs</h2></div></div><div class="alerts-runs"><?php if($runs===[]): ?><div class="alerts-empty"><strong>No completed syncs</strong><p>Run notification automation to create history.</p></div><?php endif; ?><?php foreach($runs as $run): ?><article><div><strong><?= e((string)$run['as_of_date']) ?></strong><p><?= e(ucfirst((string)$run['status'])) ?></p></div><span><?= (int)$run['notification_count'] ?> alerts<small><?= (int)$run['calendar_event_count'] ?> events · <?= (int)$run['expired_count'] ?> expired</small></span></article><?php endforeach; ?></div></section>
</div><aside class="alerts-sidebar">
<section class="alerts-panel"><div class="alerts-panel__heading"><div><p class="alerts-kicker">Digest</p><h2>Build a review packet</h2></div></div><div class="alerts-digest"><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="generate_digest"><label>Cadence<select name="cadence"><option value="daily">Daily</option><option value="weekly">Weekly</option></select></label><label>As-of date<input type="date" name="as_of_date" value="<?= e($today) ?>"></label><button type="submit">Generate digest</button></form><?php if($activeDigest): ?><article><strong><?= e(ucfirst((string)$activeDigest['cadence'])) ?> digest</strong><p><?= (int)$activeDigest['item_count'] ?> items · <?= e((string)$activeDigest['status']) ?></p><small><?= e((string)$activeDigest['created_at']) ?></small></article><?php endif; ?></div></section>
<section class="alerts-panel"><div class="alerts-panel__heading"><div><p class="alerts-kicker">External delivery</p><h2>Adapter outbox</h2></div></div><div class="alerts-outbox"><?php if($outbox===[]): ?><div class="alerts-empty"><strong>No external deliveries queued</strong><p>In-app delivery remains active.</p></div><?php endif; ?><?php foreach($outbox as $row): ?><article><span><?= e(strtoupper((string)$row['channel'])) ?></span><div><strong><?= (int)$row['total'] ?></strong><p><?= e(ucfirst((string)$row['status'])) ?></p></div></article><?php endforeach; ?></div></section>
<?php if($canManage): ?><section class="alerts-panel alerts-controls"><div class="alerts-panel__heading"><div><p class="alerts-kicker">Automation controls</p><h2>Timing &amp; preferences</h2></div></div><details><summary>Household settings</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="save_settings"><div class="alerts-form-pair"><label>Task due-soon days<input type="number" min="1" max="30" name="due_soon_days" value="<?= (int)$settings['due_soon_days'] ?>" required></label><label>Forecast alert days<input type="number" min="1" max="90" name="forecast_alert_days" value="<?= (int)$settings['forecast_alert_days'] ?>" required></label><label>Prepared-food days<input type="number" min="1" max="30" name="prepared_food_alert_days" value="<?= (int)$settings['prepared_food_alert_days'] ?>" required></label><label>Digest hour<input type="number" min="0" max="23" name="digest_hour" value="<?= (int)$settings['digest_hour'] ?>"></label><label>Quiet start<input type="time" name="quiet_start" value="<?= e(substr((string)($settings['quiet_start']??''),0,5)) ?>"></label><label>Quiet end<input type="time" name="quiet_end" value="<?= e(substr((string)($settings['quiet_end']??''),0,5)) ?>"></label></div><label>Digest cadence<select name="digest_cadence"><option value="off" <?= $settings['digest_cadence']==='off'?'selected':'' ?>>Off</option><option value="daily" <?= $settings['digest_cadence']==='daily'?'selected':'' ?>>Daily</option><option value="weekly" <?= $settings['digest_cadence']==='weekly'?'selected':'' ?>>Weekly</option></select></label><label class="check"><input type="checkbox" name="email_adapter_enabled" value="1" <?= (int)$settings['email_adapter_enabled']===1?'checked':'' ?>> Enable email outbox adapter</label><label class="check"><input type="checkbox" name="web_push_adapter_enabled" value="1" <?= (int)$settings['web_push_adapter_enabled']===1?'checked':'' ?>> Enable web-push outbox adapter</label><button type="submit">Save household settings</button></form></details><details><summary>Member preferences</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action_key" value="<?= e($actionKey) ?>"><input type="hidden" name="action" value="save_member_preferences"><label>Member<select name="household_member_id" required><?php foreach($members as $member): ?><option value="<?= (int)$member['id'] ?>" <?= (int)$member['id']===$memberId?'selected':'' ?>><?= e((string)$member['display_name']) ?></option><?php endforeach; ?></select></label><label>Minimum priority<select name="minimum_priority"><?php foreach($data['priorities'] as $priority): ?><option value="<?= e($priority) ?>" <?= $preference['minimum_priority']===$priority?'selected':'' ?>><?= e(ucfirst($priority)) ?></option><?php endforeach; ?></select></label><label>Digest preference<select name="digest_cadence"><option value="inherit">Use household setting</option><option value="off">Off</option><option value="daily">Daily</option><option value="weekly">Weekly</option></select></label><fieldset><legend>Categories</legend><?php foreach($categories as $category): ?><label class="check"><input type="checkbox" name="enabled_categories[]" value="<?= e($category) ?>" <?= in_array($category,$enabledCategories,true)?'checked':'' ?>> <?= e(ucwords(str_replace('_',' ',$category))) ?></label><?php endforeach; ?></fieldset><label class="check"><input type="checkbox" name="email_enabled" value="1" <?= (int)$preference['email_enabled']===1?'checked':'' ?>> Email</label><label class="check"><input type="checkbox" name="web_push_enabled" value="1" <?= (int)$preference['web_push_enabled']===1?'checked':'' ?>> Web push</label><label class="check"><input type="checkbox" name="allow_sensitive_previews" value="1" <?= (int)$preference['allow_sensitive_previews']===1?'checked':'' ?>> Allow sensitive preview text outside Homestead</label><button type="submit">Save preferences</button></form></details></section><?php endif; ?>
</aside></div></main><script src="assets/js/homestead-alerts.js" defer></script></body></html>
