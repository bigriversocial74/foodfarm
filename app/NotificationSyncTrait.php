<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

trait NotificationSyncTrait
{
    public function runSync(int $householdId, int $memberId, string $asOfDate): array
    {
        $this->assertActiveMember($householdId, $memberId);
        $asOf = $this->date($asOfDate, 'Sync date');
        $settings = $this->settings($householdId);
        $watermark = $this->sourceWatermark($householdId);
        $runKey = hash('sha256', implode('|', [
            'phase11', $householdId, $asOf->format('Y-m-d'), $watermark, self::MODEL_VERSION,
        ]));

        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $existing = $this->pdo->prepare(
                'SELECT id, notification_count, calendar_event_count, expired_count
                 FROM notification_sync_runs
                 WHERE household_id = ? AND run_key = ? AND status = "completed" FOR UPDATE'
            );
            $existing->execute([$householdId, $runKey]);
            $existingRow = $existing->fetch();
            if (is_array($existingRow)) {
                $this->pdo->commit();
                return [
                    'sync_run_id' => (int)$existingRow['id'],
                    'notification_count' => (int)$existingRow['notification_count'],
                    'calendar_event_count' => (int)$existingRow['calendar_event_count'],
                    'expired_count' => (int)$existingRow['expired_count'],
                    'reused' => true,
                ];
            }

            $insertRun = $this->pdo->prepare(
                'INSERT INTO notification_sync_runs
                 (household_id, run_key, source_watermark, as_of_date, status, generated_by_member_id)
                 VALUES (?, ?, ?, ?, "running", ?)'
            );
            $insertRun->execute([$householdId, $runKey, $watermark, $asOf->format('Y-m-d'), $memberId]);
            $syncRunId = (int)$this->pdo->lastInsertId();

            $notificationCount = 0;
            $calendarCount = 0;

            $dueHorizon = $asOf->modify('+' . (int)$settings['due_soon_days'] . ' days')->format('Y-m-d 23:59:59');
            $taskQuery = $this->pdo->prepare(
                "SELECT id, assigned_member_id, title, description, due_at, priority
                 FROM household_tasks
                 WHERE household_id = ?
                   AND status IN ('planned','ready','in_progress')
                   AND due_at IS NOT NULL
                   AND due_at <= ?
                 ORDER BY due_at, id"
            );
            $taskQuery->execute([$householdId, $dueHorizon]);
            foreach ($taskQuery->fetchAll() as $task) {
                $recipientId = $task['assigned_member_id'] !== null ? (int)$task['assigned_member_id'] : null;
                $overdue = strtotime((string)$task['due_at']) < strtotime($asOf->format('Y-m-d 00:00:00'));
                $priority = $overdue && $this->priorityRank((string)$task['priority']) < 2
                    ? 'high'
                    : (string)$task['priority'];
                $notificationId = $this->upsertNotification($householdId, $syncRunId, [
                    'recipient_member_id' => $recipientId,
                    'source_type' => 'household_task',
                    'source_id' => (int)$task['id'],
                    'dedup_seed' => 'task-due|' . (int)$task['id'],
                    'category' => 'task',
                    'title' => ($overdue ? 'Overdue: ' : 'Due soon: ') . (string)$task['title'],
                    'body' => $task['description'] !== null ? (string)$task['description'] : 'Review and complete this household task.',
                    'priority' => $priority,
                    'visibility' => $this->notificationVisibility($recipientId),
                    'occurs_at' => (string)$task['due_at'],
                    'due_at' => (string)$task['due_at'],
                    'expires_at' => null,
                    'sensitive_preview' => 0,
                ]);
                $notificationCount++;
                $this->upsertCalendarEvent($householdId, $memberId, $notificationId, [
                    'source_type' => 'household_task',
                    'source_id' => (int)$task['id'],
                    'event_seed' => 'task|' . (int)$task['id'],
                    'recipient_member_id' => $recipientId,
                    'title' => (string)$task['title'],
                    'description' => $task['description'],
                    'starts_at' => (string)$task['due_at'],
                    'ends_at' => null,
                    'all_day' => 0,
                    'visibility' => $this->notificationVisibility($recipientId),
                ]);
                $calendarCount++;
            }

            $inventoryQuery = $this->pdo->prepare(
                "SELECT id, name, current_quantity, reorder_level, unit
                 FROM inventory_items
                 WHERE household_id = ? AND status = 'active'
                   AND reorder_level IS NOT NULL AND current_quantity <= reorder_level
                 ORDER BY current_quantity, id"
            );
            $inventoryQuery->execute([$householdId]);
            foreach ($inventoryQuery->fetchAll() as $item) {
                $priority = (float)$item['current_quantity'] <= 0 ? 'critical' : 'high';
                $this->upsertNotification($householdId, $syncRunId, [
                    'recipient_member_id' => null,
                    'source_type' => 'inventory_item',
                    'source_id' => (int)$item['id'],
                    'dedup_seed' => 'low-stock|' . (int)$item['id'],
                    'category' => 'inventory',
                    'title' => 'Low stock: ' . (string)$item['name'],
                    'body' => sprintf(
                        'Current quantity is %.2f %s; reorder level is %.2f %s.',
                        (float)$item['current_quantity'],
                        (string)$item['unit'],
                        (float)$item['reorder_level'],
                        (string)$item['unit']
                    ),
                    'priority' => $priority,
                    'visibility' => 'household',
                    'occurs_at' => $asOf->format('Y-m-d 09:00:00'),
                    'due_at' => $asOf->format('Y-m-d 18:00:00'),
                    'expires_at' => null,
                    'sensitive_preview' => 0,
                ]);
                $notificationCount++;
            }

            $preparedHorizon = $asOf->modify('+' . (int)$settings['prepared_food_alert_days'] . ' days')->format('Y-m-d');
            $preparedQuery = $this->pdo->prepare(
                "SELECT id, name, servings_remaining, storage_method, use_by_date
                 FROM prepared_food_batches
                 WHERE household_id = ? AND status IN ('active','frozen')
                   AND use_by_date IS NOT NULL AND use_by_date <= ?
                 ORDER BY use_by_date, id"
            );
            $preparedQuery->execute([$householdId, $preparedHorizon]);
            foreach ($preparedQuery->fetchAll() as $batch) {
                $overdue = (string)$batch['use_by_date'] < $asOf->format('Y-m-d');
                $notificationId = $this->upsertNotification($householdId, $syncRunId, [
                    'recipient_member_id' => null,
                    'source_type' => 'prepared_food_batch',
                    'so