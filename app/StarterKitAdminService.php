<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class StarterKitAdminService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function duplicateVersion(int $sourceVersionId, int $newVersionNumber, string $newSku): int
    {
        $newSku = strtoupper(trim($newSku));
        if ($sourceVersionId < 1 || $newVersionNumber < 1 || $newVersionNumber > 100000) {
            throw new InvalidArgumentException('Choose a source version and a valid new version number.');
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,99}$/', $newSku)) {
            throw new InvalidArgumentException('The new SKU must contain 2–100 uppercase letters, numbers, periods, underscores, or hyphens.');
        }

        try {
            $this->pdo->beginTransaction();
            $source = $this->pdo->prepare(
                "SELECT v.*, k.status AS kit_status
                 FROM starter_kit_versions v
                 JOIN starter_kits k ON k.id = v.starter_kit_id
                 WHERE v.id = ? FOR UPDATE"
            );
            $source->execute([$sourceVersionId]);
            $version = $source->fetch();
            if (!is_array($version) || $version['kit_status'] === 'retired') {
                throw new RuntimeException('The selected version cannot be duplicated.');
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO starter_kit_versions
                 (starter_kit_id, version_number, sku, price, currency_code, status, published_at)
                 VALUES (?, ?, ?, ?, ?, 'draft', NULL)"
            );
            $insert->execute([
                $version['starter_kit_id'], $newVersionNumber, $newSku,
                $version['price'], $version['currency_code'],
            ]);
            $newVersionId = (int)$this->pdo->lastInsertId();

            $copyItems = $this->pdo->prepare(
                'INSERT INTO starter_kit_items
                 (starter_kit_version_id, item_name, item_kind, fulfillment_type, required,
                  delivery_eligible, shipping_eligible, default_quantity, unit, inventory_category_id,
                  suggested_storage_type, reorder_level, estimated_price, supplier_name, inventory_metadata,
                  sort_order)
                 SELECT ?, item_name, item_kind, fulfillment_type, required,
                        delivery_eligible, shipping_eligible, default_quantity, unit, inventory_category_id,
                        suggested_storage_type, reorder_level, estimated_price, supplier_name, inventory_metadata,
                        sort_order
                 FROM starter_kit_items WHERE starter_kit_version_id = ?'
            );
            $copyItems->execute([$newVersionId, $sourceVersionId]);

            $copyRecipes = $this->pdo->prepare(
                'INSERT INTO starter_kit_recipes (starter_kit_version_id, recipe_id)
                 SELECT ?, recipe_id FROM starter_kit_recipes WHERE starter_kit_version_id = ?'
            );
            $copyRecipes->execute([$newVersionId, $sourceVersionId]);

            $copySnapshots = $this->pdo->prepare(
                'INSERT INTO starter_kit_recipe_snapshots
                 (starter_kit_version_id, source_recipe_id, snapshot_hash, recipe_snapshot)
                 SELECT ?, source_recipe_id, snapshot_hash, recipe_snapshot
                 FROM starter_kit_recipe_snapshots WHERE starter_kit_version_id = ?'
            );
            $copySnapshots->execute([$newVersionId, $sourceVersionId]);

            $copyTasks = $this->pdo->prepare(
                'INSERT INTO starter_kit_tasks
                 (starter_kit_version_id, title, area, due_offset_days, recurring_rule, instructions, sort_order)
                 SELECT ?, title, area, due_offset_days, recurring_rule, instructions, sort_order
                 FROM starter_kit_tasks WHERE starter_kit_version_id = ?'
            );
            $copyTasks->execute([$newVersionId, $sourceVersionId]);

            $this->pdo->commit();
            return $newVersionId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function retireVersion(int $versionId): void
    {
        if ($versionId < 1) {
            throw new InvalidArgumentException('Choose a version to retire.');
        }

        try {
            $this->pdo->beginTransaction();
            $query = $this->pdo->prepare(
                'SELECT starter_kit_id, status FROM starter_kit_versions WHERE id = ? FOR UPDATE'
            );
            $query->execute([$versionId]);
            $version = $query->fetch();
            if (!is_array($version) || $version['status'] === 'retired') {
                throw new RuntimeException('The selected version is unavailable or already retired.');
            }

            $update = $this->pdo->prepare(
                "UPDATE starter_kit_versions SET status = 'retired' WHERE id = ? AND status <> 'retired'"
            );
            $update->execute([$versionId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The version changed before it could be retired.');
            }

            $publishedCount = $this->pdo->prepare(
                "SELECT COUNT(*) FROM starter_kit_versions
                 WHERE starter_kit_id = ? AND status = 'published'"
            );
            $publishedCount->execute([(int)$version['starter_kit_id']]);
            if ((int)$publishedCount->fetchColumn() === 0) {
                $this->pdo->prepare(
                    "UPDATE starter_kits SET status = 'draft'
                     WHERE id = ? AND status = 'published'"
                )->execute([(int)$version['starter_kit_id']]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function retireKit(int $kitId): void
    {
        if ($kitId < 1) {
            throw new InvalidArgumentException('Choose a starter kit to retire.');
        }

        try {
            $this->pdo->beginTransaction();
            $query = $this->pdo->prepare('SELECT status FROM starter_kits WHERE id = ? FOR UPDATE');
            $query->execute([$kitId]);
            $status = $query->fetchColumn();
            if ($status === false || $status === 'retired') {
                throw new RuntimeException('The selected starter kit is unavailable or already retired.');
            }

            $update = $this->pdo->prepare(
                "UPDATE starter_kits SET status = 'retired' WHERE id = ? AND status <> 'retired'"
            );
            $update->execute([$kitId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The starter kit changed before it could be retired.');
            }
            $this->pdo->prepare(
                "UPDATE starter_kit_versions SET status = 'retired'
                 WHERE starter_kit_id = ? AND status <> 'retired'"
            )->execute([$kitId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
