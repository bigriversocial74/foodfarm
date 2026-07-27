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

$uiRoutes = [
    'dashboard.php' => ['class' => 'ui-dashboard', 'stylesheet' => 'core-pages.css'],
    'phase2.php' => ['class' => 'ui-household', 'stylesheet' => 'core-pages.css'],
    'phase4.php' => ['class' => 'ui-recipes', 'stylesheet' => 'core-pages.css'],
    'phase6.php' => ['class' => 'ui-grow', 'stylesheet' => 'core-pages.css'],
    'prepared-food.php' => ['class' => 'ui-preserve', 'stylesheet' => 'core-pages.css'],
    'phase3.php' => ['class' => 'ui-access', 'stylesheet' => 'intelligence-pages.css'],
    'phase5.php' => ['class' => 'ui-kits', 'stylesheet' => 'intelligence-pages.css'],
    'starter-kit-lifecycle.php' => ['class' => 'ui-kits', 'stylesheet' => 'intelligence-pages.css'],
    'phase7.php' => ['class' => 'ui-planning', 'stylesheet' => 'intelligence-pages.css'],
    'phase8.php' => ['class' => 'ui-forecast', 'stylesheet' => 'intelligence-pages.css'],
    'phase9.php' => ['class' => 'ui-finance', 'stylesheet' => 'intelligence-pages.css'],
    'phase10.php' => ['class' => 'ui-nutrition', 'stylesheet' => 'intelligence-pages.css'],
    'phase11.php' => ['class' => 'ui-alerts', 'stylesheet' => 'intelligence-pages.css'],
    'account.php' => ['class' => 'ui-account', 'stylesheet' => 'intelligence-pages.css'],
    'login.php' => ['class' => 'ui-auth-flow ui-login', 'stylesheet' => 'access-flow.css'],
    'accept-invite.php' => ['class' => 'ui-auth-flow ui-invite', 'stylesheet' => 'access-flow.css'],
    'activate-kit.php' => ['class' => 'ui-auth-flow ui-kit-activation', 'stylesheet' => 'access-flow.css'],
];
$shellRoutes = [
    'dashboard.php', 'phase2.php', 'phase3.php', 'phase4.php', 'phase5.php',
    'starter-kit-lifecycle.php', 'phase6.php', 'prepared-food.php', 'phase7.php',
    'phase8.php', 'phase9.php', 'phase10.php', 'phase11.php', 'account.php',
];

