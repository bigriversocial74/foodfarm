<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('homestead_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

$configFile = dirname(__DIR__) . '/config.php';
$config = is_file($configFile) ? require $configFile : require dirname(__DIR__) . '/config-example.php';

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/HouseholdContext.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/RecipeService.php';

$database = new Homestead\Database($config['database'] ?? []);
$pdo = $database->connection();
$householdContext = new Homestead\HouseholdContext($pdo);
$auth = new Homestead\Auth($pdo);
