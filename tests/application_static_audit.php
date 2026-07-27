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
$phase4 = $read('phase4.php');
$phase5 = $read('phase5.php');
$phase6 = $read('phase6.php');
$phase7 = $read('phase7.php');
$phase8 = $read('phase8.php');
$phase9 = $read('phase9.php');
$phase10 = $read('phase10.php');
$phase11 = $read('phase11.php');
$login = $read('login.php');
$logout = $read('logout.php');
$invite = $read('accept-invite.php');
$activation = $read('activate-kit.php');
$account = $read('account.php');
$context = $read('app/HouseholdContext.php');
$auth = $read('app/Auth.php');
$database = $read('app/Database.php');
$support = $read('app/Support.php');
$bootstrap = $read('app/bootstrap.php');
$ownerBootstrap = $read('bin/create-owner.php');
$phase3Migration = $read('database/phase3_install.sql');
$phase4Hardening = $read('database/phase4_hardening.sql');
$phase5Migration = $read('database/phase5_hardening.sql');
$css = $read('assets/css/app.css');
$workflow = $read('.github/workflows/php-lint.yml');
$healthFiles = [
    $read('api/phase2-health.php'),
    $read('api/phase3-health.php'),
    $read('api/phase4-health.php'),
    $read('api/phase5-health.php'),
    $read('api/phase6-health.php'),
    $read('api/phase7-health.php'),
    $read('api/phase8-health.php'),
    $read('api/phase9-health.php'),
    $read('api/phase10-health.php'),
    $read('api/phase11-health.php'),
];

