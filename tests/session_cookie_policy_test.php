<?php

declare(strict_types=1);

use function Homestead\csrf_is_valid;
use function Homestead\request_uses_https;
use function Homestead\resolve_app_base_path;
use function Homestead\resolve_redirect_target;

require dirname(__DIR__) . '/app/Support.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
};
$expectException = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (Throwable) {
        return true;
    }
};

$config = ['app' => ['base_url' => 'https://example.test/foodfarm']];
$appRoot = '/var/www/html/foodfarm';

$assert(
    resolve_app_base_path($config, [
        'SCRIPT_FILENAME' => '/var/www/html/foodfarm/login.php',
        'SCRIPT_NAME' => '/foodfarm/login.php',
    ], $appRoot) === '/foodfarm',
    'login route derives its deployed subfolder'
);

$assert(
    resolve_app_base_path($config, [
        'SCRIPT_FILENAME' => '/var/www/html/foodfarm/api/phase11-health.php',
        'SCRIPT_NAME' => '/foodfarm/api/phase11-health.php',
    ], $appRoot) === '/foodfarm',
    'nested API routes derive the same application subfolder'
);

$assert(
    resolve_app_base_path($config, [], $appRoot) === '/foodfarm',
    'configured base URL provides a safe fallback path'
);

$assert(
    resolve_app_base_path(['app' => ['base_url' => 'https://example.test/']], [], $appRoot) === '',
    'domain-root deployments keep the root cookie path'
);

$assert(
    resolve_redirect_target('/phase2.php?section=family', ['REQUEST_URI' => '/phase2.php?section=family']) === '/phase2.php?section=family',
    'domain-root post-submit redirects remain at the domain root'
);
$assert(
    resolve_redirect_target('/phase2.php?section=family', ['REQUEST_URI' => '/foodfarm/phase2.php?section=family'], '/foodfarm') === '/foodfarm/phase2.php?section=family',
    'same-route redirects preserve a deployed subfolder path'
);
$assert(
    resolve_redirect_target('/login.php', ['REQUEST_URI' => '/foodfarm/phase2.php?section=family'], '/foodfarm') === '/foodfarm/login.php',
    'cross-route redirects use the deployed application base path'
);
$assert(
    resolve_redirect_target('/foodfarm/phase2.php?section=family', ['REQUEST_URI' => '/foodfarm/phase2.php'], '/foodfarm') === '/foodfarm/phase2.php?section=family',
    'already-prefixed redirect targets are not double-prefixed'
);
$assert(
    resolve_redirect_target('/phase4.php#meal-planning', ['REQUEST_URI' => '/foodfarm/phase4.php'], '/foodfarm') === '/foodfarm/phase4.php#meal-planning',
    'same-route redirects retain fragments inside a deployed subfolder'
);
$assert(
    $expectException(static fn() => resolve_redirect_target('//attacker.test/phase2.php', [], '/foodfarm')),
    'protocol-relative redirect targets remain blocked'
);

$assert(request_uses_https($config, []) === true, 'configured HTTPS enables Secure cookies behind a proxy');
$assert(request_uses_https(['app' => ['base_url' => 'http://example.test/foodfarm']], ['HTTPS' => 'on']) === true, 'direct HTTPS enables Secure cookies');
$assert(request_uses_https(['app' => ['base_url' => 'http://example.test/foodfarm']], ['SERVER_PORT' => 443]) === true, 'HTTPS port enables Secure cookies');
$assert(request_uses_https(['app' => ['base_url' => 'http://example.test/foodfarm']], ['HTTP_X_FORWARDED_PROTO' => 'https']) === true, 'forwarded HTTPS enables Secure cookies');
$assert(request_uses_https(['app' => ['base_url' => 'http://example.test/foodfarm']], []) === false, 'plain HTTP does not emit an unusable Secure cookie');

$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$assert(csrf_is_valid(str_repeat('a', 64)) === true, 'matching CSRF token remains valid');
$assert(csrf_is_valid(str_repeat('b', 64)) === false, 'mismatched CSRF token is rejected');
$assert(csrf_is_valid(null) === false, 'missing CSRF token is rejected');

$loginSource = (string)file_get_contents(dirname(__DIR__) . '/login.php');
$assert(str_contains($loginSource, 'csrf_is_valid'), 'login handles an expired form session inside its styled error flow');
$assert(!str_contains($loginSource, 'verify_csrf($_POST'), 'login no longer exits before rendering its error panel');

$supportSource = (string)file_get_contents(dirname(__DIR__) . '/app/Support.php');
$assert(str_contains($supportSource, 'resolve_redirect_target'), 'redirects use the deployment-aware route resolver');

$phase2Source = (string)file_get_contents(dirname(__DIR__) . '/phase2.php');
$assert(str_contains($phase2Source, "redirect('/phase2.php?section=family')"), 'Phase 2 family writes use the centralized redirect helper');

echo "Session, redirect, and login policy test passed.\n";
