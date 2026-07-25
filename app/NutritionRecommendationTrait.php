<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

trait NutritionRecommendationTrait
{
    private function generateMemberNutritionRecommendations(
        int $householdId,
        int $assessmentId,
        array $member,
        array $metrics,
        array $settings,
        string $dueOn
    ): int {
        $recommendations = [];
        $memberId = (int)$member['id'];
        $memberName = (string)$member['display_name'];

        if ((float)$metrics['completeness'] < (float)$settings['minimum_data_completeness_percent']) {
            $recommendations[] = [
                'type' => 'data_quality',
                'title' => 'Complete nutrition data for ' . $memberName,
                'rationale' => sprintf(
                    'Only %.1f%% of planned meals had usable recipe nutrition snapshots.',
                    (float)$metrics['completeness']
                ),
                'action' => 'Add ingredient nutrition profiles and recalculate the recipes used in this meal plan.',
                'priority' => 'high',
            ];
        }
        if ((int)$metrics['distinct_recipes'] < (int)$settings['minimum_recipe_variety']) {
            $recommendations[] = [
                'type' => 'variety',
                'title' => 'Increase recipe variety for ' . $memberName,
                'rationale' => sprintf(
                    'The plan includes %d distinct assessed recipes; the household target is %d.',
                    (int)$metrics['distinct_recipes'],
                    (int)$settings['minimum_recipe_variety']
                ),
                'action' => 'Add another household-approved recipe or rotate a different protein, vegetable, grain, or legume option.',
                'priority' => 'medium',
            ];
        }
        if ((int)$metrics['conflicts'] > 0) {
            $recommendations[] = [
                'type' => 'allergen_conflict',
                'title' => 'Review allergen conflicts for ' . $memberName,
                'rationale' => sprintf(
                    '%d planned recipe servings contain an ingredient tag that conflicts with this member’s active allergen or intolerance rules.',
                    (int)$metrics['conflicts']
                ),
                'action' => 'Review the flagged recipes and replace or verify ingredients before preparation or service.',
                'priority' => 'critical',
            ];
        }
        if ($metrics['protein_coverage'] !== null && (float)$metrics['protein_coverage'] < 75) {
            $recommendations[] = [
                'type' => 'protein_gap',
                'title' => 'Review protein coverage for ' . $memberName,
                'rationale' => sprintf(
                    'Tracked meals provide %.1f%% of this member’s optional planning target for the assessment window.',
                    (float)$metrics['protein_coverage']
                ),
                'action' => 'Review portions or add a household-approved protein source to planned meals.',
                'priority' => 'medium',
            ];
        }
        if ($metrics['fiber_coverage'] !== null && (float)$metrics['fiber_coverage'] < 75) {
            $recommendations[] = [
                'type' => 'fiber_gap',
                'title' => 'Review fiber coverage for ' . $memberName,
                'rationale' => sprintf(
                    'Tracked meals provide %.1f%% of this member’s optional planning target for the assessment window.',
                    (float)$metrics['fiber_coverage']
                ),
                'action' => 'Review fruit, vegetable, whole-grain, bean, seed, or other household-approved fiber options.',
                'priority' => 'medium',
            ];
        }
        if ($metrics['sodium_usage'] !== null && (float)$metrics['sodium_usage'] > 100) {
            $recommendations[] = [
                'type' => 'sodium_review',
                'title' => 'Review sodium planning for ' . $memberName,
                'rationale' => sprintf(
                    'Tracked meals use %.1f%% of this member’s optional planning limit for the assessment window.',
                    (float)$metrics['sodium_usage']
                ),
                'action' => 'Review recipe labels, prepared ingredients, portions, and lower-sodium substitutions where appropriate.',
                'priority' => 'high',
            ];
        }
        if ($metrics['sugar_usage'] !== null && (float)$metrics['sugar_usage'] > 100) {
            $recommendations[] = [
                'type' => 'added_sugar_review',
                'title' => 'Review added-sugar planning for ' . $memberName,
                'rationale' => sprintf(
                    'Tracked meals use %.1f%% of this member’s optional planning limit for the assessment window.',
                    (float)$metrics['sugar_usage']
                ),
                'action' => 'Review sweetened ingredients, beverages, snacks, and portions before finalizing the plan.',
                'priority' => 'medium',
            ];
        }

        $inserted = 0;
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO nutrition_recommendations
             (household_id, assessment_id, household_member_id, recommendation_type,
              generation_key, title, rationale, recommended_action, priority, due_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($recommendations as $recommendation) {
            $generationKey = hash(
                'sha256',
                implode('|', [
                    'phase10',
                    $householdId,
                    $assessmentId,
                    $memberId,
                    $recommendation['type'],
                ])
            );
            $statement->execute([
                $householdId,
                $assessmentId,
                $memberId,
                $recommendation['type'],
                $generationKey,
                $recommendation['title'],
                $recommendation['rationale'],
                $recommendation['action'],
                $recommendation['priority'],
                $dueOn,
            ]);
            $inserted += $statement->rowCount();
        }
        return $inserted;
    }

    public function acceptRecommendation(int $householdId, int $memberId, int $recommendationId): int
    {
        $this->assertActiveMember($householdId, $memberId);
        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $query = $this->pdo->prepare(
                'SELECT * FROM nutrition_recommendations
                 WHERE id = ? AND household_id = ? FOR UPDATE'
            );
            $query->execute([$recommendationId, $householdId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new InvalidArgumentException('Nutrition recommendation was not found.');
            }
            if ((string)$row['status'] === 'accepted' && $row['related_task_id'] !== null) {
                $this->pdo->commit();
                return (int)$row['related_task_id'];
            }
            if ((string)$row['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending nutrition recommendations can be accepted.');
            }

            $dueAt = $row['due_on'] !== null
                ? (string)$row['due_on'] . ' 09:00:00'
                : (new DateTimeImmutable('+7 days 09:00'))->format('Y-m-d H:i:s');
            $task = $this->pdo->prepare(
                'INSERT INTO household_tasks
                 (household_id, assigned_member_id, title, description, related_type, related_id,
                  due_at, priority, status)
                 VALUES (?, ?, ?, ?, "nutrition_recommendation", ?, ?, ?, "ready")'
            );
            $task->execute([
                $householdId,
                $row['household_member_id'] !== null ? (int)$row['household_member_id'] : null,
                (string)$row['title'],
                (string)$row['recommended_action'],
                $recommendationId,
                $dueAt,
                (string)$row['priority'],
            ]);
            $taskId = (int)$this->pdo->lastInsertId();
            $generationKey = hash('sha256', 'phase10-recommendation-task|' . $householdId . '|' . $recommendationId);
            $metadata = $this->pdo->prepare(
                'INSERT INTO task_automation_metadata
                 (household_id, household_task_id, planning_cycle_id, recurring_template_id,
                  source_type, source_id, generation_key)
                 VALUES (?, ?, NULL, NULL, "nutrition_recommendation", ?, ?)'
            );
            $metadata->execute([$householdId, $taskId, $recommendationId, $generationKey]);

            $update = $this->pdo->prepare(
                'UPDATE nutrition_recommendations
                 SET status = "accepted", related_task_id = ?, acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = "pending"'
            );
            $update->execute([$taskId, $memberId, $recommendationId, $householdId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Recommendation changed before it could be accepted.');
            }
            $this->recordNutritionEvent(
                $householdId,
                (int)$row['assessment_id'],
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
        $this->transitionNutritionRecommendation($householdId, $memberId, $recommendationId, 'dismissed');
    }

    public function completeRecommendation(int $householdId, int $memberId, int $recommendationId): void
    {
        $this->transitionNutritionRecommendation($householdId, $memberId, $recommendationId, 'completed');
    }

    private function transitionNutritionRecommendation(
        int $householdId,
        int $memberId,
        int $recommendationId,
        string $toStatus
    ): void {
        $this->assertActiveMember($householdId, $memberId);
        $toStatus = $this->choice($toStatus, ['dismissed', 'completed'], 'Recommendation status');
        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $query = $this->pdo->prepare(
                'SELECT * FROM nutrition_recommendations
                 WHERE id = ? AND household_id = ? FOR UPDATE'
            );
            $query->execute([$recommendationId, $householdId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new InvalidArgumentException('Nutrition recommendation was not found.');
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
                'UPDATE nutrition_recommendations
                 SET status = ?, acted_by_member_id = ?, acted_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND status = ?'
            );
            $update->execute([$toStatus, $memberId, $recommendationId, $householdId, $from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Recommendation changed before it could be updated.');
            }
            $this->recordNutritionEvent(
                $householdId,
                (int)$row['assessment_id'],
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