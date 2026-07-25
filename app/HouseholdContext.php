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
        if (!empty($_SESSION['household_id'])) {
            return (int)$_SESSION['household_id'];
        }

        $id = (int)$this->pdo->query('SELECT id FROM households ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($id < 1) {
            throw new RuntimeException('No household exists. Import database/phase2_install.sql first.');
        }

        $_SESSION['household_id'] = $id;
        return $id;
    }

    public function memberId(): ?int
    {
        if (!empty($_SESSION['member_id'])) {
            return (int)$_SESSION['member_id'];
        }

        $statement = $this->pdo->prepare("SELECT id FROM household_members WHERE household_id = ? AND status = 'active' ORDER BY FIELD(role, 'owner','administrator','adult_member','youth_member','guest_helper'), id LIMIT 1");
        $statement->execute([$this->id()]);
        $id = (int)$statement->fetchColumn();
        if ($id > 0) {
            $_SESSION['member_id'] = $id;
            return $id;
        }

        return null;
    }
}
