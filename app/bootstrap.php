<?php

declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Homestead is not configured. Copy config-example.php to config.php and provide deployment credentials.');
}
$config = require $configFile;
if (!is_array($config)) {
    http_response_code(503);
    exit('Homestead configuration is invalid.');
}

$environment = (string)($config['app']['environment'] ?? 'production');
$debug = (bool)($config['app']['debug'] ?? false);
if (!in_array($environment, ['development', 'testing', 'production'], true)) {
    http_response_code(503);
    exit('Homestead environment configuration is invalid.');
}
if ($environment === 'production' && $debug) {
    http_response_code(503);
    exit('Unsafe production configuration: debug mode must be disabled.');
}

$timezone = (string)($config['app']['timezone'] ?? 'UTC');
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    http_response_code(503);
    exit('Homestead timezone configuration is invalid.');
}
date_default_timezone_set($timezone);

if ($environment === 'production') {
    $healthKey = trim((string)($config['security']['health_key'] ?? ''));
    if (strlen($healthKey) < 32 || str_contains(strtolower($healthKey), 'replace-with')) {
        http_response_code(503);
        exit('Unsafe production configuration: provide a random health-check key of at least 32 characters.');
    }
    $databasePassword = (string)($config['database']['password'] ?? '');
    if ($databasePassword === '' || str_contains(strtolower($databasePassword), 'replace-with')) {
        http_response_code(503);
        exit('Unsafe production configuration: explicit database credentials are required.');
    }
}

require_once __DIR__ . '/Support.php';
Homestead\apply_security_headers($environment === 'production');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '43200');
    $sessionName = (string)($config['security']['session_name'] ?? 'homestead_session');
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sessionName)) {
        http_response_code(503);
        exit('Homestead session configuration is invalid.');
    }
    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'httponly' => true,
        'secure' => $environment === 'production' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
$routeName = basename($requestPath);

$uiRouteClasses = [
    'dashboard.php' => 'ui-dashboard',
    'phase2.php' => 'ui-household',
    'phase4.php' => 'ui-recipes',
    'phase6.php' => 'ui-grow',
    'prepared-food.php' => 'ui-preserve',
];
$uiPageClass = $uiRouteClasses[$routeName] ?? null;
if ($uiPageClass !== null) {
    $sectionClass = '';
    if ($routeName === 'phase2.php') {
        $section = (string)($_GET['section'] ?? 'family');
        if (in_array($section, ['family', 'storage', 'inventory', 'ledger'], true)) {
            $sectionClass = ' ui-household-' . $section;
        }
    } elseif ($routeName === 'phase6.php') {
        $section = (string)($_GET['section'] ?? 'garden');
        if (in_array($section, ['garden', 'harvests', 'preservation'], true)) {
            $sectionClass = ' ui-grow-' . $section;
        }
    }
    $uiPageClass .= $sectionClass;

    ob_start(static function (string $html) use ($uiPageClass): string {
        if (!str_contains(strtolower($html), '<!doctype html')) {
            return $html;
        }

        if (!str_contains($html, '/assets/css/core-pages.css')) {
            $html = str_ireplace(
                '</head>',
                '<link rel="stylesheet" href="/assets/css/core-pages.css?v=20260726"></head>',
                $html
            );
        }

        $safeClass = htmlspecialchars($uiPageClass, ENT_QUOTES, 'UTF-8');
        return (string)preg_replace_callback(
            '/<body\b([^>]*)>/i',
            static function (array $matches) use ($safeClass): string {
                $attributes = $matches[1];
                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attributes, $classMatch)) {
                    $updatedClass = trim($classMatch[2] . ' ' . $safeClass);
                    $updatedAttribute = 'class=' . $classMatch[1] . $updatedClass . $classMatch[1];
                    $attributes = (string)preg_replace('/\bclass\s*=\s*(["\'])(.*?)\1/i', $updatedAttribute, $attributes, 1);
                    return '<body' . $attributes . '>';
                }

                return '<body' . $attributes . ' class="' . $safeClass . '">';
            },
            $html,
            1
        );
    });
}

$isFoodWorkflowRoute = in_array($routeName, ['phase4.php', 'prepared-food.php'], true);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $isFoodWorkflowRoute) {
    $_SESSION['recipe_action_key'] = bin2hex(random_bytes(32));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isFoodWorkflowRoute) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'complete_recipe') {
        $_POST['completion_key'] = (string)($_SESSION['recipe_action_key'] ?? '');
    } elseif ($action === 'update_prepared_food') {
        $_POST['action_key'] = (string)($_SESSION['recipe_action_key'] ?? '');
    }
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/HouseholdContext.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/RecipeService.php';
require_once __DIR__ . '/GrowPreserveService.php';
require_once __DIR__ . '/StarterKitService.php';
require_once __DIR__ . '/StarterKitAdminService.php';

$database = new Homestead\Database($config['database'] ?? []);
$pdo = $database->connection();
$householdContext = new Homestead\HouseholdContext($pdo);
$auth = new Homestead\Auth($pdo);
