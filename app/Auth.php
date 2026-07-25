<?php

declare(strict_types=1);

namespace Homestead;

use PDO;
use RuntimeException;

final class Auth
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function user(): ?array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT u.id, u.email, u.display_name, u.status,
                    hm.id AS member_id, hm.household_id, hm.role, hm.age_group,
                    hm.permission_overrides, hm.status AS member_status
             FROM users u
             JOIN household_members hm ON hm.user_id = u.id
             WHERE u.id = ? AND u.status = 'active' AND hm.status = 'active'
             LIMIT 1"
        );
        $statement->execute([$userId]);
        $user = $statement->fetch();

        if (!is_array($user)) {
            $this->logout();
            return null;
        }

        return $user;
    }

    public function attempt(string $email, string $password): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.password_hash, hm.id AS member_id, hm.household_id
             FROM users u
             JOIN household_members hm ON hm.user_id = u.id
             WHERE LOWER(u.email) = LOWER(?)
               AND u.status = 'active'
               AND hm.status = 'active'
             LIMIT 1"
        );
        $statement->execute([trim($email)]);
        $record = $statement->fetch();

        if (!is_array($record) || !password_verify($password, (string)$record['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$record['id'];
        $_SESSION['member_id'] = (int)$record['member_id'];
        $_SESSION['household_id'] = (int)$record['household_id'];
        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['household_id']);
        session_regenerate_id(true);
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if ($user === null) {
            $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? '/phase3.php';
            header('Location: /login.php', true, 303);
            exit;
        }
        return $user;
    }

    public function can(array $user, string $permission): bool
    {
        if ($user['role'] === 'owner') {
            return true;
        }

        $defaults = [
            'administrator' => ['members.manage', 'members.invite', 'permissions.manage', 'storage.manage', 'inventory.manage'],
            'adult_member' => ['storage.view', 'inventory.view', 'inventory.manage', 'tasks.manage'],
            'youth_member' => ['storage.view', 'inventory.view', 'tasks.complete'],
            'guest_helper' => ['tasks.complete'],
        ];

        $allowed = $defaults[(string)$user['role']] ?? [];
        $overrides = json_decode((string)($user['permission_overrides'] ?? '[]'), true);
        if (is_array($overrides)) {
            foreach ($overrides as $key => $value) {
                if ($value === true && !in_array($key, $allowed, true)) {
                    $allowed[] = (string)$key;
                }
                if ($value === false) {
                    $allowed = array_values(array_filter($allowed, static fn(string $item): bool => $item !== $key));
                }
            }
        }

        return in_array($permission, $allowed, true);
    }

    public function requirePermission(array $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            http_response_code(403);
            throw new RuntimeException('You do not have permission to perform this action.');
        }
    }
}
