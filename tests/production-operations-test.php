<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$files = [
    'bin/production-preflight.php',
    'bin/database-backup.sh',
    'bin/verify-database-restore.sh',
    'bin/post-deploy-smoke.php',
    'docs/PRODUCTION_OPERATIONS.md',
];
foreach ($files as $file) {
    $assert(is_file($root . '/' . $file), 'Missing operations file: ' . $file);
}

$preflight = (string)@file_get_contents($root . '/bin/production-preflight.php');
$backup = (string)@file_get_contents($root . '/bin/database-backup.sh');
$restore = (string)@file_get_contents($root . '/bin/verify-database-restore.sh');
$smoke = (string)@file_get_contents($root . '/bin/post-deploy-smoke.php');
$docs = (string)@file_get_contents($root . '/docs/PRODUCTION_OPERATIONS.md');

$assert(str_contains($preflight, "PHP_SAPI !== 'cli'"), 'Preflight must be CLI-only.');
$assert(str_contains($preflight, 'app.environment=production'), 'Preflight must enforce the production environment.');
$assert(str_contains($preflight, 'HTTPS base URL'), 'Preflight must validate HTTPS.');
$assert(!str_contains($preflight, 'password='), 'Preflight must not print database passwords.');

$assert(str_contains($backup, 'umask 077'), 'Backup script must use a private umask.');
$assert(str_contains($backup, 'defaults-extra-file'), 'Backup script must use a temporary client defaults file.');
$assert(str_contains($backup, 'outside the Homestead repository'), 'Backup script must reject web-root destinations.');
$assert(str_contains($backup, 'sha256sum'), 'Backup script must create a checksum.');
$assert(str_contains($backup, 'gzip -t'), 'Backup script must verify compressed output.');
$assert(!str_contains($backup, '--password='), 'Backup script must not pass a password on the command line.');

$assert(str_contains($restore, '^homestead_restore_'), 'Restore verification must require a disposable database prefix.');
$assert(str_contains($restore, '--confirm-disposable'), 'Restore verification must require explicit destructive confirmation.');
$assert(str_contains($restore, 'Refusing to overwrite the configured Homestead database'), 'Restore verification must protect production.');
$assert(!str_contains($restore, '--password='), 'Restore script must not pass a password on the command line.');

$assert(str_contains($smoke, "PHP_SAPI !== 'cli'"), 'Smoke checker must be CLI-only.');
$assert(str_contains($smoke, 'X-Homestead-Health-Key'), 'Smoke checker must use keyed protected health checks.');
$assert(str_contains($smoke, "str_starts_with(strtolower(\$baseUrl), 'https://')"), 'Smoke checker must require HTTPS by default.');
$assert(!str_contains($smoke, "echo \$response['body']"), 'Smoke checker must not print protected response bodies.');

foreach (['preflight', 'backup', 'restore', 'post-deploy', 'rollback'] as $term) {
    $assert(str_contains(strtolower($docs), $term), 'Operations documentation is missing: ' . $term);
}

if ($failures !== []) {
    fwrite(STDERR, "Production operations policy test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Production operations policy test passed.\n";
