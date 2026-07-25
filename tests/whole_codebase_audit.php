<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$findings = [];
$inventory = [
    'php' => 0,
    'sql' => 0,
    'js' => 0,
    'css' => 0,
    'shell' => 0,
    'workflow' => 0,
    'docs' => 0,
    'other' => 0,
];

$weights = [
    'security' => 25.0,
    'authorization' => 20.0,
    'integrity' => 20.0,
    'validation' => 15.0,
    'maintainability' => 10.0,
    'accessibility' => 5.0,
    'documentation' => 5.0,
];

$severityPenalty = [
    'critical' => 8.0,
    'high' => 4.0,
    'medium' => 1.5,
    'low' => 0.5,
];

$add = static function (
    string $severity,
    string $category,
    string $message,
    ?string $file = null
) use (&$findings): void {
    $findings[] = [
        'severity' => $severity,
        'category' => $category,
        'message' => $message,
        'file' => $file,
    ];
};

$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $relative);
    }
    return $content;
};

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $entry) {
    if (!$entry->isFile()) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    if (
        str_starts_with($relative, '.git/')
        || str_starts_with($relative, 'vendor/')
        || str_starts_with($relative, 'storage/')
        || str_starts_with($relative, 'node_modules/')
    ) {
        continue;
    }
    $files[$relative] = $entry->getPathname();

    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if ($extension === 'php') {
        $inventory['php']++;
    } elseif ($extension === 'sql') {
        $inventory['sql']++;
    } elseif ($extension === 'js') {
        $inventory['js']++;
    } elseif ($extension === 'css') {
        $inventory['css']++;
    } elseif ($extension === 'sh') {
        $inventory['shell']++;
    } elseif (in_array($extension, ['yml', 'yaml'], true) && str_starts_with($relative, '.github/workflows/')) {
        $inventory['workflow']++;
    } elseif (in_array($extension, ['md', 'txt'], true)) {
        $inventory['docs']++;
    } else {
        $inventory['other']++;
    }
}
ksort($files);

$requiredFiles = [
    'app/bootstrap.php',
    'app/Auth.php',
    'app/Database.php',
    'app/HouseholdContext.php',
    'app/Support.php',
    'database/schema.sql',
    'database/phase8_forecasting_seasonal_self_sufficiency.sql',
    'tests/application_static_audit.php',
    'tests/database_integration_audit.php',
    'tests/http_smoke.sh',
    'tests/phase8_integration.php',
    'tests/phase8_http_smoke.sh',
    '.htaccess',
    'config-example.php',
    'README.md',
];
foreach ($requiredFiles as $requiredFile) {
    if (!isset($files[$requiredFile])) {
        $add('high', 'validation', 'Required application or certification file is missing.', $requiredFile);
    }
}

if (isset($files['config.php'])) {
    $add('critical', 'security', 'A live config.php file is committed to the repository.', 'config.php');
}

$publicRoutes = [
    'index.php',
    'login.php',
    'accept-invite.php',
    'activate-kit.php',
    'config-example.php',
];
$nonBrowserPhp = [
    'bin/create-owner.php',
];

