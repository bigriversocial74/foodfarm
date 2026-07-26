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
                    'source_id' => (int)$batch['id'],
                    'dedup_seed' => 'prepared-use-by|' . (int)$batch['id'],
                    'category' => 'prepared_food',
                    'title' => ($overdue ? 'Review immediately: ' : 'Use or freeze: ') . (string)$batch['name'],
                    'body' => sprintf(
                        '%.1f servings remain. Storage: %s. Use-by date: %s.',
                        (float)$batch['servings_remaining'],
                        str_replace('_', ' ', (string)$batch['storage_method']),
                        (string)$batch['use_by_date']
                    ),
                    'priority' => $overdue ? 'critical' : 'high',
                    'visibility' => 'household',
                    'occurs_at' => (string)$batch['use_by_date'] . ' 09:00:00',
                    'due_at' => (string)$batch['use_by_date'] . ' 18:00:00',
                    'expires_at' => null,
                    'sensitive_preview' => 0,
                ]);
                $notificationCount++;
                $this->upsertCalendarEvent($householdId, $memberId, $notificationId, [
                    'source_type' => 'prepared_food_batch',
                    'source_id' => (int)$batch['id'],
                    'event_seed' => 'prepared-use-by|' . (int)$batch['id'],
                    'title' => 'Use or freeze ' . (string)$batch['name'],
                    'description' => 'Prepared-food use-by reminder.',
                    'starts_at' => (string)$batch['use_by_date'] . ' 09:00:00',
                    'ends_at' => null,
                    'all_day' => 1,
                    'visibility' => 'household',
                ]);
                $calendarCount++;
            }

            $forecastHorizon = $asOf->modify('+' . (int)$settings['forecast_alert_days'] . ' days')->format('Y-m-d');
            $snapshotStatement = $this->pdo->prepare(
                "SELECT id FROM forecast_snapshots
                 WHERE household_id = ? AND status = 'completed'
                 ORDER BY as_of_date DESC, id DESC LIMIT 1"
            );
            $snapshotStatement->execute([$householdId]);
            $snapshotId = $snapshotStatement->fetchColumn();
            if ($snapshotId !== false) {
                $projectionQuery = $this->pdo->prepare(
                    'SELECT id, inventory_item_id, item_name_snapshot, unit, projected_ending_quantity,
                            shortage_date, confidence
                     FROM forecast_item_projections
                     WHERE household_id = ? AND snapshot_id = ?
                       AND shortage_date IS NOT NULL AND shortage_date <= ?
                     ORDER BY shortage_date, id'
                );
                $projectionQuery->execute([$householdId, (int)$snapshotId, $forecastHorizon]);
                foreach ($projectionQuery->fetchAll() as $projection) {
                    $notificationId = $this->upsertNotification($householdId, $syncRunId, [
                        'recipient_member_id' => null,
                        'source_type' => 'forecast_projection',
                        'source_id' => (int)$projection['id'],
                        'dedup_seed' => 'forecast-shortage|' . (int)$projection['inventory_item_id'],
                        'category' => 'forecast',
                        'title' => 'Projected shortage: ' . (string)$projection['item_name_snapshot'],
                        'body' => sprintf(
                            'Projected shortage date: %s. Ending quantity: %.2f %s. Confidence: %s.',
                            (string)$projection['shortage_date'],
                            (float)$projection['projected_ending_quantity'],
                            (string)$projection['unit'],
                            (string)$projection['confidence']
                        ),
                        'priority' => 'high',
                        'visibility' => 'household',
                        'occurs_at' => (string)$projection['shortage_date'] . ' 09:00:00',
                        'due_at' => (string)$projection['shortage_date'] . ' 09:00:00',
                        'expires_at' => null,
                        'sensitive_preview' => 0,
                    ]);
                    $notificationCount++;
                    $this->upsertCalendarEvent($householdId, $memberId, $notificationId, [
                        'source_type' => 'forecast_projection',
                        'source_id' => (int)$projection['id'],
                        'event_seed' => 'forecast-shortage|' . (int)$projection['inventory_item_id'],
                        'title' => 'Projected shortage: ' . (string)$projection['item_name_snapshot'],
                        'description' => 'Forecasted household inventory shortage.',
                        'starts_at' => (string)$projection['shortage_date'] . ' 09:00:00',
                        'ends_at' => null,
                        'all_day' => 1,
                        'visibility' => 'household',
                    ]);
                    $calendarCount++;
                }
            }

            $seasonalEnd = $asOf->modify('+21 days')->format('Y-m-d');
            $seasonalQuery = $this->pdo->prepare(
                "SELECT id, assigned_member_id, title, entry_type, starts_on, ends_on, notes
                 FROM seasonal_plan_entries
                 WHERE household_id = ? AND status IN ('planned','accepted')
                   AND starts_on BETWEEN ? AND ?
                 ORDER BY starts_on, id"
            );
            $seasonalQuery->execute([$householdId, $asOf->format('Y-m-d'), $seasonalEnd]);
            foreach ($seasonalQuery->fetchAll() as $entry) {
                $recipientId = $entry['assigned_member_id'] !== null ? (int)$entry['assigned_member_id'] : null;
                $category = in_array((string)$entry['entry_type'], ['plant', 'harvest'], true) ? 'garden' : 'preservation';
                $notificationId = $this->upsertNotification($householdId, $syncRunId, [
                    'recipient_member_id' => $recipientId,
                    'source_type' => 'seasonal_plan_entry',
                    'source_id' => (int)$entry['id'],
                    'dedup_seed' => 'seasonal|' . (int)$entry['id'],
                    'category' => $category,
                    'title' => 'Seasonal plan: ' . (string)$entry['title'],
                    'body' => $entry['notes'] !== null ? (string)$entry['notes'] : 'Review this upcoming seasonal household plan.',
                    'priority' => 'medium',
                    'visibility' => $this->notificationVisibility($recipientId),
                    'occurs_at' => (string)$entry['starts_on'] . ' 09:00:00',
                    'due_at' => (string)$entry['starts_on'] . ' 09:00:00',
                    'expires_at' => $entry['ends_on'] !== null ? (string)$entry['ends_on'] . ' 23:59:59' : null,
                    'sensitive_preview' => 0,
                ]);
                $notificationCount++;
                $this->upsertCalendarEvent($householdId, $memberId, $notificationId, [
                    'source_type' => 'seasonal_plan_entry',
                    'source_id' => (int)$entry['id'],
                    'event_seed' => 'seasonal|' . (int)$entry['id'],
                    'title' => (string)$entry['title'],
                    'description' => $entry['notes'],
                    'starts_at' => (string)$entry['starts_on'] . ' 09:00:00',
                    'ends_at' => $entry['ends_on'] !== null ? (string)$entry['ends_on'] . ' 18:00:00' : null,
                    'all_day' => 1,
                    'visibility' => $this->notificationVisibility($recipientId),
                ]);
                $calendarCount++;
            }

            $notificationCount += $this->syncRecommendationAlerts(
                $householdId,
                $syncRunId,
                'finance_recommendations',
                'finance_recommendation',
                'finance',
                false
            );
            $notificationCount += $this->syncRecommendationAlerts(
                $householdId,
                $syncRunId,
                'nutrition_recommendations',
                'nutrition_recommendation',
                'nutrition',
                true
            );

            $mealEnd = $asOf->modify('+14 days')->format('Y-m-d');
            $mealQuery = $this->pdo->prepare(
                "SELECT mpi.id, mpi.meal_date, mpi.meal_type, mpi.notes, r.name AS recipe_name
                 FROM meal_plan_items mpi
                 JOIN meal_plans mp ON mp.id = mpi.meal_plan_id
                 LEFT JOIN recipes r ON r.id = mpi.recipe_id
                 WHERE mp.household_id = ? AND mp.status IN ('draft','active')
                   AND mpi.meal_date BETWEEN ? AND ?
                   AND mpi.status = 'planned'
                 ORDER BY mpi.meal_date, FIELD(mpi.meal_type,'breakfast','lunch','dinner','snack'), mpi.id"
            );
            $mealQuery->execute([$householdId, $asOf->format('Y-m-d'), $mealEnd]);
            foreach ($mealQuery->fetchAll() as $meal) {
                $mealHour = match ((string)$meal['meal_type']) {
                    'breakfast' => '08:00:00',
                    'lunch' => '12:00:00',
                    'dinner' => '18:00:00',
                    default => '15:00:00',
                };
                $mealTitle = ucfirst((string)$meal['meal_type']) . ': ' . (string)($meal['recipe_name'] ?? 'Planned household meal');
                $this->upsertCalendarEvent($householdId, $memberId, null, [
                    'source_type' => 'meal_plan_item',
                    'source_id' => (int)$meal['id'],
                    'event_seed' => 'meal|' . (int)$meal['id'],
                    'title' => $mealTitle,
                    'description' => $meal['notes'],
                    'starts_at' => (string)$meal['meal_date'] . ' ' . $mealHour,
                    'ends_at' => null,
                    'all_day' => 0,
                    'visibility' => 'household',
                ]);
                $calendarCount++;
                if ((string)$meal['meal_date'] <= $asOf->modify('+1 day')->format('Y-m-d')) {
                    $this->upsertNotification($householdId, $syncRunId, [
                        'recipient_member_id' => null,
                        'source_type' => 'meal_plan_item',
                        'source_id' => (int)$meal['id'],
                        'dedup_seed' => 'meal-reminder|' . (int)$meal['id'],
                        'category' => 'meal',
                        'title' => 'Upcoming meal: ' . $mealTitle,
                        'body' => 'Review ingredients, preparation time, household servings, and dietary rules.',
                        'priority' => 'medium',
                        'visibility' => 'household',
                        'occurs_at' => (string)$meal['meal_date'] . ' ' . $mealHour,
                        'due_at' => (string)$meal['meal_date'] . ' ' . $mealHour,
                        'expires_at' => (string)$meal['meal_date'] . ' 23:59:59',
                        'sensitive_preview' => 1,
                    ]);
                    $notificationCount++;
                }
            }

            $harvestEnd = $asOf->modify('+14 days')->format('Y-m-d');
            $plantingQuery = $this->pdo->prepare(
                "SELECT p.id, p.crop_name, p.variety, p.expected_harvest_start,
                        p.expected_harvest_end, p.growth_stage, gz.name AS zone_name
                 FROM plantings p
                 JOIN garden_zones gz ON gz.id = p.garden_zone_id
                 WHERE gz.household_id = ?
                   AND p.growth_stage NOT IN ('completed','failed')
                   AND (
                       p.growth_stage = 'harvest_ready'
                       OR p.expected_harvest_start BETWEEN ? AND ?
                   )
                 ORDER BY p.expected_harvest_start IS NULL, p.expected_harvest_start, p.id"
            );
            $plantingQuery->execute([$householdId, $asOf->format('Y-m-d'), $harvestEnd]);
            foreach ($plantingQuery->fetchAll() as $planting) {
                $start = $planting['expected_harvest_start'] !== null
                    ? (string)$planting['expected_harvest_start']
                    : $asOf->format('Y-m-d');
                $crop = trim((string)$planting['crop_name'] . ' ' . (string)($planting['variety'] ?? ''));
                $notificationId = $this->upsertNotification($householdId, $syncRunId, [
                    'recipient_member_id' => null,
                    'source_type' => 'planting',
                    'source_id' => (int)$planting['id'],
                    'dedup_seed' => 'harvest-window|' . (int)$planting['id'],
                    'category' => 'garden',
                    'title' => 'Harvest window: ' . $crop,
                    'body' => 'Zone: ' . (string)$planting['zone_name'] . '. Growth stage: ' . str_replace('_', ' ', (string)$planting['growth_stage']) . '.',
                    'priority' => (string)$planting['growth_stage'] === 'harvest_ready' ? 'high' : 'medium',
                    'visibility' => 'household',
                    'occurs_at' => $start . ' 08:00:00',
                    'due_at' => $start . ' 08:00:00',
                    'expires_at' => $planting['expected_harvest_end'] !== null ? (string)$planting['expected_harvest_end'] . ' 23:59:59' : null,
                    'sensitive_preview' => 0,
                ]);
                $notificationCount++;
                $this->upsertCalendarEvent($householdId, $memberId, $notificationId, [
                    'source_type' => 'planting',
                    'source_id' => (int)$planting['id'],
                    'event_seed' => 'harvest-window|' . (int)$planting['id'],
                    'title' => 'Harvest ' . $crop,
                    'description' => 'Expected harvest window in ' . (string)$planting['zone_name'] . '.',
                    'starts_at' => $start . ' 08:00:00',
                    'ends_at' => $planting['expected_harvest_end'] !== null ? (string)$planting['expected_harvest_end'] . ' 18:00:00' : null,
                    'all_day' => 1,
                    'visibility' => 'household',
                ]);
                $calendarCount++;
            }

            $managedSources = [
                'household_task', 'inventory_item', 'prepared_food_batch', 'forecast_projection',
                'seasonal_plan_entry', 'finance_recommendation', 'nutrition_recommendation',
                'meal_plan_item', 'planting',
            ];
            $placeholders = implode(',', array_fill(0, count($managedSources), '?'));
            $expire = $this->pdo->prepare(
                "UPDATE household_notifications
                 SET status = 'expired', acted_at = UTC_TIMESTAMP()
                 WHERE household_id = ?
                   AND source_type IN ($placeholders)
                   AND status IN ('unread','acknowledged')
                   AND (last_seen_sync_run_id IS NULL OR last_seen_sync_run_id <> ?)"
            );
            $expire->execute(array_merge([$householdId], $managedSources, [$syncRunId]));
            $expiredCount = $expire->rowCount();

            $complete = $this->pdo->prepare(
                'UPDATE notification_sync_runs
                 SET status = "completed", notification_count = ?, calendar_event_count = ?,
                     expired_count = ?, completed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = "running"'
            );
            $complete->execute([$notificationCount, $calendarCount, $expiredCount, $syncRunId, $householdId]);
            if ($complete->rowCount() !== 1) {
                throw new RuntimeException('Notification sync changed before completion.');
            }

            $this->recordNotificationEvent(
                $householdId,
                null,
                null,
                null,
                $memberId,
                'notification_sync_completed',
                'running',
                'completed',
                sprintf('%d notifications, %d calendar events, %d expired.', $notificationCount, $calendarCount, $expiredCount)
            );
            $this->pdo->commit();
            return [
                'sync_run_id' => $syncRunId,
                'notification_count' => $notificationCount,
                'calendar_event_count' => $calendarCount,
                'expired_count' => $expiredCount,
                'reused' => false,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function syncRecommendationAlerts(
        int $householdId,
        int $syncRunId,
        string $table,
        string $sourceType,
        string $category,
        bool $sensitive
    ): int {
        if (!in_array($table, ['finance_recommendations', 'nutrition_recommendations'], true)) {
            throw new InvalidArgumentException('Unsupported recommendation source.');
        }
        $memberColumn = $table === 'nutrition_recommendations' ? 'household_member_id' : 'NULL';
        $statement = $this->pdo->prepare(
            "SELECT id, $memberColumn AS recipient_member_id, title, rationale,
                    recommended_action, priority, due_on
             FROM `$table`
             WHERE household_id = ? AND status IN ('pending','accepted')
               AND priority IN ('high','critical')
             ORDER BY FIELD(priority,'critical','high'), due_on IS NULL, due_on, id"
        );
        $statement->execute([$householdId]);
        $count = 0;
        foreach ($statement->fetchAll() as $recommendation) {
            $recipientId = $recommendation['recipient_member_id'] !== null
                ? (int)$recommendation['recipient_member_id']
                : null;
            $this->upsertNotification($householdId, $syncRunId, [
                'recipient_member_id' => $recipientId,
                'source_type' => $sourceType,
                'source_id' => (int)$recommendation['id'],
                'dedup_seed' => $sourceType . '|' . (int)$recommendation['id'],
                'category' => $category,
                'title' => (string)$recommendation['title'],
                'body' => (string)$recommendation['rationale'] . ' ' . (string)$recommendation['recommended_action'],
                'priority' => (string)$recommendation['priority'],
                'visibility' => $this->notificationVisibility($recipientId, $category === 'finance'),
                'occurs_at' => $recommendation['due_on'] !== null ? (string)$recommendation['due_on'] . ' 09:00:00' : null,
                'due_at' => $recommendation['due_on'] !== null ? (string)$recommendation['due_on'] . ' 09:00:00' : null,
                'expires_at' => null,
                'sensitive_preview' => $sensitive ? 1 : 0,
            ]);
            $count++;
        }
        return $count;
    }

    private function upsertNotification(int $householdId, int $syncRunId, array $data): int
    {
        $recipientId = $data['recipient_member_id'] !== null ? (int)$data['recipient_member_id'] : null;
        if ($recipientId !== null) {
            $this->assertHouseholdMember($householdId, $recipientId);
        }
        $category = $this->choice($data['category'], self::CATEGORIES, 'Notification category');
        $priority = $this->choice($data['priority'], self::PRIORITIES, 'Notification priority');
        $visibility = $this->choice($data['visibility'], ['household', 'adults_only', 'private'], 'Notification visibility');
        $dedupKey = hash('sha256', 'phase11|' . $householdId . '|' . (string)$data['dedup_seed']);
        $scopeKey = $recipientId ?? 0;

        $existing = $this->pdo->prepare(
            'SELECT id FROM household_notifications
             WHERE household_id = ? AND recipient_scope_key = ? AND dedup_key = ?'
        );
        $existing->execute([$householdId, $scopeKey, $dedupKey]);
        $existingId = $existing->fetchColumn();

        $statement = $this->pdo->prepare(
            'INSERT INTO household_notifications
             (household_id, recipient_member_id, recipient_scope_key, source_type, source_id,
              dedup_key, category, title, body, priority, visibility, status, sensitive_preview,
              occurs_at, due_at, expires_at, last_seen_sync_run_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "unread", ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                source_type = VALUES(source_type),
                source_id = VALUES(source_id),
                category = VALUES(category),
                title = VALUES(title),
                body = VALUES(body),
                priority = VALUES(priority),
                visibility = VALUES(visibility),
                sensitive_preview = VALUES(sensitive_preview),
                occurs_at = VALUES(occurs_at),
                due_at = VALUES(due_at),
                expires_at = VALUES(expires_at),
                last_seen_sync_run_id = VALUES(last_seen_sync_run_id),
                status = IF(status = "expired", "unread", status)'
        );
        $statement->execute([
            $householdId,
            $recipientId,
            $scopeKey,
            $this->text($data['source_type'], 80, 'Source type'),
            $data['source_id'] !== null ? (int)$data['source_id'] : null,
            $dedupKey,
            $category,
            $this->text($data['title'], 180, 'Notification title'),
            $this->nullableText($data['body'], 5000, 'Notification body'),
            $priority,
            $visibility,
            $this->boolValue($data['sensitive_preview'] ?? 0),
            $data['occurs_at'],
            $data['due_at'],
            $data['expires_at'],
            $syncRunId,
        ]);

        if ($existingId === false) {
            $notificationId = (int)$this->pdo->lastInsertId();
            $this->recordNotificationEvent(
                $householdId,
                $notificationId,
                null,
                null,
                null,
                'notification_created',
                null,
                'unread'
            );
        } else {
            $notificationId = (int)$existingId;
        }
        $this->queueDeliveryCandidates($householdId, $notificationId);
        return $notificationId;
    }

    private function upsertCalendarEvent(
        int $householdId,
        int $memberId,
        ?int $notificationId,
        array $data
    ): int {
        $eventKey = hash('sha256', 'phase11-calendar|' . $householdId . '|' . (string)$data['event_seed']);
        $existing = $this->pdo->prepare(
            'SELECT id FROM household_calendar_events WHERE household_id = ? AND event_key = ?'
        );
        $existing->execute([$householdId, $eventKey]);
        $existingId = $existing->fetchColumn();

        $statement = $this->pdo->prepare(
            'INSERT INTO household_calendar_events
             (household_id, notification_id, source_type, source_id, event_key, title, description,
              starts_at, ends_at, all_day, visibility, status, created_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "scheduled", ?)
             ON DUPLICATE KEY UPDATE
                notification_id = VALUES(notification_id),
                source_type = VALUES(source_type),
                source_id = VALUES(source_id),
                title = VALUES(title),
                description = VALUES(description),
                starts_at = VALUES(starts_at),
                ends_at = VALUES(ends_at),
                all_day = VALUES(all_day),
                visibility = VALUES(visibility),
                status = IF(status = "cancelled", "scheduled", status)'
        );
        $statement->execute([
            $householdId,
            $notificationId,
            $this->text($data['source_type'], 80, 'Calendar source type'),
            $data['source_id'] !== null ? (int)$data['source_id'] : null,
            $eventKey,
            $this->text($data['title'], 180, 'Calendar title'),
            $this->nullableText($data['description'], 5000, 'Calendar description'),
            (string)$data['starts_at'],
            $data['ends_at'],
            $this->boolValue($data['all_day'] ?? 0),
            $this->choice($data['visibility'], ['household', 'adults_only', 'private'], 'Calendar visibility'),
            $memberId,
        ]);
        $calendarId = $existingId === false ? (int)$this->pdo->lastInsertId() : (int)$existingId;
        if ($existingId === false) {
            $this->recordNotificationEvent(
                $householdId,
                $notificationId,
                $calendarId,
                null,
                $memberId,
                'calendar_event_created',
                null,
                'scheduled'
            );
        }
        return $calendarId;
    }

    private function queueDeliveryCandidates(int $householdId, int $notificationId): void
    {
        $settings = $this->settings($householdId);
        if ((int)$settings['email_adapter_enabled'] !== 1
            && (int)$settings['web_push_adapter_enabled'] !== 1) {
            return;
        }

        $query = $this->pdo->prepare(
            'SELECT * FROM household_notifications WHERE id = ? AND household_id = ?'
        );
        $query->execute([$notificationId, $householdId]);
        $notification = $query->fetch();
        if (!is_array($notification)) {
            return;
        }

        if ($notification['recipient_member_id'] !== null) {
            $memberIds = [(int)$notification['recipient_member_id']];
        } else {
            $members = $this->pdo->prepare(
                "SELECT id FROM household_members
                 WHERE household_id = ? AND status = 'active' AND user_id IS NOT NULL
                 ORDER BY id"
            );
            $members->execute([$householdId]);
            $memberIds = array_map('intval', $members->fetchAll(\PDO::FETCH_COLUMN));
        }

        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO notification_delivery_outbox
             (household_id, notification_id, recipient_member_id, channel, status, payload_json, available_at)
             VALUES (?, ?, ?, ?, "pending", ?, UTC_TIMESTAMP())'
        );
        foreach ($memberIds as $recipientId) {
            $preference = $this->memberPreference($householdId, $recipientId);
            if ($this->priorityRank((string)$notification['priority']) < $this->priorityRank((string)$preference['minimum_priority'])) {
                continue;
            }
            if (!in_array((string)$notification['category'], (array)$preference['enabled_categories_list'], true)) {
                continue;
            }
            $privatePreview = (int)$notification['sensitive_preview'] === 1
                && (int)$preference['allow_sensitive_previews'] !== 1;
            $payload = json_encode([
                'notification_id' => $notificationId,
                'category' => (string)$notification['category'],
                'priority' => (string)$notification['priority'],
                'title' => $privatePreview ? 'Private household notification' : (string)$notification['title'],
                'body' => $privatePreview ? 'Open Homestead to review this private household update.' : (string)($notification['body'] ?? ''),
                'due_at' => $notification['due_at'],
            ], JSON_THROW_ON_ERROR);

            if ((int)$settings['email_adapter_enabled'] === 1 && (int)$preference['email_enabled'] === 1) {
                $insert->execute([$householdId, $notificationId, $recipientId, 'email', $payload]);
            }
            if ((int)$settings['web_push_adapter_enabled'] === 1 && (int)$preference['web_push_enabled'] === 1) {
                $insert->execute([$householdId, $notificationId, $recipientId, 'web_push', $payload]);
            }
        }
    }
}
