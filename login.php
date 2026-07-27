<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
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

        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $password === '' || strlen($password) > 4096) {
            throw new InvalidArgumentException('Enter a valid email address and password.');
        }

        $ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $emailHash = hash('sha256', $email);
        $persistentAttempts = $pdo->prepare(
            "SELECT COUNT(*) FROM authentication_events
             WHERE event_type = 'login_failure'
               AND occurred_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
               AND (COALESCE(ip_address, '') = ? OR JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.email_hash')) = ?)"
        );
        $persistentAttempts->execute([$ipAddress, $emailHash]);
        if (count($attempts) >= 8 || (int)$persistentAttempts->fetchColumn() >= 12) {
            throw new RuntimeException('Too many sign-in attempts. Wait 15 minutes and try again.');
        }

        if (!$auth->attempt($email, $password)) {
            $attempts[] = $now;
            $_SESSION['login_attempts'] = $attempts;
            $statement = $pdo->prepare(
                "INSERT INTO authentication_events (event_type, ip_address, user_agent, metadata)
                 VALUES ('login_failure', ?, ?, ?)"
            );
            $statement->execute([
                $ipAddress ?: null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                json_encode(['email_hash' => $emailHash], JSON_THROW_ON_ERROR),
            ]);
            throw new RuntimeException('The email address or password is incorrect.');
        }

        unset($_SESSION['login_attempts']);
        $user = $auth->user();
        if (!is_array($user)) {
            throw new RuntimeException('The authenticated account could not be loaded.');
        }
        $statement = $pdo->prepare(
            "INSERT INTO authentication_events (user_id, household_id, event_type, ip_address, user_agent)
             VALUES (?, ?, 'login_success', ?, ?)"
        );
        $statement->execute([
            $user['id'],
            $user['household_id'],
            $ipAddress ?: null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        flash('success', 'Welcome back to Homestead.');

        $target = (string)($_SESSION['intended_url'] ?? '/phase3.php');
        unset($_SESSION['intended_url']);
        if (!str_starts_with($target, '/') || str_starts_with($target, '//') || preg_match('/[\x00-\x1F\x7F]/', $target)) {
            $target = '/phase3.php';
        }
        redirect($target);
    } catch (Throwable $exception) {
        $error = user_error_message($exception, 'Sign-in could not be completed. Try again.');
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#1f4b36">
    <meta name="application-name" content="Homestead">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>Sign in · Homestead</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/assets/icons/homestead-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/icons/homestead-icon.svg">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/pwa.js?v=20260727" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to sign-in</a>
<main id="main-content" class="auth-page">
    <section class="auth-shell">
        <div class="auth-story">
            <div>
                <a class="landing-brand" href="/" style="color:#fff" aria-label="Return to Homestead home">
                    <span class="landing-brand-mark" aria-hidden="true" style="background:rgba(255,255,255,.14);box-shadow:none">H</span>
                    <span><strong style="color:#fff">Homestead</strong><small style="color:rgba(255,255,255,.66)">Household food system</small></span>
                </a>
                <p class="eyebrow" style="margin-top:64px;color:#d5bf91">Welcome home</p>
                <h1>Keep the household food cycle moving.</h1>
                <p>Access pantry inventory, recipes, meal plans, garden work, preservation, household tasks, alerts, nutrition planning, and food-cost history.</p>
            </div>
            <div class="auth-points" aria-label="Homestead account features">
                <span>Permission-aware family access</span>
                <span>One connected household food record</span>
                <span>Secure activity and authentication history</span>
            </div>
        </div>

        <div class="auth-form">
            <a class="auth-brand" href="/" aria-label="Return to Homestead home">
                <span class="landing-brand-mark" aria-hidden="true">H</span>
                <span><strong>Homestead</strong></span>
            </a>
            <p class="eyebrow">Household access</p>
            <h2>Sign in</h2>
            <p class="page-description" style="margin:10px 0 26px">Enter your account credentials to open your household workspace.</p>

            <?php if ($error): ?>
                <div class="status status-warning" role="alert" style="display:flex;width:100%;margin:0 0 20px;border-radius:12px;padding:11px 12px"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Email address
                    <input type="email" name="email" autocomplete="email" maxlength="190" required>
                </label>
                <label>Password
                    <input type="password" name="password" autocomplete="current-password" maxlength="4096" required>
                </label>
                <button class="button primary" type="submit">Sign in to Homestead</button>
            </form>
        </div>
    </section>
</main>
</body>
</html>
