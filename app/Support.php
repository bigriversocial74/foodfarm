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


function normalize_app_base_path(string $path): string
{
    $normalized = '/' . trim(str_replace('\\', '/', $path), '/');
    return $normalized === '/' ? '' : $normalized;
}

function resolve_app_base_path(array $config, array $server, string $appRoot): string
{
    $normalizedRoot = rtrim(str_replace('\\', '/', (string)(realpath($appRoot) ?: $appRoot)), '/');
    $scriptFilenameRaw = (string)($server['SCRIPT_FILENAME'] ?? '');
    $scriptFilename = str_replace('\\', '/', (string)(realpath($scriptFilenameRaw) ?: $scriptFilenameRaw));
    $scriptName = str_replace('\\', '/', (string)($server['SCRIPT_NAME'] ?? ''));

    if ($normalizedRoot !== '' && $scriptFilename !== '' && str_starts_with($scriptFilename, $normalizedRoot)) {
        $relativeScript = substr($scriptFilename, strlen($normalizedRoot));
        if ($relativeScript !== '' && str_ends_with($scriptName, $relativeScript)) {
            return normalize_app_base_path(substr($scriptName, 0, -strlen($relativeScript)));
        }
    }

    $configuredPath = parse_url((string)($config['app']['base_url'] ?? ''), PHP_URL_PATH);
    return normalize_app_base_path(is_string($configuredPath) ? $configuredPath : '');
}

function request_uses_https(array $config, array $server): bool
{
    $https = strtolower(trim((string)($server['HTTPS'] ?? '')));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    if ((int)($server['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    $forwardedProto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($forwardedProto === 'https') {
        return true;
    }

    return strtolower((string)parse_url((string)($config['app']['base_url'] ?? ''), PHP_URL_SCHEME)) === 'https';
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

function csrf_is_valid(?string $token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function verify_csrf(?string $token): void
{
    if (!csrf_is_valid($token)) {
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

function resolve_redirect_target(string $url, array $server, string $basePath = ''): string
{
    if (!str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\x00-\x1F\x7F]/', $url)) {
        throw new RuntimeException('Unsafe redirect target.');
    }

    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'], $parts['port'])) {
        throw new RuntimeException('Unsafe redirect target.');
    }

    $targetPath = (string)($parts['path'] ?? '/');
    if (!str_starts_with($targetPath, '/') || str_starts_with($targetPath, '//')) {
        throw new RuntimeException('Unsafe redirect target.');
    }

    $suffix = isset($parts['query']) ? '?' . $parts['query'] : '';
    $suffix .= isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    $normalizedBasePath = normalize_app_base_path($basePath);

    $requestUri = (string)($server['REQUEST_URI'] ?? '');
    if (!str_starts_with($requestUri, '//')) {
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        if (is_string($requestPath)) {
            $requestPath = '/' . ltrim(str_replace('\\', '/', $requestPath), '/');
            if (
                $requestPath !== '/'
                && !str_starts_with($requestPath, '//')
                && preg_match('/[\x00-\x1F\x7F]/', $requestPath) !== 1
                && ($requestPath === $targetPath || str_ends_with($requestPath, $targetPath))
            ) {
                return $requestPath . $suffix;
            }
        }
    }

    if (
        $normalizedBasePath !== ''
        && ($targetPath === $normalizedBasePath || str_starts_with($targetPath, $normalizedBasePath . '/'))
    ) {
        return $targetPath . $suffix;
    }

    return $normalizedBasePath . $targetPath . $suffix;
}

function redirect(string $url): never
{
    $basePath = defined('HOMESTEAD_BASE_PATH') ? (string)HOMESTEAD_BASE_PATH : '';
    $target = resolve_redirect_target($url, $_SERVER, $basePath);
    header('Location: ' . $target, true, 303);
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