$checks = [
    'Phase 2 requires authenticated user' => str_contains($phase2, '$auth->requireUser()'),
    'Phase 2 member writes require permission' => str_contains($phase2, '$auth->requirePermission($user, \'members.manage\')'),
    'Phase 2 storage writes require permission' => str_contains($phase2, '$auth->requirePermission($user, \'storage.manage\')'),
    'Phase 2 inventory writes require permission' => str_contains($phase2, '$auth->requirePermission($user, \'inventory.manage\')'),
    'Phase 2 validates household storage ownership' => str_contains($phase2, 'The storage location is invalid.'),
    'Household context has no first-household fallback' => !str_contains($context, 'SELECT id FROM households ORDER BY id ASC LIMIT 1'),
    'Household context binds user member and household' => str_contains($context, 'user_id = ?') && str_contains($context, 'household_id = ?'),
    'Auth binds session member household and auth version' => str_contains($auth, 'hm.id = ? AND hm.household_id = ?') && str_contains($auth, 'u.auth_version = ?'),
    'Auth clears session identity during logout' => str_contains($auth, '$_SESSION = []'),
    'Auth uses centralized safe login redirect' => str_contains($auth, "redirect('/login.php')"),
    'Role permission defaults include task finance nutrition and notification permissions' => str_contains($auth, "'storage.view'") && str_contains($auth, "'tasks.complete'") && str_contains($auth, "'finance.view'") && str_contains($auth, "'finance.manage'") && str_contains($auth, "'nutrition.view'") && str_contains($auth, "'nutrition.manage'") && str_contains($auth, "'notifications.view'") && str_contains($auth, "'notifications.manage'"),
    'Phase 3 has administration access guard' => str_contains($phase3, 'You do not have permission to administer household access.'),
    'Phase 3 serializes invitation creation' => str_contains($phase3, 'SELECT id FROM households WHERE id = ? FOR UPDATE'),
    'Phase 3 prevents duplicate invitations' => str_contains($phase3, 'active invitation already exists'),
    'Phase 3 records invitation revocation' => str_contains($phase3, "'invitation_revoked'"),
    'Login no longer publishes seed credential' => !str_contains($login, 'ChangeMe123!'),
    'Login returns successful authentication to its fixed route' => str_contains($login, "redirect('/login.php')") && !str_contains($login, "redirect('/phase3.php')"),
    'Login hashes failed email metadata' => str_contains($login, 'email_hash'),
    'Login has persistent attempt throttling' => str_contains($login, 'authentication_events') && str_contains($login, 'INTERVAL 15 MINUTE'),
    'Invitation token format is validated' => str_contains($invite, "preg_match('/^[a-f0-9]{64}$/', \$token)"),
    'Invitation acceptance locks row' => str_contains($invite, 'FOR UPDATE'),
    'Invitation acceptance verifies single consume' => str_contains($invite, 'rowCount() !== 1'),
    'Invitation session binds auth version' => str_contains($invite, "\$_SESSION['auth_version'] = 1"),
    'Account password change locks active account' => str_contains($account, "status = 'active' FOR UPDATE"),
    'Account password change has persistent throttling' => str_contains($account, 'password_change_failure') && str_contains($account, 'INTERVAL 15 MINUTE'),
    'Password change invalidates other sessions' => str_contains($account, 'auth_version = ?') && str_contains($account, 'newAuthVersion'),
    'Logout is POST and CSRF protected' => str_contains($logout, "REQUEST_METHOD'] === 'POST'") && str_contains($logout, 'verify_csrf'),
    'Logout survives audit-log failure' => str_contains($logout, 'logout audit failed') && str_contains($logout, '$auth->logout()'),
    'Global security headers are configured' => str_contains($support, 'Content-Security-Policy') && str_contains($support, 'Strict-Transport-Security'),
    'HTTPS content upgrade is production-only' => str_contains($support, "$contentSecurityPolicy .= '; upgrade-insecure-requests'") && str_contains($support, 'if ($isProduction)'),
    'Internal failures have a safe browser-message helper' => str_contains($support, 'function user_error_message') && str_contains($support, 'PDOException'),
    'Strict sessions are enabled' => str_contains($bootstrap, 'session.use_strict_mode') && str_contains($bootstrap, 'session.use_trans_sid'),
    'Production configuration is validated' => str_contains($bootstrap, 'random health-check key') && str_contains($bootstrap, 'explicit database credentials'),
    'Recipe nonce supports subdirectory deployments' => str_contains($bootstrap, '$routeName = basename($requestPath)') && str_contains($bootstrap, "['phase4.php', 'prepared-food.php']"),
    'Redirect helper rejects protocol-relative targets' => str_contains($support, "str_starts_with(\$url, '//')"),
    'Health access supports keyed or platform-admin checks' => str_contains($support, 'HTTP_X_HOMESTEAD_HEALTH_KEY') && str_contains($support, 'is_platform_admin'),
    'Health errors hide production exception messages' => str_contains($support, "'Health check failed.'"),
    'Every health endpoint is access controlled' => count(array_filter($healthFiles, static fn(string $file): bool => str_contains($file, 'require_health_access'))) === count($healthFiles),
    'Every health endpoint uses safe error helper' => count(array_filter($healthFiles, static fn(string $file): bool => str_contains($file, 'health_error'))) === count($healthFiles),
    'Owner bootstrap is CLI only' => str_contains($ownerBootstrap, "PHP_SAPI !== 'cli'"),
    'Owner bootstrap requires explicit strong password' => str_contains($ownerBootstrap, 'HOMESTEAD_OWNER_PASSWORD') && str_contains($ownerBootstrap, 'strlen($password) < 14'),
    'Owner bootstrap verifies existing account password' => str_contains($ownerBootstrap, 'password_verify($password'),
    'Owner bootstrap blocks cross-household ambiguity' => str_contains($ownerBootstrap, 'multi-household login is not enabled'),
    'Database configuration is constrained' => str_contains($database, 'NO_ENGINE_SUBSTITUTION') && str_contains($database, 'PDO::ATTR_TIMEOUT'),
    'Authentication version migration exists' => str_contains($phase4Hardening, 'auth_version'),
    'Authentication throttle indexes exist' => str_contains($phase4Hardening, 'idx_auth_event_ip_type_time') && str_contains($phase4Hardening, 'idx_auth_event_user_type_time'),
    'Migrations contain no known owner password seed' => !str_contains($phase3Migration, '$2y$') && !str_contains($phase5Migration, 'ORDER BY id ASC LIMIT 1'),
    'Phase 4 uses permissions' => str_contains($phase4, 'recipes.manage') && str_contains($phase4, 'recipes.complete'),
    'Phase 5 requires platform administration' => str_contains($phase5, 'requirePlatformAdmin'),
    'Phase 6 requires household permissions' => str_contains($phase6, 'garden.manage') && str_contains($phase6, 'preservation.manage'),
    'Phase 7 requires task permissions' => str_contains($phase7, 'tasks.manage') && str_contains($phase7, 'tasks.complete'),
    'Phase 8 requires forecast permissions' => str_contains($phase8, 'inventory.view') && str_contains($phase8, 'tasks.manage'),
    'Phase 9 requires finance permissions' => str_contains($phase9, 'finance.view') && str_contains($phase9, 'finance.manage'),
    'Phase 10 requires nutrition permissions and safety boundary' => str_contains($phase10, 'nutrition.view') && str_contains($phase10, 'nutrition.manage') && str_contains($phase10, 'not diagnosis, treatment, or medical advice'),
    'Phase 11 requires notification permissions and delivery boundary' => str_contains($phase11, 'notifications.view') && str_contains($phase11, 'notifications.manage') && str_contains($phase11, 'adapter-ready outside the app'),
    'Activation token suppresses referrer leakage' => str_contains($activation, 'Referrer-Policy: no-referrer'),
    'Keyboard focus treatment exists' => str_contains($css, ':focus-visible') && str_contains($css, '.skip-link'),
    'Reduced motion is supported' => str_contains($css, 'prefers-reduced-motion'),
    'High contrast mode is supported' => str_contains($css, 'forced-colors'),
    'CI validates migration replay' => str_contains($workflow, 'Replay incremental migrations'),
    'CI imports Phase 9 migration' => str_contains($workflow, 'database/phase9_cost_waste_savings_intelligence.sql'),
    'CI imports Phase 10 migration' => str_contains($workflow, 'database/phase10_nutrition_dietary_wellness.sql'),
    'CI imports Phase 11 migration' => str_contains($workflow, 'database/phase11_alerts_calendar_notifications.sql'),
    'CI runs database workflow integration' => str_contains($workflow, 'tests/workflow_integration.php'),
    'CI runs Phase 9 integration' => str_contains($workflow, 'tests/phase9_integration.php'),
    'CI runs Phase 10 integration' => str_contains($workflow, 'tests/phase10_integration.php'),
    'CI runs Phase 11 integration' => str_contains($workflow, 'tests/phase11_integration.php'),
    'CI runs authenticated HTTP smoke tests' => str_contains($workflow, 'tests/http_smoke.sh') && str_contains($workflow, 'tests/phase9_http_smoke.sh') && str_contains($workflow, 'tests/phase10_http_smoke.sh') && str_contains($workflow, 'tests/phase11_http_smoke.sh'),
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

echo 'Whole-application static security and release audit passed.' . PHP_EOL;
