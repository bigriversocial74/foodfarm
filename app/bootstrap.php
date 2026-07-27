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
    'dashboard.php' => ['class' => 'ui-dashboard', 'stylesheet' => 'homestead-dashboard.css'],
    'phase2.php' => ['class' => 'ui-household', 'stylesheet' => 'homestead-pantry.css'],
    'phase4.php' => ['class' => 'ui-recipes', 'stylesheet' => 'homestead-recipes.css'],
    'phase6.php' => ['class' => 'ui-grow', 'stylesheet' => 'homestead-garden.css'],
    'prepared-food.php' => ['class' => 'ui-preserve', 'stylesheet' => 'core-pages.css'],
    'phase3.php' => ['class' => 'ui-access', 'stylesheet' => 'intelligence-pages.css'],
    'phase5.php' => ['class' => 'ui-kits', 'stylesheet' => 'intelligence-pages.css'],
    'starter-kit-lifecycle.php' => ['class' => 'ui-kits', 'stylesheet' => 'intelligence-pages.css'],
    'phase7.php' => ['class' => 'ui-planning', 'stylesheet' => 'intelligence-pages.css'],
    'shopping-list.php' => ['class' => 'ui-shopping', 'stylesheet' => 'homestead-list.css'],
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
    'starter-kit-lifecycle.php', 'phase6.php', 'prepared-food.php', 'phase7.php', 'shopping-list.php',
    'phase8.php', 'phase9.php', 'phase10.php', 'phase11.php', 'account.php',
];