foreach ($files as $relative => $absolute) {
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if ($extension !== 'php') {
        continue;
    }

    $content = file_get_contents($absolute);
    if ($content === false) {
        $add('high', 'validation', 'PHP file could not be read.', $relative);
        continue;
    }

    $lineCount = substr_count($content, "\n") + 1;
    if ($lineCount > 1800) {
        $add('high', 'maintainability', 'PHP file exceeds 1,800 lines and should be split into focused services.', $relative);
    } elseif ($lineCount > 1200) {
        $add('medium', 'maintainability', 'PHP file exceeds 1,200 lines and is a change-risk hotspot.', $relative);
    } elseif ($lineCount > 700 && (str_starts_with($relative, 'app/') || !str_contains($relative, '/'))) {
        $add('low', 'maintainability', 'Large PHP file would benefit from extraction into smaller units.', $relative);
    }

    if (preg_match('/\b(eval|assert)\s*\(/i', $content) === 1) {
        $add('critical', 'security', 'Dynamic PHP execution was detected.', $relative);
    }
    if (preg_match('/\b(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/i', $content) === 1 && !str_starts_with($relative, 'tests/')) {
        $add('critical', 'security', 'Operating-system command execution was detected in application code.', $relative);
    }
    if (preg_match('/\bunserialize\s*\(/i', $content) === 1) {
        $add('high', 'security', 'Native unserialize() was detected; use a safe structured format.', $relative);
    }
    if (preg_match('/<\?=\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/', $content) === 1) {
        $add('critical', 'security', 'A superglobal is rendered directly into HTML.', $relative);
    }
    if (preg_match('/(?:query|exec)\s*\(\s*["\'][^"\']*\$_(GET|POST|REQUEST|COOKIE)/s', $content) === 1) {
        $add('critical', 'security', 'A superglobal appears inside a direct SQL execution call.', $relative);
    }

    $isRootRoute = !str_contains($relative, '/') && !in_array($relative, $publicRoutes, true);
    $isApiRoute = str_starts_with($relative, 'api/');
    if (($isRootRoute || $isApiRoute) && !in_array($relative, $nonBrowserPhp, true)) {
        $hasAccessGuard = str_contains($content, 'requireUser(')
            || str_contains($content, 'requirePlatformAdmin(')
            || str_contains($content, 'require_health_access(')
            || str_contains($content, 'requirePermission(');
        if (!$hasAccessGuard) {
            $add('high', 'authorization', 'Browser-accessible PHP route has no recognizable authentication or health-access guard.', $relative);
        }
    }

    $usesPost = str_contains($content, "REQUEST_METHOD'] === 'POST'")
        || str_contains($content, 'REQUEST_METHOD"] === "POST"')
        || str_contains($content, '$_POST');
    $hasPostForm = preg_match('/<form\b[^>]*method=["\']post["\']/i', $content) === 1;
    if (($usesPost || $hasPostForm) && !str_starts_with($relative, 'tests/')) {
        if (!str_contains($content, 'verify_csrf')) {
            $add('critical', 'security', 'POST handling or forms are present without verify_csrf().', $relative);
        }
        if ($hasPostForm && !str_contains($content, 'csrf_token')) {
            $add('critical', 'security', 'POST form is present without a CSRF token field.', $relative);
        }
    }

    if (
        !str_starts_with($relative, 'tests/')
        && !str_starts_with($relative, 'bin/')
        && str_contains($content, 'getMessage()')
        && !str_contains($content, 'user_error_message')
        && !str_contains($content, 'health_error')
        && !str_contains($content, 'error_log')
    ) {
        $add('high', 'security', 'Exception messages may be exposed without the safe browser-message helper.', $relative);
    }

    if (
        !str_starts_with($relative, 'tests/')
        && preg_match('/header\s*\(\s*["\']Location:/i', $content) === 1
        && !str_contains($content, 'function redirect')
    ) {
        $add('medium', 'security', 'Direct Location header bypasses the centralized safe redirect helper.', $relative);
    }

    $isHtmlDocument = str_contains($content, '<!doctype html>');
    if ($isHtmlDocument) {
        if (!str_contains($content, '<html lang="en">') && !str_contains($content, "<html lang='en'>")) {
            $add('medium', 'accessibility', 'HTML page does not declare an English document language.', $relative);
        }
        if (!str_contains($content, 'name="viewport"')) {
            $add('medium', 'accessibility', 'HTML page is missing a viewport meta tag.', $relative);
        }
        if (!str_contains($content, 'skip-link')) {
            $add('low', 'accessibility', 'Authenticated HTML page has no skip link.', $relative);
        }
    }
}

foreach ($files as $relative => $absolute) {
    if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'sql') {
        continue;
    }
    $content = file_get_contents($absolute);
    if ($content === false) {
        continue;
    }

    if (preg_match('/\b(DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i', $content) === 1) {
        $add('high', 'integrity', 'Destructive table operation exists in a committed migration.', $relative);
    }
    if (preg_match('/\$2[ayb]\$[0-9]{2}\$/', $content) === 1 || str_contains($content, 'ChangeMe123!')) {
        $add('critical', 'security', 'A password hash or known seed password appears in SQL.', $relative);
    }
    if (
        $relative !== 'database/schema.sql'
        && preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $content) === 1
    ) {
        $add('high', 'integrity', 'Incremental migration contains a non-idempotent CREATE TABLE statement.', $relative);
    }
    if (
        str_starts_with($relative, 'database/phase')
        && preg_match('/ALTER\s+TABLE/i', $content) === 1
        && !str_contains($content, 'information_schema')
    ) {
        $add('medium', 'integrity', 'Incremental ALTER TABLE statements are not guarded through information_schema checks.', $relative);
    }
}

$workflowFiles = array_filter(
    array_keys($files),
    static fn(string $relative): bool => str_starts_with($relative, '.github/workflows/')
        && in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), ['yml', 'yaml'], true)
);
if (count($workflowFiles) > 6) {
    $add('medium', 'maintainability', 'The repository has more than six overlapping workflows; consolidate to reduce drift and duplicated CI cost.', '.github/workflows');
}
foreach ($workflowFiles as $relative) {
    $content = $read($relative);
    if (str_contains($content, 'contents: write') && !str_contains($content, 'workflow_dispatch')) {
        $add('high', 'security', 'Workflow grants write access without being an explicit manual release workflow.', $relative);
    }
    if (!str_contains($content, 'permissions:')) {
        $add('medium', 'security', 'Workflow does not declare least-privilege permissions.', $relative);
    }
}

