<?php

declare(strict_types=1);

use PDO;
use RuntimeException;
use Throwable;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$required = static function (string $name): string {
    $value = trim((string)getenv($name));
    if ($value === '') {
        throw new RuntimeException($name . ' is required.');
    }
    return $value;
};

try {
    $host = trim((string)(getenv('DB_HOST') ?: '127.0.0.1'));
    $port = (int)(getenv('DB_PORT') ?: 3306);
    $database = $required('DB_NAME');
    $databaseUser = $required('DB_USER');
    $databasePassword = (string)getenv('DB_PASSWORD');
    $email = strtolower($required('HOMESTEAD_OWNER_EMAIL'));
    $password = $required('HOMESTEAD_OWNER_PASSWORD');
    $displayName = $required('HOMESTEAD_OWNER_NAME');
    $householdName = trim((string)(getenv('HOMESTEAD_HOUSEHOLD_NAME') ?: 'My Homestead'));
    $householdSlug = strtolower(trim((string)(getenv('HOMESTEAD_HOUSEHOLD_SLUG') ?: 'my-homestead'));
    $householdTimezone = trim((string)(getenv('HOMESTEAD_HOUSEHOLD_TIMEZONE') ?: 'America/Phoenix'));
    $platformAdmin = filter_var(getenv('HOMESTEAD_PLATFORM_ADMIN') ?: '0', FILTER_VALIDATE_BOOL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new RuntimeException('HOMESTEAD_OWNER_EMAIL must be a valid email address.');
    }
    if (strlen($password) < 14 || strlen($password) > 4096) {
        throw new RuntimeException('HOMESTEAD_OWNER_PASSWORD must contain 14–4096 characters.');
    }
    if ($displayName === '' || $householdName === '' || mb_strlen($displayName) > 120 || mb_strlen($householdName) > 160) {
        throw new RuntimeException('Owner and household names are required and must fit their limits.');
    }
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $householdSlug) || strlen($householdSlug) > 180) {
        throw new RuntimeException('HOMESTEAD_HOUSEHOLD_SLUG must be lowercase and hyphenated.');
    }
    if (!in_array($householdTimezone, timezone_identifiers_list(), true)) {
        throw new RuntimeException('HOMESTEAD_HOUSEHOLD_TIMEZONE must be a valid IANA timezone.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $pdo = new PDO($dsn, $databaseUser, $databasePassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->beginTransaction();

    $householdQuery = $pdo->prepare('SELECT id FROM households WHERE slug = ? FOR UPDATE');
    $householdQuery->execute([$householdSlug]);
    $householdId = (int)$householdQuery->fetchColumn();
    if ($householdId < 1) {
        $insert = $pdo->prepare(
            "INSERT INTO households (name, slug, timezone, measurement_system, currency_code)
             VALUES (?, ?, ?, 'us', 'USD')"
        );
        $insert->execute([$householdName, $householdSlug, $householdTimezone]);
        $householdId = (int)$pdo->lastInsertId();
    }

    $userQuery = $pdo->prepare('SELECT id, status, password_hash FROM users WHERE LOWER(email) = LOWER(?) FOR UPDATE');
    $userQuery->execute([$email]);
    $user = $userQuery->fetch();
    if (is_array($user)) {
        if ($user['status'] !== 'active') {
            throw new RuntimeException('An account exists for this email but is not active.');
        }
        if (!password_verify($password, (string)$user['password_hash'])) {
            throw new RuntimeException('The existing account password does not match HOMESTEAD_OWNER_PASSWORD.');
        }
        $userId = (int)$user['id'];

        $otherMembership = $pdo->prepare(
            "SELECT household_id FROM household_members
             WHERE user_id = ? AND household_id <> ? AND status = 'active' LIMIT 1 FOR UPDATE"
        );
        $otherMembership->execute([$userId, $householdId]);
        if ($otherMembership->fetchColumn()) {
            throw new RuntimeException('This account already belongs to another active household; multi-household login is not enabled.');
        }
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Password processing failed.');
        }
        $insert = $pdo->prepare(
            "INSERT INTO users (email, password_hash, display_name, status, is_platform_admin)
             VALUES (?, ?, ?, 'active', ?)"
        );
        $insert->execute([$email, $passwordHash, $displayName, $platformAdmin ? 1 : 0]);
        $userId = (int)$pdo->lastInsertId();
    }

    if ($platformAdmin) {
        $pdo->prepare('UPDATE users SET is_platform_admin = 1 WHERE id = ?')->execute([$userId]);
    }

    $memberQuery = $pdo->prepare(
        "SELECT id FROM household_members
         WHERE household_id = ? AND user_id = ? AND role = 'owner' AND status = 'active' FOR UPDATE"
    );
    $memberQuery->execute([$householdId, $userId]);
    $memberId = (int)$memberQuery->fetchColumn();

    if ($memberId < 1) {
        $unlinked = $pdo->prepare(
            "SELECT id FROM household_members
             WHERE household_id = ? AND user_id IS NULL AND role = 'owner' AND status = 'active'
             ORDER BY id LIMIT 1 FOR UPDATE"
        );
        $unlinked->execute([$householdId]);
        $memberId = (int)$unlinked->fetchColumn();
        if ($memberId > 0) {
            $update = $pdo->prepare('UPDATE household_members SET user_id = ?, display_name = ? WHERE id = ? AND user_id IS NULL');
            $update->execute([$userId, $displayName, $memberId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The existing owner membership changed during bootstrap.');
            }
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO household_members
                 (household_id, user_id, display_name, age_group, role, status, serving_multiplier,
                  activity_level, wellness_visibility, joined_at)
                 VALUES (?, ?, ?, 'adult', 'owner', 'active', 1.00, 'not_set', 'private', CURRENT_DATE)"
            );
            $insert->execute([$householdId, $userId, $displayName]);
            $memberId = (int)$pdo->lastInsertId();
        }
    }

    $pdo->commit();
    fwrite(STDOUT, sprintf(
        "Owner ready: user=%d household=%d member=%d%s\n",
        $userId,
        $householdId,
        $memberId,
        $platformAdmin ? ' platform-admin' : ''
    ));
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Owner bootstrap failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
