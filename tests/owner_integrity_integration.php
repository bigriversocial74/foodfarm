<?php

declare(strict_types=1);

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    (int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_NAME') ?: 'homestead'
);
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$owner = $pdo->query(
    "SELECT hm.id, hm.household_id FROM household_members hm
     JOIN users u ON u.id = hm.user_id
     WHERE hm.role = 'owner' AND hm.status = 'active' AND u.status = 'active'
     ORDER BY hm.id LIMIT 1"
)->fetch();
if (!is_array($owner)) {
    fwrite(STDERR, "A bootstrapped household owner is required.\n");
    exit(1);
}
$ownerId = (int)$owner['id'];
$householdId = (int)$owner['household_id'];

$throws = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (Throwable) {
        return true;
    }
};

if (!$throws(static fn() => $pdo->prepare("UPDATE household_members SET status = 'inactive' WHERE id = ?")->execute([$ownerId]))) {
    fwrite(STDERR, "Owner deactivation was allowed.\n");
    exit(1);
}
echo "[PASS] owner cannot be deactivated\n";

if (!$throws(static fn() => $pdo->prepare('DELETE FROM household_members WHERE id = ?')->execute([$ownerId]))) {
    fwrite(STDERR, "Owner deletion was allowed.\n");
    exit(1);
}
echo "[PASS] owner cannot be deleted\n";

$pdo->prepare("INSERT INTO users (email, password_hash, display_name, status) VALUES ('second-owner@example.test', ?, 'Second Owner', 'active')")
    ->execute([password_hash('SecondOwnerIntegration!2026', PASSWORD_DEFAULT)]);
$secondUserId = (int)$pdo->lastInsertId();
if (!$throws(static fn() => $pdo->prepare(
    "INSERT INTO household_members
     (household_id, user_id, display_name, age_group, role, status)
     VALUES (?, ?, 'Second Owner', 'adult', 'owner', 'active')"
)->execute([$householdId, $secondUserId]))) {
    fwrite(STDERR, "A second owner was allowed.\n");
    exit(1);
}
echo "[PASS] a household cannot gain a second owner\n";

$pdo->prepare(
    "INSERT INTO household_members
     (household_id, user_id, display_name, age_group, role, status)
     VALUES (?, ?, 'Second Owner', 'adult', 'adult_member', 'active')"
)->execute([$householdId, $secondUserId]);
$memberId = (int)$pdo->lastInsertId();
if (!$throws(static fn() => $pdo->prepare("UPDATE household_members SET role = 'owner' WHERE id = ?")->execute([$memberId]))) {
    fwrite(STDERR, "Ownership was assigned through a member update.\n");
    exit(1);
}
echo "[PASS] ownership cannot be assigned through role editing\n";

$status = $pdo->query('SELECT status FROM household_members WHERE id = ' . $ownerId)->fetchColumn();
if ($status !== 'active') {
    fwrite(STDERR, "Owner status changed during rejected operations.\n");
    exit(1);
}
echo "Owner integrity integration suite passed.\n";
