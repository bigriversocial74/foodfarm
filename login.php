<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\verify_csrf;

if ($auth->user() !== null) {
    redirect('/phase3.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);

        $now = time();
        $attempts = array_values(array_filter(
            (array)($_SESSION['login_attempts'] ?? []),
            static fn(mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now - 900
        ));
        if (count($attempts) >= 8) {
            throw new RuntimeException('Too many sign-in attempts. Wait 15 minutes and try again.');
        }

        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new InvalidArgumentException('Enter a valid email address and password.');
        }

        if (!$auth->attempt($email, $password)) {
            $attempts[] = $now;
            $_SESSION['login_attempts'] = $attempts;
            $statement = $pdo->prepare("INSERT INTO authentication_events (event_type, ip_address, user_agent, metadata) VALUES ('login_failure', ?, ?, ?)");
            $statement->execute([
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                json_encode(['email_hash' => hash('sha256', $email)]),
            ]);
            throw new RuntimeException('The email address or password is incorrect.');
        }

        unset($_SESSION['login_attempts']);
        $user = $auth->user();
        $statement = $pdo->prepare("INSERT INTO authentication_events (user_id, household_id, event_type, ip_address, user_agent) VALUES (?, ?, 'login_success', ?, ?)");
        $statement->execute([
            $user['id'],
            $user['household_id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        flash('success', 'Welcome back to Homestead.');

        $target = (string)($_SESSION['intended_url'] ?? '/phase3.php');
        unset($_SESSION['intended_url']);
        if (!str_starts_with($target, '/') || str_starts_with($target, '//') || str_contains($target, "\r") || str_contains($target, "\n")) {
            $target = '/phase3.php';
        }
        redirect($target);
    } catch (Throwable $exception) {
        $error = $exception instanceof RuntimeException || $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Sign-in could not be completed. Try again.';
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><main class="page-container" style="max-width:620px;padding-top:8vh"><section class="panel"><p class="eyebrow">Homestead household access</p><h1>Sign in</h1><p class="page-description">Access your family, storage, inventory, and household activity.</p>
<?php if ($error): ?><div class="status status-warning" style="display:block;margin:20px 0"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Email<input class="search-field" type="email" name="email" autocomplete="email" maxlength="190" required></label><label>Password<input class="search-field" type="password" name="password" autocomplete="current-password" required></label><button class="button primary" type="submit">Sign in</button></form></section></main></body></html>
