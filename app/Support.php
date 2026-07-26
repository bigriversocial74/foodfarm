<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_path(): string
{
    if (defined('HOMESTEAD_BASE_PATH')) {
        return (string)HOMESTEAD_BASE_PATH;
    }

    return '';
}

function app_url(string $path = '/'): string
{
    if ($path === '' || $path === '/') {
        return app_base_path() !== '' ? app_base_path() . '/' : '/';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) === 1 || str_starts_with($path, '//') || str_starts_with($path, '#')) {
        return $path;
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    $basePath = app_base_path();
    if ($basePath === '' || $path === $basePath || str_starts_with($path, $basePath . '/')) {
        return $path;
    }

    return $basePath . $path;
}

function rewrite_app_urls(string $content): string
{
    if (app_base_path() === '' || $content === '') {
        return $content;
    }

    $rewritten = preg_replace_callback(
        '/\\b(href|src|action)=("|\\\')(\\/(?!\\/)[^"\\\']*)\\2/i',
        static fn(array $match): string => $match[1] . '=' . $match[2] . app_url($match[3]) . $match[2],
        $content
    );

    return is_string($rewritten) ? $rewritten : $content;
}

function apply_security_headers(bool $isProduction): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    $contentSecurityPolicy = "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'";
    if ($isProduction) {
        $contentSecurityPolicy .= '; upgrade-insecure-requests';
    }
    header('Content-Security-Policy: ' . $contentSecurityPolicy);
    header('X-Frame-Options: DENY');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
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
    $safeType = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
    $_SESSION['flash'][] = ['type' => $safeType, 'message' => mb_substr($message, 0, 1000)];
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
    header('Location: ' . app_url($url), true, 303);
    exit;
}

function positive_decimal(mixed $value, string $field, bool $allowZero = true): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException($field . ' must be a number.');
    }

    $number = (float)$value;
    if (!is_finite($number) || $number < 0 || (!$allowZero && $number === 0.0)) {
        throw new InvalidArgumentException($field . ' must be greater than' . ($allowZero ? ' or equal to' : '') . ' zero.');
    }

    return $number;
}

/**
 * Return only intentional application-validation messages to the browser.
 * Database, driver, and unexpected runtime details remain server-side.
 */
function user_error_message(Throwable $exception, string $fallback = 'The request could not be completed. Try again.'): string
{
    if ($exception instanceof InvalidArgumentException) {
        return $exception->getMessage();
    }
    if ($exception instanceof RuntimeException && !$exception instanceof PDOException) {
        return $exception->getMessage();
    }

    error_log(sprintf(
        'Homestead error [%s]: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    return $fallback;
}

function require_health_access(array $config, Auth $auth): void
{
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: application/json; charset=utf-8');

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
    if (!$isDebug) {
        error_log(sprintf('Homestead health error [%s]: %s', $exception::class, $exception->getMessage()));
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'connected' => false,
        'error' => $isDebug ? $exception->getMessage() : 'Health check failed.',
        'timestamp' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
