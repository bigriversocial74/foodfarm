<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class StarterKitService
{
    private const FULFILLMENT_TYPES = ['shipped', 'shopping_list', 'optional_delivery', 'digital_only', 'customer_supplied'];
    private const ITEM_KINDS = ['ingredient', 'equipment', 'supply', 'seed', 'digital'];
    private const ACTIVATION_STATUSES = ['pending', 'shopping', 'delivery_requested', 'shipped', 'received', 'stocked', 'skipped'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createKit(array $data, int $userId): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $slug = strtolower(trim((string)($data['slug'] ?? '')));
        $type = (string)($data['kit_type'] ?? 'basic');
        $status = (string)($data['status'] ?? 'draft');

        if ($name === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new InvalidArgumentException('Enter a kit name and a lowercase hyphenated slug.');
        }
        if (!in_array($type, ['basic', 'specialized'], true) || !in_array($status, ['draft', 'published', 'retired'], true)) {
            throw new InvalidArgumentException('Invalid starter-kit type or status.');
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
            $status,
            $userId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createVersion(int $kitId, array $data): int
    {
        $version = (int)($data['version_number'] ?? 0);
        $sku = strtoupper(trim((string)($data['sku'] ?? '')));
        $status = (string)($data['status'] ?? 'draft');
        $price = $this->nullableFloat($data['price'] ?? null);

        if ($kitId < 1 || $version < 1 || $sku === '' || !in_array($status, ['draft', 'published', 'retired'], true)) {
            throw new InvalidArgumentException('Kit, version number, SKU, and valid status are required.');
        }
        if ($price !== null && $price < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }
        $kit = $this->pdo->prepare('SELECT id FROM starter_kits WHERE id = ? AND status <> \'retired\' LIMIT 1');
        $kit->execute([$kitId]);
        if (!$kit->fetchColumn()) {
            throw new RuntimeException('The selected starter kit is unavailable.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO starter_kit_versions (starter_kit_id, version_number, sku, price, currency_code, status, published_at) VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = \'published\' THEN UTC_TIMESTAMP() ELSE NULL END)'
        );
        $statement->execute([
            $kitId,
            $version,
            $sku,
            $price,
            strtoupper(trim((string)($data['currency_code'] ?? 'USD'))) ?: 'USD',
            $status,
            $status,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addItem(int $versionId, array $data): int
    {
        $version = $this->draftVersion($versionId);
        $name = trim((string)($data['item_name'] ?? ''));
        $kind = (string)($data['item_kind'] ?? 'ingredient');
        $fulfillment = (string)($data['fulfillment_type'] ?? 'shopping_list');
        $quantity = $this->nullableFloat($data['default_quantity'] ?? null);
        $deliveryEligible = !empty($data['delivery_eligible']);
        $shippingEligible = !empty($data['shipping_eligible']);

        if ($name === '' || !in_array($kind, self::ITEM_KINDS, true) || !in_array($fulfillment, self::FULFILLMENT_TYPES, true)) {
            throw new InvalidArgumentException('Item name, kind, and fulfillment type are required.');
        }
        if ($quantity !== null && $quantity < 0) {
            throw new InvalidArgumentException('Item quantity cannot be negative.');
        }
        if ($fulfillment === 'optional_delivery' && !$deliveryEligible) {
            throw new InvalidArgumentException('Optional-delivery items must be marked delivery eligible.');
        }
        if ($fulfillment === 'shipped' && !$shippingEligible) {
            throw new InvalidArgumentException('Shipped items must be marked shipping eligible.');
        }
        if ($kind === 'digital' && $fulfillment !== 'digital_only') {
            throw new InvalidArgumentException('Digital items must use digital-only fulfillment.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO starter_kit_items
            (starter_kit_version_id, item_name, item_kind, fulfillment_type, required, delivery_eligible, shipping_eligible, default_quantity, unit, inventory_category_id, suggested_storage_type, reorder_level, estimated_price, supplier_name, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $version['id'],
            $name,
            $kind,
            $fulfillment,
            !empty($data['required']) ? 1 : 0,
            $deliveryEligible ? 1 : 0,
            $shippingEligible ? 1 : 0,
            $quantity,
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

    public function attachRecipe(int $versionId, int $recipeId): void
    {
        $this->draftVersion($versionId);
        $recipe = $this->pdo->prepare('SELECT id FROM recipes WHERE id = ? AND status = \'active\' LIMIT 1');
        $recipe->execute([$recipeId]);
        if (!$recipe->fetchColumn()) {
            throw new RuntimeException('The selected recipe is unavailable.');
        }
        $statement = $this->pdo->prepare('INSERT IGNORE INTO starter_kit_recipes (starter_kit_version_id, recipe_id) VALUES (?, ?)');
        $statement->execute([$versionId, $recipeId]);
    }

    public function addTask(int $versionId, array $data): void
    {
        $this->draftVersion($versionId);
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Task title is required.');
        }
        $statement = $this->pdo->prepare('INSERT INTO starter_kit_tasks (starter_kit_version_id, title, area, due_offset_days, recurring_rule, instructions, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $versionId,
            $title,
            trim((string)($data['area'] ?? '')) ?: null,
            (int)($data['due_offset_days'] ?? 0),
            trim((string)($data['recurring_rule'] ?? '')) ?: null,
            trim((string)($data['instructions'] ?? '')) ?: null,
            (int)($data['sort_order'] ?? 0),
        ]);
    }

    public function createOrderAndActivation(int $versionId, string $customerEmail, ?string $externalOrderId = null): array
    {
        $email = strtolower(trim($customerEmail));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid customer email address is required.');
        }
        $version = $this->pdo->prepare("SELECT v.id FROM starter_kit_versions v JOIN starter_kits k ON k.id=v.starter_kit_id WHERE v.id=? AND v.status='published' AND k.status='published' LIMIT 1");
        $version->execute([$versionId]);
        if (!$version->fetchColumn()) {
            throw new RuntimeException('Only a published starter-kit version can be ordered.');
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM starter_kit_items WHERE starter_kit_version_id = ?');
        $count->execute([$versionId]);
        if ((int)$count->fetchColumn() < 1) {
            throw new RuntimeException('The starter-kit version must contain at least one item.');
        }

        $token = bin2hex(random_bytes(32));
        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare('INSERT INTO starter_kit_orders (starter_kit_version_id, external_order_id, customer_email) VALUES (?, ?, ?)');
            $statement->execute([$versionId, $externalOrderId ?: null, $email]);
            $orderId = (int)$this->pdo->lastInsertId();

            $statement = $this->pdo->prepare('INSERT INTO starter_kit_activations (starter_kit_order_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))');
            $statement->execute([$orderId, hash('sha256', $token)]);
            $activationId = (int)$this->pdo->lastInsertId();

            $statement = $this->pdo->prepare('INSERT INTO starter_kit_activation_items (starter_kit_activation_id, starter_kit_item_id, selected_fulfillment_type, confirmed_quantity, unit) SELECT ?, id, fulfillment_type, default_quantity, unit FROM starter_kit_items WHERE starter_kit_version_id = ?');
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

    public function activationByToken(string $token, bool $allowActivated = false): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new RuntimeException('This starter-kit activation is invalid.');
        }
        $sql = "SELECT a.*, o.customer_email, o.starter_kit_version_id, o.external_order_id, k.name AS kit_name, k.kit_type, v.version_number, v.sku
                FROM starter_kit_activations a
                JOIN starter_kit_orders o ON o.id = a.starter_kit_order_id
                JOIN starter_kit_versions v ON v.id = o.starter_kit_version_id
                JOIN starter_kits k ON k.id = v.starter_kit_id
                WHERE a.token_hash = ? AND a.revoked_at IS NULL AND a.expires_at > UTC_TIMESTAMP()";
        if (!$allowActivated) {
            $sql .= ' AND a.activated_at IS NULL';
        }
        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([hash('sha256', $token)]);
        $activation = $statement->fetch();
        if (!is_array($activation)) {
            throw new RuntimeException('This starter-kit activation is invalid, expired, revoked, or already used.');
        }
        return $activation;
    }

    public function activate(string $token, array $user, array $selections): void
    {
        $email = strtolower((string)$user['email']);
        $householdId = (int)$user['household_id'];
        $memberId = (int)$user['member_id'];

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare("SELECT a.*, o.customer_email, o.starter_kit_version_id, o.id AS starter_kit_order_id, k.name AS kit_name
                FROM starter_kit_activations a
                JOIN starter_kit_orders o ON o.id=a.starter_kit_order_id
                JOIN starter_kit_versions v ON v.id=o.starter_kit_version_id
                JOIN starter_kits k ON k.id=v.starter_kit_id
                WHERE a.token_hash=? AND a.activated_at IS NULL AND a.revoked_at IS NULL AND a.expires_at>UTC_TIMESTAMP() FOR UPDATE");
            $statement->execute([hash('sha256', $token)]);
            $activation = $statement->fetch();
            if (!is_array($activation)) {
                throw new RuntimeException('This starter kit has already been used or is no longer valid.');
            }
            if (!hash_equals(strtolower((string)$activation['customer_email']), $email)) {
                throw new RuntimeException('Sign in with the customer email address assigned to this starter-kit order.');
            }

            $itemsStatement = $this->pdo->prepare('SELECT ai.*, i.item_name, i.item_kind, i.fulfillment_type, i.required, i.delivery_eligible, i.shipping_eligible, i.inventory_category_id, i.reorder_level, i.suggested_storage_type FROM starter_kit_activation_items ai JOIN starter_kit_items i ON i.id=ai.starter_kit_item_id WHERE ai.starter_kit_activation_id=? ORDER BY ai.id FOR UPDATE');
            $itemsStatement->execute([(int)$activation['id']]);
            $items = $itemsStatement->fetchAll();
            if (count($items) !== count($selections)) {
                throw new RuntimeException('Every starter-kit item must be reviewed before activation.');
            }

            foreach ($items as $item) {
                $id = (int)$item['id'];
                if (!isset($selections[$id]) || !is_array($selections[$id])) {
                    throw new RuntimeException('A starter-kit item selection is missing.');
                }
                $selection = $selections[$id];
                $status = (string)($selection['status'] ?? 'pending');
                $fulfillment = (string)($selection['fulfillment_type'] ?? $item['selected_fulfillment_type']);
                $quantity = is_numeric($selection['quantity'] ?? null) ? (float)$selection['quantity'] : (float)($item['confirmed_quantity'] ?? 0);
                $unit = trim((string)($selection['unit'] ?? $item['unit'] ?? 'each')) ?: 'each';

                $this->validateSelection($item, $status, $fulfillment, $quantity);
                $inventoryId = null;
                $shoppingId = null;

                if ($status === 'stocked' && $item['item_kind'] !== 'digital') {
                    $locationId = $this->defaultLocation($householdId, (string)($item['suggested_storage_type'] ?? ''));
                    $statement = $this->pdo->prepare("INSERT INTO inventory_items (household_id, category_id, storage_location_id, name, item_type, current_quantity, unit, reorder_level, status, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
                    $statement->execute([$householdId, $item['inventory_category_id'] ?: null, $locationId, $item['item_name'], $this->inventoryType((string)$item['item_kind']), $quantity, $unit, $item['reorder_level'] ?: null, json_encode(['source_type'=>'starter_kit','activation_id'=>(int)$activation['id'],'starter_kit_item_id'=>(int)$item['starter_kit_item_id']], JSON_THROW_ON_ERROR)]);
                    $inventoryId = (int)$this->pdo->lastInsertId();
                    $ledger = $this->pdo->prepare("INSERT INTO food_ledger_events (household_id, inventory_item_id, member_id, event_type, quantity, unit, destination_location_id, related_type, related_id, notes) VALUES (?, ?, ?, 'received', ?, ?, ?, 'starter_kit_activation', ?, ?)");
                    $ledger->execute([$householdId, $inventoryId, $memberId, $quantity, $unit, $locationId, (int)$activation['id'], 'Provisioned from starter kit '.$activation['kit_name']]);
                } elseif (in_array($status, ['shopping', 'delivery_requested'], true)) {
                    $listId = $this->activeShoppingList($householdId);
                    $shoppingStatus = $status === 'delivery_requested' ? 'delivery_requested' : 'needed';
                    $statement = $this->pdo->prepare("INSERT INTO shopping_list_items (shopping_list_id, item_name, quantity, unit, priority, source_type, supplier, estimated_cost, status, notes) SELECT ?, ?, ?, ?, 'medium', 'starter_kit', i.supplier_name, i.estimated_price, ?, ? FROM starter_kit_items i WHERE i.id=?");
                    $statement->execute([$listId, $item['item_name'], max($quantity, 0.0001), $unit, $shoppingStatus, 'Starter kit: '.$activation['kit_name'], (int)$item['starter_kit_item_id']]);
                    $shoppingId = (int)$this->pdo->lastInsertId();
                }

                $update = $this->pdo->prepare('UPDATE starter_kit_activation_items SET selected_fulfillment_type=?, confirmed_quantity=?, unit=?, status=?, inventory_item_id=?, shopping_list_item_id=? WHERE id=?');
                $update->execute([$fulfillment, $quantity, $unit, $status, $inventoryId, $shoppingId, $id]);
            }

            $this->provisionRecipes((int)$activation['starter_kit_version_id'], $householdId, $memberId);
            $this->provisionTasks((int)$activation['starter_kit_version_id'], $householdId, $memberId, (int)$activation['id']);

            $statement = $this->pdo->prepare('UPDATE starter_kit_activations SET household_id=?, activated_by_member_id=?, activated_at=UTC_TIMESTAMP() WHERE id=? AND activated_at IS NULL');
            $statement->execute([$householdId, $memberId, (int)$activation['id']]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The starter kit was activated by another request.');
            }
            $this->pdo->prepare("UPDATE starter_kit_orders SET household_id=?, activation_status='activated' WHERE id=?")->execute([$householdId, (int)$activation['starter_kit_order_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function validateSelection(array $item, string $status, string $fulfillment, float $quantity): void
    {
        if (!in_array($status, self::ACTIVATION_STATUSES, true) || !in_array($fulfillment, self::FULFILLMENT_TYPES, true)) {
            throw new InvalidArgumentException('Invalid starter-kit selection.');
        }
        if ($quantity < 0 || ($status === 'stocked' && $quantity <= 0)) {
            throw new InvalidArgumentException('Stocked quantities must be greater than zero.');
        }
        if (!empty($item['required']) && $status === 'skipped') {
            throw new InvalidArgumentException($item['item_name'].' is required and cannot be skipped.');
        }
        if ($status === 'delivery_requested' && empty($item['delivery_eligible'])) {
            throw new InvalidArgumentException($item['item_name'].' is not eligible for delivery.');
        }
        if ($fulfillment === 'shipped' && empty($item['shipping_eligible'])) {
            throw new InvalidArgumentException($item['item_name'].' is not eligible for shipping.');
        }
        if ($item['item_kind'] === 'digital' && $fulfillment !== 'digital_only') {
            throw new InvalidArgumentException('Digital items must remain digital-only.');
        }
    }

    private function provisionRecipes(int $versionId, int $householdId, int $memberId): void
    {
        $recipes = $this->pdo->prepare('SELECT r.* FROM starter_kit_recipes skr JOIN recipes r ON r.id=skr.recipe_id WHERE skr.starter_kit_version_id=?');
        $recipes->execute([$versionId]);
        foreach ($recipes->fetchAll() as $recipe) {
            $insert = $this->pdo->prepare("INSERT INTO recipes (household_id, name, category, servings, yield_quantity, yield_unit, prep_minutes, cook_minutes, rest_minutes, status, instructions, notes, created_by_member_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)");
            $insert->execute([$householdId, $recipe['name'], $recipe['category'], $recipe['servings'], $recipe['yield_quantity'], $recipe['yield_unit'], $recipe['prep_minutes'], $recipe['cook_minutes'], $recipe['rest_minutes'], $recipe['instructions'], 'Provisioned from starter kit version '.$versionId, $memberId]);
            $newRecipeId = (int)$this->pdo->lastInsertId();
            $ingredients = $this->pdo->prepare('INSERT INTO recipe_ingredients (recipe_id, inventory_item_id, ingredient_name, quantity, unit, optional, sort_order) SELECT ?, NULL, ingredient_name, quantity, unit, optional, sort_order FROM recipe_ingredients WHERE recipe_id=?');
            $ingredients->execute([$newRecipeId, (int)$recipe['id']]);
        }
    }

    private function provisionTasks(int $versionId, int $householdId, int $memberId, int $activationId): void
    {
        $statement = $this->pdo->prepare("INSERT INTO household_tasks (household_id, assigned_member_id, title, description, related_type, related_id, due_at, recurrence_rule, priority, status) SELECT ?, ?, title, instructions, 'starter_kit_activation', ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL due_offset_days DAY), recurring_rule, 'medium', 'planned' FROM starter_kit_tasks WHERE starter_kit_version_id=?");
        $statement->execute([$householdId, $memberId, $activationId, $versionId]);
    }

    private function draftVersion(int $versionId): array
    {
        $statement = $this->pdo->prepare('SELECT id, status FROM starter_kit_versions WHERE id=? LIMIT 1');
        $statement->execute([$versionId]);
        $version = $statement->fetch();
        if (!is_array($version) || $version['status'] !== 'draft') {
            throw new RuntimeException('Published and retired kit versions are immutable. Create a new draft version.');
        }
        return $version;
    }

    private function activeShoppingList(int $householdId): int
    {
        $statement = $this->pdo->prepare("SELECT id FROM shopping_lists WHERE household_id=? AND status IN ('draft','active') ORDER BY id DESC LIMIT 1");
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
        $statement = $this->pdo->prepare('SELECT id FROM storage_locations WHERE household_id=? AND (?=\'\' OR location_type=?) ORDER BY id LIMIT 1');
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