$uiRoute = $uiRoutes[$routeName] ?? null;
if (is_array($uiRoute)) {
    $uiPageClass = (string)$uiRoute['class'];
    $uiStylesheet = (string)$uiRoute['stylesheet'];
    $sectionClass = '';
    $activeShellKey = match ($routeName) {
        'dashboard.php' => 'home',
        'phase2.php' => (string)($_GET['section'] ?? 'family') === 'family' ? 'household' : 'pantry',
        'phase3.php' => 'access',
        'phase4.php' => 'recipes',
        'phase5.php', 'starter-kit-lifecycle.php' => 'kits',
        'phase6.php' => (string)($_GET['section'] ?? 'garden') === 'preservation' ? 'preserve' : 'garden',
        'prepared-food.php' => 'preserve',
        'phase7.php' => 'planning',
        'phase8.php' => 'forecast',
        'phase9.php' => 'finance',
        'phase10.php' => 'nutrition',
        'phase11.php' => 'alerts',
        'account.php' => 'account',
        default => '',
    };
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
    $injectShell = in_array($routeName, $shellRoutes, true);
    $uiPageClass .= $sectionClass . ($injectShell ? ' has-homestead-shell' : '');

    ob_start(static function (string $html) use (
        $uiPageClass,
        $uiStylesheet,
        $injectShell,
        $activeShellKey,
        &$auth
    ): string {
        if (!str_contains(strtolower($html), '<!doctype html')) {
            return $html;
        }

        $stylesheets = ['assets/css/' . $uiStylesheet];
        if ($injectShell) {
            $stylesheets[] = 'assets/css/app-shell.css';
        }
        foreach ($stylesheets as $stylesheetPath) {
            if (!str_contains($html, $stylesheetPath)) {
                $safeStylesheet = htmlspecialchars($stylesheetPath, ENT_QUOTES, 'UTF-8');
                $html = str_ireplace(
                    '</head>',
                    '<link rel="stylesheet" href="' . $safeStylesheet . '?v=20260727-2"></head>',
                    $html
                );
            }
        }

        $safeClass = htmlspecialchars($uiPageClass, ENT_QUOTES, 'UTF-8');
        $html = (string)preg_replace_callback(
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

        if (!$injectShell || !is_object($auth)) {
            return $html;
        }

        $user = $auth->user();
        if (!is_array($user)) {
            return $html;
        }

        $canAny = static function (array $permissions) use ($auth, $user): bool {
            foreach ($permissions as $permission) {
                if ($auth->can($user, $permission)) {
                    return true;
                }
            }
            return false;
        };
        $navigationItems = [
            ['key' => 'home', 'label' => 'Home', 'href' => '/dashboard.php', 'visible' => true],
            ['key' => 'household', 'label' => 'Household', 'href' => '/phase2.php?section=family', 'visible' => true],
            ['key' => 'pantry', 'label' => 'Pantry', 'href' => '/phase2.php?section=inventory', 'visible' => $canAny(['storage.view', 'storage.manage', 'inventory.view', 'inventory.manage'])],
            ['key' => 'recipes', 'label' => 'Recipes', 'href' => '/phase4.php', 'visible' => $canAny(['recipes.view', 'recipes.manage', 'recipes.complete', 'meals.manage'])],
            ['key' => 'garden', 'label' => 'Garden', 'href' => '/phase6.php?section=garden', 'visible' => $canAny(['garden.view', 'garden.manage', 'harvest.record'])],
            ['key' => 'preserve', 'label' => 'Preserve', 'href' => '/phase6.php?section=preservation', 'visible' => $canAny(['preservation.view', 'preservation.manage'])],
            ['key' => 'planning', 'label' => 'Planning', 'href' => '/phase7.php', 'visible' => $canAny(['tasks.manage', 'tasks.complete'])],
            ['key' => 'forecast', 'label' => 'Forecast', 'href' => '/phase8.php', 'visible' => $canAny([
                'inventory.view', 'inventory.manage', 'garden.view', 'garden.manage',
                'harvest.record', 'preservation.view', 'preservation.manage',
                'tasks.manage', 'tasks.complete',
            ])],
            ['key' => 'finance', 'label' => 'Finance', 'href' => '/phase9.php', 'visible' => $canAny(['finance.view', 'finance.manage'])],
            ['key' => 'nutrition', 'label' => 'Nutrition', 'href' => '/phase10.php', 'visible' => $canAny(['nutrition.view', 'nutrition.manage'])],
            ['key' => 'alerts', 'label' => 'Alerts', 'href' => '/phase11.php', 'visible' => $canAny(['notifications.view', 'notifications.manage'])],
            ['key' => 'access', 'label' => 'Access', 'href' => '/phase3.php', 'visible' => $canAny(['members.manage', 'members.invite', 'permissions.manage'])],
            ['key' => 'kits', 'label' => 'Starter Kits', 'href' => '/phase5.php', 'visible' => !empty($user['is_platform_admin'])],
            ['key' => 'account', 'label' => 'Account', 'href' => '/account.php', 'visible' => true],
        ];

        $desktopLinks = '';
        $mobileLinks = '';
        foreach ($navigationItems as $item) {
            if (!$item['visible']) {
                continue;
            }
            $isActive = $item['key'] === $activeShellKey;
            $safeHref = htmlspecialchars((string)$item['href'], ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8');
            $activeClass = $isActive ? ' is-active' : '';
            $current = $isActive ? ' aria-current="page"' : '';
            $desktopLinks .= '<a class="homestead-appbar__link' . $activeClass . '" href="' . $safeHref . '"' . $current . '>' . $safeLabel . '</a>';
            $mobileLinks .= '<a class="homestead-mobile-menu__link' . $activeClass . '" href="' . $safeHref . '"' . $current . '>' . $safeLabel . '</a>';
        }

        $displayName = trim((string)($user['display_name'] ?? 'Household member'));
        $safeDisplayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $initial = function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1);
        $safeInitial = htmlspecialchars(strtoupper((string)$initial), ENT_QUOTES, 'UTF-8');

        $navigation = '<header class="homestead-appbar">'
            . '<div class="homestead-appbar__inner">'
            . '<a class="homestead-appbar__brand" href="/dashboard.php" aria-label="Homestead dashboard">'
            . '<span class="homestead-appbar__mark" aria-hidden="true">H</span>'
            . '<span class="homestead-appbar__brand-copy"><strong>Homestead</strong><small>Household food system</small></span>'
            . '</a>'
            . '<nav class="homestead-appbar__links" aria-label="Homestead sections">' . $desktopLinks . '</nav>'
            . '<div class="homestead-appbar__member">'
            . '<a class="homestead-appbar__avatar" href="/account.php" aria-label="Open account for ' . $safeDisplayName . '">' . $safeInitial . '</a>'
            . '<a class="homestead-appbar__signout" href="/logout.php">Sign out</a>'
            . '</div>'
            . '<details class="homestead-mobile-menu">'
            . '<summary><span aria-hidden="true"></span>Menu</summary>'
            . '<div class="homestead-mobile-menu__panel">'
            . '<div class="homestead-mobile-menu__member"><span class="homestead-appbar__avatar">' . $safeInitial . '</span><div><strong>' . $safeDisplayName . '</strong><small>Household workspace</small></div></div>'
            . '<nav aria-label="Mobile Homestead sections">' . $mobileLinks . '</nav>'
            . '<a class="homestead-mobile-menu__signout" href="/logout.php">Sign out</a>'
            . '</div>'
            . '</details>'
            . '</div>'
            . '</header>';

        return (string)preg_replace_callback(
            '/<body\b[^>]*>/i',
            static fn(array $matches): string => $matches[0] . $navigation,
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
