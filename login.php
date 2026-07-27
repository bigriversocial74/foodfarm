<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\csrf_is_valid;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\redirect;
use function Homestead\user_error_message;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$user = $auth->user();
$error = null;
$success = isset($_SESSION['login_success_message'])
    ? (string)$_SESSION['login_success_message']
    : null;
unset($_SESSION['login_success_message']);

if ($user === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            throw new RuntimeException('The form session expired. Reload this page and try again.');
        }

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

        unset($_SESSION['login_attempts'], $_SESSION['intended_url']);
        $user = $auth->user();
        if (!is_array($user)) {
            throw new RuntimeException('The authenticated account could not be loaded.');
        }

        try {
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
        } catch (Throwable $auditException) {
            error_log('Homestead login audit failed: ' . $auditException->getMessage());
        }

        $_SESSION['login_success_message'] = 'You are signed in. Choose where to continue.';
        redirect('/login.php');
    } catch (Throwable $exception) {
        $error = user_error_message($exception, 'Sign-in could not be completed. Try again.');
    }
}

$publicCss = __DIR__ . '/assets/css/homestead-public.css';
$cssVersion = is_file($publicCss) ? (string)filemtime($publicCss) : '20260727';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <meta name="application-name" content="Homestead">
    <title><?= $user !== null ? 'Signed in' : 'Sign in' ?> · Homestead</title>
    <link rel="icon" href="assets/icons/homestead-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/homestead-public.css?v=<?= e($cssVersion) ?>">
    <style>
        .signed-in-card{display:grid;gap:18px}.signed-in-name{font-size:clamp(2rem,5vw,3.8rem);line-height:.98;margin:0}.signed-in-meta{display:grid;gap:8px;padding:16px;border:1px solid rgba(150,124,80,.25);border-radius:14px;background:rgba(255,255,255,.025)}.signed-in-meta span{overflow-wrap:anywhere}.signed-in-actions{display:grid;gap:10px}.signed-in-actions a{text-align:center;text-decoration:none}.secondary-link{display:block;text-align:center;color:inherit;padding:12px}.signed-in-status{padding:12px 14px;border-radius:12px;background:rgba(75,145,91,.14);border:1px solid rgba(75,145,91,.35)}
    </style>
</head>
<body class="access-page">
<a class="skip-link" href="#main-content">Skip to account access</a>
<main id="main-content" class="access-layout">
    <section class="access-story login-story">
        <a class="site-brand light-brand" href="./" aria-label="Return to Homestead home">
            <span class="brand-seal"><svg viewBox="0 0 48 48" aria-hidden="true">
<circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="1.5"/>
<path d="M24 10v27M24 15c-5-1-8-4-9-8M24 20c5-1 8-4 9-8M24 25c-5-1-8-4-9-8M24 30c5-1 8-4 9-8M18 37h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
</svg></span>
            <span class="brand-word">Homestead</span>
        </a>
        <div class="access-story-copy">
            <p class="gold-kicker">Welcome home</p>
            <h1>Keep the household food cycle moving.</h1>
            <p>Open pantry inventory, recipes, meal plans, garden work, preservation batches, household tasks, alerts, and food-cost history.</p>
        </div>
        <div class="access-points" aria-label="Homestead account features">
            <span>Permission-aware family access</span>
            <span>One connected household food record</span>
            <span>Secure authentication and activity history</span>
        </div>
    </section>

    <section class="access-form-panel">
        <a class="site-brand form-brand" href="./" aria-label="Return to Homestead home">
            <span class="brand-seal"><svg viewBox="0 0 48 48" aria-hidden="true">
<circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="1.5"/>
<path d="M24 10v27M24 15c-5-1-8-4-9-8M24 20c5-1 8-4 9-8M24 25c-5-1-8-4-9-8M24 30c5-1 8-4 9-8M18 37h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
</svg></span>
            <span class="brand-word">Homestead</span>
        </a>

        <?php if ($user === null): ?>
            <div class="form-heading">
                <p class="gold-kicker">Household access</p>
                <h2>Sign in</h2>
                <p>Enter your account credentials to open your household workspace.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-warning" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="public-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>
                    <span>Email address</span>
                    <input type="email" name="email" autocomplete="email" maxlength="190" required>
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="current-password" maxlength="4096" required>
                </label>
                <button class="gold-button full-width" type="submit">Sign in to Homestead</button>
            </form>
            <p class="form-note">Planning and record keeping—not medical, financial, or food-safety certification.</p>
        <?php else: ?>
            <div class="signed-in-card">
                <div class="form-heading">
                    <p class="gold-kicker">Authenticated</p>
                    <h2 class="signed-in-name">Welcome, <?= e((string)$user['display_name']) ?>.</h2>
                    <p>You are signed in at <strong>login.php</strong>. No automatic Phase 3 redirect is being used.</p>
                </div>

                <?php if ($success): ?>
                    <div class="signed-in-status" role="status"><?= e($success) ?></div>
                <?php endif; ?>

                <div class="signed-in-meta">
                    <span><strong>Email:</strong> <?= e((string)$user['email']) ?></span>
                    <span><strong>Role:</strong> <?= e(ucwords(str_replace('_', ' ', (string)$user['role']))) ?></span>
                    <span><strong>Household:</strong> #<?= (int)$user['household_id'] ?></span>
                </div>

                <div class="signed-in-actions">
                    <a class="gold-button full-width" href="./">Open Homestead</a>
                    <a class="secondary-link" href="phase3.php">Open Family Access</a>
                    <a class="secondary-link" href="logout.php">Sign out</a>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
