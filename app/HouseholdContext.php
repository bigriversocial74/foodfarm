<?php

declare(strict_types=1);

namespace Homestead;

use PDO;
use RuntimeException;

final class HouseholdContext
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function id(): int
    {
        $householdId = (int)($_SESSION['household_id'] ?? 0);
        $memberId = (int)($_SESSION['member_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($householdId < 1 || $memberId < 1 || $userId < 1) {
            throw new RuntimeException('An authenticated household context is required.');
        }

        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM household_members
             WHERE id = ? AND household_id = ? AND user_id = ? AND status = 'active'
             LIMIT 1"
        );
        $statement->execute([$memberId, $householdId, $userId]);
        if (!$statement->fetchColumn()) {
            $this->clear();
            throw new RuntimeException('The active household context is invalid. Sign in again.');
        }

        return $householdId;
    }

    public function memberId(): int
    {
        $this->id();
        return (int)$_SESSION['member_id'];
    }

    private function clear(): void
    {
        unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['household_id']);
    }
}
