<?php

declare(strict_types=1);

namespace Homestead;

use PDO;
use RuntimeException;

final class Auth
{
    private const ABSOLUTE_SESSION_LIFETIME = 43200;
    private const IDLE_SESSION_LIFETIME = 7200;
    private const SESSION_ROTATION_INTERVAL = 900;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function user(): ?array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $memberId = (int)($_SESSION['member_id'] ?? 0);
        $householdId = (int)($_SESSION['household_id'] ?? 0);
        $authVersion = (int)($_SESSION['auth_version'] ?? 0);
        if ($userId < 1 || $memberId < 1 || $householdId < 1 || $authVersion < 1) {
            return null;
        }

        $now = time();
        $authenticatedAt = (int)($_SESSION['authenticated_at'] ?? 0);
        $lastActivityAt = (int)($_SESSION['last_activity_at'] ?? 0);
        $sessionRotatedAt = (int)($_SESSION['session_rotated_at'] ?? 0);
        if ($authenticatedAt < 1 || $lastActivityAt < 1 || $sessionRotatedAt < 1
            || ($now - $authenticatedAt) > self::ABSOLUTE_SESSION_LIFETIME
            || ($now - $lastActivityAt) > self::IDLE_SESSION_LIFETIME) {
            $this->logout();
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT u.id, u.email, u.display_name, u.status, u.is_platform_admin, u.auth_version,
                    hm.id AS member_id, hm.household_id, hm.role, hm.age_group,
                    hm.permission_overrides, hm.status AS member_status
             FROM users u
             JOIN household_members hm ON hm.user_id = u.id
             WHERE u.id = ? AND hm.id = ? AND hm.household_id = ?
               AND u.auth_version = ? AND u.status = 'active' AND hm.status = 'active'
             LIMIT 1"
        );
        $statement->execute([$userId, $memberId, $householdId, $authVersion]);
        $user = $statement->fetch();

        if (!is_array($user)) {
            $this->logout();
            return null;
        }

        if (($now - $sessionRotatedAt) >= self::SESSION_ROTATION_INTERVAL) {
            $this->rotateSessionId();
            $_SESSION['session_rotated_at'] = $now;
        }
        $_SESSION['last_activity_at'] = $now;

        $user['is_platform_admin'] = (bool)$user['is_platform_admin'];
        return $user;
    }

    public function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $password === '' || strlen($password) > 4096) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "SELECT u.id, u.password_hash, u.auth_version, hm.id AS member_id, hm.household_id
             FROM users u
             JOIN household_members hm ON hm.user_id = u.id
             WHERE LOWER(u.email) = LOWER(?)
               AND u.status = 'active'
               AND hm.status = 'active'
             ORDER BY FIELD(hm.role, 'owner','administrator','adult_member','youth_member','guest_helper'), hm.id
             LIMIT 1"
        );
        $statement->execute([$email]);
        $record = $statement->fetch();

        if (!is_array($record) || !password_verify($password, (string)$record['password_hash'])) {
            return false;
        }

        if (password_needs_rehash((string)$record['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if (is_string($newHash)) {
                $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND status = 'active'")
                    ->execute([$newHash, (int)$record['id']]);
            }
        }

        $intendedUrl = $_SESSION['intended_url'] ?? null;
        $activationToken = $_SESSION['starter_kit_activation_token'] ?? null;
        $_SESSION = [];
        $this->rotateSessionId();

        if ($this->isSafeLocalUrl($intendedUrl)) {
            $_SESSION['intended_url'] = $intendedUrl;
        }
        if (is_string($activationToken) && preg_match('/^[a-f0-9]{64}$/', $activationToken) === 1) {
            $_SESSION['starter_kit_activation_token'] = $activationToken;
        }

        $now = time();
        $_SESSION['user_id'] = (int)$record['id'];
        $_SESSION['member_id'] = (int)$record['member_id'];
        $_SESSION['household_id'] = (int)$record['household_id'];
        $_SESSION['auth_version'] = (int)$record['auth_version'];
        $_SESSION['authenticated_at'] = $now;
        $_SESSION['last_activity_at'] = $now;
        $_SESSION['session_rotated_at'] = $now;
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        $this->rotateSessionId();
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if ($user === null) {
            $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/phase3.php');
            if (!$this->isSafeLocalUrl($requestUri)) {
                $requestUri = '/phase3.php';
            }
            $_SESSION['intended_url'] = $requestUri;
            header('Location: /login.php', true, 303);
            exit;
        }
        return $user;
    }

    public function requirePlatformAdmin(array $user): void
    {
        if (empty($user['is_platform_admin'])) {
            http_response_code(403);
            throw new RuntimeException('Platform administrator access is required.');
        }
    }

    public function can(array $user, string $permission): bool
    {
        if (($user['role'] ?? null) === 'owner') {
            return true;
        }

        $defaults = [
            'administrator' => [
                'members.manage', 'members.invite', 'permissions.manage',
                'storage.view', 'storage.manage', 'inventory.view', 'inventory.manage',
                'recipes.view', 'recipes.manage', 'meals.manage', 'recipes.complete',
                'garden.view', 'garden.manage', 'harvest.record',
                'preservation.view', 'preservation.manage',
                'tasks.manage', 'tasks.complete',
            ],
            'adult_member' => [
                'storage.view', 'inventory.view', 'inventory.manage',
                'tasks.manage', 'tasks.complete', 'recipes.view', 'recipes.manage',
                'meals.manage', 'recipes.complete',
                'garden.view', 'garden.manage', 'harvest.record',
                'preservation.view', 'preservation.manage',
            ],
            'youth_member' => [
                'storage.view', 'inventory.view', 'tasks.complete',
                'recipes.view', 'recipes.complete', 'garden.view', 'harvest.record',
                'preservation.view',
            ],
            'guest_helper' => ['tasks.complete', 'recipes.view', 'garden.view'],
        ];

        $allowed = $defaults[(string)($user['role'] ?? '')] ?? [];
        $overrides = json_decode((string)($user['permission_overrides'] ?? '[]'), true);
        if (is_array($overrides)) {
            foreach ($overrides as $key => $value) {
                if (!is_string($key) || !is_bool($value)) {
                    continue;
                }
                if ($value && !in_array($key, $allowed, true)) {
                    $allowed[] = $key;
                } elseif (!$value) {
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

    private function isSafeLocalUrl(mixed $url): bool
    {
        return is_string($url)
            && str_starts_with($url, '/')
            && !str_starts_with($url, '//')
            && preg_match('/[\x00-\x1F\x7F]/', $url) !== 1;
    }

    private function rotateSessionId(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }
}
