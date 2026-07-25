<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ForecastingService
{
    private const MODEL_VERSION = 'deterministic-v1';

    public function __construct(private PDO $pdo)
    {
    }

    public function settings(int $householdId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT household_id, horizon_days, history_days, target_self_sufficiency_percent,
                    target_buffer_days, updated_by_member_id, created_at, updated_at
             FROM household_forecast_settings WHERE household_id = ?'
        );
        $statement->execute([$householdId]);
        $settings = $statement->fetch();
        if (is_array($settings)) {
            return $settings;
        }

        return [
            'household_id' => $householdId,
            'horizon_days' => 90,
            'history_days' => 90,
            'target_self_sufficiency_percent' => '25.00',
            'target_buffer_days' => 21,
            'updated_by_member_id' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function saveSettings(int $householdId, int $memberId, array $input): void
    {
        $this->assertActiveMember($householdId, $memberId);
        $horizonDays = $this->boundedInt($input['horizon_days'] ?? 90, 30, 365, 'Forecast horizon');
        $historyDays = $this->boundedInt($input['history_days'] ?? 90, 30, 365, 'History window');
        $targetSelf = $this->boundedFloat(
            $input['target_self_sufficiency_percent'] ?? 25,
            0,
            100,
            'Self-sufficiency target'
        );
        $targetBuffer = $this->boundedInt($input['target_buffer_days'] ?? 21, 1, 180, 'Target buffer');

        $statement = $this->pdo->prepare(
            'INSERT INTO household_forecast_settings
             (household_id, horizon_days, history_days, target_self_sufficiency_percent,
              target_buffer_days, updated_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 horizon_days = VALUES(horizon_days),
                 history_days = VALUES(history_days),
                 target_self_sufficiency_percent = VALUES(target_self_sufficiency_percent),
                 target_buffer_days = VALUES(target_buffer_days),
                 updated_by_member_id = VALUES(updated_by_member_id)'
        );
        $statement->execute([
            $householdId,
            $horizonDays,
            $historyDays,
            $targetSelf,
            $targetBuffer,
            $memberId,
        ]);
    }

    public function runForecast(int $householdId, int $memberId, string $asOfDate): array
    {
        $this->assertActiveMember($householdId, $memberId);
        $asOf = $this->date($asOfDate, 'Forecast date');
        $settings = $this->settings($householdId);
        $horizonDays = (int)$settings['horizon_days'];
        $historyDays = (int)$settings['history_days'];
        $watermark = $this->sourceWatermark($householdId);
        $runKey = hash(
            'sha256',
            implode('|', [
                'phase8',
                self::MODEL_VERSION,
                (string)$householdId,
                $asOf->format('Y-m-d'),
                (string)$horizonDays,
                (string)$historyDays,
                (string)$settings['target_buffer_days'],
                (string)$settings['target_self_sufficiency_percent'],
                $watermark,
            ])
        );

        $this->pdo->beginTransaction();
        $snapshotId = null;
        try {
            $existing = $this->pdo->prepare(
                'SELECT * FROM forecast_snapshots
                 WHERE household_id = ? AND run_key = ? FOR UPDATE'
            );
            $existing->execute([$householdId, $runKey]);
            $snapshot = $existing->fetch();
            if (is_array($snapshot) && $snapshot['status'] === 'completed') {
                $this->pdo->commit();
                return $this->snapshotResult($snapshot, true);
            }
            if (is_array($snapshot) && $snapshot['status'] === 'running') {
                throw new RuntimeException('This forecast is already being generated.');
            }
            if (is_array($snapshot)) {
                $this->pdo->prepare('DELETE FROM forecast_snapshots WHERE id = ? AND household_id = ?')
                    ->execute([(int)$snapshot['id'], $householdId]);
            }

            $insertSnapshot = $this->pdo->prepare(
                'INSERT INTO forecast_snapshots
                 (household_id, as_of_date, horizon_days, history_days, run_key,
                  source_watermark, model_version, status, generated_by_member_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "running", ?)'
            );
            $insertSnapshot->execute([
                $householdId,
                $asOf->format('Y-m-d'),
                $horizonDays,
                $historyDays,
                $runKey,
                $watermark,
                self::MODEL_VERSION,
                $memberId,
            ]);
            $snapshotId = (int)$this->pdo->lastInsertId();

            $historyStart = $asOf->modify('-' . $historyDays . ' days')->format('Y-m-d 00:00:00');
            $horizonEnd = $asOf->modify('+' . $horizonDays . ' days');
            $items = $this->inventorySignals($householdId, $historyStart);
            $plannedDemand = $this->plannedDemand($householdId, $asOf, $horizonEnd);
            $harvestProfiles = $this->historicalHarvestProfiles($householdId, $historyStart);
            $upcomingHarvests = $this->upcomingHarvests($householdId, $asOf, $horizonEnd);
            $preservationInflows = $this->preservationInflows($householdId, $asOf, $horizonEnd);

            $coverageConfigured = 0;
            $coverageMet = 0;
            $shortageCount = 0;
            $productionRatios = [];
            $itemDetails = [];

            foreach ($items as $item) {
                $itemId = (int)$item['id'];
                $nameKey = $this->normalKey((string)$item['name']);
                $unit = (string)$item['unit'];
                $current = max(0.0, (float)$item['current_quantity']);
                $depletionQuantity = max(0.0, (float)$item['depletion_quantity']);
                $dailyDepletion = $depletionQuantity / max(1, $historyDays);
                $planned = max(0.0, (float)($plannedDemand[$itemId] ?? 0));
                $baselineConsumption = $dailyDepletion * $horizonDays;
                $projectedConsumption = max($baselineConsumption, $planned);

                $harvestQuantity = 0.0;
                $upcoming = $upcomingHarvests[$nameKey] ?? null;
                $harvestProfile = $harvestProfiles[$nameKey] ?? null;
                if (is_array($upcoming) && is_array($harvestProfile)
                    && strcasecmp((string)$harvestProfile['unit'], $unit) === 0) {
                    $harvestQuantity = max(
                        0.0,
                        (float)$harvestProfile['average_quantity'] * (int)$upcoming['planting_count']
                    );
                }
                $preservationQuantity = max(0.0, (float)($preservationInflows[$itemId] ?? 0));
                $ending = $current + $harvestQuantity + $preservationQuantity - $projectedConsumption;
                $daysOnHand = $dailyDepletion > 0 ? $current / $dailyDepletion : null;
                $shortageDate = null;
                if ($ending < 0 && $dailyDepletion > 0) {
                    $availableDays = max(0, (int)floor(($current + $harvestQuantity + $preservationQuantity) / $dailyDepletion));
                    $shortageDate = $asOf->modify('+' . min($horizonDays, $availableDays) . ' days')->format('Y-m-d');
                    $shortageCount++;
                }

                $produced = max(0.0, (float)$item['produced_quantity']);
                $external = max(0.0, (float)$item['external_quantity']);
                $ratio = ($produced + $external) > 0 ? ($produced / ($produced + $external)) * 100 : null;
                if ($ratio !== null) {
                    $productionRatios[] = $ratio;
                }

                $eventCount = (int)$item['depletion_event_count'];
                $confidence = $eventCount >= 8 ? 'high' : ($eventCount >= 3 ? 'medium' : 'low');
                $projectionId = $this->insertProjection(
                    $snapshotId,
                    $householdId,
                    $item,
                    $dailyDepletion,
                    $planned,
                    $projectedConsumption,
                    $harvestQuantity,
                    $preservationQuantity,
                    $ending,
                    $daysOnHand,
                    $shortageDate,
                    $ratio,
                    $eventCount,
                    $confidence
                );
                $itemDetails[$itemId] = [
                    'projection_id' => $projectionId,
                    'item' => $item,
                    'daily_depletion' => $dailyDepletion,
                    'projected_consumption' => $projectedConsumption,
                    'harvest_quantity' => $harvestQuantity,
                    'preservation_quantity' => $preservationQuantity,
                    'ending' => $ending,
                    'days_on_hand' => $daysOnHand,
                    'shortage_date' => $shortageDate,
                    'upcoming' => $upcoming,
                    'ratio' => $ratio,
                ];

                if ($item['reorder_level'] !== null) {
                    $coverageConfigured++;
                    if ($current > (float)$item['reorder_level']) {
                        $coverageMet++;
                    }
                }
            }

            $coverageScore = $coverageConfigured > 0 ? ($coverageMet / $coverageConfigured) * 100 : 0.0;
            $selfSufficiency = $productionRatios !== []
                ? array_sum($productionRatios) / count($productionRatios)
                : 0.0;
            $seasonalReadiness = $this->seasonalReadinessScore($upcomingHarvests, $horizonDays);
            $resilience = ($coverageScore + $selfSufficiency + $seasonalReadiness) / 3;

            foreach ($itemDetails as $detail) {
                $this->generateItemRecommendations(
                    $householdId,
                    $snapshotId,
                    $asOf,
                    $horizonEnd,
                    $settings,
                    $detail
                );
            }
            $this->generateSeasonalEntries(
                $householdId,
                $snapshotId,
                $asOf,
                $horizonEnd,
                $itemDetails
            );

            $recommendationCountStatement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM forecast_recommendations
                 WHERE household_id = ? AND snapshot_id = ?'
            );
            $recommendationCountStatement->execute([$householdId, $snapshotId]);
            $recommendationCount = (int)$recommendationCountStatement->fetchColumn();

            $harvestCount = array_sum(array_map(
                static fn(array $row): int => (int)$row['planting_count'],
                $upcomingHarvests
            ));

            $this->insertMetric(
                $snapshotId,
                $householdId,
                'inventory_coverage',
                $coverageMet,
                $coverageConfigured,
                $coverageScore,
                'Percentage of inventory items with configured reorder levels that are currently above their reorder threshold.'
            );
            $this->insertMetric(
                $snapshotId,
                $householdId,
                'tracked_production_share',
                count($productionRatios),
                count($items),
                $selfSufficiency,
                'Average item-level share of tracked inflow from harvest and preservation rather than purchase or receipt; this is not a calorie calculation.'
            );
            $this->insertMetric(
                $snapshotId,
                $householdId,
                'seasonal_readiness',
                count($upcomingHarvests),
                max(1, (int)ceil($horizonDays / 30)),
                $seasonalReadiness,
                'Percentage of forecast months containing at least one expected harvest window.'
            );
            $this->insertMetric(
                $snapshotId,
                $householdId,
                'resilience',
                $coverageScore + $selfSufficiency + $seasonalReadiness,
                3,
                $resilience,
                'Simple average of inventory coverage, tracked production share, and seasonal readiness.'
            );

            $update = $this->pdo->prepare(
                'UPDATE forecast_snapshots
                 SET status = "completed",
                     inventory_coverage_score = ?,
                     self_sufficiency_score = ?,
                     seasonal_readiness_score = ?,
                     resilience_score = ?,
                     projected_shortage_count = ?,
                     projected_harvest_count = ?,
                     recommendation_count = ?,
                     completed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = "running"'
            );
            $update->execute([
                $this->score($coverageScore),
                $this->score($selfSufficiency),
                $this->score($seasonalReadiness),
                $this->score($resilience),
                $shortageCount,
                $harvestCount,
                $recommendationCount,
                $snapshotId,
                $householdId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The forecast snapshot could not be finalized.');
            }

            $this->recordEvent(
                $householdId,
                $snapshotId,
                null,
                null,
                $memberId,
                'snapshot_completed',
                'running',
                'completed',
                sprintf('%d projections and %d recommendations generated.', count($items), $recommendationCount)
            );
            $this->pdo->commit();

            $completed = $this->pdo->prepare('SELECT * FROM forecast_snapshots WHERE id = ? AND household_id = ?');
            $completed->execute([$snapshotId, $householdId]);
            $row = $completed->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('The completed forecast could not be read.');
            }
            return $this->snapshotResult($row, false);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createSeasonalEntry(int $householdId, int $memberId, array $input): int
    {
        $this->assertActiveMember($householdId, $memberId);
        $title = $this->text($input['title'] ?? '', 180, 'Title');
        $entryType = $this->choice(
            $input['entry_type'] ?? '',
            ['plant', 'harvest', 'preserve', 'purchase', 'rotate', 'prepare'],
            'Entry type'
        );
        $start = $this->date((string)($input['starts_on'] ?? ''), 'Start date');
        $end = null;
        if (trim((string)($input['ends_on'] ?? '')) !== '') {
            $end = $this->date((string)$input['ends_on'], 'End date');
            if ($end < $start) {
                throw new InvalidArgumentException('End date must not be earlier than the start date.');
            }
        }
        $assignedMemberId = $this->nullablePositiveInt($input['assigned_member_id'] ?? null);
        if ($assignedMemberId !== null) {
            $this->assertActiveMember($householdId, $assignedMemberId);
        }
        $quantity = $this->nullableFloat($input['expected_quantity'] ?? null, 0, 999999999, 'Expected quantity');
        $unit = trim((string)($input['unit'] ?? ''));
        if ($quantity !== null && $unit === '') {
            throw new InvalidArgumentException('A unit is required when an expected quantity is supplied.');
        }
        if (strlen($unit) > 30) {
            throw new InvalidArgumentException('Unit is too long.');
        }
        $cropOrItem = trim((string)($input['crop_or_item'] ?? ''));
        if (strlen($cropOrItem) > 180) {
            throw new InvalidArgumentException('Crop or item is too long.');
        }
        $notes = trim((string)($input['notes'] ?? ''));
        if (strlen($notes) > 5000) {
            throw new InvalidArgumentException('Notes are too long.');
        }
        $actionKey = strtolower(trim((string)($input['action_key'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $actionKey) !== 1) {
            throw new InvalidArgumentException('A valid action key is required.');
        }
        $generationKey = hash('sha256', 'phase8-manual-season|' . $householdId . '|' . $actionKey);

        $insert = $this->pdo->prepare(
            'INSERT INTO seasonal_plan_entries
             (household_id, entry_type, generation_key, title, crop_or_item, starts_on, ends_on,
              expected_quantity, unit, source_type, assigned_member_id, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "manual", ?, "planned", ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $insert->execute([
            $householdId,
            $entryType,
            $generationKey,
            $title,
            $cropOrItem !== '' ? $cropOrItem : null,
            $start->format('Y-m-d'),
            $end?->format('Y-m-d'),
            $quantity,
            $unit !== '' ? $unit : null,
            $assignedMemberId,
            $notes !== '' ? $notes : null,
        ]);
        $entryId = (int)$this->pdo->lastInsertId();
        if ($entryId <= 0) {
            $lookup = $this->pdo->prepare(
                'SELECT id FROM seasonal_plan_entries WHERE household_id = ? AND generation_key = ?'
            );
            $lookup->execute([$householdId, $generationKey]);
            $entryId = (int)$lookup->fetchColumn();
        }
        return $entryId;
    }

    public function updateSeasonalEntry(
        int $householdId,
        int $memberId,
        int $entryId,
        string $toStatus
    ): void {
        $this->assertActiveMember($householdId, $memberId);
        $toStatus = $this->choice($toStatus, ['accepted', 'completed', 'dismissed'], 'Seasonal status');

        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT * FROM seasonal_plan_entries WHERE id = ? AND household_id = ? FOR UPDATE'
            );
            $query->execute([$entryId, $householdId]);
            $entry = $query->fetch();
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Seasonal plan entry was not found.');
            }
            $from = (string)$entry['status'];
            if ($from === $toStatus) {
                $this->pdo->commit();
                return;
            }
            $allowed = [
                'planned' => ['accepted', 'dismissed', 'completed'],
                'accepted' => ['completed', 'dismissed'],
                'completed' => [],
                'dismissed' => [],
            ];
            if (!in_array($toStatus, $allowed[$from] ?? [], true)) {
                throw new InvalidArgumentException('That seasonal plan transition is not allowed.');
            }
            $update = $this->pdo->prepare(
                'UPDATE seasonal_plan_entries
                 SET status = ?, acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = ?'
            );
            $update->execute([$toStatus, $memberId, $entryId, $householdId, $from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Seasonal plan entry changed before it could be updated.');
            }
            $this->recordEvent(
                $householdId,
                $entry['snapshot_id'] !== null ? (int)$entry['snapshot_id'] : null,
                null,
                $entryId,
                $memberId,
                'seasonal_status_changed',
                $from,
                $toStatus,
                null
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function acceptRecommendation(int $householdId, int $memberId, int $recommendationId): int
    {
        $this->assertActiveMember($householdId, $memberId);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT * FROM forecast_recommendations
                 WHERE id = ? AND household_id = ? FOR UPDATE'
            );
            $query->execute([$recommendationId, $householdId]);
            $recommendation = $query->fetch();
            if (!is_array($recommendation)) {
                throw new InvalidArgumentException('Forecast recommendation was not found.');
            }
            if ($recommendation['status'] === 'accepted' && $recommendation['related_task_id'] !== null) {
                $this->pdo->commit();
                return (int)$recommendation['related_task_id'];
            }
            if ($recommendation['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending recommendations can be accepted.');
            }

            $dueAt = $recommendation['due_on'] !== null
                ? (string)$recommendation['due_on'] . ' 09:00:00'
                : (new DateTimeImmutable('+7 days 09:00'))->format('Y-m-d H:i:s');
            $task = $this->pdo->prepare(
                'INSERT INTO household_tasks
                 (household_id, assigned_member_id, title, description, related_type, related_id,
                  due_at, priority, status)
                 VALUES (?, NULL, ?, ?, "forecast_recommendation", ?, ?, ?, "ready")'
            );
            $task->execute([
                $householdId,
                (string)$recommendation['title'],
                (string)$recommendation['recommended_action'],
                $recommendationId,
                $dueAt,
                (string)$recommendation['priority'],
            ]);
            $taskId = (int)$this->pdo->lastInsertId();
            $generationKey = hash('sha256', 'phase8-recommendation-task|' . $householdId . '|' . $recommendationId);
            $metadata = $this->pdo->prepare(
                'INSERT INTO task_automation_metadata
                 (household_id, household_task_id, planning_cycle_id, recurring_template_id,
                  source_type, source_id, generation_key)
                 VALUES (?, ?, NULL, NULL, "forecast_recommendation", ?, ?)'
            );
            $metadata->execute([$householdId, $taskId, $recommendationId, $generationKey]);

            $update = $this->pdo->prepare(
                'UPDATE forecast_recommendations
                 SET status = "accepted", related_task_id = ?, acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = "pending"'
            );
            $update->execute([$taskId, $memberId, $recommendationId, $householdId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Recommendation changed before it could be accepted.');
            }

            $this->recordEvent(
                $householdId,
                (int)$recommendation['snapshot_id'],
                $recommendationId,
                null,
                $memberId,
                'recommendation_accepted',
                'pending',
                'accepted',
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

    public function dismissRecommendation(int $householdId, int $memberId, int $recommendationId): void
    {
        $this->transitionRecommendation($householdId, $memberId, $recommendationId, 'dismissed');
    }

    public function completeRecommendation(int $householdId, int $memberId, int $recommendationId): void
    {
        $this->transitionRecommendation($householdId, $memberId, $recommendationId, 'completed');
    }

    public function dashboardData(int $householdId): array
    {
        $settings = $this->settings($householdId);
        $snapshotQuery = $this->pdo->prepare(
            'SELECT fs.*, hm.display_name AS generated_by
             FROM forecast_snapshots fs
             LEFT JOIN household_members hm
               ON hm.id = fs.generated_by_member_id AND hm.household_id = fs.household_id
             WHERE fs.household_id = ? AND fs.status = "completed"
             ORDER BY fs.as_of_date DESC, fs.id DESC LIMIT 1'
        );
        $snapshotQuery->execute([$householdId]);
        $snapshot = $snapshotQuery->fetch();
        $snapshotId = is_array($snapshot) ? (int)$snapshot['id'] : 0;

        $projections = [];
        if ($snapshotId > 0) {
            $projectionQuery = $this->pdo->prepare(
                'SELECT fp.*, i.best_use_date, i.reorder_level, i.target_stock_level
                 FROM forecast_item_projections fp
                 JOIN inventory_items i
                   ON i.id = fp.inventory_item_id AND i.household_id = fp.household_id
                 WHERE fp.household_id = ? AND fp.snapshot_id = ?
                 ORDER BY
                   CASE WHEN fp.shortage_date IS NULL THEN 1 ELSE 0 END,
                   fp.shortage_date,
                   fp.days_on_hand,
                   fp.item_name_snapshot'
            );
            $projectionQuery->execute([$householdId, $snapshotId]);
            $projections = $projectionQuery->fetchAll();
        }

        $recommendationQuery = $this->pdo->prepare(
            'SELECT fr.*, fp.item_name_snapshot, ht.status AS task_status
             FROM forecast_recommendations fr
             LEFT JOIN forecast_item_projections fp
               ON fp.id = fr.projection_id AND fp.household_id = fr.household_id
             LEFT JOIN household_tasks ht
               ON ht.id = fr.related_task_id AND ht.household_id = fr.household_id
             WHERE fr.household_id = ? AND fr.status IN ("pending","accepted")
             ORDER BY FIELD(fr.priority, "critical","high","medium","low"), fr.due_on, fr.id'
        );
        $recommendationQuery->execute([$householdId]);

        $seasonalQuery = $this->pdo->prepare(
            'SELECT spe.*, hm.display_name AS assignee_name
             FROM seasonal_plan_entries spe
             LEFT JOIN household_members hm
               ON hm.id = spe.assigned_member_id AND hm.household_id = spe.household_id
             WHERE spe.household_id = ?
               AND spe.status IN ("planned","accepted")
               AND spe.starts_on <= DATE_ADD(CURDATE(), INTERVAL 365 DAY)
             ORDER BY spe.starts_on, spe.entry_type, spe.id'
        );
        $seasonalQuery->execute([$householdId]);

        $trendQuery = $this->pdo->prepare(
            'SELECT id, as_of_date, horizon_days, inventory_coverage_score,
                    self_sufficiency_score, seasonal_readiness_score, resilience_score,
                    projected_shortage_count, recommendation_count, completed_at
             FROM forecast_snapshots
             WHERE household_id = ? AND status = "completed"
             ORDER BY as_of_date DESC, id DESC LIMIT 12'
        );
        $trendQuery->execute([$householdId]);

        $membersQuery = $this->pdo->prepare(
            'SELECT id, display_name, role FROM household_members
             WHERE household_id = ? AND status = "active"
             ORDER BY FIELD(role, "owner","administrator","adult_member","youth_member","guest_helper"),
                      display_name'
        );
        $membersQuery->execute([$householdId]);

        return [
            'settings' => $settings,
            'snapshot' => is_array($snapshot) ? $snapshot : null,
            'projections' => $projections,
            'recommendations' => $recommendationQuery->fetchAll(),
            'seasonal_entries' => $seasonalQuery->fetchAll(),
            'trends' => $trendQuery->fetchAll(),
            'members' => $membersQuery->fetchAll(),
        ];
    }

    private function inventorySignals(int $householdId, string $historyStart): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                 i.id, i.name, i.unit, i.current_quantity, i.reorder_level,
                 i.target_stock_level, i.best_use_date, c.name AS category_name,
                 COALESCE(SUM(CASE
                     WHEN e.event_type IN ("consumed","used_in_recipe","spoiled","discarded")
                     THEN ABS(e.quantity) ELSE 0 END), 0) AS depletion_quantity,
                 COALESCE(SUM(CASE
                     WHEN e.event_type IN ("consumed","used_in_recipe","spoiled","discarded")
                     THEN 1 ELSE 0 END), 0) AS depletion_event_count,
                 COALESCE(SUM(CASE
                     WHEN e.event_type IN ("harvested","preserved")
                     THEN ABS(e.quantity) ELSE 0 END), 0) AS produced_quantity,
                 COALESCE(SUM(CASE
                     WHEN e.event_type IN ("purchased","received")
                     THEN ABS(e.quantity) ELSE 0 END), 0) AS external_quantity
             FROM inventory_items i
             LEFT JOIN inventory_categories c
               ON c.id = i.category_id
              AND (c.household_id IS NULL OR c.household_id = i.household_id)
             LEFT JOIN food_ledger_events e
               ON e.inventory_item_id = i.id
              AND e.household_id = i.household_id
              AND e.occurred_at >= ?
             WHERE i.household_id = ?
               AND i.status = "active"
               AND i.item_type IN ("ingredient","prepared_food","preserved_food")
             GROUP BY i.id, i.name, i.unit, i.current_quantity, i.reorder_level,
                      i.target_stock_level, i.best_use_date, c.name
             ORDER BY i.name'
        );
        $statement->execute([$historyStart, $householdId]);
        return $statement->fetchAll();
    }

    private function plannedDemand(int $householdId, DateTimeImmutable $asOf, DateTimeImmutable $horizonEnd): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ri.inventory_item_id,
                    SUM(ri.quantity * mpi.planned_servings / NULLIF(r.servings, 0)) AS planned_quantity
             FROM meal_plan_items mpi
             JOIN meal_plans mp ON mp.id = mpi.meal_plan_id
             JOIN recipes r ON r.id = mpi.recipe_id AND r.household_id = mp.household_id
             JOIN recipe_ingredients ri ON ri.recipe_id = r.id AND ri.inventory_item_id IS NOT NULL
             JOIN inventory_items i
               ON i.id = ri.inventory_item_id AND i.household_id = mp.household_id
             WHERE mp.household_id = ?
               AND mp.status IN ("draft","active")
               AND mpi.meal_date BETWEEN ? AND ?
               AND LOWER(ri.unit) = LOWER(i.unit)
             GROUP BY ri.inventory_item_id'
        );
        $statement->execute([
            $householdId,
            $asOf->format('Y-m-d'),
            $horizonEnd->format('Y-m-d'),
        ]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(int)$row['inventory_item_id']] = (float)$row['planned_quantity'];
        }
        return $result;
    }

    private function historicalHarvestProfiles(int $householdId, string $historyStart): array
    {
        $statement = $this->pdo->prepare(
            'SELECT LOWER(TRIM(p.crop_name)) AS crop_key, h.unit,
                    AVG(h.quantity) AS average_quantity, COUNT(*) AS harvest_count
             FROM harvests h
             JOIN plantings p ON p.id = h.planting_id
             JOIN garden_zones z ON z.id = p.garden_zone_id
             WHERE z.household_id = ? AND h.harvested_at >= ?
             GROUP BY LOWER(TRIM(p.crop_name)), h.unit'
        );
        $statement->execute([$householdId, $historyStart]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $key = (string)$row['crop_key'];
            if (!isset($result[$key]) || (int)$row['harvest_count'] > (int)$result[$key]['harvest_count']) {
                $result[$key] = $row;
            }
        }
        return $result;
    }

    private function upcomingHarvests(
        int $householdId,
        DateTimeImmutable $asOf,
        DateTimeImmutable $horizonEnd
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT LOWER(TRIM(p.crop_name)) AS crop_key, p.crop_name,
                    COUNT(*) AS planting_count,
                    MIN(p.expected_harvest_start) AS starts_on,
                    MAX(COALESCE(p.expected_harvest_end, p.expected_harvest_start)) AS ends_on
             FROM plantings p
             JOIN garden_zones z ON z.id = p.garden_zone_id
             WHERE z.household_id = ?
               AND p.growth_stage NOT IN ("completed","failed")
               AND p.expected_harvest_start BETWEEN ? AND ?
             GROUP BY LOWER(TRIM(p.crop_name)), p.crop_name'
        );
        $statement->execute([
            $householdId,
            $asOf->format('Y-m-d'),
            $horizonEnd->format('Y-m-d'),
        ]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(string)$row['crop_key']] = $row;
        }
        return $result;
    }

    private function preservationInflows(
        int $householdId,
        DateTimeImmutable $asOf,
        DateTimeImmutable $horizonEnd
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT output_inventory_item_id, SUM(COALESCE(yield_quantity, 0)) AS quantity
             FROM preservation_batches
             WHERE household_id = ?
               AND output_inventory_item_id IS NOT NULL
               AND status IN ("planned","prepared","processed","cooling","labeled")
               AND DATE(COALESCE(completed_at, started_at, created_at)) <= ?
             GROUP BY output_inventory_item_id'
        );
        $statement->execute([
            $householdId,
            $horizonEnd->format('Y-m-d'),
        ]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(int)$row['output_inventory_item_id']] = (float)$row['quantity'];
        }
        return $result;
    }

    private function insertProjection(
        int $snapshotId,
        int $householdId,
        array $item,
        float $dailyDepletion,
        float $planned,
        float $projectedConsumption,
        float $harvestQuantity,
        float $preservationQuantity,
        float $ending,
        ?float $daysOnHand,
        ?string $shortageDate,
        ?float $ratio,
        int $eventCount,
        string $confidence
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO forecast_item_projections
             (snapshot_id, household_id, inventory_item_id, item_name_snapshot,
              category_name_snapshot, unit, current_quantity, daily_depletion_rate,
              planned_demand_quantity, projected_consumption_quantity,
              projected_harvest_quantity, projected_preservation_quantity,
              projected_ending_quantity, days_on_hand, shortage_date,
              production_ratio_percent, source_event_count, confidence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $snapshotId,
            $householdId,
            (int)$item['id'],
            (string)$item['name'],
            $item['category_name'] !== null ? (string)$item['category_name'] : null,
            (string)$item['unit'],
            $this->quantity((float)$item['current_quantity']),
            $this->rate($dailyDepletion),
            $this->quantity($planned),
            $this->quantity($projectedConsumption),
            $this->quantity($harvestQuantity),
            $this->quantity($preservationQuantity),
            $this->quantity($ending),
            $daysOnHand !== null ? round($daysOnHand, 2) : null,
            $shortageDate,
            $ratio !== null ? $this->score($ratio) : null,
            $eventCount,
            $confidence,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function generateItemRecommendations(
        int $householdId,
        int $snapshotId,
        DateTimeImmutable $asOf,
        DateTimeImmutable $horizonEnd,
        array $settings,
        array $detail
    ): void {
        $item = $detail['item'];
        $itemId = (int)$item['id'];
        $projectionId = (int)$detail['projection_id'];
        $name = (string)$item['name'];
        $unit = (string)$item['unit'];
        $current = (float)$item['current_quantity'];
        $daily = (float)$detail['daily_depletion'];
        $ending = (float)$detail['ending'];
        $daysOnHand = $detail['days_on_hand'];
        $shortageDate = $detail['shortage_date'];
        $bufferDays = (int)$settings['target_buffer_days'];

        if ($ending < 0 || ($daysOnHand !== null && $daysOnHand < $bufferDays)) {
            $targetStock = $item['target_stock_level'] !== null
                ? (float)$item['target_stock_level']
                : max(
                    $item['reorder_level'] !== null ? (float)$item['reorder_level'] * 2 : 0,
                    $daily * $bufferDays
                );
            $recommended = max(
                0,
                $targetStock - $current,
                ((float)$detail['projected_consumption'] + ($daily * $bufferDays))
                    - $current
                    - (float)$detail['harvest_quantity']
                    - (float)$detail['preservation_quantity']
            );
            $dueOn = $shortageDate ?? $asOf->modify('+7 days')->format('Y-m-d');
            $priority = $this->priorityForDate($asOf, $dueOn);
            $this->createRecommendation(
                $householdId,
                $snapshotId,
                $projectionId,
                'restock',
                'Restock ' . $name,
                sprintf(
                    '%s is projected to end the %d-day horizon at %s %s with %s days currently on hand.',
                    $name,
                    (int)$settings['horizon_days'],
                    number_format($ending, 2),
                    $unit,
                    $daysOnHand !== null ? number_format($daysOnHand, 1) : 'no measured depletion rate'
                ),
                sprintf(
                    'Review the projection, then acquire or produce approximately %s %s to restore the configured buffer.',
                    number_format($recommended, 2),
                    $unit
                ),
                $recommended,
                $unit,
                $priority,
                $dueOn,
                'restock|' . $itemId
            );
        }

        if ($item['best_use_date'] !== null) {
            $bestUse = new DateTimeImmutable((string)$item['best_use_date']);
            if ($bestUse >= $asOf && $bestUse <= $asOf->modify('+14 days') && $current > 0) {
                $this->createRecommendation(
                    $householdId,
                    $snapshotId,
                    $projectionId,
                    'use_first',
                    'Use ' . $name . ' first',
                    sprintf('%s has a best-use date of %s and %s %s remains in active inventory.', $name, $bestUse->format('Y-m-d'), number_format($current, 2), $unit),
                    'Move this item into the next meal, preservation batch, donation plan, or rotation task before the date passes.',
                    $current,
                    $unit,
                    'high',
                    $bestUse->format('Y-m-d'),
                    'use-first|' . $itemId
                );
            }
        }

        if (is_array($detail['upcoming']) && (float)$detail['harvest_quantity'] > max(0.01, (float)$detail['projected_consumption'] * 0.25)) {
            $upcoming = $detail['upcoming'];
            $this->createRecommendation(
                $householdId,
                $snapshotId,
                $projectionId,
                'preserve',
                'Plan preservation for ' . $name,
                sprintf(
                    '%d planting(s) are expected between %s and %s, with an estimated %s %s based on household harvest history.',
                    (int)$upcoming['planting_count'],
                    (string)$upcoming['starts_on'],
                    (string)$upcoming['ends_on'],
                    number_format((float)$detail['harvest_quantity'], 2),
                    $unit
                ),
                'Reserve containers, freezer space, dehydrator time, or another appropriate preservation workflow before the harvest window.',
                (float)$detail['harvest_quantity'],
                $unit,
                'medium',
                (string)$upcoming['starts_on'],
                'preserve|' . $itemId
            );
        }

        if ((int)$item['depletion_event_count'] === 0 && $current > 0) {
            $this->createRecommendation(
                $householdId,
                $snapshotId,
                $projectionId,
                'review_data',
                'Review usage data for ' . $name,
                'No depletion events were recorded during the configured history window, so days-on-hand cannot be estimated.',
                'Confirm the current quantity and record recipe use, consumption, spoilage, or adjustment events to improve future forecasts.',
                null,
                null,
                'low',
                $horizonEnd->format('Y-m-d'),
                'review-data|' . $itemId
            );
        }
    }

    private function generateSeasonalEntries(
        int $householdId,
        int $snapshotId,
        DateTimeImmutable $asOf,
        DateTimeImmutable $horizonEnd,
        array $itemDetails
    ): void {
        $plantingQuery = $this->pdo->prepare(
            'SELECT p.id, p.crop_name, p.expected_harvest_start,
                    COALESCE(p.expected_harvest_end, p.expected_harvest_start) AS expected_harvest_end
             FROM plantings p
             JOIN garden_zones z ON z.id = p.garden_zone_id
             WHERE z.household_id = ?
               AND p.growth_stage NOT IN ("completed","failed")
               AND p.expected_harvest_start BETWEEN ? AND ?
             ORDER BY p.expected_harvest_start, p.id'
        );
        $plantingQuery->execute([
            $householdId,
            $asOf->format('Y-m-d'),
            $horizonEnd->format('Y-m-d'),
        ]);
        foreach ($plantingQuery->fetchAll() as $planting) {
            $this->insertSeasonalEntry([
                'household_id' => $householdId,
                'snapshot_id' => $snapshotId,
                'entry_type' => 'harvest',
                'generation_key' => hash('sha256', 'phase8-harvest|' . $snapshotId . '|' . $planting['id']),
                'title' => 'Harvest ' . $planting['crop_name'],
                'crop_or_item' => $planting['crop_name'],
                'starts_on' => $planting['expected_harvest_start'],
                'ends_on' => $planting['expected_harvest_end'],
                'expected_quantity' => null,
                'unit' => null,
                'source_type' => 'planting',
                'source_id' => $planting['id'],
                'notes' => 'Generated from the current planting harvest window.',
            ]);
        }

        $batchQuery = $this->pdo->prepare(
            'SELECT id, name, COALESCE(DATE(started_at), DATE(created_at)) AS starts_on,
                    yield_quantity, yield_unit
             FROM preservation_batches
             WHERE household_id = ?
               AND status IN ("planned","prepared")
               AND COALESCE(DATE(started_at), DATE(created_at)) <= ?
             ORDER BY COALESCE(started_at, created_at), id'
        );
        $batchQuery->execute([$householdId, $horizonEnd->format('Y-m-d')]);
        foreach ($batchQuery->fetchAll() as $batch) {
            $this->insertSeasonalEntry([
                'household_id' => $householdId,
                'snapshot_id' => $snapshotId,
                'entry_type' => 'preserve',
                'generation_key' => hash('sha256', 'phase8-preservation|' . $snapshotId . '|' . $batch['id']),
                'title' => 'Complete ' . $batch['name'],
                'crop_or_item' => $batch['name'],
                'starts_on' => $batch['starts_on'],
                'ends_on' => null,
                'expected_quantity' => $batch['yield_quantity'],
                'unit' => $batch['yield_unit'],
                'source_type' => 'preservation_batch',
                'source_id' => $batch['id'],
                'notes' => 'Generated from an active preservation batch.',
            ]);
        }

        foreach ($itemDetails as $itemId => $detail) {
            $item = $detail['item'];
            if ($detail['shortage_date'] !== null) {
                $this->insertSeasonalEntry([
                    'household_id' => $householdId,
                    'snapshot_id' => $snapshotId,
                    'entry_type' => 'purchase',
                    'generation_key' => hash('sha256', 'phase8-purchase|' . $snapshotId . '|' . $itemId),
                    'title' => 'Replenish ' . $item['name'],
                    'crop_or_item' => $item['name'],
                    'starts_on' => $detail['shortage_date'],
                    'ends_on' => null,
                    'expected_quantity' => max(0, -(float)$detail['ending']),
                    'unit' => $item['unit'],
                    'source_type' => 'inventory_item',
                    'source_id' => $itemId,
                    'notes' => 'Generated from a projected shortage.',
                ]);
            }
            if ($item['best_use_date'] !== null) {
                $bestUse = new DateTimeImmutable((string)$item['best_use_date']);
                if ($bestUse >= $asOf && $bestUse <= $horizonEnd) {
                    $this->insertSeasonalEntry([
                        'household_id' => $householdId,
                        'snapshot_id' => $snapshotId,
                        'entry_type' => 'rotate',
                        'generation_key' => hash('sha256', 'phase8-rotate|' . $snapshotId . '|' . $itemId),
                        'title' => 'Rotate ' . $item['name'],
                        'crop_or_item' => $item['name'],
                        'starts_on' => $bestUse->format('Y-m-d'),
                        'ends_on' => null,
                        'expected_quantity' => (float)$item['current_quantity'],
                        'unit' => $item['unit'],
                        'source_type' => 'inventory_item',
                        'source_id' => $itemId,
                        'notes' => 'Generated from the inventory best-use date.',
                    ]);
                }
            }
        }
    }

    private function createRecommendation(
        int $householdId,
        int $snapshotId,
        int $projectionId,
        string $type,
        string $title,
        string $rationale,
        string $action,
        ?float $quantity,
        ?string $unit,
        string $priority,
        ?string $dueOn,
        string $keySuffix
    ): void {
        $generationKey = hash('sha256', 'phase8-recommendation|' . $snapshotId . '|' . $keySuffix);
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO forecast_recommendations
             (household_id, snapshot_id, projection_id, recommendation_type,
              generation_key, title, rationale, recommended_action,
              recommended_quantity, unit, priority, due_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $householdId,
            $snapshotId,
            $projectionId,
            $type,
            $generationKey,
            $title,
            $rationale,
            $action,
            $quantity !== null ? $this->quantity($quantity) : null,
            $unit,
            $priority,
            $dueOn,
        ]);
    }

    private function insertSeasonalEntry(array $entry): void
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO seasonal_plan_entries
             (household_id, snapshot_id, entry_type, generation_key, title, crop_or_item,
              starts_on, ends_on, expected_quantity, unit, source_type, source_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $entry['household_id'],
            $entry['snapshot_id'],
            $entry['entry_type'],
            $entry['generation_key'],
            $entry['title'],
            $entry['crop_or_item'],
            $entry['starts_on'],
            $entry['ends_on'],
            $entry['expected_quantity'] !== null ? $this->quantity((float)$entry['expected_quantity']) : null,
            $entry['unit'],
            $entry['source_type'],
            $entry['source_id'],
            $entry['notes'],
        ]);
    }

    private function insertMetric(
        int $snapshotId,
        int $householdId,
        string $key,
        float|int $numerator,
        float|int $denominator,
        float $score,
        string $explanation
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO self_sufficiency_metrics
             (snapshot_id, household_id, metric_key, numerator, denominator, score_percent, explanation)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $snapshotId,
            $householdId,
            $key,
            $numerator,
            $denominator,
            $this->score($score),
            $explanation,
        ]);
    }

    private function transitionRecommendation(
        int $householdId,
        int $memberId,
        int $recommendationId,
        string $toStatus
    ): void {
        $this->assertActiveMember($householdId, $memberId);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT * FROM forecast_recommendations
                 WHERE id = ? AND household_id = ? FOR UPDATE'
            );
            $query->execute([$recommendationId, $householdId]);
            $recommendation = $query->fetch();
            if (!is_array($recommendation)) {
                throw new InvalidArgumentException('Forecast recommendation was not found.');
            }
            $from = (string)$recommendation['status'];
            $allowed = $toStatus === 'dismissed'
                ? ['pending']
                : ['pending', 'accepted'];
            if (!in_array($from, $allowed, true)) {
                if ($from === $toStatus) {
                    $this->pdo->commit();
                    return;
                }
                throw new InvalidArgumentException('That recommendation transition is not allowed.');
            }
            $update = $this->pdo->prepare(
                'UPDATE forecast_recommendations
                 SET status = ?, acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = ?'
            );
            $update->execute([$toStatus, $memberId, $recommendationId, $householdId, $from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Recommendation changed before it could be updated.');
            }
            $this->recordEvent(
                $householdId,
                (int)$recommendation['snapshot_id'],
                $recommendationId,
                null,
                $memberId,
                'recommendation_' . $toStatus,
                $from,
                $toStatus,
                null
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function recordEvent(
        int $householdId,
        ?int $snapshotId,
        ?int $recommendationId,
        ?int $seasonalEntryId,
        ?int $memberId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $notes
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO forecast_lifecycle_events
             (household_id, snapshot_id, recommendation_id, seasonal_entry_id,
              member_id, event_type, from_status, to_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $householdId,
            $snapshotId,
            $recommendationId,
            $seasonalEntryId,
            $memberId,
            $eventType,
            $fromStatus,
            $toStatus,
            $notes,
        ]);
    }

    private function sourceWatermark(int $householdId): string
    {
        $sources = [
            'inventory' => [
                'SELECT CONCAT(COALESCE(MAX(updated_at), ""), "|", COUNT(*))
                 FROM inventory_items WHERE household_id = ?',
                [$householdId],
            ],
            'ledger' => [
                'SELECT CONCAT(COALESCE(MAX(occurred_at), ""), "|", COUNT(*))
                 FROM food_ledger_events WHERE household_id = ?',
                [$householdId],
            ],
            'harvest' => [
                'SELECT CONCAT(COALESCE(MAX(h.harvested_at), ""), "|", COUNT(*))
                 FROM harvests h
                 JOIN plantings p ON p.id = h.planting_id
                 JOIN garden_zones z ON z.id = p.garden_zone_id
                 WHERE z.household_id = ?',
                [$householdId],
            ],
            'planting' => [
                'SELECT CONCAT(COALESCE(MAX(p.expected_harvest_end), ""), "|", COUNT(*), "|", COALESCE(MAX(p.id), 0))
                 FROM plantings p
                 JOIN garden_zones z ON z.id = p.garden_zone_id
                 WHERE z.household_id = ?',
                [$householdId],
            ],
            'preservation' => [
                'SELECT CONCAT(COALESCE(MAX(updated_at), ""), "|", COUNT(*))
                 FROM preservation_batches WHERE household_id = ?',
                [$householdId],
            ],
            'meals' => [
                'SELECT CONCAT(COALESCE(MAX(mpi.meal_date), ""), "|", COUNT(*), "|", COALESCE(MAX(mpi.id), 0))
                 FROM meal_plan_items mpi
                 JOIN meal_plans mp ON mp.id = mpi.meal_plan_id
                 WHERE mp.household_id = ?',
                [$householdId],
            ],
        ];

        $parts = [];
        foreach ($sources as $name => [$sql, $params]) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $parts[] = $name . ':' . (string)$statement->fetchColumn();
        }
        return hash('sha256', implode('|', $parts));
    }

    private function seasonalReadinessScore(array $upcomingHarvests, int $horizonDays): float
    {
        $monthSlots = max(1, min(12, (int)ceil($horizonDays / 30)));
        $months = [];
        foreach ($upcomingHarvests as $row) {
            if (!empty($row['starts_on'])) {
                $months[(new DateTimeImmutable((string)$row['starts_on']))->format('Y-m')] = true;
            }
        }
        return min(100.0, (count($months) / $monthSlots) * 100);
    }

    private function snapshotResult(array $snapshot, bool $reused): array
    {
        return [
            'snapshot_id' => (int)$snapshot['id'],
            'reused' => $reused,
            'coverage_score' => (float)($snapshot['inventory_coverage_score'] ?? 0),
            'self_sufficiency_score' => (float)($snapshot['self_sufficiency_score'] ?? 0),
            'seasonal_readiness_score' => (float)($snapshot['seasonal_readiness_score'] ?? 0),
            'resilience_score' => (float)($snapshot['resilience_score'] ?? 0),
            'shortages' => (int)($snapshot['projected_shortage_count'] ?? 0),
            'harvests' => (int)($snapshot['projected_harvest_count'] ?? 0),
            'recommendations' => (int)($snapshot['recommendation_count'] ?? 0),
        ];
    }

    private function assertActiveMember(int $householdId, int $memberId): void
    {
        if ($householdId <= 0 || $memberId <= 0) {
            throw new InvalidArgumentException('Household and member are required.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM household_members
             WHERE id = ? AND household_id = ? AND status = "active"'
        );
        $statement->execute([$memberId, $householdId]);
        if ($statement->fetchColumn() === false) {
            throw new InvalidArgumentException('The selected household member is not active in this household.');
        }
    }

    private function date(string $value, string $label): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . ' must be a valid date.');
        }
        return $date;
    }

    private function text(mixed $value, int $max, string $label): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        if (strlen($text) > $max) {
            throw new InvalidArgumentException($label . ' is too long.');
        }
        return $text;
    }

    private function boundedInt(mixed $value, int $min, int $max, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException($label . ' must be a whole number.');
        }
        $number = (int)$value;
        if ($number < $min || $number > $max) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d.', $label, $min, $max));
        }
        return $number;
    }

    private function boundedFloat(mixed $value, float $min, float $max, string $label): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($label . ' must be numeric.');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < $min || $number > $max) {
            throw new InvalidArgumentException(sprintf('%s must be between %s and %s.', $label, $min, $max));
        }
        return $number;
    }

    private function nullableFloat(mixed $value, float $min, float $max, string $label): ?float
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->boundedFloat($value, $min, $max, $label);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) {
            throw new InvalidArgumentException('Selected member is invalid.');
        }
        return (int)$value;
    }

    private function choice(mixed $value, array $allowed, string $label): string
    {
        $value = trim((string)$value);
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
        return $value;
    }

    private function normalKey(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function priorityForDate(DateTimeImmutable $asOf, string $dueOn): string
    {
        $days = (int)$asOf->diff(new DateTimeImmutable($dueOn))->format('%r%a');
        return match (true) {
            $days <= 3 => 'critical',
            $days <= 14 => 'high',
            $days <= 30 => 'medium',
            default => 'low',
        };
    }

    private function score(float $value): float
    {
        return round(max(0, min(100, $value)), 2);
    }

    private function quantity(float $value): float
    {
        return round($value, 4);
    }

    private function rate(float $value): float
    {
        return round(max(0, $value), 6);
    }
}
