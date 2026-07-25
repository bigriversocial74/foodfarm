<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

trait CostWasteRecommendationTrait
{
    public function acceptRecommendation(int $householdId, int $memberId, int $recommendationId): int
    {
        $this->assertActiveMember($householdId, $memberId);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT * FROM finance_recommendations
                 WHERE id = ? AND household_id = ? FOR UPDATE'
            );
            $query->execute([$recommendationId, $householdId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new InvalidArgumentException('Finance recommendation was not found.');
            }
            if ($row['status'] === 'accepted' && $row['related_task_id'] !== null) {
                $this->pdo->commit();
                return (int)$row['related_task_id'];
            }
            if ($row['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending finance recommendations can be accepted.');
            }

            $dueAt = $row['due_on'] !== null
                ? (string)$row['due_on'] . ' 09:00:00'
                : (new \DateTimeImmutable('+7 days 09:00'))->format('Y-m-d H:i:s');
            $task = $this->pdo->prepare(
                'INSERT INTO household_tasks
                 (household_id, assigned_member_id, title, description, related_type, related_id,
                  due_at, priority, status)
                 VALUES (?, NULL, ?, ?, "finance_recommendation", ?, ?, ?, "ready")'
            );
            $task->execute([
                $householdId,
                (string)$row['title'],
                (string)$row['recommended_action'],
                $recommendationId,
                $dueAt,
                (string)$row['priority'],
            ]);
            $taskId = (int)$this->pdo->lastInsertId();
            $generationKey = hash('sha256', 'phase9-recommendation-task|' . $householdId . '|' . $recommendationId);
            $metadata = $this->pdo->prepare(
                'INSERT INTO task_automation_metadata
                 (household_id, household_task_id, planning_cycle_id, recurring_template_id,
                  source_type, source_id, generation_key)
                 VALUES (?, ?, NULL, NULL, "finance_recommendation", ?, ?)'
            );
            $metadata->execute([$householdId, $taskId, $recommendationId, $generationKey]);

            $update = $this->pdo->prepare(
                'UPDATE finance_recommendations
                 SET status = "accepted", related_task_id = ?, acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = "pending"'
            );
            $update->execute([$taskId, $memberId, $recommendationId, $householdId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Recommendation changed before it could be accepted.');
            }
            $this->recordEvent(
                $householdId,
                (int)$row['snapshot_id'],
                null,
                null,
                $recommendationId,
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
        $this->transitionFinanceRecommendation($householdId, $memberId, $recommendationId, 'dismissed');
    }

    public function completeRecommendation(int $householdId, int $memberId, int $recommendationId): void
    {
        $this->transitionFinanceRecommendation($householdId, $memberId, $recommendationId, 'completed');
    }

    private function transitionFinanceRecommendation(
        int $householdId,
        int $memberId,
        int $recommendationId,
        string $toStatus
    ): void {
        $this->assertActiveMember($householdId, $memberId);
        $toStatus = $this->choice($toStatus, ['dismissed', 'completed'], 'Recommendation status');
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT * FROM finance_recommendations
                 WHERE id = ? AND household_id = ? FOR UPDATE'
            );
            $query->execute([$recommendationId, $householdId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new InvalidArgumentException('Finance recommendation was not found.');
            }
            $from = (string)$row['status'];
            if ($from === $toStatus) {
                $this->pdo->commit();
                return;
            }
            $allowed = [
                'pending' => ['dismissed'],
                'accepted' => ['completed', 'dismissed'],
                'dismissed' => [],
                'completed' => [],
            ];
            if (!in_array($toStatus, $allowed[$from] ?? [], true)) {
                throw new InvalidArgumentException('That recommendation transition is not allowed.');
            }
            $update = $this->pdo->prepare(
                'UPDATE finance_recommendations
                 SET status = ?, acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = ?'
            );
            $update->execute([$toStatus, $memberId, $recommendationId, $householdId, $from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Recommendation changed before it could be updated.');
            }
            $this->recordEvent(
                $householdId,
                (int)$row['snapshot_id'],
                null,
                null,
                $recommendationId,
                $memberId,
                'recommendation_status_changed',
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
}