$uiRoute = $uiRoutes[$routeName] ?? null;
if ($routeName === 'phase6.php' && (string)($_GET['section'] ?? '') === 'preservation' && is_array($uiRoute)) {
    $uiRoute['class'] = 'ui-preserve';
    $uiRoute['stylesheet'] = 'homestead-preserve.css';
}
if (is_array($uiRoute)) {
    $uiPageClass = (string)$uiRoute['class'];
    $uiStylesheet = (string)$uiRoute['stylesheet'];
    $sectionClass = '';
    $activeShellKey = match ($routeName) {
        'dashboard.php' => 'home',
        'phase2.php' => match ((string)($_GET['section'] ?? 'family')) {
            'family' => 'household',
            'storage' => 'storage',
            default => 'pantry',
        },
        'phase3.php' => 'access',
        'phase4.php' => 'recipes',
        'phase5.php', 'starter-kit-lifecycle.php' => 'kits',
        'phase6.php' => (string)($_GET['section'] ?? 'garden') === 'preservation' ? 'preserve' : 'garden',
        'prepared-food.php' => 'preserve',
        'phase7.php' => 'planning',
        'shopping-list.php' => 'shopping',
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
                    '<link rel="stylesheet" href="' . $safeStylesheet . '?v=20260727-4"></head>',
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

        $navigationGroups = [
            'Main' => [
                ['key' => 'home', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => '⌂', 'visible' => true],
                ['key' => 'pantry', 'label' => 'Pantry Inventory', 'href' => 'phase2.php?section=inventory', 'icon' => '▦', 'visible' => $canAny(['storage.view', 'storage.manage', 'inventory.view', 'inventory.manage'])],
                ['key' => 'recipes', 'label' => 'Recipes & Meal Planning', 'href' => 'phase4.php', 'icon' => '✣', 'visible' => $canAny(['recipes.view', 'recipes.manage', 'recipes.complete', 'meals.manage'])],
                ['key' => 'garden', 'label' => 'Garden', 'href' => 'phase6.php?section=garden', 'icon' => '♧', 'visible' => $canAny(['garden.view', 'garden.manage', 'harvest.record'])],
                ['key' => 'preserve', 'label' => 'Preservation', 'href' => 'phase6.php?section=preservation', 'icon' => '▣', 'visible' => $canAny(['preservation.view', 'preservation.manage'])],
                ['key' => 'planning', 'label' => 'Planning & Tasks', 'href' => 'phase7.php', 'icon' => '✓', 'visible' => $canAny(['tasks.manage', 'tasks.complete'])],
                ['key' => 'forecast', 'label' => 'Forecast & Seasons', 'href' => 'phase8.php', 'icon' => '⌁', 'visible' => $canAny([
                    'inventory.view', 'inventory.manage', 'garden.view', 'garden.manage',
                    'harvest.record', 'preservation.view', 'preservation.manage',
                    'tasks.manage', 'tasks.complete',
                ])],
            ],
            'Manage' => [
                ['key' => 'shopping', 'label' => 'Shopping List', 'href' => 'shopping-list.php', 'icon' => '☷', 'visible' => $canAny(['tasks.manage', 'tasks.complete'])],
                ['key' => 'household', 'label' => 'Family & Household', 'href' => 'phase2.php?section=family', 'icon' => '♙', 'visible' => true],
                ['key' => 'storage', 'label' => 'Storage Locations', 'href' => 'phase2.php?section=storage', 'icon' => '▤', 'visible' => $canAny(['storage.view', 'storage.manage'])],
                ['key' => 'finance', 'label' => 'Costs & Reports', 'href' => 'phase9.php', 'icon' => '▥', 'visible' => $canAny(['finance.view', 'finance.manage'])],
                ['key' => 'nutrition', 'label' => 'Nutrition', 'href' => 'phase10.php', 'icon' => '◎', 'visible' => $canAny(['nutrition.view', 'nutrition.manage'])],
                ['key' => 'alerts', 'label' => 'Alerts & Calendar', 'href' => 'phase11.php', 'icon' => '!', 'visible' => $canAny(['notifications.view', 'notifications.manage'])],
            ],
            'Settings' => [
                ['key' => 'access', 'label' => 'Family Access', 'href' => 'phase3.php', 'icon' => '⚿', 'visible' => $canAny(['members.manage', 'members.invite', 'permissions.manage'])],
                ['key' => 'kits', 'label' => 'Starter Kits', 'href' => 'phase5.php', 'icon' => '◆', 'visible' => !empty($user['is_platform_admin'])],
                ['key' => 'account', 'label' => 'Profile & Settings', 'href' => 'account.php', 'icon' => '⚙', 'visible' => true],
            ],
        ];

        $sidebarNavigation = '';
        $mobileNavigation = '';
        $alertsVisible = false;
        foreach ($navigationGroups as $groupLabel => $items) {
            $groupLinks = '';
            $mobileGroupLinks = '';
            foreach ($items as $item) {
                if (!$item['visible']) {
                    continue;
                }
                if ($item['key'] === 'alerts') {
                    $alertsVisible = true;
                }
                $isActive = $item['key'] === $activeShellKey;
                $safeHref = htmlspecialchars((string)$item['href'], ENT_QUOTES, 'UTF-8');
                $safeLabel = htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8');
                $safeIcon = htmlspecialchars((string)$item['icon'], ENT_QUOTES, 'UTF-8');
                $activeClass = $isActive ? ' is-active' : '';
                $current = $isActive ? ' aria-current="page"' : '';
                $groupLinks .= '<a class="homestead-nav__link' . $activeClass . '" href="' . $safeHref . '"' . $current . '>'
                    . '<span class="homestead-nav__icon" aria-hidden="true">' . $safeIcon . '</span>'
                    . '<span>' . $safeLabel . '</span></a>';
                $mobileGroupLinks .= '<a class="homestead-mobile-nav__link' . $activeClass . '" href="' . $safeHref . '"' . $current . '>'
                    . '<span class="homestead-nav__icon" aria-hidden="true">' . $safeIcon . '</span>'
                    . '<span>' . $safeLabel . '</span></a>';
            }
            if ($groupLinks === '') {
                continue;
            }
            $safeGroup = htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8');
            $sidebarNavigation .= '<section class="homestead-nav__group"><p class="homestead-nav__heading">' . $safeGroup . '</p><nav aria-label="' . $safeGroup . '">' . $groupLinks . '</nav></section>';
            $mobileNavigation .= '<section class="homestead-mobile-nav__group"><p>' . $safeGroup . '</p><nav aria-label="Mobile ' . $safeGroup . '">' . $mobileGroupLinks . '</nav></section>';
        }

        $displayName = trim((string)($user['display_name'] ?? 'Household member'));
        $safeDisplayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $initial = function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1);
        $safeInitial = htmlspecialchars(strtoupper((string)$initial), ENT_QUOTES, 'UTF-8');
        $safeRole = htmlspecialchars(ucwords(str_replace('_', ' ', (string)($user['role'] ?? 'member'))), ENT_QUOTES, 'UTF-8');

        $alertLink = $alertsVisible
            ? '<a class="homestead-topbar__icon-link" href="phase11.php" aria-label="Open alerts"><span aria-hidden="true">♢</span></a>'
            : '';

        $shellOpen = '<div class="homestead-shell">'
            . '<aside class="homestead-sidebar" id="homestead-sidebar" aria-label="Homestead navigation">'
            . '<div class="homestead-sidebar__brand-row">'
            . '<a class="homestead-sidebar__brand" href="dashboard.php" aria-label="Homestead dashboard">'
            . '<span class="homestead-sidebar__mark" aria-hidden="true">H</span><strong>Homestead</strong></a>'
            . '<button class="homestead-sidebar__close" type="button" data-shell-menu-close aria-label="Close navigation">×</button>'
            . '</div>'
            . '<div class="homestead-sidebar__navigation">' . $sidebarNavigation . '</div>'
            . '<div class="homestead-sidebar__footer">'
            . '<p>Household system</p><span>All core workflows connected.</span>'
            . '<a href="phase8.php">View resilience →</a>'
            . '</div>'
            . '</aside>'
            . '<div class="homestead-workspace">'
            . '<header class="homestead-topbar">'
            . '<button class="homestead-topbar__menu" type="button" data-shell-menu-toggle aria-controls="homestead-sidebar" aria-expanded="false"><span aria-hidden="true"></span><span class="visually-hidden">Open navigation</span></button>'
            . '<a class="homestead-topbar__search" href="phase2.php?section=inventory" aria-label="Open pantry inventory"><span aria-hidden="true">⌕</span><span>Search household records</span></a>'
            . '<div class="homestead-topbar__actions">' . $alertLink
            . '<a class="homestead-topbar__profile" href="account.php" aria-label="Open account for ' . $safeDisplayName . '">'
            . '<span class="homestead-topbar__avatar">' . $safeInitial . '</span>'
            . '<span class="homestead-topbar__profile-copy"><strong>' . $safeDisplayName . '</strong><small>' . $safeRole . '</small></span>'
            . '<span class="homestead-topbar__chevron" aria-hidden="true">⌄</span></a>'
            . '</div>'
            . '<details class="homestead-mobile-nav">'
            . '<summary aria-label="Open mobile navigation"><span aria-hidden="true">☰</span></summary>'
            . '<div class="homestead-mobile-nav__panel">'
            . '<div class="homestead-mobile-nav__member"><span class="homestead-topbar__avatar">' . $safeInitial . '</span><div><strong>' . $safeDisplayName . '</strong><small>' . $safeRole . '</small></div></div>'
            . $mobileNavigation
            . '<a class="homestead-mobile-nav__signout" href="logout.php">Sign out</a>'
            . '</div></details>'
            . '</header>';

        $shellClose = '</div></div>'
            . '<button class="homestead-shell__backdrop" type="button" data-shell-menu-close aria-label="Close navigation"></button>'
            . '<script src="assets/js/homestead-app-shell.js?v=20260727-4" defer></script>';

        $html = (string)preg_replace_callback(
            '/<body\b[^>]*>/i',
            static fn(array $matches): string => $matches[0] . $shellOpen,
            $html,
            1
        );

        return str_ireplace('</body>', $shellClose . '</body>', $html);
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