if (isset($files['tests/application_static_audit.php'])) {
    $applicationAudit = $read('tests/application_static_audit.php');
    foreach (range(2, 8) as $phase) {
        $healthPath = "api/phase{$phase}-health.php";
        if (isset($files[$healthPath]) && !str_contains($applicationAudit, $healthPath)) {
            $add('high', 'validation', 'Whole-application static audit does not include the Phase ' . $phase . ' health endpoint.', 'tests/application_static_audit.php');
        }
    }
}

if (isset($files['.github/workflows/php-lint.yml'])) {
    $workflow = $read('.github/workflows/php-lint.yml');
    foreach ([
        'database/phase6_grow_harvest_preserve.sql',
        'database/phase7_planning_tasks_automation.sql',
        'database/phase8_forecasting_seasonal_self_sufficiency.sql',
    ] as $migration) {
        if (isset($files[$migration]) && !str_contains($workflow, $migration)) {
            $add('high', 'validation', 'Primary whole-application workflow does not import or replay a current migration.', '.github/workflows/php-lint.yml');
        }
    }
    foreach ([
        'tests/phase6_integration.php',
        'tests/phase7_integration.php',
        'tests/phase8_integration.php',
    ] as $suite) {
        if (isset($files[$suite]) && !str_contains($workflow, $suite)) {
            $add('medium', 'validation', 'Primary whole-application workflow does not execute a current integration suite.', '.github/workflows/php-lint.yml');
        }
    }
}

if (isset($files['README.md'])) {
    $readme = $read('README.md');
    if (!str_contains($readme, 'database/phase8_forecasting_seasonal_self_sufficiency.sql')) {
        $add('high', 'documentation', 'README migration sequence does not include Phase 8.', 'README.md');
    }
    if (!str_contains($readme, '/phase8.php')) {
        $add('medium', 'documentation', 'README interface list does not include Phase 8.', 'README.md');
    }
}

if (isset($files['.htaccess'])) {
    $htaccess = $read('.htaccess');
    foreach (['config.php', '.env', 'database', 'storage'] as $sensitiveName) {
        if (!str_contains($htaccess, $sensitiveName)) {
            $add('medium', 'security', 'Apache hardening does not visibly protect ' . $sensitiveName . '.', '.htaccess');
        }
    }
}

$categoryScores = $weights;
foreach ($findings as $finding) {
    $category = $finding['category'];
    $severity = $finding['severity'];
    if (!isset($categoryScores[$category], $severityPenalty[$severity])) {
        continue;
    }
    $categoryScores[$category] = max(0.0, $categoryScores[$category] - $severityPenalty[$severity]);
}

$totalScore = array_sum($categoryScores);
$scoreOutOfTen = round($totalScore / 10, 1);
$severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
usort($findings, static function (array $left, array $right) use ($severityOrder): int {
    $severityComparison = ($severityOrder[$left['severity']] ?? 9) <=> ($severityOrder[$right['severity']] ?? 9);
    if ($severityComparison !== 0) {
        return $severityComparison;
    }
    $categoryComparison = $left['category'] <=> $right['category'];
    if ($categoryComparison !== 0) {
        return $categoryComparison;
    }
    return ($left['file'] ?? '') <=> ($right['file'] ?? '');
});

echo "Homestead whole-codebase audit\n";
echo "===============================\n";
echo 'Files scanned: ' . count($files) . PHP_EOL;
foreach ($inventory as $type => $count) {
    echo sprintf('  %-10s %d', $type . ':', $count) . PHP_EOL;
}
echo PHP_EOL;
echo sprintf('Initial score: %.1f/10 (%.1f/100)', $scoreOutOfTen, $totalScore) . PHP_EOL;
foreach ($categoryScores as $category => $score) {
    echo sprintf('  %-16s %.1f/%.1f', ucfirst($category), $score, $weights[$category]) . PHP_EOL;
}
echo PHP_EOL;

if ($findings === []) {
    echo "No audit findings.\n";
    exit(0);
}

echo 'Findings: ' . count($findings) . PHP_EOL;
foreach ($findings as $index => $finding) {
    echo sprintf(
        '%02d. [%s] [%s] %s%s',
        $index + 1,
        strtoupper($finding['severity']),
        strtoupper($finding['category']),
        $finding['message'],
        $finding['file'] !== null ? ' (' . $finding['file'] . ')' : ''
    ) . PHP_EOL;
}

echo PHP_EOL;
echo "Audit completed with findings. The score is diagnostic; release certification remains the source of truth for executable behavior.\n";
