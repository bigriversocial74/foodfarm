<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class StarterKitActivationAdminService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function revokeActivation(int $activationId): void
    {
        if ($activationId < 1) {
            throw new InvalidArgumentException('Choose an activation to revoke.');
        }

        try {
            $this->pdo->beginTransaction();
            $query = $this->pdo->prepare(
                'SELECT a.id, a.starter_kit_order_id, a.activated_at, a.revoked_at, o.activation_status
                 FROM starter_kit_activations a
                 JOIN starter_kit_orders o ON o.id = a.starter_kit_order_id
                 WHERE a.id = ? FOR UPDATE'
            );
            $query->execute([$activationId]);
            $activation = $query->fetch();
            if (!is_array($activation) || $activation['activated_at'] !== null
                || $activation['revoked_at'] !== null || $activation['activation_status'] !== 'pending') {
                throw new RuntimeException('Only a pending, unused activation can be revoked.');
            }

            $update = $this->pdo->prepare(
                'UPDATE starter_kit_activations SET revoked_at = UTC_TIMESTAMP()
                 WHERE id = ? AND activated_at IS NULL AND revoked_at IS NULL'
            );
            $update->execute([$activationId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The activation changed before it could be revoked.');
            }
            $this->pdo->prepare(
                "UPDATE starter_kit_orders SET activation_status = 'revoked'
                 WHERE id = ? AND activation_status = 'pending'"
            )->execute([(int)$activation['starter_kit_order_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function replaceActivation(int $orderId): string
    {
        if ($orderId < 1) {
            throw new InvalidArgumentException('Choose an order for replacement activation.');
        }

        $token = bin2hex(random_bytes(32));
        try {
            $this->pdo->beginTransaction();
            $query = $this->pdo->prepare(
                "SELECT id, starter_kit_version_id, activation_status, fulfillment_status
                 FROM starter_kit_orders WHERE id = ? FOR UPDATE"
            );
            $query->execute([$orderId]);
            $order = $query->fetch();
            if (!is_array($order) || $order['activation_status'] === 'activated'
                || $order['fulfillment_status'] === 'cancelled') {
                throw new RuntimeException('This order cannot receive a replacement activation.');
            }

            $this->pdo->prepare(
                'UPDATE starter_kit_activations SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                 WHERE starter_kit_order_id = ? AND activated_at IS NULL'
            )->execute([$orderId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO starter_kit_activations (starter_kit_order_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))'
            );
            $insert->execute([$orderId, hash('sha256', $token)]);
            $activationId = (int)$this->pdo->lastInsertId();
            $copyItems = $this->pdo->prepare(
                'INSERT INTO starter_kit_activation_items
                 (starter_kit_activation_id, starter_kit_item_id, selected_fulfillment_type,
                  confirmed_quantity, unit)
                 SELECT ?, id, fulfillment_type, default_quantity, unit
                 FROM starter_kit_items WHERE starter_kit_version_id = ?'
            );
            $copyItems->execute([$activationId, $order['starter_kit_version_id']]);
            if ($copyItems->rowCount() < 1) {
                throw new RuntimeException('The Starter Kit version no longer has activation items.');
            }
            $this->pdo->prepare(
                "UPDATE starter_kit_orders SET activation_status = 'pending' WHERE id = ?"
            )->execute([$orderId]);
            $this->pdo->commit();
            return $token;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelOrder(int $orderId): void
    {
        if ($orderId < 1) {
            throw new InvalidArgumentException('Choose an order to cancel.');
        }

        try {
            $this->pdo->beginTransaction();
            $query = $this->pdo->prepare(
                'SELECT fulfillment_status, activation_status FROM starter_kit_orders WHERE id = ? FOR UPDATE'
            );
            $query->execute([$orderId]);
            $order = $query->fetch();
            if (!is_array($order) || $order['fulfillment_status'] === 'cancelled'
                || $order['activation_status'] === 'activated') {
                throw new RuntimeException('Only an unactivated, open order can be cancelled.');
            }
            $this->pdo->prepare(
                "UPDATE starter_kit_orders
                 SET fulfillment_status = 'cancelled', activation_status = 'revoked'
                 WHERE id = ? AND activation_status <> 'activated'"
            )->execute([$orderId]);
            $this->pdo->prepare(
                'UPDATE starter_kit_activations SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                 WHERE starter_kit_order_id = ? AND activated_at IS NULL'
            )->execute([$orderId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
