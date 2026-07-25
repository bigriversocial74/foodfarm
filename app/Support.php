<?php

declare(strict_types=1);

namespace Homestead;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
    header('Location: ' . $url, true, 303);
    exit;
}

function positive_decimal(mixed $value, string $field, bool $allowZero = true): float
{
    if (!is_numeric($value)) {
        throw new \InvalidArgumentException($field . ' must be a number.');
    }

    $number = (float)$value;
    if ($number < 0 || (!$allowZero && $number === 0.0)) {
        throw new \InvalidArgumentException($field . ' must be greater than' . ($allowZero ? ' or equal to' : '') . ' zero.');
    }

    return $number;
}
