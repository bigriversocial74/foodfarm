<?php

declare(strict_types=1);

use Homestead\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$options = getopt('', ['config::', 'json']);
$configPath = isset($options['config']) && is_string($options['config']) && trim($options['config']) !== ''
    ? (string)$options['config']
    : $root . '/config.php';
$jsonOutput = array_key_exists('json', $options);

$checks = [];
$failed = false;

$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failed): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failed = true;
    }
};

try {
    $record('CLI execution', true, 'Running from the command line.');

    if (!is_file($configPath) || !is_readable($configPath)) {
        throw new RuntimeException('Configuration file is missing or unreadable: ' . $configPath);
    }
    $record('Configuration file', true, 'Configuration file is readable.');

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Configuration file must return an array.');
    }

    $environment = (string)($config['app']['environment'] ?? '');
    $debug = (bool)($config['app']['debug'] ?? true);
    $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    $timezone = (string)($config['app']['timezone'] ?? '');

    $record('Production environment', $environment === 'production', $environment === 'production'
        ? 'Environment is production.'
        : 'Expected app.environment=production.');
    $record('Debug disabled', !$debug, !$debug ? 'Debug mode is disabled.' : 'Production debug mode must be false.');
    $record('HTTPS base URL', filter_var($baseUrl, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($baseUrl), 'https://'),
        str_starts_with(strtolower($baseUrl), 'https://') ? 'Base URL uses HTTPS.' : 'Production app.base_url must use HTTPS.');
    $record('Valid timezone', in_array($timezone, timezone_identifiers_list(), true),
        in_array($timezone, timezone_identifiers_list(), true) ? 'Timezone is recognized.' : 'Configured timezone is invalid.');

    $requiredExtensions = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl'];
    foreach ($requiredExtensions as $extension) {
        $loaded = extension_loaded($extension);
        $record('PHP extension: ' . $extension, $loaded, $loaded ? 'Loaded.' : 'Required extension is not loaded.');
    }

    $sessionName = (string)($config['security']['session_name'] ?? '');
    $csrfKey = (string)($config['security']['csrf_key'] ?? '');
    $healthKey = (string)($config['security']['health_key'] ?? '');
    $record('Session name', preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sessionName) === 1,
        preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sessionName) === 1 ? 'Session name is valid.' : 'Session name is invalid.');
    $record('CSRF secret', strlen($csrfKey) >= 32 && !str_contains(strtolower($csrfKey), 'replace-with'),
        strlen($csrfKey) >= 32 && !str_contains(strtolower($csrfKey), 'replace-with') ? 'CSRF secret length is acceptable.' : 'Use a unique random CSRF secret of at least 32 characters.');
    $record('Health-check secret', strlen($healthKey) >= 32 && !str_contains(strtolower($healthKey), 'replace-with'),
        strlen($healthKey) >= 32 && !str_contains(strtolower($healthKey), 'replace-with') ? 'Health-check secret length is acceptable.' : 'Use a unique random health-check secret of at least 32 characters.');

    $databaseConfig = is_array($config['database'] ?? null) ? $config['database'] : [];
    $databasePassword = (string)($databaseConfig['password'] ?? '');
    $record('Database credentials', $databasePassword !== '' && !str_contains(strtolower($databasePassword), 'replace-with'),
        $databasePassword !== '' && !str_contains(strtolower($databasePassword), 'replace-with') ? 'Explicit database credentials are configured.' : 'Database password is missing or still a placeholder.');

    require_once $root . '/app/Database.php';
    $database = new Database($databaseConfig);
    $pdo = $database->connection();
    $record('Database connection', true, 'Connected to the configured database.');

    $serverVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $record('Database server version', $serverVersion !== '', $serverVersion !== '' ? $serverVersion : 'Version unavailable.');

    $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $expectedName = (string)($databaseConfig['name'] ?? '');
    $record('Database selection', $databaseName !== '' && hash_equals($expectedName, $databaseName),
        $databaseName !== '' ? 'Connected database: ' . $databaseName : 'No database selected.');

    $timeZone = (string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
    $record('Database session timezone', $timeZone === '+00:00', $timeZone === '+00:00'
        ? 'Database session timezone is UTC.'
        : 'Expected database session timezone +00:00; found ' . $timeZone . '.');

    $sqlMode = strtoupper((string)$pdo->query('SELECT @@session.sql_mode')->fetchColumn());
    $strictMode = str_contains($sqlMode, 'STRICT_TRANS_TABLES') || str_contains($sqlMode, 'STRICT_ALL_TABLES');
    $record('Strict SQL mode', $strictMode, $strictMode ? 'Strict SQL mode is active.' : 'Strict SQL mode is not active.');

    $requiredTables = [
        'households',
        'users',
        'household_members',
        'storage_locations',
        'inventory_items',
        'food_ledger_events',
        'recipes',
        'garden_zones',
        'authentication_events',
        'household_tasks',
        'household_notifications',
    ];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $statement = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
    );
    $statement->execute($requiredTables);
    $presentTables = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    $missingTables = array_values(array_diff($requiredTables, $presentTables));
    $record('Representative schema objects', $missingTables === [], $missingTables === []
        ? 'Core, authentication, planning, and notification tables are present.'
        : 'Missing tables: ' . implode(', ', $missingTables));

    $ownerCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM household_members WHERE role = 'owner' AND status = 'active'"
    )->fetchColumn();
    $record('Active household owner', $ownerCount > 0,
        $ownerCount > 0 ? 'At least one active household owner exists.' : 'No active household owner exists.');

    $configDirectory = dirname(realpath($configPath) ?: $configPath);
    $record('Configuration outside public assets', !str_contains(str_replace('\\', '/', $configDirectory), '/assets/'),
        'Configuration is not stored under the public assets directory.');
} catch (Throwable $exception) {
    $record('Preflight execution', false, $exception->getMessage());
}

$result = [
    'ok' => !$failed,
    'checked_at_utc' => gmdate(DATE_ATOM),
    'checks' => $checks,
];

if ($jsonOutput) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Homestead production preflight\n";
    echo str_repeat('=', 31) . "\n";
    foreach ($checks as $check) {
        echo sprintf("[%s] %s — %s\n", $check['ok'] ? 'PASS' : 'FAIL', $check['name'], $check['detail']);
    }
    echo "\nResult: " . ($failed ? 'FAILED' : 'PASSED') . "\n";
}

exit($failed ? 1 : 0);
