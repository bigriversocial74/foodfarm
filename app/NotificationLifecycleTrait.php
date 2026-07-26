<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

trait NotificationLifecycleTrait
{
    public function transitionNotification(
        int $householdId,
        int $memberId,
        int $notificationId,
        string $toStatus
    ): void {
        $this->assertActiveMember($householdId, $memberId);
        $toStatus = $this->choice(
            $toStatus,
            ['acknowledged', 'completed', 'dismissed'],
            'Notification status'
        );

        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $query = $this->pdo->prepare(
                'SELECT * FROM household_notifications
                 WHERE id = ? AND household_id = ? FOR UPDATE'
            );
            $query->execute([$notificationId, $householdId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new InvalidArgumentException('Notification was not found.');
            }
            $this->assertNotificationAccess($householdId, $memberId, $row);

            $from = (string)$row['status'];
            if ($from === $toStatus) {
                $this->pdo->commit();
                return;
            }
            $allowed = [
                'unread' => ['acknowledged', 'completed', 'dismissed'],
                'acknowledged' => ['completed', 'dismissed'],
                'completed' => [],
                'dismissed' => [],
                'expired' => [],
            ];
            if (!in_array($toStatus, $allowed[$from] ?? [], true)) {
                throw new InvalidArgumentException('That notification transition is not allowed.');
            }

            $update = $this->pdo->prepare(
                'UPDATE household_notifications
                 SET status = ?, acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = ?'
            );
            $update->execute([$toStatus, $memberId, $notificationId, $householdId, $from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Notification changed before it could be updated.');
            }

            if ($toStatus === 'completed' && $row['related_task_id'] !== null) {
                $this->pdo->prepare(
                    "UPDATE household_tasks
                     SET status = 'completed', completed_at = UTC_TIMESTAMP()
                     WHERE id = ? AND household_id = ?
                       AND status IN ('planned','ready','in_progress')"
                )->execute([(int)$row['related_task_id'], $householdId]);
            }

            $this->recordNotificationEvent(
                $householdId,
                $notificationId,
                null,
                null,
                $memberId,
                'notification_status_changed',
                $from,
                $toStatus
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createTaskFromNotification(int $householdId, int $memberId, int $notificationId): int
    {
        $this->assertActiveMember($householdId, $memberId);
        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $query = $this->pdo->prepare(
                'SELECT * FROM household_notifications
                 WHERE id = ? AND household_id = ? FOR UPDATE'
            );
            $query->execute([$notificationId, $householdId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new InvalidArgumentException('Notification was not found.');
            }
            $this->assertNotificationAccess($householdId, $memberId, $row);
            if ($row['related_task_id'] !== null) {
                $this->pdo->commit();
                return (int)$row['related_task_id'];
            }
            if (in_array((string)$row['status'], ['completed', 'dismissed', 'expired'], true)) {
                throw new InvalidArgumentException('Closed notifications cannot create new tasks.');
            }

            $dueAt = $row['due_at'] !== null
                ? (string)$row['due_at']
                : (new DateTimeImmutable('+3 days 09:00'))->format('Y-m-d H:i:s');
            $task = $this->pdo->prepare(
                'INSERT INTO household_tasks
                 (household_id, assigned_member_id, title, description, related_type, related_id,
                  due_at, priority, status)
                 VALUES (?, ?, ?, ?, "household_notification", ?, ?, ?, "ready")'
            );
            $task->execute([
                $householdId,
                $row['recipient_member_id'] !== null ? (int)$row['recipient_member_id'] : null,
                (string)$row['title'],
                (string)($row['body'] ?? 'Follow up on this household notification.'),
                $notificationId,
                $dueAt,
                (string)$row['priority'],
            ]);
            $taskId = (int)$this->pdo->lastInsertId();

            $metadata = $this->pdo->prepare(
                'INSERT INTO task_automation_metadata
                 (household_id, household_task_id, planning_cycle_id, recurring_template_id,
                  source_type, source_id, generation_key)
                 VALUES (?, ?, NULL, NULL, "household_notification", ?, ?)'
            );
            $metadata->execute([
                $householdId,
                $taskId,
                $notificationId,
                hash('sha256', 'phase11-notification-task|' . $householdId . '|' . $notificationId),
            ]);

            $update = $this->pdo->prepare(
                'UPDATE household_notifications
                 SET related_task_id = ?, status = "acknowledged",
                     acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND related_task_id IS NULL'
            );
            $update->execute([$taskId, $memberId, $notificationId, $householdId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Notification changed before a task could be linked.');
            }

            $this->recordNotificationEvent(
                $householdId,
                $notificationId,
                null,
                null,
                $memberId,
                'notification_task_created',
                (string)$row['status'],
                'acknowledged',
                'Created household task #' . $taskId . '.'
            );
            $this->pdo->commit();
            return $taskId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function generateDigest(
        int $householdId,
        int $memberId,
        string $cadence,
        string $asOfDate
    ): array {
        $this->assertActiveMember($householdId, $memberId);
        $cadence = $this->choice($cadence, ['daily', 'weekly'], 'Digest cadence');
        $date = $this->date($asOfDate, 'Digest date');
        $periodStart = $cadence === 'daily' ? $date : $date->modify('-6 days');
        $periodEnd = $date->modify('+1 day');
        $runKey = hash(
            'sha256',
            implode('|', ['phase11-digest', $householdId, $memberId, $cadence, $periodStart->format('Y-m-d'), $date->format('Y-m-d')])
        );

        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $existing = $this->pdo->prepare(
                'SELECT id, item_count FROM notification_digest_runs
                 WHERE household_id = ? AND run_key = ? FOR UPDATE'
            );
            $existing->execute([$householdId, $runKey]);
            $row = $existing->fetch();
            if (is_array($row)) {
                $this->pdo->commit();
                return ['digest_id' => (int)$row['id'], 'item_count' => (int)$row['item_count'], 'reused' => true];
            }

            $adultAccess = in_array(
                $this->memberRole($householdId, $memberId),
                ['owner', 'administrator', 'adult_member'],
                true
            ) ? 1 : 0;
            $items = $this->pdo->prepare(
                "SELECT id FROM household_notifications
                 WHERE household_id = ?
                   AND (
                       visibility = 'household'
                       OR (visibility = 'adults_only' AND ? = 1)
                       OR (visibility = 'private' AND recipient_member_id = ?)
                   )
                   AND status IN ('unread','acknowledged')
                   AND created_at >= ? AND created_at < ?
                 ORDER BY FIELD(priority, 'critical','high','medium','low'), due_at IS NULL, due_at, id
                 LIMIT 200"
            );
            $items->execute([
                $householdId,
                $adultAccess,
                $memberId,
                $periodStart->format('Y-m-d 00:00:00'),
                $periodEnd->format('Y-m-d 00:00:00'),
            ]);
            $notificationIds = array_map('intval', $items->fetchAll(\PDO::FETCH_COLUMN));

            $insert = $this->pdo->prepare(
                'INSERT INTO notification_digest_runs
                 (household_id, recipient_member_id, cadence, period_start, period_end,
                  run_key, status, item_count, created_by_member_id)
                 VALUES (?, ?, ?, ?, ?, ?, "ready", ?, ?)'
            );
            $insert->execute([
                $householdId,
                $memberId,
                $cadence,
                $periodStart->format('Y-m-d 00:00:00'),
                $periodEnd->format('Y-m-d 00:00:00'),
                $runKey,
                count($notificationIds),
                $memberId,
            ]);
            $digestId = (int)$this->pdo->lastInsertId();

            $line = $this->pdo->prepare(
                'INSERT INTO notification_digest_items (digest_run_id, notification_id, sort_order)
                 VALUES (?, ?, ?)'
            );
            foreach ($notificationIds as $index => $id) {
                $line->execute([$digestId, $id, $index + 1]);
            }
            $this->recordNotificationEvent(
                $householdId,
                null,
                null,
                $digestId,
                $memberId,
                'digest_generated',
                null,
                'ready',
                'Digest contains ' . count($notificationIds) . ' notification(s).'
            );
            $this->pdo->commit();
            return ['digest_id' => $digestId, 'item_count' => count($notificationIds), 'reused' => false];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
