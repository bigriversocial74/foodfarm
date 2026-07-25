<?php

declare(strict_types=1);

use Homestead\Auth;

require dirname(__DIR__) . '/app/Auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_id(bin2hex(random_bytes(16)));
    session_start();
}

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
$auth = new Auth($pdo);
$email = (string)getenv('HOMESTEAD_OWNER_EMAIL');
$password = (string)getenv('HOMESTEAD_OWNER_PASSWORD');
if ($email === '' || $password === '') {
    fwrite(STDERR, "Owner credentials are required for authentication integration.\n");
    exit(1);
}

$_SESSION = ['csrf_token' => str_repeat('a', 64)];
if ($auth->user() !== null || ($_SESSION['csrf_token'] ?? null) !== str_repeat('a', 64)) {
    fwrite(STDERR, "Anonymous session state was cleared while checking authentication.\n");
    exit(1);
}
echo "[PASS] anonymous authentication checks preserve CSRF state\n";

$activationToken = bin2hex(random_bytes(32));
$_SESSION = [
    'csrf_token' => str_repeat('b', 64),
    'intended_url' => '/activate-kit.php',
    'starter_kit_activation_token' => $activationToken,
];
if (!$auth->attempt($email, $password)) {
    fwrite(STDERR, "Owner authentication failed.\n");
    exit(1);
}
$required = ['user_id', 'member_id', 'household_id', 'auth_version', 'authenticated_at', 'last_activity_at', 'session_rotated_at'];
foreach ($required as $key) {
    if (empty($_SESSION[$key])) {
        fwrite(STDERR, "Authenticated session is missing {$key}.\n");
        exit(1);
    }
}
if (($_SESSION['starter_kit_activation_token'] ?? null) !== $activationToken
    || ($_SESSION['intended_url'] ?? null) !== '/activate-kit.php'
    || isset($_SESSION['csrf_token'])) {
    fwrite(STDERR, "Login did not preserve the safe handoff fields or retained stale CSRF state.\n");
    exit(1);
}
echo "[PASS] login preserves only safe activation handoff state\n";

$user = $auth->user();
if (!is_array($user) || (int)$user['id'] !== (int)$_SESSION['user_id']) {
    fwrite(STDERR, "Authenticated session could not be resolved.\n");
    exit(1);
}
echo "[PASS] authenticated expiring session resolves after login\n";

$_SESSION['last_activity_at'] = time() - 7201;
if ($auth->user() !== null || $_SESSION !== []) {
    fwrite(STDERR, "Expired idle session was not cleared.\n");
    exit(1);
}
echo "[PASS] idle expiration clears authenticated and activation state\n";

echo "Authentication login integration suite passed.\n";
