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
        $name = $this->text($data['name'] ?? '', 'Kit name', 180, true);
        $slug = strtolower(trim((string)($data['slug'] ?? '')));
        $type = (string)($data['kit_type'] ?? 'basic');

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 190) {
            throw new InvalidArgumentException('Use a lowercase, hyphenated slug no longer than 190 characters.');
        }
        if (!in_array($type, ['basic', 'specialized'], true)) {
            throw new InvalidArgumentException('Invalid starter-kit type.');
        }
        if ($userId < 1) {
            throw new RuntimeException('A valid platform administrator is required.');
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO starter_kits (name, slug, kit_type, category, description, status, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, 'draft', ?)"
        );
        $statement->execute([
            $name,
            $slug,
            $type,
            $this->text($data['category'] ?? '', 'Category', 100),
            $this->text($data['description'] ?? '', 'Description', 5000),
            $userId,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function createVersion(int $kitId, array $data): int
    {
        $versionNumber = (int)($data['version_number'] ?? 0);
        $sku = strtoupper(trim((string)($data['sku'] ?? '')));
        $price = $this->nullableDecimal($data['price'] ?? null, 'Price');
        $currency = strtoupper(trim((string)($data['currency_code'] ?? 'USD')));

        if ($kitId < 1 || $versionNumber < 1 || $versionNumber > 100000) {
            throw new InvalidArgumentException('Choose a kit and a valid version number.');
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,99}$/', $sku)) {
            throw new InvalidArgumentException('SKU must contain 2–100 uppercase letters, numbers, periods, underscores, or hyphens.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter code.');
        }
        if ($price !== null && $price < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }

        $kit = $this->pdo->prepare("SELECT id FROM starter_kits WHERE id = ? AND status <> 'retired' LIMIT 1");
        $kit->execute([$kitId]);
        if (!$kit->fetchColumn()) {
            throw new RuntimeException('The selected starter kit is unavailable.');
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO starter_kit_versions
             (starter_kit_id, version_number, sku, price, currency_code, status, published_at)
             VALUES (?, ?, ?, ?, ?, 'draft', NULL)"
        );
        $statement->execute([$kitId, $versionNumber, $sku, $price, $currency]);

        return (int)$this->pdo->lastInsertId();
    }

    public function publishVersion(int $versionId): void
    {
        if ($versionId < 1) {
            throw new InvalidArgumentException('Choose a draft version to publish.');
        }

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare(
                "SELECT v.id, v.starter_kit_id, v.status, k.status AS kit_status
                 FROM starter_kit_versions v
                 JOIN starter_kits k ON k.id = v.starter_kit_id
                 WHERE v.id = ? FOR UPDATE"
            );
            $statement->execute([$versionId]);
            $version = $statement->fetch();
            if (!is_array($version) || $version['status'] !== 'draft' || $version['kit_status'] === 'retired') {
                throw new RuntimeException('Only a draft version of an available kit can be published.');
            }

            $items = $this->pdo->prepare(
                'SELECT item_name, item_kind, fulfillment_type, default_quantity, unit, delivery_eligible, shipping_eligible
                 FROM starter_kit_items WHERE starter_kit_version_id = ? ORDER BY id FOR UPDATE'
            );
            $items->execute([$versionId]);
            $rows = $items->fetchAll();
            if ($rows === []) {
                throw new RuntimeException('Add at least one item before publishing this version.');
            }
            foreach ($rows as $item) {
                $this->validatePublishableItem($item);
            }

            $recipeLinks = $this->pdo->prepare(
                'SELECT recipe_id FROM starter_kit_recipes WHERE starter_kit_version_id = ? ORDER BY recipe_id FOR UPDATE'
            );
            $recipeLinks->execute([$versionId]);
            foreach ($recipeLinks->fetchAll(PDO::FETCH_COLUMN) as $recipeId) {
                $snapshot = $this->snapshotRecipe((int)$recipeId, null);
                $this->upsertRecipeSnapshot($versionId, (int)$recipeId, $snapshot);
            }
            $this->assertSnapshotIntegrity($versionId);

            $update = $this->pdo->prepare(
                "UPDATE starter_kit_versions SET status = 'published', published_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'draft'"
            );
            $update->execute([$versionId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The starter-kit version changed before it could be published.');
            }
            $this->pdo->prepare("UPDATE starter_kits SET status = 'published' WHERE id = ? AND status = 'draft'")
                ->execute([(int)$version['starter_kit_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function addItem(int $versionId, array $data): int
    {
        $version = $this->draftVersion($versionId);
        $name = $this->text($data['item_name'] ?? '', 'Item name', 180, true);
        $kind = (string)($data['item_kind'] ?? 'ingredient');
        $fulfillment = (string)($data['fulfillment_type'] ?? 'shopping_list');
        $quantity = $this->nullableDecimal($data['default_quantity'] ?? null, 'Quantity');
        $unit = $this->text($data['unit'] ?? '', 'Unit', 30);
        $reorderLevel = $this->nullableDecimal($data['reorder_level'] ?? null, 'Reorder level');
        $estimatedPrice = $this->nullableDecimal($data['estimated_price'] ?? null, 'Estimated price');
        $deliveryEligible = !empty($data['delivery_eligible']);
        $shippingEligible = !empty($data['shipping_eligible']);
        $categoryId = (int)($data['inventory_category_id'] ?? 0) ?: null;

        if (!in_array($kind, self::ITEM_KINDS, true) || !in_array($fulfillment, self::FULFILLMENT_TYPES, true)) {
            throw new InvalidArgumentException('Item kind or fulfillment type is invalid.');
        }
        if (($quantity !== null && $quantity < 0) || ($reorderLevel !== null && $reorderLevel < 0) || ($estimatedPrice !== null && $estimatedPrice < 0)) {
            throw new InvalidArgumentException('Quantities, reorder levels, and prices cannot be negative.');
        }
        if ($kind === 'digital') {
            if ($fulfillment !== 'digital_only') {
                throw new InvalidArgumentException('Digital items must use digital-only fulfillment.');
            }
            $quantity = null;
            $unit = null;
            $categoryId = null;
            $reorderLevel = null;
        } elseif ($quantity === null || $quantity <= 0 || $unit === null) {
            throw new InvalidArgumentException('Physical kit items require a quantity greater than zero and a unit.');
        }
        if ($fulfillment === 'optional_delivery' && !$deliveryEligible) {
            throw new InvalidArgumentException('Optional-delivery items must be marked delivery eligible.');
        }
        if ($fulfillment === 'shipped' && !$shippingEligible) {
            throw new InvalidArgumentException('Shipped items must be marked shipping eligible.');
        }
        if ($categoryId !== null) {
            $category = $this->pdo->prepare('SELECT id FROM inventory_categories WHERE id = ? AND household_id IS NULL LIMIT 1');
            $category->execute([$categoryId]);
            if (!$category->fetchColumn()) {
                throw new RuntimeException('Starter kits may use only platform inventory categories.');
            }
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO starter_kit_items
             (starter_kit_version_id, item_name, item_kind, fulfillment_type, required, delivery_eligible,
              shipping_eligible, default_quantity, unit, inventory_category_id, suggested_storage_type,
              reorder_level, estimated_price, supplier_name, sort_order)
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
            $unit,
            $categoryId,
            $this->text($data['suggested_storage_type'] ?? '', 'Suggested storage', 80),
            $reorderLevel,
            $estimatedPrice,
            $this->text($data['supplier_name'] ?? '', 'Supplier', 180),
            max(0, (int)($data['sort_order'] ?? 0)),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function attachRecipe(int $versionId, int $recipeId, int $sourceHouseholdId): void
    {
        if ($recipeId < 1 || $sourceHouseholdId < 1) {
            throw new InvalidArgumentException('Choose a valid starter recipe.');
        }

        try {
            $this->pdo->beginTransaction();
            $this->draftVersion($versionId, true);
            $snapshot = $this->snapshotRecipe($recipeId, $sourceHouseholdId);
            $statement = $this->pdo->prepare(
                'INSERT IGNORE INTO starter_kit_recipes (starter_kit_version_id, recipe_id) VALUES (?, ?)'
            );
            $statement->execute([$versionId, $recipeId]);
            $this->upsertRecipeSnapshot($versionId, $recipeId, $snapshot);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function addTask(int $versionId, array $data): void
    {
        $this->draftVersion($versionId);
        $title = $this->text($data['title'] ?? '', 'Task title', 180, true);
        $offset = (int)($data['due_offset_days'] ?? 0);
        if ($offset < -365 || $offset > 3650) {
            throw new InvalidArgumentException('Task due offset must be between -365 and 3,650 days.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO starter_kit_tasks
             (starter_kit_version_id, title, area, due_offset_days, recurring_rule, instructions, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $versionId,
            $title,
            $this->text($data['area'] ?? '', 'Task area', 80),
            $offset,
            $this->text($data['recurring_rule'] ?? '', 'Recurrence rule', 190),
            $this->text($data['instructions'] ?? '', 'Task instructions', 5000),
            max(0, (int)($data['sort_order'] ?? 0)),
        ]);
    }

    public function createOrderAndActivation(int $versionId, string $customerEmail, ?string $externalOrderId = null): array
    {
        $email = strtolower(trim($customerEmail));
        $externalOrderId = $externalOrderId === null ? null : trim($externalOrderId);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new InvalidArgumentException('A valid customer email address is required.');
        }
        if ($externalOrderId !== null && ($externalOrderId === '' || strlen($externalOrderId) > 190)) {
            throw new InvalidArgumentException('External order ID must be 1–190 characters.');
        }

        $version = $this->pdo->prepare(
            "SELECT v.id FROM starter_kit_versions v
             JOIN starter_kits k ON k.id = v.starter_kit_id
             WHERE v.id = ? AND v.status = 'published' AND k.status = 'published' LIMIT 1"
        );
        $version->execute([$versionId]);
        if (!$version->fetchColumn()) {
            throw new RuntimeException('Only a published starter-kit version can be ordered.');
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM starter_kit_items WHERE starter_kit_version_id = ?');
        $count->execute([$versionId]);
        if ((int)$count->fetchColumn() < 1) {
            throw new RuntimeException('The starter-kit version must contain at least one item.');
        }
        $this->assertSnapshotIntegrity($versionId);

        $token = bin2hex(random_bytes(32));
        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare(
                'INSERT INTO starter_kit_orders (starter_kit_version_id, external_order_id, customer_email) VALUES (?, ?, ?)'
            );
            $statement->execute([$versionId, $externalOrderId, $email]);
            $orderId = (int)$this->pdo->lastInsertId();

            $statement = $this->pdo->prepare(
                'INSERT INTO starter_kit_activations (starter_kit_order_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))'
            );
            $statement->execute([$orderId, hash('sha256', $token)]);
            $activationId = (int)$this->pdo->lastInsertId();

            $statement = $this->pdo->prepare(
                'INSERT INTO starter_kit_activation_items
                 (starter_kit_activation_id, starter_kit_item_id, selected_fulfillment_type, confirmed_quantity, unit)
                 SELECT ?, id, fulfillment_type, default_quantity, unit
                 FROM starter_kit_items WHERE starter_kit_version_id = ?'
            );
            $statement->execute([$activationId, $versionId]);
            if ($statement->rowCount() < 1) {
                throw new RuntimeException('Starter-kit activation items could not be created.');
            }
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
        $sql = "SELECT a.*, o.customer_email, o.starter_kit_version_id, o.external_order_id,
                       k.name AS kit_name, k.kit_type, v.version_number, v.sku
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
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new RuntimeException('This starter-kit activation is invalid.');
        }
        $email = strtolower(trim((string)($user['email'] ?? '')));
        $householdId = (int)($user['household_id'] ?? 0);
        $memberId = (int)($user['member_id'] ?? 0);
        $userId = (int)($user['id'] ?? 0);
        if ($householdId < 1 || $memberId < 1 || $userId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid signed-in household member is required.');
        }

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare(
                "SELECT a.*, o.customer_email, o.starter_kit_version_id, o.id AS starter_kit_order_id, k.name AS kit_name
                 FROM starter_kit_activations a
                 JOIN starter_kit_orders o ON o.id = a.starter_kit_order_id
                 JOIN starter_kit_versions v ON v.id = o.starter_kit_version_id
                 JOIN starter_kits k ON k.id = v.starter_kit_id
                 WHERE a.token_hash = ? AND a.activated_at IS NULL AND a.revoked_at IS NULL
                   AND a.expires_at > UTC_TIMESTAMP() FOR UPDATE"
            );
            $statement->execute([hash('sha256', $token)]);
            $activation = $statement->fetch();
            if (!is_array($activation)) {
                throw new RuntimeException('This starter kit has already been used or is no longer valid.');
            }
            if (!hash_equals(strtolower((string)$activation['customer_email']), $email)) {
                throw new RuntimeException('Sign in with the customer email address assigned to this starter-kit order.');
            }

            $member = $this->pdo->prepare(
                "SELECT id FROM household_members WHERE id = ? AND household_id = ? AND user_id = ? AND status = 'active' LIMIT 1"
            );
            $member->execute([$memberId, $householdId, $userId]);
            if (!$member->fetchColumn()) {
                throw new RuntimeException('The active household membership could not be verified.');
            }
            $this->assertSnapshotIntegrity((int)$activation['starter_kit_version_id']);

            $itemsStatement = $this->pdo->prepare(
                'SELECT ai.*, i.item_name, i.item_kind, i.fulfillment_type, i.required,
                        i.delivery_eligible, i.shipping_eligible, i.inventory_category_id,
                        i.reorder_level, i.suggested_storage_type
                 FROM starter_kit_activation_items ai
                 JOIN starter_kit_items i ON i.id = ai.starter_kit_item_id
                 WHERE ai.starter_kit_activation_id = ? ORDER BY ai.id FOR UPDATE'
            );
            $itemsStatement->execute([(int)$activation['id']]);
            $items = $itemsStatement->fetchAll();
            if ($items === [] || count($items) !== count($selections)) {
                throw new RuntimeException('Every starter-kit item must be reviewed before activation.');
            }

            foreach ($items as $item) {
                $activationItemId = (int)$item['id'];
                if (!isset($selections[$activationItemId]) || !is_array($selections[$activationItemId])) {
                    throw new RuntimeException('A starter-kit item selection is missing.');
                }
                $selection = $selections[$activationItemId];
                $status = (string)($selection['status'] ?? 'pending');
                $fulfillment = (string)($selection['fulfillment_type'] ?? $item['selected_fulfillment_type']);
                $quantity = is_numeric($selection['quantity'] ?? null)
                    ? (float)$selection['quantity']
                    : (float)($item['confirmed_quantity'] ?? 0);
                $unit = trim((string)($selection['unit'] ?? $item['unit'] ?? ''));

                $this->validateSelection($item, $status, $fulfillment, $quantity, $unit);
                $inventoryId = null;
                $shoppingId = null;

                if ($status === 'stocked' && $item['item_kind'] !== 'digital') {
                    $locationId = $this->defaultLocation($householdId, (string)($item['suggested_storage_type'] ?? ''));
                    $metadata = json_encode([
                        'source_type' => 'starter_kit',
                        'activation_id' => (int)$activation['id'],
                        'starter_kit_item_id' => (int)$item['starter_kit_item_id'],
                    ], JSON_THROW_ON_ERROR);
                    $statement = $this->pdo->prepare(
                        "INSERT INTO inventory_items
                         (household_id, category_id, storage_location_id, name, item_type, current_quantity,
                          unit, reorder_level, status, metadata)
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
                        $metadata,
                    ]);
                    $inventoryId = (int)$this->pdo->lastInsertId();
                    $ledger = $this->pdo->prepare(
                        "INSERT INTO food_ledger_events
                         (household_id, inventory_item_id, member_id, event_type, quantity, unit,
                          destination_location_id, related_type, related_id, notes)
                         VALUES (?, ?, ?, 'received', ?, ?, ?, 'starter_kit_activation', ?, ?)"
                    );
                    $ledger->execute([
                        $householdId,
                        $inventoryId,
                        $memberId,
                        $quantity,
                        $unit,
                        $locationId,
                        (int)$activation['id'],
                        'Provisioned from starter kit ' . $activation['kit_name'],
                    ]);
                } elseif (in_array($status, ['shopping', 'delivery_requested'], true)) {
                    $listId = $this->activeShoppingList($householdId);
                    $shoppingStatus = $status === 'delivery_requested' ? 'delivery_requested' : 'needed';
                    $statement = $this->pdo->prepare(
                        "INSERT INTO shopping_list_items
                         (shopping_list_id, item_name, quantity, unit, priority, source_type, supplier,
                          estimated_cost, status, notes)
                         SELECT ?, ?, ?, ?, 'medium', 'starter_kit', i.supplier_name, i.estimated_price, ?, ?
                         FROM starter_kit_items i WHERE i.id = ?"
                    );
                    $statement->execute([
                        $listId,
                        $item['item_name'],
                        $quantity,
                        $unit,
                        $shoppingStatus,
                        'Starter kit: ' . $activation['kit_name'],
                        (int)$item['starter_kit_item_id'],
                    ]);
                    if ($statement->rowCount() !== 1) {
                        throw new RuntimeException('A starter-kit shopping item could not be created.');
                    }
                    $shoppingId = (int)$this->pdo->lastInsertId();
                }

                $update = $this->pdo->prepare(
                    'UPDATE starter_kit_activation_items
                     SET selected_fulfillment_type = ?, confirmed_quantity = ?, unit = ?, status = ?,
                         inventory_item_id = ?, shopping_list_item_id = ?
                     WHERE id = ? AND starter_kit_activation_id = ?'
                );
                $update->execute([
                    $fulfillment,
                    $item['item_kind'] === 'digital' ? null : $quantity,
                    $item['item_kind'] === 'digital' ? null : $unit,
                    $status,
                    $inventoryId,
                    $shoppingId,
                    $activationItemId,
                    (int)$activation['id'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('A starter-kit activation item could not be finalized.');
                }
            }

            $this->provisionRecipes((int)$activation['starter_kit_version_id'], $householdId, $memberId, (int)$activation['id']);
            $this->provisionTasks((int)$activation['starter_kit_version_id'], $householdId, $memberId, (int)$activation['id']);

            $statement = $this->pdo->prepare(
                'UPDATE starter_kit_activations
                 SET household_id = ?, activated_by_member_id = ?, activated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND activated_at IS NULL'
            );
            $statement->execute([$householdId, $memberId, (int)$activation['id']]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The starter kit was activated by another request.');
            }
            $orderUpdate = $this->pdo->prepare(
                "UPDATE starter_kit_orders SET household_id = ?, activation_status = 'activated'
                 WHERE id = ? AND activation_status = 'pending'"
            );
            $orderUpdate->execute([$householdId, (int)$activation['starter_kit_order_id']]);
            if ($orderUpdate->rowCount() !== 1) {
                throw new RuntimeException('The starter-kit order could not be finalized.');
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function validateSelection(array $item, string $status, string $fulfillment, float $quantity, string $unit): void
    {
        if (!in_array($status, self::ACTIVATION_STATUSES, true) || !in_array($fulfillment, self::FULFILLMENT_TYPES, true)) {
            throw new InvalidArgumentException('Invalid starter-kit selection.');
        }
        if (!is_finite($quantity) || $quantity < 0) {
            throw new InvalidArgumentException('Starter-kit quantities must be valid, non-negative numbers.');
        }
        if (!empty($item['required']) && $status === 'skipped') {
            throw new InvalidArgumentException($item['item_name'] . ' is required and cannot be skipped.');
        }
        if ($item['item_kind'] === 'digital') {
            if ($fulfillment !== 'digital_only' || !in_array($status, ['pending', 'received'], true)) {
                throw new InvalidArgumentException('Digital items must remain digital-only and included with the activation.');
            }
            return;
        }
        if ($quantity <= 0 || $unit === '' || strlen($unit) > 30) {
            throw new InvalidArgumentException($item['item_name'] . ' requires a quantity greater than zero and a valid unit.');
        }
        $configuredUnit = trim((string)($item['unit'] ?? ''));
        if ($configuredUnit !== '' && strcasecmp($configuredUnit, $unit) !== 0) {
            throw new InvalidArgumentException($item['item_name'] . ' must use the configured unit ' . $configuredUnit . '.');
        }
        if ($status === 'shopping' && $fulfillment !== 'shopping_list') {
            throw new InvalidArgumentException('Shopping-list status requires local-shopping fulfillment.');
        }
        if ($status === 'delivery_requested' && ($fulfillment !== 'optional_delivery' || empty($item['delivery_eligible']))) {
            throw new InvalidArgumentException($item['item_name'] . ' is not eligible for delivery.');
        }
        if (in_array($status, ['pending', 'shipped'], true) && $fulfillment !== 'shipped') {
            throw new InvalidArgumentException('Pending or shipped status requires shipped fulfillment.');
        }
        if ($fulfillment === 'shipped' && empty($item['shipping_eligible'])) {
            throw new InvalidArgumentException($item['item_name'] . ' is not eligible for shipping.');
        }
        if ($fulfillment === 'optional_delivery' && empty($item['delivery_eligible'])) {
            throw new InvalidArgumentException($item['item_name'] . ' is not eligible for delivery.');
        }
        if ($status === 'skipped' && !empty($item['required'])) {
            throw new InvalidArgumentException($item['item_name'] . ' is required and cannot be skipped.');
        }
    }

    private function validatePublishableItem(array $item): void
    {
        if (!in_array((string)$item['item_kind'], self::ITEM_KINDS, true)
            || !in_array((string)$item['fulfillment_type'], self::FULFILLMENT_TYPES, true)) {
            throw new RuntimeException('A kit item has an invalid type or fulfillment method.');
        }
        if ($item['item_kind'] === 'digital') {
            if ($item['fulfillment_type'] !== 'digital_only') {
                throw new RuntimeException($item['item_name'] . ' must use digital-only fulfillment.');
            }
            return;
        }
        if ((float)$item['default_quantity'] <= 0 || trim((string)$item['unit']) === '') {
            throw new RuntimeException($item['item_name'] . ' requires a positive quantity and unit before publishing.');
        }
        if ($item['fulfillment_type'] === 'shipped' && empty($item['shipping_eligible'])) {
            throw new RuntimeException($item['item_name'] . ' must be shipping eligible.');
        }
        if ($item['fulfillment_type'] === 'optional_delivery' && empty($item['delivery_eligible'])) {
            throw new RuntimeException($item['item_name'] . ' must be delivery eligible.');
        }
    }

    private function snapshotRecipe(int $recipeId, ?int $householdId): array
    {
        $sql = "SELECT id, name, category, servings, yield_quantity, yield_unit, prep_minutes,
                       cook_minutes, rest_minutes, instructions, notes
                FROM recipes WHERE id = ? AND status = 'active'";
        $params = [$recipeId];
        if ($householdId !== null) {
            $sql .= ' AND household_id = ?';
            $params[] = $householdId;
        }
        $sql .= ' LIMIT 1';
        $recipeQuery = $this->pdo->prepare($sql);
        $recipeQuery->execute($params);
        $recipe = $recipeQuery->fetch();
        if (!is_array($recipe)) {
            throw new RuntimeException('The selected starter recipe is unavailable.');
        }

        $ingredientQuery = $this->pdo->prepare(
            'SELECT ingredient_name, quantity, unit, optional, sort_order
             FROM recipe_ingredients WHERE recipe_id = ? ORDER BY sort_order, id'
        );
        $ingredientQuery->execute([$recipeId]);
        $ingredients = $ingredientQuery->fetchAll();
        if ($ingredients === []) {
            throw new RuntimeException('The starter recipe must contain at least one ingredient.');
        }

        $snapshot = [
            'schema_version' => 1,
            'name' => (string)$recipe['name'],
            'category' => $recipe['category'],
            'servings' => (float)$recipe['servings'],
            'yield_quantity' => $recipe['yield_quantity'] === null ? null : (float)$recipe['yield_quantity'],
            'yield_unit' => $recipe['yield_unit'],
            'prep_minutes' => $recipe['prep_minutes'] === null ? null : (int)$recipe['prep_minutes'],
            'cook_minutes' => $recipe['cook_minutes'] === null ? null : (int)$recipe['cook_minutes'],
            'rest_minutes' => $recipe['rest_minutes'] === null ? null : (int)$recipe['rest_minutes'],
            'instructions' => $recipe['instructions'],
            'notes' => $recipe['notes'],
            'ingredients' => array_map(static fn(array $ingredient): array => [
                'ingredient_name' => (string)$ingredient['ingredient_name'],
                'quantity' => (float)$ingredient['quantity'],
                'unit' => (string)$ingredient['unit'],
                'optional' => (int)$ingredient['optional'],
                'sort_order' => (int)$ingredient['sort_order'],
            ], $ingredients),
        ];
        $this->validateRecipeSnapshot($snapshot);

        return $snapshot;
    }

    private function upsertRecipeSnapshot(int $versionId, int $recipeId, array $snapshot): void
    {
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $statement = $this->pdo->prepare(
            'INSERT INTO starter_kit_recipe_snapshots
             (starter_kit_version_id, source_recipe_id, snapshot_hash, recipe_snapshot)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE snapshot_hash = VALUES(snapshot_hash), recipe_snapshot = VALUES(recipe_snapshot)'
        );
        $statement->execute([$versionId, $recipeId, hash('sha256', $json), $json]);
    }

    private function assertSnapshotIntegrity(int $versionId): void
    {
        $links = $this->pdo->prepare('SELECT COUNT(*) FROM starter_kit_recipes WHERE starter_kit_version_id = ?');
        $links->execute([$versionId]);
        $linkCount = (int)$links->fetchColumn();

        $snapshots = $this->pdo->prepare(
            'SELECT snapshot_hash, recipe_snapshot FROM starter_kit_recipe_snapshots
             WHERE starter_kit_version_id = ? ORDER BY id'
        );
        $snapshots->execute([$versionId]);
        $rows = $snapshots->fetchAll();
        if (count($rows) < $linkCount) {
            throw new RuntimeException('One or more Starter Kit recipes are missing immutable snapshots.');
        }
        foreach ($rows as $row) {
            $raw = (string)$row['recipe_snapshot'];
            if (!hash_equals((string)$row['snapshot_hash'], hash('sha256', $raw))) {
                throw new RuntimeException('A Starter Kit recipe snapshot failed its integrity check.');
            }
            $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot)) {
                throw new RuntimeException('A Starter Kit recipe snapshot is invalid.');
            }
            $this->validateRecipeSnapshot($snapshot);
        }
    }

    private function validateRecipeSnapshot(array $snapshot): void
    {
        if (($snapshot['schema_version'] ?? null) !== 1
            || !is_string($snapshot['name'] ?? null)
            || trim((string)$snapshot['name']) === ''
            || mb_strlen((string)$snapshot['name']) > 180
            || !is_numeric($snapshot['servings'] ?? null)
            || (float)$snapshot['servings'] <= 0
            || !is_array($snapshot['ingredients'] ?? null)
            || $snapshot['ingredients'] === []) {
            throw new RuntimeException('A Starter Kit recipe snapshot has an invalid recipe structure.');
        }
        foreach ($snapshot['ingredients'] as $ingredient) {
            if (!is_array($ingredient)
                || trim((string)($ingredient['ingredient_name'] ?? '')) === ''
                || mb_strlen((string)$ingredient['ingredient_name']) > 180
                || !is_numeric($ingredient['quantity'] ?? null)
                || (float)$ingredient['quantity'] <= 0
                || trim((string)($ingredient['unit'] ?? '')) === ''
                || mb_strlen((string)$ingredient['unit']) > 30) {
                throw new RuntimeException('A Starter Kit recipe snapshot has an invalid ingredient structure.');
            }
        }
    }

    private function provisionRecipes(int $versionId, int $householdId, int $memberId, int $activationId): void
    {
        $recipes = $this->pdo->prepare(
            'SELECT snapshot_hash, recipe_snapshot FROM starter_kit_recipe_snapshots
             WHERE starter_kit_version_id = ? ORDER BY id'
        );
        $recipes->execute([$versionId]);
        foreach ($recipes->fetchAll() as $row) {
            $raw = (string)$row['recipe_snapshot'];
            if (!hash_equals((string)$row['snapshot_hash'], hash('sha256', $raw))) {
                throw new RuntimeException('A Starter Kit recipe snapshot failed its integrity check.');
            }
            $recipe = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($recipe)) {
                throw new RuntimeException('A Starter Kit recipe snapshot is invalid.');
            }
            $this->validateRecipeSnapshot($recipe);

            $insert = $this->pdo->prepare(
                "INSERT INTO recipes
                 (household_id, name, category, servings, yield_quantity, yield_unit, prep_minutes,
                  cook_minutes, rest_minutes, status, instructions, notes, created_by_member_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)"
            );
            $insert->execute([
                $householdId,
                $recipe['name'],
                $recipe['category'] ?? null,
                $recipe['servings'],
                $recipe['yield_quantity'] ?? null,
                $recipe['yield_unit'] ?? null,
                $recipe['prep_minutes'] ?? null,
                $recipe['cook_minutes'] ?? null,
                $recipe['rest_minutes'] ?? null,
                $recipe['instructions'] ?? null,
                'Provisioned from starter-kit activation #' . $activationId,
                $memberId,
            ]);
            $newRecipeId = (int)$this->pdo->lastInsertId();
            $ingredientInsert = $this->pdo->prepare(
                'INSERT INTO recipe_ingredients
                 (recipe_id, inventory_item_id, ingredient_name, quantity, unit, optional, sort_order)
                 VALUES (?, NULL, ?, ?, ?, ?, ?)'
            );
            foreach ($recipe['ingredients'] as $ingredient) {
                $ingredientInsert->execute([
                    $newRecipeId,
                    $ingredient['ingredient_name'],
                    $ingredient['quantity'],
                    $ingredient['unit'],
                    !empty($ingredient['optional']) ? 1 : 0,
                    max(0, (int)($ingredient['sort_order'] ?? 0)),
                ]);
            }
        }
    }

    private function provisionTasks(int $versionId, int $householdId, int $memberId, int $activationId): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO household_tasks
             (household_id, assigned_member_id, title, description, related_type, related_id,
              due_at, recurrence_rule, priority, status)
             SELECT ?, ?, title, instructions, 'starter_kit_activation', ?,
                    DATE_ADD(UTC_TIMESTAMP(), INTERVAL due_offset_days DAY), recurring_rule, 'medium', 'planned'
             FROM starter_kit_tasks WHERE starter_kit_version_id = ?"
        );
        $statement->execute([$householdId, $memberId, $activationId, $versionId]);
    }

    private function draftVersion(int $versionId, bool $lock = false): array
    {
        $sql = 'SELECT v.id, v.status, k.status AS kit_status
                FROM starter_kit_versions v
                JOIN starter_kits k ON k.id = v.starter_kit_id
                WHERE v.id = ? LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$versionId]);
        $version = $statement->fetch();
        if (!is_array($version) || $version['status'] !== 'draft' || $version['kit_status'] === 'retired') {
            throw new RuntimeException('Published and retired kit versions are immutable. Create a new draft version.');
        }

        return $version;
    }

    private function activeShoppingList(int $householdId): int
    {
        $lock = $this->pdo->prepare('SELECT id FROM households WHERE id = ? FOR UPDATE');
        $lock->execute([$householdId]);
        if (!$lock->fetchColumn()) {
            throw new RuntimeException('The household could not be verified.');
        }
        $statement = $this->pdo->prepare(
            "SELECT id FROM shopping_lists
             WHERE household_id = ? AND status IN ('draft','active') ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([$householdId]);
        $id = (int)$statement->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $statement = $this->pdo->prepare(
            "INSERT INTO shopping_lists (household_id, name, status)
             VALUES (?, 'Starter Kit Shopping List', 'active')"
        );
        $statement->execute([$householdId]);

        return (int)$this->pdo->lastInsertId();
    }

    private function defaultLocation(int $householdId, string $type): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM storage_locations
             WHERE household_id = ? AND (? = '' OR location_type = ?) ORDER BY id LIMIT 1"
        );
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

    private function nullableDecimal(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be a number.');
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            throw new InvalidArgumentException($field . ' must be a finite number.');
        }

        return $number;
    }

    private function text(mixed $value, string $field, int $maximum, bool $required = false): ?string
    {
        $text = trim((string)$value);
        if ($required && $text === '') {
            throw new InvalidArgumentException($field . ' is required.');
        }
        if (mb_strlen($text) > $maximum) {
            throw new InvalidArgumentException($field . ' is too long.');
        }

        return $text === '' ? null : $text;
    }
}
