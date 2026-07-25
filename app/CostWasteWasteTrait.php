<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

trait CostWasteWasteTrait
{
    public function recordWaste(int $householdId, int $memberId, array $input): array
    {
        $this->assertActiveMember($householdId, $memberId);
        $inventoryItemId = $this->nullablePositiveInt($input['inventory_item_id'] ?? null);
        $preparedFoodBatchId = $this->nullablePositiveInt($input['prepared_food_batch_id'] ?? null);
        if (($inventoryItemId === null) === ($preparedFoodBatchId === null)) {
            throw new InvalidArgumentException('Choose either one inventory item or one prepared-food batch.');
        }
        $wasteType = $this->choice(
            $input['waste_type'] ?? '',
            ['spoiled', 'composted', 'discarded', 'overproduction', 'trim_loss', 'expired', 'damaged', 'other'],
            'Waste type'
        );
        $quantity = $this->decimal($input['quantity'] ?? null, 0.0001, 999999999, 'Waste quantity');
        $occurredOn = $this->date($input['occurred_on'] ?? date('Y-m-d'), 'Waste date')->format('Y-m-d');
        $reason = $this->nullableText($input['reason'] ?? null, 500, 'Waste reason');
        $actionKey = $this->actionKey($input['action_key'] ?? null);

        $this->pdo->beginTransaction();
        try {
            $this->lockHousehold($householdId);
            $existing = $this->pdo->prepare(
                'SELECT id, inventory_item_id, prepared_food_batch_id, waste_type, quantity,
                        occurred_on, estimated_value
                 FROM food_waste_events
                 WHERE household_id = ? AND action_key = ? FOR UPDATE'
            );
            $existing->execute([$householdId, $actionKey]);
            $existingRow = $existing->fetch();
            if (is_array($existingRow)) {
                $sameInventory = ($inventoryItemId === null && $existingRow['inventory_item_id'] === null)
                    || ($inventoryItemId !== null && (int)$existingRow['inventory_item_id'] === $inventoryItemId);
                $samePrepared = ($preparedFoodBatchId === null && $existingRow['prepared_food_batch_id'] === null)
                    || ($preparedFoodBatchId !== null
                        && (int)$existingRow['prepared_food_batch_id'] === $preparedFoodBatchId);
                if (
                    !$sameInventory
                    || !$samePrepared
                    || (string)$existingRow['waste_type'] !== $wasteType
                    || abs((float)$existingRow['quantity'] - $quantity) >= 0.000001
                    || (string)$existingRow['occurred_on'] !== $occurredOn
                ) {
                    throw new InvalidArgumentException(
                        'This waste action key is already bound to different waste details.'
                    );
                }
                $this->pdo->commit();
                return [
                    'waste_event_id' => (int)$existingRow['id'],
                    'estimated_value' => (float)$existingRow['estimated_value'],
                    'reused' => true,
                ];
            }

            $unit = '';
            $estimatedValue = 0.0;
            $ledgerItemId = null;
            if ($inventoryItemId !== null) {
                $item = $this->lockInventoryItem($householdId, $inventoryItemId);
                if ((float)$item['current_quantity'] + 0.000001 < $quantity) {
                    throw new InvalidArgumentException('Waste quantity exceeds the current inventory quantity.');
                }
                $unit = (string)$item['unit'];
                $estimatedValue = round($quantity * $this->costBasisValue($householdId, $inventoryItemId, $unit), 2);
                $remaining = max(0.0, (float)$item['current_quantity'] - $quantity);
                $status = $remaining <= 0.000001
                    ? ($wasteType === 'composted' ? 'composted' : ($wasteType === 'spoiled' || $wasteType === 'expired' ? 'spoiled' : 'archived'))
                    : 'active';
                $update = $this->pdo->prepare(
                    'UPDATE inventory_items
                     SET current_quantity = ?, status = ?
                     WHERE id = ? AND household_id = ? AND current_quantity = ?'
                );
                $update->execute([$remaining, $status, $inventoryItemId, $householdId, $item['current_quantity']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Inventory changed before the waste event could be posted.');
                }
                $ledgerItemId = $inventoryItemId;
            } else {
                $batch = $this->pdo->prepare(
                    'SELECT pfb.*, rr.recipe_id
                     FROM prepared_food_batches pfb
                     LEFT JOIN recipe_runs rr ON rr.id = pfb.recipe_run_id AND rr.household_id = pfb.household_id
                     WHERE pfb.id = ? AND pfb.household_id = ? FOR UPDATE'
                );
                $batch->execute([$preparedFoodBatchId, $householdId]);
                $batchRow = $batch->fetch();
                if (!is_array($batchRow)) {
                    throw new InvalidArgumentException('Prepared-food batch was not found.');
                }
                if ((float)$batchRow['servings_remaining'] + 0.000001 < $quantity) {
                    throw new InvalidArgumentException('Waste quantity exceeds the prepared servings remaining.');
                }
                $unit = 'servings';
                $recipeId = $batchRow['recipe_id'] !== null ? (int)$batchRow['recipe_id'] : null;
                if ($recipeId !== null) {
                    $cost = $this->pdo->prepare(
                        'SELECT cost_per_serving FROM recipe_cost_snapshots
                         WHERE household_id = ? AND recipe_id = ? AND cost_per_serving IS NOT NULL
                         ORDER BY as_of_date DESC, id DESC LIMIT 1'
                    );
                    $cost->execute([$householdId, $recipeId]);
                    $estimatedValue = round($quantity * (float)($cost->fetchColumn() ?: 0), 2);
                }
                $remaining = max(0.0, (float)$batchRow['servings_remaining'] - $quantity);
                $status = $remaining <= 0.000001 ? 'spoiled' : (string)$batchRow['status'];
                $update = $this->pdo->prepare(
                    'UPDATE prepared_food_batches
                     SET servings_remaining = ?, status = ?
                     WHERE id = ? AND household_id = ? AND servings_remaining = ?'
                );
                $update->execute([
                    $remaining,
                    $status,
                    $preparedFoodBatchId,
                    $householdId,
                    $batchRow['servings_remaining'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Prepared food changed before the waste event could be posted.');
                }
                $preparedActionType = 'spoiled';
                $this->pdo->prepare(
                    'INSERT INTO prepared_food_actions
                     (household_id, prepared_food_batch_id, member_id, action_key, action_type, quantity, unit, notes)
                     VALUES (?, ?, ?, ?, ?, ?, "servings", ?)'
                )->execute([
                    $householdId,
                    $preparedFoodBatchId,
                    $memberId,
                    $actionKey,
                    $preparedActionType,
                    $quantity,
                    $reason,
                ]);
                $ledgerItemId = $batchRow['inventory_item_id'] !== null ? (int)$batchRow['inventory_item_id'] : null;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO food_waste_events
                 (household_id, inventory_item_id, prepared_food_batch_id, member_id, action_key,
                  waste_type, quantity, unit, estimated_value, reason, occurred_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $householdId,
                $inventoryItemId,
                $preparedFoodBatchId,
                $memberId,
                $actionKey,
                $wasteType,
                $quantity,
                $unit,
                $estimatedValue,
                $reason,
                $occurredOn,
            ]);
            $wasteId = (int)$this->pdo->lastInsertId();

            $ledgerEventType = match ($wasteType) {
                'composted' => 'composted',
                'spoiled', 'expired', 'damaged' => 'spoiled',
                default => 'discarded',
            };
            $ledger = $this->pdo->prepare(
                'INSERT INTO food_ledger_events
                 (household_id, inventory_item_id, member_id, event_type, quantity, unit,
                  related_type, related_id, cost_effect, notes, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?, "food_waste", ?, ?, ?, ?)'
            );
            $ledger->execute([
                $householdId,
                $ledgerItemId,
                $memberId,
                $ledgerEventType,
                $quantity,
                $unit,
                $wasteId,
                -$estimatedValue,
                $reason,
                $occurredOn . ' 12:00:00',
            ]);
            $ledgerId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare(
                'UPDATE food_waste_events SET ledger_event_id = ?
                 WHERE id = ? AND household_id = ?'
            )->execute([$ledgerId, $wasteId, $householdId]);

            $this->recordEvent(
                $householdId,
                null,
                null,
                $wasteId,
                null,
                $memberId,
                'waste_recorded',
                null,
                null,
                sprintf('Recorded %.4f %s with an estimated value of %.2f.', $quantity, $unit, $estimatedValue)
            );
            $this->pdo->commit();
            return ['waste_event_id' => $wasteId, 'estimated_value' => $estimatedValue, 'reused' => false];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}