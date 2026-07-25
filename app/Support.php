<?php

declare(strict_types=1);

namespace Homestead;

use RuntimeException;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function apply_security_headers(bool $isProduction): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    if ($isProduction) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function verify_csrf(?string $token): void
{
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('The form session expired. Return to the previous page and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

function redirect(string $url): never
{
    if (!str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\x00-\x1F\x7F]/', $url)) {
        throw new RuntimeException('Unsafe redirect target.');
    }
    header('Location: ' . $url, true, 303);
    exit;
}

function positive_decimal(mixed $value, string $field, bool $allowZero = true): float
{
    if (!is_numeric($value)) {
        throw new \InvalidArgumentException($field . ' must be a number.');
    }

    $number = (float)$value;
    if (!is_finite($number) || $number < 0 || (!$allowZero && $number === 0.0)) {
        throw new \InvalidArgumentException($field . ' must be greater than' . ($allowZero ? ' or equal to' : '') . ' zero.');
    }

    return $number;
}

function require_health_access(array $config, Auth $auth): void
{
    header('Cache-Control: no-store, max-age=0');

    $configuredKey = trim((string)($config['security']['health_key'] ?? ''));
    $providedKey = trim((string)($_SERVER['HTTP_X_HOMESTEAD_HEALTH_KEY'] ?? ''));
    if ($configuredKey !== '' && $providedKey !== '' && hash_equals($configuredKey, $providedKey)) {
        return;
    }

    $user = $auth->user();
    if (is_array($user) && !empty($user['is_platform_admin'])) {
        return;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found.'], JSON_UNESCAPED_SLASHES);
    exit;
}

function health_error(Throwable $exception, array $config): never
{
    $isDebug = !empty($config['app']['debug']) && (($config['app']['environment'] ?? 'production') !== 'production');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'connected' => false,
        'error' => $isDebug ? $exception->getMessage() : 'Health check failed.',
        'timestamp' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
