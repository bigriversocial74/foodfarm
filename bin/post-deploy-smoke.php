<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['config::', 'base-url::', 'allow-http', 'json']);
$root = dirname(__DIR__);
$configPath = isset($options['config']) && is_string($options['config']) && trim($options['config']) !== ''
    ? (string)$options['config']
    : $root . '/config.php';
$jsonOutput = array_key_exists('json', $options);
$allowHttp = array_key_exists('allow-http', $options);

if (!is_file($configPath) || !is_readable($configPath)) {
    fwrite(STDERR, "Configuration file is missing or unreadable.\n");
    exit(1);
}
$config = require $configPath;
if (!is_array($config)) {
    fwrite(STDERR, "Configuration file must return an array.\n");
    exit(1);
}

$baseUrl = isset($options['base-url']) && is_string($options['base-url']) && trim($options['base-url']) !== ''
    ? rtrim((string)$options['base-url'], '/')
    : rtrim((string)($config['app']['base_url'] ?? ''), '/');
$healthKey = (string)($config['security']['health_key'] ?? '');

if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "A valid base URL is required.\n");
    exit(1);
}
if (!$allowHttp && !str_starts_with(strtolower($baseUrl), 'https://')) {
    fwrite(STDERR, "Production smoke checks require HTTPS. Use --allow-http only for local testing.\n");
    exit(1);
}
if (strlen($healthKey) < 32 || str_contains(strtolower($healthKey), 'replace-with')) {
    fwrite(STDERR, "A configured health-check key of at least 32 characters is required.\n");
    exit(1);
}

/** @return array{status:int,headers:array<string,string>,body:string,error:?string} */
function requestUrl(string $url, array $headers = []): array
{
    $headerLines = array_merge([
        'Accept: */*',
        'User-Agent: Homestead-Post-Deploy-Smoke/1.0',
    ], $headers);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headerLines),
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    $normalized = [];
    foreach ($responseHeaders as $index => $line) {
        if ($index === 0 && preg_match('/\s(\d{3})\s/', $line, $match)) {
            $status = (int)$match[1];
            continue;
        }
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $normalized[strtolower(trim($name))] = trim($value);
        }
    }

    return [
        'status' => $status,
        'headers' => $normalized,
        'body' => is_string($body) ? $body : '',
        'error' => $body === false ? (error_get_last()['message'] ?? 'Request failed.') : null,
    ];
}

$checks = [];
$failed = false;
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failed): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failed = true;
    }
};

$publicRoutes = [
    '/' => 'text/html',
    '/login.php' => 'text/html',
    '/manifest.webmanifest' => 'application/manifest+json',
    '/service-worker.js' => 'javascript',
    '/offline.html' => 'text/html',
];
foreach ($publicRoutes as $path => $expectedType) {
    $response = requestUrl($baseUrl . $path);
    $contentType = strtolower((string)($response['headers']['content-type'] ?? ''));
    $ok = $response['status'] === 200 && str_contains($contentType, $expectedType);
    $record('Public route ' . $path, $ok, sprintf('HTTP %d; Content-Type %s', $response['status'], $contentType ?: 'missing'));

    if (in_array($path, ['/', '/login.php'], true)) {
        foreach (['content-security-policy', 'x-content-type-options', 'x-frame-options'] as $headerName) {
            $present = isset($response['headers'][$headerName]) && $response['headers'][$headerName] !== '';
            $record($path . ' header ' . $headerName, $present, $present ? 'Present.' : 'Missing.');
        }
    }
}

for ($phase = 2; $phase <= 11; $phase++) {
    $path = '/api/phase' . $phase . '-health.php';
    $response = requestUrl($baseUrl . $path, ['X-Homestead-Health-Key: ' . $healthKey, 'Accept: application/json']);
    $payload = json_decode($response['body'], true);
    $ok = $response['status'] === 200 && is_array($payload) && ($payload['ok'] ?? false) === true;
    $record('Protected health phase ' . $phase, $ok, $ok ? 'Health check returned ok=true.' : 'Health check failed without exposing response content.');
}

$result = [
    'ok' => !$failed,
    'base_url' => $baseUrl,
    'checked_at_utc' => gmdate(DATE_ATOM),
    'checks' => $checks,
];

if ($jsonOutput) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Homestead post-deploy smoke check\n";
    echo str_repeat('=', 35) . "\n";
    foreach ($checks as $check) {
        echo sprintf("[%s] %s — %s\n", $check['ok'] ? 'PASS' : 'FAIL', $check['name'], $check['detail']);
    }
    echo "\nResult: " . ($failed ? 'FAILED' : 'PASSED') . "\n";
}

exit($failed ? 1 : 0);
