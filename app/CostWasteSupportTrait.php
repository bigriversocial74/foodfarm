<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

trait CostWasteSupportTrait
{
    private function assertActiveMember(int $householdId, int $memberId): void
    {
        if ($householdId < 1 || $memberId < 1) {
            throw new InvalidArgumentException('A valid household member is required.');
        }
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM household_members
             WHERE id = ? AND household_id = ? AND status = 'active'"
        );
        $statement->execute([$memberId, $householdId]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new InvalidArgumentException('The household member is not active in this household.');
        }
    }

    private function lockInventoryItem(int $householdId, int $itemId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM inventory_items WHERE id = ? AND household_id = ? FOR UPDATE'
        );
        $statement->execute([$itemId, $householdId]);
        $item = $statement->fetch();
        if (!is_array($item)) {
            throw new InvalidArgumentException('Inventory item was not found in this household.');
        }
        return $item;
    }

    private function assertSupplier(int $householdId, int $supplierId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM household_suppliers
             WHERE id = ? AND household_id = ? AND status = 'active'"
        );
        $statement->execute([$supplierId, $householdId]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new InvalidArgumentException('Supplier was not found in this household.');
        }
    }

    private function updateCostBasis(
        int $householdId,
        int $itemId,
        string $unit,
        float $quantity,
        float $unitCost,
        int $purchaseId
    ): void {
        $existing = $this->pdo->prepare(
            'SELECT unit, weighted_unit_cost, quantity_costed
             FROM inventory_cost_basis
             WHERE household_id = ? AND inventory_item_id = ? FOR UPDATE'
        );
        $existing->execute([$householdId, $itemId]);
        $row = $existing->fetch();
        if (is_array($row) && (string)$row['unit'] !== $unit) {
            throw new InvalidArgumentException('Purchase unit does not match the existing cost-basis unit.');
        }

        $oldQuantity = is_array($row) ? max(0.0, (float)$row['quantity_costed']) : 0.0;
        $oldCost = is_array($row) ? max(0.0, (float)$row['weighted_unit_cost']) : 0.0;
        $newQuantity = $oldQuantity + $quantity;
        $weighted = $newQuantity > 0
            ? (($oldQuantity * $oldCost) + ($quantity * $unitCost)) / $newQuantity
            : $unitCost;

        $statement = $this->pdo->prepare(
            'INSERT INTO inventory_cost_basis
             (household_id, inventory_item_id, unit, weighted_unit_cost, quantity_costed, source_purchase_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 unit = VALUES(unit),
                 weighted_unit_cost = VALUES(weighted_unit_cost),
                 quantity_costed = VALUES(quantity_costed),
                 source_purchase_id = VALUES(source_purchase_id)'
        );
        $statement->execute([
            $householdId,
            $itemId,
            $unit,
            round($weighted, 6),
            round($newQuantity, 4),
            $purchaseId,
        ]);
    }

    private function costBasisValue(int $householdId, int $itemId, string $unit): float
    {
        $statement = $this->pdo->prepare(
            'SELECT weighted_unit_cost, unit FROM inventory_cost_basis
             WHERE household_id = ? AND inventory_item_id = ?'
        );
        $statement->execute([$householdId, $itemId]);
        $row = $statement->fetch();
        if (!is_array($row) || (string)$row['unit'] !== $unit) {
            return 0.0;
        }
        return max(0.0, (float)$row['weighted_unit_cost']);
    }

    private function latestPurchaseId(int $householdId, int $itemId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM food_purchase_records
             WHERE household_id = ? AND inventory_item_id = ?
             ORDER BY purchased_on DESC, id DESC LIMIT 1'
        );
        $statement->execute([$householdId, $itemId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    private function recordEvent(
        int $householdId,
        ?int $snapshotId,
        ?int $purchaseId,
        ?int $wasteEventId,
        ?int $recommendationId,
        ?int $memberId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $notes
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO finance_lifecycle_events
             (household_id, snapshot_id, purchase_id, waste_event_id, recommendation_id,
              member_id, event_type, from_status, to_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $householdId,
            $snapshotId,
            $purchaseId,
            $wasteEventId,
            $recommendationId,
            $memberId,
            $eventType,
            $fromStatus,
            $toStatus,
            $notes,
        ]);
    }

    private function sourceWatermark(int $householdId, string $monthStart, string $monthEnd): string
    {
        $queries = [
            'purchases' => 'SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(MAX(created_at), "1970-01-01")
                FROM food_purchase_records WHERE household_id = ? AND purchased_on BETWEEN ? AND ?',
            'waste' => 'SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(MAX(created_at), "1970-01-01")
                FROM food_waste_events WHERE household_id = ? AND occurred_on BETWEEN ? AND ?',
            'harvests' => 'SELECT COUNT(*), COALESCE(MAX(h.id), 0), COALESCE(MAX(h.harvested_at), "1970-01-01")
                FROM harvests h JOIN plantings p ON p.id = h.planting_id
                JOIN garden_zones z ON z.id = p.garden_zone_id
                WHERE z.household_id = ? AND DATE(h.harvested_at) BETWEEN ? AND ?',
            'preservation' => 'SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(MAX(updated_at), "1970-01-01")
                FROM preservation_batches WHERE household_id = ? AND DATE(COALESCE(completed_at, started_at, created_at)) BETWEEN ? AND ?',
            'settings' => 'SELECT COUNT(*), COALESCE(MAX(updated_at), "1970-01-01"), 0
                FROM household_finance_settings WHERE household_id = ?',
            'cost_basis' => 'SELECT COUNT(*), COALESCE(MAX(updated_at), "1970-01-01"), 0
                FROM inventory_cost_basis WHERE household_id = ?',
        ];
        $parts = [];
        foreach ($queries as $name => $sql) {
            $statement = $this->pdo->prepare($sql);
            $params = in_array($name, ['settings', 'cost_basis'], true)
                ? [$householdId]
                : [$householdId, $monthStart, $monthEnd];
            $statement->execute($params);
            $parts[$name] = $statement->fetch(PDO::FETCH_NUM) ?: [];
        }
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function monthBounds(string $month): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m', trim($month));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Month must use YYYY-MM format.');
        }
        return [$date->format('Y-m-01'), $date->modify('last day of this month')->format('Y-m-d')];
    }

    private function date(mixed $value, string $label): DateTimeImmutable
    {
        $text = trim((string)$value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException($label . ' must be a valid date.');
        }
        return $date;
    }

    private function actionKey(mixed $value): string
    {
        $key = strtolower(trim((string)$value));
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            throw new InvalidArgumentException('A valid action key is required.');
        }
        return $key;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($int === false) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return (int)$int;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($int === false) {
            throw new InvalidArgumentException('Identifier must be a positive integer.');
        }
        return (int)$int;
    }

    private function decimal(mixed $value, float $min, float $max, string $label): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($label . ' must be numeric.');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < $min || $number > $max) {
            throw new InvalidArgumentException($label . ' is outside the allowed range.');
        }
        return $number;
    }

    private function nullableDecimal(mixed $value, float $min, float $max, string $label): ?float
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->decimal($value, $min, $max, $label);
    }

    private function choice(mixed $value, array $allowed, string $label): string
    {
        $choice = trim((string)$value);
        if (!in_array($choice, $allowed, true)) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
        return $choice;
    }

    private function text(mixed $value, int $maxLength, string $label): string
    {
        $text = trim((string)$value);
        if ($text === '' || strlen($text) > $maxLength) {
            throw new InvalidArgumentException($label . ' is required and must be at most ' . $maxLength . ' characters.');
        }
        return $text;
    }

    private function nullableText(mixed $value, int $maxLength, string $label): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > $maxLength) {
            throw new InvalidArgumentException($label . ' must be at most ' . $maxLength . ' characters.');
        }
        return $text;
    }
}