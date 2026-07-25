<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};

$phase2 = $read('phase2.php');
$phase3 = $read('phase3.php');
$login = $read('login.php');
$invite = $read('accept-invite.php');
$account = $read('account.php');
$context = $read('app/HouseholdContext.php');
$auth = $read('app/Auth.php');
$support = $read('app/Support.php');
$bootstrap = $read('app/bootstrap.php');
$healthFiles = [
    $read('api/phase2-health.php'),
    $read('api/phase3-health.php'),
    $read('api/phase4-health.php'),
    $read('api/phase5-health.php'),
];

$checks = [
    'Phase 2 requires authenticated user' => str_contains($phase2, '$auth->requireUser()'),
    'Phase 2 member writes require permission' => str_contains($phase2, '$auth->requirePermission($user, \'members.manage\')'),
    'Phase 2 storage writes require permission' => str_contains($phase2, '$auth->requirePermission($user, \'storage.manage\')'),
    'Phase 2 inventory writes require permission' => str_contains($phase2, '$auth->requirePermission($user, \'inventory.manage\')'),
    'Phase 2 validates household storage ownership' => str_contains($phase2, 'The storage location is invalid.'),
    'Household context has no first-household fallback' => !str_contains($context, 'SELECT id FROM households ORDER BY id ASC LIMIT 1'),
    'Household context binds user member and household' => str_contains($context, 'user_id = ?') && str_contains($context, 'household_id = ?'),
    'Auth binds session member and household' => str_contains($auth, 'hm.id = ? AND hm.household_id = ?'),
    'Phase 3 has administration access guard' => str_contains($phase3, 'You do not have permission to administer household access.'),
    'Phase 3 exposes recipe permissions' => str_contains($phase3, "'recipes.manage'") && str_contains($phase3, "'meals.manage'"),
    'Phase 3 prevents duplicate invitations' => str_contains($phase3, 'active invitation already exists'),
    'Login no longer publishes seed credential' => !str_contains($login, 'ChangeMe123!'),
    'Login rejects protocol-relative redirects' => str_contains($login, "str_starts_with($target, '//')"),
    'Login hashes failed email metadata' => str_contains($login, "'email_hash'"),
    'Login has attempt throttling' => str_contains($login, 'Too many sign-in attempts'),
    'Invitation token format is validated' => str_contains($invite, "preg_match('/^[a-f0-9]{64}$/', $token)"),
    'Invitation acceptance locks row' => str_contains($invite, 'FOR UPDATE'),
    'Invitation acceptance verifies single consume' => str_contains($invite, 'rowCount() !== 1'),
    'Account password change locks active account' => str_contains($account, "status = 'active' FOR UPDATE"),
    'Account password change has throttling' => str_contains($account, 'Too many password-change attempts'),
    'Account rotates CSRF after password change' => str_contains($account, "unset($_SESSION['csrf_token'])"),
    'Global security headers are configured' => str_contains($support, 'Content-Security-Policy') && str_contains($support, 'Strict-Transport-Security'),
    'Strict sessions are enabled' => str_contains($bootstrap, "session.use_strict_mode"),
    'Redirect helper rejects protocol-relative targets' => str_contains($support, "str_starts_with($url, '//')"),
    'Health access supports keyed or platform-admin checks' => str_contains($support, 'X_HOMESTEAD_HEALTH_KEY') && str_contains($support, "is_platform_admin"),
    'Health errors hide production exception messages' => str_contains($support, "'Health check failed.'"),
    'Every health endpoint is access controlled' => count(array_filter($healthFiles, static fn(string $file): bool => str_contains($file, 'require_health_access'))) === count($healthFiles),
    'Every health endpoint uses safe error helper' => count(array_filter($healthFiles, static fn(string $file): bool => str_contains($file, 'health_error'))) === count($healthFiles),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Application static audit failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Whole-application static security audit passed.' . PHP_EOL;
