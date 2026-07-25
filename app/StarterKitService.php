<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class StarterKitService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createKit(array $data, int $userId): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $type = (string)($data['kit_type'] ?? 'basic');
        if ($name === '' || $slug === '' || !in_array($type, ['basic', 'specialized'], true)) {
            throw new InvalidArgumentException('Kit name, slug, and valid type are required.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO starter_kits (name, slug, kit_type, category, description, status, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $name,
            $slug,
            $type,
            trim((string)($data['category'] ?? '')) ?: null,
            trim((string)($data['description'] ?? '')) ?: null,
            (string)($data['status'] ?? 'draft'),
            $userId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createVersion(int $kitId, array $data): int
    {
        $version = (int)($data['version_number'] ?? 0);
        $sku = trim((string)($data['sku'] ?? ''));
        if ($kitId < 1 || $version < 1 || $sku === '') {
            throw new InvalidArgumentException('Kit, version number, and SKU are required.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO starter_kit_versions (starter_kit_id, version_number, sku, price, currency_code, status, published_at) VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = \'published\' THEN UTC_TIMESTAMP() ELSE NULL END)'
        );
        $status = (string)($data['status'] ?? 'draft');
        $statement->execute([
            $kitId,
            $version,
            $sku,
            $this->nullableFloat($data['price'] ?? null),
            strtoupper(trim((string)($data['currency_code'] ?? 'USD'))) ?: 'USD',
            $status,
            $status,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addItem(int $versionId, array $data): int
    {
        $name = trim((string)($data['item_name'] ?? ''));
        $fulfillment = (string)($data['fulfillment_type'] ?? 'shopping_list');
        $allowed = ['shipped', 'shopping_list', 'optional_delivery', 'digital_only', 'customer_supplied'];
        if ($name === '' || !in_array($fulfillment, $allowed, true)) {
            throw new InvalidArgumentException('Item name and valid fulfillment type are required.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO starter_kit_items
            (starter_kit_version_id, item_name, item_kind, fulfillment_type, required, delivery_eligible, shipping_eligible, default_quantity, unit, inventory_category_id, suggested_storage_type, reorder_level, estimated_price, supplier_name, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $versionId,
            $name,
            (string)($data['item_kind'] ?? 'ingredient'),
            $fulfillment,
            !empty($data['required']) ? 1 : 0,
            !empty($data['delivery_eligible']) ? 1 : 0,
            !empty($data['shipping_eligible']) ? 1 : 0,
            $this->nullableFloat($data['default_quantity'] ?? null),
            trim((string)($data['unit'] ?? '')) ?: null,
            (int)($data['inventory_category_id'] ?? 0) ?: null,
            trim((string)($data['suggested_storage_type'] ?? '')) ?: null,
            $this->nullableFloat($data['reorder_level'] ?? null),
            $this->nullableFloat($data['estimated_price'] ?? null),
            trim((string)($data['supplier_name'] ?? '')) ?: null,
            (int)($data['sort_order'] ?? 0),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createOrderAndActivation(int $versionId, string $customerEmail, ?string $externalOrderId = null): array
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare(
                'INSERT INTO starter_kit_orders (starter_kit_version_id, external_order_id, customer_email) VALUES (?, ?, ?)'
            );
            $statement->execute([$versionId, $externalOrderId ?: null, strtolower(trim($customerEmail))]);
            $orderId = (int)$this->pdo->lastInsertId();

            $statement = $this->pdo->prepare(
                'INSERT INTO starter_kit_activations (starter_kit_order_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))'
            );
            $statement->execute([$orderId, $hash]);
            $activationId = (int)$this->pdo->lastInsertId();

            $statement = $this->pdo->prepare(
                'INSERT INTO starter_kit_activation_items (starter_kit_activation_id, starter_kit_item_id, selected_fulfillment_type, confirmed_quantity, unit)
                 SELECT ?, id, fulfillment_type, default_quantity, unit FROM starter_kit_items WHERE starter_kit_version_id = ?'
            );
            $statement->execute([$activationId, $versionId]);
            $this->pdo->commit();
            return ['order_id' => $orderId, 'activation_id' => $activationId, 'token' => $token];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function activationByToken(string $token): array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.*, o.customer_email, o.starter_kit_version_id, o.external_order_id, k.name AS kit_name, k.kit_type, v.version_number, v.sku
             FROM starter_kit_activations a
             JOIN starter_kit_orders o ON o.id = a.starter_kit_order_id
             JOIN starter_kit_versions v ON v.id = o.starter_kit_version_id
             JOIN starter_kits k ON k.id = v.starter_kit_id
             WHERE a.token_hash = ? AND a.revoked_at IS NULL AND a.expires_at > UTC_TIMESTAMP() LIMIT 1"
        );
        $statement->execute([hash('sha256', $token)]);
        $activation = $statement->fetch();
        if (!is_array($activation)) {
            throw new RuntimeException('This starter-kit activation is invalid, expired, or revoked.');
        }
        return $activation;
    }

    public function activate(string $token, int $householdId, int $memberId, array $selections): void
    {
        $activation = $this->activationByToken($token);
        if (!empty($activation['activated_at'])) {
            throw new RuntimeException('This starter kit has already been activated.');
        }

        try {
            $this->pdo->beginTransaction();
            foreach ($selections as $activationItemId => $selection) {
                $statement = $this->pdo->prepare(
                    'SELECT ai.*, i.item_name, i.item_kind, i.inventory_category_id, i.reorder_level, i.suggested_storage_type
                     FROM starter_kit_activation_items ai
                     JOIN starter_kit_items i ON i.id = ai.starter_kit_item_id
                     WHERE ai.id = ? AND ai.starter_kit_activation_id = ? FOR UPDATE'
                );
                $statement->execute([(int)$activationItemId, (int)$activation['id']]);
                $item = $statement->fetch();
                if (!is_array($item)) {
                    continue;
                }

                $status = (string)($selection['status'] ?? 'pending');
                $quantity = isset($selection['quantity']) && is_numeric($selection['quantity']) ? (float)$selection['quantity'] : (float)($item['confirmed_quantity'] ?? 0);
                $unit = trim((string)($selection['unit'] ?? $item['unit'] ?? 'each')) ?: 'each';
                $fulfillment = (string)($selection['fulfillment_type'] ?? $item['selected_fulfillment_type']);
                $inventoryId = null;
                $shoppingId = null;

                if ($status === 'stocked' && $quantity > 0 && $item['item_kind'] !== 'digital') {
                    $locationId = $this->defaultLocation($householdId, (string)($item['suggested_storage_type'] ?? ''));
                    $statement = $this->pdo->prepare(
                        "INSERT INTO inventory_items (household_id, category_id, storage_location_id, name, item_type, current_quantity, unit, reorder_level, status, metadata)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
                    );
                    $statement->execute([
                        $householdId,
                        $item['inventory_category_id'] ?: null,
                        $locationId,
                        $item['item_name'],
                        $this->inventoryType((string)$item['item_kind']),
                        $quantity,
                        $unit,
                        $item['reorder_level'] ?: null,
                        json_encode(['source_type' => 'starter_kit', 'activation_id' => (int)$activation['id'], 'starter_kit_item_id' => (int)$item['starter_kit_item_id']]),
                    ]);
                    $inventoryId = (int)$this->pdo->lastInsertId();
                    $ledger = $this->pdo->prepare(
                        "INSERT INTO food_ledger_events (household_id, inventory_item_id, member_id, event_type, quantity, unit, destination_location_id, related_type, related_id, notes)
                         VALUES (?, ?, ?, 'received', ?, ?, ?, 'starter_kit_activation', ?, ?)"
                    );
                    $ledger->execute([$householdId, $inventoryId, $memberId, $quantity, $unit, $locationId, (int)$activation['id'], 'Provisioned from starter kit ' . $activation['kit_name']]);
                } elseif (in_array($status, ['shopping', 'delivery_requested'], true)) {
                    $listId = $this->activeShoppingList($householdId);
                    $statement = $this->pdo->prepare(
                        "INSERT INTO shopping_list_items (shopping_list_id, item_name, quantity, unit, status, notes) VALUES (?, ?, ?, ?, 'needed', ?)"
                    );
                    $statement->execute([$listId, $item['item_name'], max($quantity, 1), $unit, 'Starter kit: ' . $activation['kit_name'] . ($status === 'delivery_requested' ? ' · delivery requested' : '')]);
                    $shoppingId = (int)$this->pdo->lastInsertId();
                }

                $update = $this->pdo->prepare(
                    'UPDATE starter_kit_activation_items SET selected_fulfillment_type = ?, confirmed_quantity = ?, unit = ?, status = ?, inventory_item_id = ?, shopping_list_item_id = ? WHERE id = ?'
                );
                $update->execute([$fulfillment, $quantity, $unit, $status, $inventoryId, $shoppingId, (int)$activationItemId]);
            }

            $statement = $this->pdo->prepare(
                "UPDATE starter_kit_activations SET household_id = ?, activated_by_member_id = ?, activated_at = UTC_TIMESTAMP() WHERE id = ?"
            );
            $statement->execute([$householdId, $memberId, (int)$activation['id']]);
            $statement = $this->pdo->prepare(
                "UPDATE starter_kit_orders SET household_id = ?, activation_status = 'activated' WHERE id = ?"
            );
            $statement->execute([$householdId, (int)$activation['starter_kit_order_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function activeShoppingList(int $householdId): int
    {
        $statement = $this->pdo->prepare("SELECT id FROM shopping_lists WHERE household_id = ? AND status IN ('draft','active') ORDER BY id DESC LIMIT 1");
        $statement->execute([$householdId]);
        $id = (int)$statement->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $statement = $this->pdo->prepare("INSERT INTO shopping_lists (household_id, name, status) VALUES (?, 'Starter Kit Shopping List', 'active')");
        $statement->execute([$householdId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function defaultLocation(int $householdId, string $type): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM storage_locations WHERE household_id = ? AND (? = \'\' OR location_type = ?) ORDER BY id LIMIT 1');
        $statement->execute([$householdId, $type, $type]);
        return (int)$statement->fetchColumn() ?: null;
    }

    private function inventoryType(string $kind): string
    {
        return match ($kind) {
            'seed' => 'seed',
            'equipment', 'supply' => 'supply',
            default => 'ingredient',
        };
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float)$value;
    }
}
