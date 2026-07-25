<?php

declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Homestead is not configured. Copy config-example.php to config.php and provide deployment credentials.');
}
$config = require $configFile;
$environment = (string)($config['app']['environment'] ?? 'production');
$debug = (bool)($config['app']['debug'] ?? false);
if ($environment === 'production' && $debug) {
    http_response_code(503);
    exit('Unsafe production configuration: debug mode must be disabled.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string)($config['security']['session_name'] ?? 'homestead_session'));
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $environment === 'production' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestPath === '/phase4.php') {
    $_SESSION['recipe_completion_key'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/HouseholdContext.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/RecipeService.php';
require_once __DIR__ . '/StarterKitService.php';

$database = new Homestead\Database($config['database'] ?? []);
$pdo = $database->connection();
$householdContext = new Homestead\HouseholdContext($pdo);
$auth = new Homestead\Auth($pdo);
