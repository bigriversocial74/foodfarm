<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "PWA policy failure: {$message}\n");
        exit(1);
    }
};

$requiredFiles = [
    'manifest.webmanifest',
    'service-worker.js',
    'offline.html',
    'assets/icons/homestead-icon.svg',
    'assets/js/pwa.js',
    'index.php',
    'login.php',
];

foreach ($requiredFiles as $path) {
    $assert(is_file($root . '/' . $path), "Missing required file {$path}.");
}

try {
    $manifest = json_decode(
        (string)file_get_contents($root . '/manifest.webmanifest'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $exception) {
    fwrite(STDERR, 'PWA policy failure: Manifest JSON is invalid: ' . $exception->getMessage() . "\n");
    exit(1);
}

$assert(is_array($manifest), 'Manifest must decode to an object.');
$assert(($manifest['id'] ?? null) === '/', 'Manifest ID must remain same-origin and root-scoped.');
$assert(($manifest['scope'] ?? null) === '/', 'Manifest scope must remain root-scoped.');
$assert(str_starts_with((string)($manifest['start_url'] ?? ''), '/dashboard.php'), 'Manifest start URL must open the authenticated dashboard.');
$assert(($manifest['display'] ?? null) === 'standalone', 'Manifest display mode must be standalone.');

$icons = is_array($manifest['icons'] ?? null) ? $manifest['icons'] : [];
$iconSizes = array_map(static fn(array $icon): string => (string)($icon['sizes'] ?? ''), $icons);
$iconPurposes = array_map(static fn(array $icon): string => (string)($icon['purpose'] ?? ''), $icons);
$assert(in_array('192x192', $iconSizes, true), 'Manifest must declare a 192x192 icon.');
$assert(in_array('512x512', $iconSizes, true), 'Manifest must declare a 512x512 icon.');
$assert(in_array('maskable', $iconPurposes, true), 'Manifest must declare a maskable icon.');

$serviceWorker = (string)file_get_contents($root . '/service-worker.js');
$assert(str_contains($serviceWorker, "request.method !== 'GET'"), 'Service worker must keep non-GET requests network-only.');
$assert(str_contains($serviceWorker, "request.mode === 'navigate'"), 'Service worker must keep page navigation network-only.');
$assert(str_contains($serviceWorker, "cache: 'no-store'"), 'Sensitive GET requests must bypass HTTP cache reuse.');
$assert(str_contains($serviceWorker, 'SENSITIVE_PATH'), 'Service worker must define protected route handling.');
$assert(str_contains($serviceWorker, "'/offline.html'"), 'Service worker must include the generic offline fallback.');

$matched = preg_match(
    '/const STATIC_ASSETS = Object\\.freeze\\((\\[[\\s\\S]*?\\])\\);/',
    $serviceWorker,
    $staticMatch
);
$assert($matched === 1, 'Unable to parse the static asset allowlist.');

try {
    $staticAssets = json_decode($staticMatch[1], true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, 'PWA policy failure: Static asset allowlist is invalid: ' . $exception->getMessage() . "\n");
    exit(1);
}

$assert(is_array($staticAssets) && $staticAssets !== [], 'Static asset allowlist must not be empty.');
foreach ($staticAssets as $asset) {
    $path = (string)$asset;
    $assert(str_starts_with($path, '/'), "Static asset {$path} must be same-origin.");
    $assert(!str_ends_with(strtolower($path), '.php'), "PHP route {$path} must never be pre-cached.");
    $assert(!str_starts_with(strtolower($path), '/api/'), "API route {$path} must never be pre-cached.");
    $assert(!str_contains(strtolower($path), 'health'), "Health endpoint {$path} must never be pre-cached.");
}

foreach (['index.php', 'login.php'] as $entryPoint) {
    $html = (string)file_get_contents($root . '/' . $entryPoint);
    $assert(str_contains($html, 'rel="manifest"'), "{$entryPoint} must link the manifest.");
    $assert(str_contains($html, '/assets/js/pwa.js'), "{$entryPoint} must register the service worker.");
}

$index = (string)file_get_contents($root . '/index.php');
$assert(str_contains($index, 'data-homestead-install'), 'Landing page must expose the progressive install action.');

echo "Homestead PWA policy test passed.\n";
