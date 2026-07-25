<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PlanningAutomationService
{
    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    private const CADENCES = ['daily', 'weekly', 'monthly'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createManualTask(int $householdId, int $memberId, array $data): int
    {
        $title = $this->text($data['title'] ?? '', 'Task title', 180, true);
        $description = $this->text($data['description'] ?? '', 'Description', 5000);
        $assignedMemberId = $this->nullableId($data['assigned_member_id'] ?? null);
        $dueAt = $this->dateTime($data['due_at'] ?? null, 'Due date and time');
        $priority = $this->priority($data['priority'] ?? 'medium');
        $estimatedMinutes = $this->nullableInteger($data['estimated_minutes'] ?? null, 'Estimated minutes', 1, 1440);
        $actionKey = $this->actionKey($data['action_key'] ?? null);
        $generationKey = hash('sha256', 'manual|' . $householdId . '|' . $actionKey);

        try {
            $this->pdo->beginTransaction();
            $this->assertMember($householdId, $memberId);
            if ($assignedMemberId !== null) {
                $this->assertMember($householdId, $assignedMemberId);
            }

            $existing = $this->pdo->prepare(
                'SELECT household_task_id FROM task_automation_metadata
                 WHERE household_id = ? AND generation_key = ? LIMIT 1'
            );
            $existing->execute([$householdId, $generationKey]);
            $existingId = (int)$existing->fetchColumn();
            if ($existingId > 0) {
                $this->pdo->commit();
                return $existingId;
            }

            $taskId = $this->insertTask(
                $householdId,
                $assignedMemberId,
                $title,
                $description,
                $dueAt,
                $priority,
                'manual',
                null
            );
            $this->insertMetadata(
                $householdId,
                $taskId,
                null,
                null,
                'manual',
                null,
                $generationKey,
                $estimatedMinutes
            );
            $this->taskEvent($householdId, $taskId, $memberId, 'created', null, 'planned', 'Manual household task created.');
            $this->activity($householdId, $memberId, 'task_created', 'household_task', $taskId, $title . ' was added to the household plan.');
            $this->pdo->commit();
            return $taskId;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function createRecurringTemplate(int $householdId, int $memberId, array $data): int
    {
        $title = $this->text($data['title'] ?? '', 'Template title', 180, true);
        $description = $this->text($data['description'] ?? '', 'Description', 5000);
        $assignedMemberId = $this->nullableId($data['assigned_member_id'] ?? null);
        $cadence = (string)($data['cadence'] ?? 'weekly');
        $startsOn = $this->date($data['starts_on'] ?? '', 'Start date', true);
        $dueTime = $this->time($data['due_time'] ?? '09:00');
        $priority = $this->priority($data['priority'] ?? 'medium');
        $estimatedMinutes = $this->nullableInteger($data['estimated_minutes'] ?? null, 'Estimated minutes', 1, 1440);

        if (!in_array($cadence, self::CADENCES, true)) {
            throw new InvalidArgumentException('Choose a valid recurrence cadence.');
        }

        $this->assertMember($householdId, $memberId);
        if ($assignedMemberId !== null) {
            $this->assertMember($householdId, $assignedMemberId);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO recurring_task_templates
             (household_id, assigned_member_id, title, description, cadence, starts_on,
              due_time, priority, estimated_minutes, enabled, created_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $statement->execute([
            $householdId,
            $assignedMemberId,
            $title,
            $description,
            $cadence,
            $startsOn,
            $dueTime,
            $priority,
            $estimatedMinutes,
            $memberId,
        ]);
        $templateId = (int)$this->pdo->lastInsertId();
        $this->activity($householdId, $memberId, 'recurring_task_template_created', 'recurring_task_template', $templateId, $title . ' recurrence was created.');
        return $templateId;
    }

    public function toggleRecurringTemplate(int $householdId, int $memberId, int $templateId): void
    {
        if ($templateId < 1) {
            throw new InvalidArgumentException('Choose a recurring task template.');
        }
        $this->assertMember($householdId, $memberId);
        $statement = $this->pdo->prepare(
            'UPDATE recurring_task_templates SET enabled = IF(enabled = 1, 0, 1)
             WHERE id = ? AND household_id = ?'
        );
        $statement->execute([$templateId, $householdId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The recurring task template could not be updated.');
        }
        $this->activity($householdId, $memberId, 'recurring_task_template_toggled', 'recurring_task_template', $templateId, 'Recurring task template status changed.');
    }

    /** @return array{cycle_id:int, tasks:int, suggestions:int, reused:bool} */
    public function runPlanningCycle(int $householdId, int $memberId, string $planDate, string $runKey): array
    {
        $planDate = $this->date($planDate, 'Plan date', true);
        $runKey = $this->actionKey($runKey);

        try {
            $this->pdo->beginTransaction();
            $this->assertMember($householdId, $memberId);

            $existing = $this->pdo->prepare(
                'SELECT id, generated_task_count, generated_suggestion_count, status
                 FROM planning_cycles WHERE household_id = ? AND plan_date = ? FOR UPDATE'
            );
            $existing->execute([$householdId, $planDate]);
            $cycle = $existing->fetch();
            if (is_array($cycle)) {
                $this->pdo->commit();
                return [
                    'cycle_id' => (int)$cycle['id'],
                    'tasks' => (int)$cycle['generated_task_count'],
                    'suggestions' => (int)$cycle['generated_suggestion_count'],
                    'reused' => true,
                ];
            }

            $cycleInsert = $this->pdo->prepare(
                "INSERT INTO planning_cycles
                 (household_id, plan_date, run_key, initiated_by_member_id, status)
                 VALUES (?, ?, ?, ?, 'running')"
            );
            $cycleInsert->execute([$householdId, $planDate, $runKey, $memberId]);
            $cycleId = (int)$this->pdo->lastInsertId();

            $taskCount = 0;
            $suggestionCount = 0;
            $taskCount += $this->generateRecurringTasks($householdId, $memberId, $cycleId, $planDate);
            [$lowStockTasks, $lowStockSuggestions] = $this->generateLowStockWork($householdId, $memberId, $cycleId, $planDate);
            $taskCount += $lowStockTasks;
            $suggestionCount += $lowStockSuggestions;
            $taskCount += $this->generateMealTasks($householdId, $memberId, $cycleId, $planDate);
            $taskCount += $this->generateHarvestTasks($householdId, $memberId, $cycleId, $planDate);
            $taskCount += $this->generatePreservationTasks($householdId, $memberId, $cycleId, $planDate);
            $taskCount += $this->generatePreparedFoodTasks($householdId, $memberId, $cycleId, $planDate);

            $complete = $this->pdo->prepare(
                "UPDATE planning_cycles
                 SET generated_task_count = ?, generated_suggestion_count = ?, status = 'completed', completed_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = 'running'"
            );
            $complete->execute([$taskCount, $suggestionCount, $cycleId, $householdId]);
            if ($complete->rowCount() !== 1) {
                throw new RuntimeException('The planning cycle could not be finalized.');
            }
            $this->activity(
                $householdId,
                $memberId,
                'planning_cycle_completed',
                'planning_cycle',
                $cycleId,
                sprintf('Daily planning generated %d tasks and %d shopping suggestions.', $taskCount, $suggestionCount)
            );
            $this->pdo->commit();

            return ['cycle_id' => $cycleId, 'tasks' => $taskCount, 'suggestions' => $suggestionCount, 'reused' => false];
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function startTask(int $householdId, int $memberId, int $taskId, bool $canManage): void
    {
        $this->transitionTask($householdId, $memberId, $taskId, ['planned', 'ready'], 'in_progress', 'started', null, $canManage);
    }

    public function completeTask(int $householdId, int $memberId, int $taskId, ?string $notes, bool $canManage): void
    {
        $notes = $this->text($notes ?? '', 'Completion notes', 5000);
        try {
            $this->pdo->beginTransaction();
            $task = $this->lockTask($householdId, $taskId);
            $this->assertMember($householdId, $memberId);
            $this->assertTaskActor($task, $memberId, $canManage);
            $from = (string)$task['status'];
            if (!in_array($from, ['planned', 'ready', 'in_progress'], true)) {
                throw new RuntimeException('Only active tasks can be completed.');
            }
            $update = $this->pdo->prepare(
                "UPDATE household_tasks
                 SET status = 'completed', completed_at = UTC_TIMESTAMP(), completion_notes = ?
                 WHERE id = ? AND household_id = ? AND status = ?"
            );
            $update->execute([$notes, $taskId, $householdId, $from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The task changed while it was being completed.');
            }
            $this->taskEvent($householdId, $taskId, $memberId, 'completed', $from, 'completed', $notes);
            $this->activity($householdId, $memberId, 'task_completed', 'household_task', $taskId, (string)$task['title'] . ' was completed.');
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function snoozeTask(int $householdId, int $memberId, int $taskId, string $dueAt, bool $canManage): void
    {
        $newDueAt = $this->dateTime($dueAt, 'Snooze date and time', true);
        $now = new DateTimeImmutable('now');
        $due = new DateTimeImmutable($newDueAt);
        if ($due <= $now || $due > $now->modify('+1 year')) {
            throw new InvalidArgumentException('Choose a future snooze time within one year.');
        }

        try {
            $this->pdo->beginTransaction();
            $task = $this->lockTask($householdId, $taskId);
            $this->assertMember($householdId, $memberId);
            $this->assertTaskActor($task, $memberId, $canManage);
            $from = (string)$task['status'];
            if (!in_array($from, ['planned', 'ready', 'in_progress'], true)) {
                throw new RuntimeException('Only active tasks can be snoozed.');
            }
            $update = $this->pdo->prepare(
                "UPDATE household_tasks SET due_at = ?, status = 'ready'
                 WHERE id = ? AND household_id = ? AND status = ?"
            );
            $update->execute([$newDueAt, $taskId, $householdId, $from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The task changed while it was being snoozed.');
            }
            $this->pdo->prepare(
                'UPDATE task_automation_metadata SET snoozed_until = ?
                 WHERE household_task_id = ? AND household_id = ?'
            )->execute([$newDueAt, $taskId, $householdId]);
            $this->taskEvent($householdId, $taskId, $memberId, 'snoozed', $from, 'ready', 'Snoozed until ' . $newDueAt . '.');
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function cancelTask(int $householdId, int $memberId, int $taskId, ?string $notes): void
    {
        $notes = $this->text($notes ?? '', 'Cancellation notes', 5000);
        $this->transitionTask($householdId, $memberId, $taskId, ['planned', 'ready', 'in_progress'], 'cancelled', 'cancelled', $notes, true);
    }

    public function reopenTask(int $householdId, int $memberId, int $taskId): void
    {
        $this->transitionTask($householdId, $memberId, $taskId, ['completed', 'skipped', 'cancelled'], 'ready', 'reopened', 'Task reopened by a household manager.', true, true);
    }

    public function acceptShoppingSuggestion(int $householdId, int $memberId, int $suggestionId): int
    {
        if ($suggestionId < 1) {
            throw new InvalidArgumentException('Choose a shopping suggestion.');
        }

        try {
            $this->pdo->beginTransaction();
            $this->assertMember($householdId, $memberId);
            $query = $this->pdo->prepare(
                "SELECT ps.*, ii.name AS inventory_name, ii.current_quantity, ii.target_stock_level
                 FROM planning_suggestions ps
                 JOIN inventory_items ii ON ii.id = ps.source_id AND ii.household_id = ps.household_id
                 WHERE ps.id = ? AND ps.household_id = ? AND ps.suggestion_type = 'shopping' FOR UPDATE"
            );
            $query->execute([$suggestionId, $householdId]);
            $suggestion = $query->fetch();
            if (!is_array($suggestion) || $suggestion['status'] !== 'pending') {
                throw new RuntimeException('The shopping suggestion is unavailable or already handled.');
            }

            $listQuery = $this->pdo->prepare(
                "SELECT id FROM shopping_lists
                 WHERE household_id = ? AND status IN ('active','shopping')
                 ORDER BY name = 'Automated Restock' DESC, id DESC LIMIT 1 FOR UPDATE"
            );
            $listQuery->execute([$householdId]);
            $listId = (int)$listQuery->fetchColumn();
            if ($listId < 1) {
                $createList = $this->pdo->prepare(
                    "INSERT INTO shopping_lists (household_id, name, status)
                     VALUES (?, 'Automated Restock', 'active')"
                );
                $createList->execute([$householdId]);
                $listId = (int)$this->pdo->lastInsertId();
            }

            $existingItem = $this->pdo->prepare(
                'SELECT id FROM shopping_list_items
                 WHERE shopping_list_id = ? AND inventory_item_id = ? AND purchased = 0 LIMIT 1'
            );
            $existingItem->execute([$listId, $suggestion['source_id']]);
            $shoppingItemId = (int)$existingItem->fetchColumn();
            if ($shoppingItemId < 1) {
                $insert = $this->pdo->prepare(
                    "INSERT INTO shopping_list_items
                     (shopping_list_id, inventory_item_id, item_name, quantity, unit, priority, source_type, purchased)
                     VALUES (?, ?, ?, ?, ?, ?, 'low_stock', 0)"
                );
                $insert->execute([
                    $listId,
                    $suggestion['source_id'],
                    $suggestion['inventory_name'],
                    $suggestion['recommended_quantity'],
                    $suggestion['unit'],
                    $suggestion['priority'],
                ]);
                $shoppingItemId = (int)$this->pdo->lastInsertId();
            }

            $update = $this->pdo->prepare(
                "UPDATE planning_suggestions SET status = 'accepted', acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = 'pending'"
            );
            $update->execute([$memberId, $suggestionId, $householdId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The shopping suggestion changed while it was being accepted.');
            }
            $this->activity($householdId, $memberId, 'planning_suggestion_accepted', 'shopping_list_item', $shoppingItemId, (string)$suggestion['title'] . ' was added to a shopping list.');
            $this->pdo->commit();
            return $shoppingItemId;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function dismissSuggestion(int $householdId, int $memberId, int $suggestionId): void
    {
        $this->assertMember($householdId, $memberId);
        $statement = $this->pdo->prepare(
            "UPDATE planning_suggestions SET status = 'dismissed', acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
             WHERE id = ? AND household_id = ? AND status = 'pending'"
        );
        $statement->execute([$memberId, $suggestionId, $householdId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The suggestion is unavailable or already handled.');
        }
    }

    /** @return array<string,mixed> */
    public function dashboardData(int $householdId, int $memberId, bool $canManage): array
    {
        $this->assertMember($householdId, $memberId);
        $scope = $canManage ? '' : ' AND (ht.assigned_member_id IS NULL OR ht.assigned_member_id = :member_id)';
        $metrics = $this->pdo->prepare(
            "SELECT
                SUM(ht.status IN ('planned','ready','in_progress') AND ht.due_at < UTC_TIMESTAMP()) AS overdue,
                SUM(ht.status IN ('planned','ready','in_progress') AND DATE(ht.due_at) = UTC_DATE()) AS today,
                SUM(ht.status IN ('planned','ready','in_progress') AND ht.due_at >= UTC_TIMESTAMP() AND ht.due_at < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS next_seven,
                SUM(ht.status = 'completed' AND ht.completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS completed_week
             FROM household_tasks ht WHERE ht.household_id = :household_id" . $scope
        );
        $metricParams = ['household_id' => $householdId];
        if (!$canManage) {
            $metricParams['member_id'] = $memberId;
        }
        $metrics->execute($metricParams);

        $tasks = $this->pdo->prepare(
            "SELECT ht.*, hm.display_name AS assignee_name, tam.source_type, tam.source_id,
                    tam.estimated_minutes, tam.snoozed_until
             FROM household_tasks ht
             LEFT JOIN household_members hm ON hm.id = ht.assigned_member_id AND hm.household_id = ht.household_id
             LEFT JOIN task_automation_metadata tam ON tam.household_task_id = ht.id AND tam.household_id = ht.household_id
             WHERE ht.household_id = :household_id" . $scope . "
               AND ht.status IN ('planned','ready','in_progress')
             ORDER BY ht.due_at IS NULL, ht.due_at, FIELD(ht.priority,'critical','high','medium','low'), ht.id
             LIMIT 150"
        );
        $tasks->execute($metricParams);

        $suggestions = $this->pdo->prepare(
            "SELECT ps.*, ii.name AS inventory_name, ii.current_quantity
             FROM planning_suggestions ps
             LEFT JOIN inventory_items ii ON ii.id = ps.source_id AND ii.household_id = ps.household_id
             WHERE ps.household_id = ? AND ps.status = 'pending'
             ORDER BY FIELD(ps.priority,'critical','high','medium','low'), ps.created_at DESC LIMIT 50"
        );
        $suggestions->execute([$householdId]);

        $templates = $this->pdo->prepare(
            'SELECT rtt.*, hm.display_name AS assignee_name
             FROM recurring_task_templates rtt
             LEFT JOIN household_members hm ON hm.id = rtt.assigned_member_id AND hm.household_id = rtt.household_id
             WHERE rtt.household_id = ? ORDER BY rtt.enabled DESC, rtt.title'
        );
        $templates->execute([$householdId]);

        $cycles = $this->pdo->prepare(
            'SELECT pc.*, hm.display_name AS initiated_by
             FROM planning_cycles pc
             JOIN household_members hm ON hm.id = pc.initiated_by_member_id AND hm.household_id = pc.household_id
             WHERE pc.household_id = ? ORDER BY pc.plan_date DESC LIMIT 14'
        );
        $cycles->execute([$householdId]);

        $members = $this->pdo->prepare(
            "SELECT id, display_name, role FROM household_members
             WHERE household_id = ? AND status = 'active'
             ORDER BY FIELD(role,'owner','administrator','adult_member','youth_member','guest_helper'), display_name"
        );
        $members->execute([$householdId]);

        return [
            'metrics' => $metrics->fetch() ?: ['overdue' => 0, 'today' => 0, 'next_seven' => 0, 'completed_week' => 0],
            'tasks' => $tasks->fetchAll(),
            'suggestions' => $suggestions->fetchAll(),
            'templates' => $templates->fetchAll(),
            'cycles' => $cycles->fetchAll(),
            'members' => $members->fetchAll(),
        ];
    }

    private function generateRecurringTasks(int $householdId, int $memberId, int $cycleId, string $planDate): int
    {
        $query = $this->pdo->prepare(
            'SELECT * FROM recurring_task_templates
             WHERE household_id = ? AND enabled = 1 AND starts_on <= ? ORDER BY id FOR UPDATE'
        );
        $query->execute([$householdId, $planDate]);
        $count = 0;
        foreach ($query->fetchAll() as $template) {
            if (!$this->recurrenceApplies((string)$template['cadence'], (string)$template['starts_on'], $planDate)) {
                continue;
            }
            $dueAt = $planDate . ' ' . (string)$template['due_time'];
            $key = hash('sha256', implode('|', ['recurring', $householdId, $template['id'], $planDate]));
            if ($this->createGeneratedTask(
                $householdId,
                $memberId,
                $cycleId,
                (int)$template['id'],
                'recurring',
                (int)$template['id'],
                $key,
                (string)$template['title'],
                $template['description'] !== null ? (string)$template['description'] : null,
                $template['assigned_member_id'] !== null ? (int)$template['assigned_member_id'] : null,
                $dueAt,
                (string)$template['priority'],
                $template['estimated_minutes'] !== null ? (int)$template['estimated_minutes'] : null
            )) {
                $count++;
            }
        }
        return $count;
    }

    /** @return array{0:int,1:int} */
    private function generateLowStockWork(int $householdId, int $memberId, int $cycleId, string $planDate): array
    {
        $query = $this->pdo->prepare(
            "SELECT id, name, current_quantity, unit, reorder_level, target_stock_level
             FROM inventory_items
             WHERE household_id = ? AND status = 'active' AND reorder_level IS NOT NULL
               AND current_quantity <= reorder_level
             ORDER BY current_quantity / NULLIF(reorder_level, 0), name FOR UPDATE"
        );
        $query->execute([$householdId]);
        $taskCount = 0;
        $suggestionCount = 0;
        foreach ($query->fetchAll() as $item) {
            $current = (float)$item['current_quantity'];
            $reorder = (float)$item['reorder_level'];
            $target = $item['target_stock_level'] !== null ? (float)$item['target_stock_level'] : max($reorder * 2, $reorder + 1);
            $quantity = round(max(0.0001, $target - $current), 4);
            $priority = $current <= 0.00001 ? 'critical' : ($current <= ($reorder / 2) ? 'high' : 'medium');
            $sourceId = (int)$item['id'];
            $taskKey = hash('sha256', implode('|', ['low_stock_task', $householdId, $sourceId, $planDate]));
            if ($this->createGeneratedTask(
                $householdId,
                $memberId,
                $cycleId,
                null,
                'low_stock',
                $sourceId,
                $taskKey,
                'Restock ' . (string)$item['name'],
                sprintf('Inventory is %s %s; reorder level is %s %s.', $item['current_quantity'], $item['unit'], $item['reorder_level'], $item['unit']),
                null,
                $planDate . ' 18:00:00',
                $priority,
                15
            )) {
                $taskCount++;
            }

            $suggestionKey = hash('sha256', implode('|', ['low_stock_suggestion', $householdId, $sourceId, $planDate]));
            $insert = $this->pdo->prepare(
                "INSERT INTO planning_suggestions
                 (household_id, planning_cycle_id, suggestion_type, source_type, source_id,
                  generation_key, title, rationale, recommended_quantity, unit, priority, status)
                 VALUES (?, ?, 'shopping', 'low_stock', ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $insert->execute([
                $householdId,
                $cycleId,
                $sourceId,
                $suggestionKey,
                'Buy ' . (string)$item['name'],
                sprintf('Restore stock toward %s %s from the current %s %s.', $target, $item['unit'], $item['current_quantity'], $item['unit']),
                $quantity,
                $item['unit'],
                $priority,
            ]);
            $suggestionCount++;
        }
        return [$taskCount, $suggestionCount];
    }

    private function generateMealTasks(int $householdId, int $memberId, int $cycleId, string $planDate): int
    {
        $query = $this->pdo->prepare(
            "SELECT mpi.id, mpi.meal_type, mpi.planned_servings, mpi.notes, r.name AS recipe_name
             FROM meal_plan_items mpi
             JOIN meal_plans mp ON mp.id = mpi.meal_plan_id AND mp.household_id = ?
             LEFT JOIN recipes r ON r.id = mpi.recipe_id AND r.household_id = mp.household_id
             WHERE mpi.meal_date = ? AND mp.status IN ('draft','active') ORDER BY mpi.id FOR UPDATE"
        );
        $query->execute([$householdId, $planDate]);
        $times = ['breakfast' => '06:30:00', 'lunch' => '10:30:00', 'dinner' => '16:00:00', 'snack' => '13:30:00'];
        $count = 0;
        foreach ($query->fetchAll() as $meal) {
            $name = (string)($meal['recipe_name'] ?: ucfirst((string)$meal['meal_type']) . ' meal');
            $key = hash('sha256', implode('|', ['meal', $householdId, $meal['id'], $planDate]));
            if ($this->createGeneratedTask(
                $householdId,
                $memberId,
                $cycleId,
                null,
                'meal',
                (int)$meal['id'],
                $key,
                'Prepare ' . $name,
                sprintf('%s plan for %s servings.', ucfirst((string)$meal['meal_type']), $meal['planned_servings']),
                null,
                $planDate . ' ' . ($times[(string)$meal['meal_type']] ?? '12:00:00'),
                'high',
                45
            )) {
                $count++;
            }
        }
        return $count;
    }

    private function generateHarvestTasks(int $householdId, int $memberId, int $cycleId, string $planDate): int
    {
        $query = $this->pdo->prepare(
            "SELECT p.id, p.crop_name, p.variety, p.growth_stage, z.name AS zone_name
             FROM plantings p JOIN garden_zones z ON z.id = p.garden_zone_id
             WHERE z.household_id = ? AND z.active = 1
               AND p.expected_harvest_start IS NOT NULL AND p.expected_harvest_start <= ?
               AND (p.expected_harvest_end IS NULL OR p.expected_harvest_end >= ?)
               AND p.growth_stage NOT IN ('completed','failed')
             ORDER BY p.expected_harvest_start, p.id FOR UPDATE"
        );
        $query->execute([$householdId, $planDate, $planDate]);
        $count = 0;
        foreach ($query->fetchAll() as $planting) {
            $crop = (string)$planting['crop_name'] . ($planting['variety'] ? ' · ' . (string)$planting['variety'] : '');
            $key = hash('sha256', implode('|', ['harvest', $householdId, $planting['id'], $planDate]));
            if ($this->createGeneratedTask(
                $householdId,
                $memberId,
                $cycleId,
                null,
                'harvest',
                (int)$planting['id'],
                $key,
                'Check harvest readiness: ' . $crop,
                'Inspect ' . $crop . ' in ' . (string)$planting['zone_name'] . ' and record any harvest.',
                null,
                $planDate . ' 09:00:00',
                (string)$planting['growth_stage'] === 'harvest_ready' ? 'high' : 'medium',
                20
            )) {
                $count++;
            }
        }
        return $count;
    }

    private function generatePreservationTasks(int $householdId, int $memberId, int $cycleId, string $planDate): int
    {
        $query = $this->pdo->prepare(
            "SELECT id, name, method, status, best_use_date
             FROM preservation_batches
             WHERE household_id = ? AND status IN ('planned','prepared','processed','cooling','labeled')
             ORDER BY created_at, id FOR UPDATE"
        );
        $query->execute([$householdId]);
        $instructions = [
            'planned' => ['Prepare preservation batch: ', 'Review ingredients, equipment, and authoritative safety guidance before starting.', '09:30:00', 'medium'],
            'prepared' => ['Process preservation batch: ', 'Complete the selected preservation process and record the result.', '11:00:00', 'high'],
            'processed' => ['Check preservation batch: ', 'Verify the batch is ready for cooling, labeling, or the next recorded stage.', '15:00:00', 'high'],
            'cooling' => ['Label preservation batch: ', 'Inspect, label, date, and prepare the batch for storage.', '17:00:00', 'high'],
            'labeled' => ['Store preservation batch: ', 'Move the labeled batch into its recorded storage location.', '18:00:00', 'medium'],
        ];
        $count = 0;
        foreach ($query->fetchAll() as $batch) {
            [$prefix, $description, $time, $priority] = $instructions[(string)$batch['status']];
            $key = hash('sha256', implode('|', ['preservation', $householdId, $batch['id'], $batch['status'], $planDate]));
            if ($this->createGeneratedTask(
                $householdId,
                $memberId,
                $cycleId,
                null,
                'preservation',
                (int)$batch['id'],
                $key,
                $prefix . (string)$batch['name'],
                $description,
                null,
                $planDate . ' ' . $time,
                $priority,
                30
            )) {
                $count++;
            }
        }
        return $count;
    }

    private function generatePreparedFoodTasks(int $householdId, int $memberId, int $cycleId, string $planDate): int
    {
        $limit = (new DateTimeImmutable($planDate))->modify('+2 days')->format('Y-m-d');
        $query = $this->pdo->prepare(
            "SELECT id, name, servings_remaining, use_by_date, storage_method, status
             FROM prepared_food_batches
             WHERE household_id = ? AND servings_remaining > 0 AND use_by_date IS NOT NULL
               AND use_by_date <= ? AND status IN ('active','frozen')
             ORDER BY use_by_date, id FOR UPDATE"
        );
        $query->execute([$householdId, $limit]);
        $count = 0;
        foreach ($query->fetchAll() as $batch) {
            $priority = (string)$batch['use_by_date'] <= $planDate ? 'critical' : 'high';
            $key = hash('sha256', implode('|', ['prepared_food', $householdId, $batch['id'], $planDate]));
            if ($this->createGeneratedTask(
                $householdId,
                $memberId,
                $cycleId,
                null,
                'prepared_food',
                (int)$batch['id'],
                $key,
                'Use or review ' . (string)$batch['name'],
                sprintf('%s servings remain with a use-by date of %s.', $batch['servings_remaining'], $batch['use_by_date']),
                null,
                $planDate . ' 17:30:00',
                $priority,
                10
            )) {
                $count++;
            }
        }
        return $count;
    }

    private function createGeneratedTask(
        int $householdId,
        int $actorMemberId,
        int $cycleId,
        ?int $templateId,
        string $sourceType,
        ?int $sourceId,
        string $generationKey,
        string $title,
        ?string $description,
        ?int $assignedMemberId,
        string $dueAt,
        string $priority,
        ?int $estimatedMinutes
    ): bool {
        $existing = $this->pdo->prepare(
            'SELECT household_task_id FROM task_automation_metadata
             WHERE household_id = ? AND generation_key = ? LIMIT 1'
        );
        $existing->execute([$householdId, $generationKey]);
        if ($existing->fetchColumn()) {
            return false;
        }
        if ($assignedMemberId !== null) {
            $this->assertMember($householdId, $assignedMemberId);
        }
        $taskId = $this->insertTask($householdId, $assignedMemberId, $title, $description, $dueAt, $priority, $sourceType, $sourceId);
        $this->insertMetadata($householdId, $taskId, $cycleId, $templateId, $sourceType, $sourceId, $generationKey, $estimatedMinutes);
        $this->taskEvent($householdId, $taskId, $actorMemberId, 'generated', null, 'planned', 'Generated by the daily planning cycle.');
        return true;
    }

    private function insertTask(
        int $householdId,
        ?int $assignedMemberId,
        string $title,
        ?string $description,
        ?string $dueAt,
        string $priority,
        string $relatedType,
        ?int $relatedId
    ): int {
        $statement = $this->pdo->prepare(
            "INSERT INTO household_tasks
             (household_id, assigned_member_id, title, description, related_type, related_id,
              due_at, priority, status, verification_required)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'planned', 0)"
        );
        $statement->execute([$householdId, $assignedMemberId, $title, $description, $relatedType, $relatedId, $dueAt, $priority]);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertMetadata(
        int $householdId,
        int $taskId,
        ?int $cycleId,
        ?int $templateId,
        string $sourceType,
        ?int $sourceId,
        string $generationKey,
        ?int $estimatedMinutes
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO task_automation_metadata
             (household_id, household_task_id, planning_cycle_id, recurring_template_id,
              source_type, source_id, generation_key, estimated_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$householdId, $taskId, $cycleId, $templateId, $sourceType, $sourceId, $generationKey, $estimatedMinutes]);
    }

    private function transitionTask(
        int $householdId,
        int $memberId,
        int $taskId,
        array $allowedFrom,
        string $toStatus,
        string $eventType,
        ?string $notes,
        bool $canManage,
        bool $clearCompletion = false
    ): void {
        try {
            $this->pdo->beginTransaction();
            $task = $this->lockTask($householdId, $taskId);
            $this->assertMember($householdId, $memberId);
            $this->assertTaskActor($task, $memberId, $canManage);
            $from = (string)$task['status'];
            if (!in_array($from, $allowedFrom, true)) {
                throw new RuntimeException('The task is not in a state that allows this action.');
            }
            $sql = $clearCompletion
                ? 'UPDATE household_tasks SET status = ?, completed_at = NULL, completion_notes = NULL WHERE id = ? AND household_id = ? AND status = ?'
                : 'UPDATE household_tasks SET status = ? WHERE id = ? AND household_id = ? AND status = ?';
            $update = $this->pdo->prepare($sql);
            $update->execute([$toStatus, $taskId, $householdId, $from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The task changed while it was being updated.');
            }
            $this->taskEvent($householdId, $taskId, $memberId, $eventType, $from, $toStatus, $notes);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function lockTask(int $householdId, int $taskId): array
    {
        if ($taskId < 1) {
            throw new InvalidArgumentException('Choose a task.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, household_id, assigned_member_id, title, status, due_at
             FROM household_tasks WHERE id = ? AND household_id = ? FOR UPDATE'
        );
        $statement->execute([$taskId, $householdId]);
        $task = $statement->fetch();
        if (!is_array($task)) {
            throw new RuntimeException('The task was not found in this household.');
        }
        return $task;
    }

    private function assertTaskActor(array $task, int $memberId, bool $canManage): void
    {
        $assigned = $task['assigned_member_id'] !== null ? (int)$task['assigned_member_id'] : null;
        if (!$canManage && $assigned !== null && $assigned !== $memberId) {
            throw new RuntimeException('This task is assigned to another household member.');
        }
    }

    private function taskEvent(
        int $householdId,
        int $taskId,
        int $memberId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $notes
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO task_lifecycle_events
             (household_id, household_task_id, member_id, event_type, from_status, to_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$householdId, $taskId, $memberId, $eventType, $fromStatus, $toStatus, $notes]);
    }

    private function activity(int $householdId, int $memberId, string $eventKey, string $subjectType, int $subjectId, string $summary): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO activity_events
             (household_id, member_id, event_key, subject_type, subject_id, summary, visibility)
             VALUES (?, ?, ?, ?, ?, ?, 'household')"
        );
        $statement->execute([$householdId, $memberId, $eventKey, $subjectType, $subjectId, $summary]);
    }

    private function recurrenceApplies(string $cadence, string $startsOn, string $planDate): bool
    {
        $start = new DateTimeImmutable($startsOn);
        $plan = new DateTimeImmutable($planDate);
        if ($plan < $start) {
            return false;
        }
        return match ($cadence) {
            'daily' => true,
            'weekly' => $plan->format('N') === $start->format('N'),
            'monthly' => $plan->format('j') === $start->format('j')
                || ((int)$start->format('j') > (int)$plan->format('t') && $plan->format('j') === $plan->format('t')),
            default => false,
        };
    }

    private function assertMember(int $householdId, int $memberId): void
    {
        if ($householdId < 1 || $memberId < 1) {
            throw new InvalidArgumentException('A valid household member is required.');
        }
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM household_members
             WHERE id = ? AND household_id = ? AND status = 'active' LIMIT 1"
        );
        $statement->execute([$memberId, $householdId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('The selected household member is unavailable.');
        }
    }

    private function text(mixed $value, string $field, int $max, bool $required = false): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            if ($required) {
                throw new InvalidArgumentException($field . ' is required.');
            }
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException($field . ' exceeds its allowed length.');
        }
        return $value;
    }

    private function date(mixed $value, string $field, bool $required = false): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            if ($required) {
                throw new InvalidArgumentException($field . ' is required.');
            }
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($field . ' is invalid.');
        }
        return $value;
    }

    private function dateTime(mixed $value, string $field, bool $required = false): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            if ($required) {
                throw new InvalidArgumentException($field . ' is required.');
            }
            return null;
        }
        $normalized = str_replace('T', ' ', $value);
        if (strlen($normalized) === 16) {
            $normalized .= ':00';
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized);
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $normalized) {
            throw new InvalidArgumentException($field . ' is invalid.');
        }
        return $normalized;
    }

    private function time(mixed $value): string
    {
        $value = trim((string)$value);
        if (strlen($value) === 5) {
            $value .= ':00';
        }
        $parsed = DateTimeImmutable::createFromFormat('!H:i:s', $value);
        if (!$parsed || $parsed->format('H:i:s') !== $value) {
            throw new InvalidArgumentException('Due time is invalid.');
        }
        return $value;
    }

    private function priority(mixed $value): string
    {
        $value = (string)$value;
        if (!in_array($value, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('Choose a valid priority.');
        }
        return $value;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('A selected record is invalid.');
        }
        return (int)$id;
    }

    private function nullableInteger(mixed $value, string $field, int $min, int $max): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) {
            throw new InvalidArgumentException($field . ' is invalid.');
        }
        return (int)$number;
    }

    private function actionKey(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
            throw new InvalidArgumentException('The action key is invalid. Refresh and try again.');
        }
        return $value;
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
