<?php

declare(strict_types=1);

use function Homestead\csrf_is_valid;
use function Homestead\request_uses_https;
use function Homestead\resolve_app_base_path;

require dirname(__DIR__) . '/app/Support.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
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

echo "Session cookie and login CSRF policy test passed.\n";
