<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

trait CostWasteSettingsTrait
{
    public function settings(int $householdId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT hfs.*, h.currency_code
             FROM household_finance_settings hfs
             JOIN households h ON h.id = hfs.household_id
             WHERE hfs.household_id = ?'
        );
        $statement->execute([$householdId]);
        $settings = $statement->fetch();
        if (is_array($settings)) {
            return $settings;
        }

        $currency = $this->pdo->prepare('SELECT currency_code FROM households WHERE id = ?');
        $currency->execute([$householdId]);
        $currencyCode = (string)($currency->fetchColumn() ?: 'USD');

        return [
            'household_id' => $householdId,
            'monthly_budget' => '600.00',
            'waste_target_percent' => '5.00',
            'savings_target_amount' => '100.00',
            'price_increase_alert_percent' => '15.00',
            'currency_code' => $currencyCode,
            'updated_by_member_id' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function saveSettings(int $householdId, int $memberId, array $input): void
    {
        $this->assertActiveMember($householdId, $memberId);
        $budget = $this->decimal($input['monthly_budget'] ?? 600, 0, 999999999.99, 'Monthly budget');
        $wasteTarget = $this->decimal($input['waste_target_percent'] ?? 5, 0, 100, 'Waste target');
        $savingsTarget = $this->decimal($input['savings_target_amount'] ?? 100, 0, 999999999.99, 'Savings target');
        $priceAlert = $this->decimal($input['price_increase_alert_percent'] ?? 15, 1, 500, 'Price increase alert');

        $statement = $this->pdo->prepare(
            'INSERT INTO household_finance_settings
             (household_id, monthly_budget, waste_target_percent, savings_target_amount,
              price_increase_alert_percent, updated_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 monthly_budget = VALUES(monthly_budget),
                 waste_target_percent = VALUES(waste_target_percent),
                 savings_target_amount = VALUES(savings_target_amount),
                 price_increase_alert_percent = VALUES(price_increase_alert_percent),
                 updated_by_member_id = VALUES(updated_by_member_id)'
        );
        $statement->execute([$householdId, $budget, $wasteTarget, $savingsTarget, $priceAlert, $memberId]);
    }

    public function createSupplier(int $householdId, int $memberId, array $input): int
    {
        $this->assertActiveMember($householdId, $memberId);
        $name = $this->text($input['name'] ?? '', 180, 'Supplier name');
        $type = $this->choice(
            $input['supplier_type'] ?? 'grocery',
            ['grocery', 'farm', 'warehouse', 'market', 'online', 'restaurant_supply', 'other'],
            'Supplier type'
        );
        $notes = $this->nullableText($input['notes'] ?? null, 5000, 'Supplier notes');

        $statement = $this->pdo->prepare(
            'INSERT INTO household_suppliers
             (household_id, name, supplier_type, status, notes, created_by_member_id)
             VALUES (?, ?, ?, "active", ?, ?)
             ON DUPLICATE KEY UPDATE
                 id = LAST_INSERT_ID(id),
                 supplier_type = VALUES(supplier_type),
                 status = "active",
                 notes = VALUES(notes)'
        );
        $statement->execute([$householdId, $name, $type, $notes, $memberId]);
        $supplierId = (int)$this->pdo->lastInsertId();
        if ($supplierId <= 0) {
            $lookup = $this->pdo->prepare(
                'SELECT id FROM household_suppliers WHERE household_id = ? AND name = ?'
            );
            $lookup->execute([$householdId, $name]);
            $supplierId = (int)$lookup->fetchColumn();
        }
        return $supplierId;
    }
}
