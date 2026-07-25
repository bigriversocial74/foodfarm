<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

trait CostWastePurchaseTrait
{
    public function recordPurchase(int $householdId, int $memberId, array $input): array
    {
        $this->assertActiveMember($householdId, $memberId);
        $itemId = $this->positiveInt($input['inventory_item_id'] ?? null, 'Inventory item');
        $supplierId = $this->nullablePositiveInt($input['supplier_id'] ?? null);
        $quantity = $this->decimal($input['quantity'] ?? null, 0.0001, 999999999, 'Purchase quantity');
        $totalCost = $this->decimal($input['total_cost'] ?? null, 0, 999999999.99, 'Total cost');
        $purchasedOn = $this->date($input['purchased_on'] ?? date('Y-m-d'), 'Purchase date')->format('Y-m-d');
        $packageQuantity = $this->nullableDecimal($input['package_quantity'] ?? null, 0.0001, 999999999, 'Package quantity');
        $packageUnit = trim((string)($input['package_unit'] ?? ''));
        if ($packageUnit !== '' && strlen($packageUnit) > 30) {
            throw new InvalidArgumentException('Package unit is too long.');
        }
        if (($packageQuantity === null) !== ($packageUnit === '')) {
            throw new InvalidArgumentException('Package quantity and package unit must be supplied together.');
        }
        $receipt = $this->nullableText($input['receipt_reference'] ?? null, 190, 'Receipt reference');
        $notes = $this->nullableText($input['notes'] ?? null, 5000, 'Purchase notes');
        $actionKey = $this->actionKey($input['action_key'] ?? null);
        $unitCost = round($totalCost / $quantity, 6);

        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare(
                'SELECT id, inventory_item_id, supplier_id, purchased_on, quantity, package_quantity,
                        package_unit, total_cost, unit_cost
                 FROM food_purchase_records
                 WHERE household_id = ? AND action_key = ? FOR UPDATE'
            );
            $existing->execute([$householdId, $actionKey]);
            $existingRow = $existing->fetch();
            if (is_array($existingRow)) {
                $sameSupplier = ($supplierId === null && $existingRow['supplier_id'] === null)
                    || ($supplierId !== null && (int)$existingRow['supplier_id'] === $supplierId);
                $samePackageQuantity = ($packageQuantity === null && $existingRow['package_quantity'] === null)
                    || ($packageQuantity !== null
                        && $existingRow['package_quantity'] !== null
                        && abs((float)$existingRow['package_quantity'] - $packageQuantity) < 0.000001);
                $samePackageUnit = ($packageUnit === '' && $existingRow['package_unit'] === null)
                    || ($packageUnit !== '' && (string)$existingRow['package_unit'] === $packageUnit);
                if (
                    (int)$existingRow['inventory_item_id'] !== $itemId
                    || !$sameSupplier
                    || (string)$existingRow['purchased_on'] !== $purchasedOn
                    || abs((float)$existingRow['quantity'] - $quantity) >= 0.000001
                    || abs((float)$existingRow['total_cost'] - $totalCost) >= 0.005
                    || !$samePackageQuantity
                    || !$samePackageUnit
                ) {
                    throw new InvalidArgumentException(
                        'This purchase action key is already bound to different purchase details.'
                    );
                }
                $this->pdo->commit();
                return [
                    'purchase_id' => (int)$existingRow['id'],
                    'unit_cost' => (float)$existingRow['unit_cost'],
                    'reused' => true,
                ];
            }

            $item = $this->lockInventoryItem($householdId, $itemId);
            $unit = (string)$item['unit'];
            if ($supplierId !== null) {
                $this->assertSupplier($householdId, $supplierId);
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO food_purchase_records
                 (household_id, inventory_item_id, supplier_id, purchased_by_member_id, action_key,
                  purchased_on, quantity, unit, package_quantity, package_unit, total_cost, unit_cost,
                  receipt_reference, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $householdId,
                $itemId,
                $supplierId,
                $memberId,
                $actionKey,
                $purchasedOn,
                $quantity,
                $unit,
                $packageQuantity,
                $packageUnit !== '' ? $packageUnit : null,
                $totalCost,
                $unitCost,
                $receipt,
                $notes,
            ]);
            $purchaseId = (int)$this->pdo->lastInsertId();

            $this->updateCostBasis($householdId, $itemId, $unit, $quantity, $unitCost, $purchaseId);

            $inventoryUpdate = $this->pdo->prepare(
                'UPDATE inventory_items
                 SET current_quantity = current_quantity + ?, status = "active"
                 WHERE id = ? AND household_id = ?'
            );
            $inventoryUpdate->execute([$quantity, $itemId, $householdId]);
            if ($inventoryUpdate->rowCount() !== 1) {
                throw new RuntimeException('Inventory changed before the purchase could be posted.');
            }

            $ledger = $this->pdo->prepare(
                'INSERT INTO food_ledger_events
                 (household_id, inventory_item_id, member_id, event_type, quantity, unit,
                  related_type, related_id, cost_effect, notes, occurred_at)
                 VALUES (?, ?, ?, "purchased", ?, ?, "food_purchase", ?, ?, ?, ?)'
            );
            $ledger->execute([
                $householdId,
                $itemId,
                $memberId,
                $quantity,
                $unit,
                $purchaseId,
                $totalCost,
                $notes,
                $purchasedOn . ' 12:00:00',
            ]);
            $ledgerId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare(
                'UPDATE food_purchase_records
                 SET ledger_event_id = ? WHERE id = ? AND household_id = ?'
            )->execute([$ledgerId, $purchaseId, $householdId]);

            $this->recordEvent(
                $householdId,
                null,
                $purchaseId,
                null,
                null,
                $memberId,
                'purchase_recorded',
                null,
                null,
                sprintf('Recorded %.4f %s at %.6f per unit.', $quantity, $unit, $unitCost)
            );
            $this->pdo->commit();

            return ['purchase_id' => $purchaseId, 'unit_cost' => $unitCost, 'reused' => false];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
