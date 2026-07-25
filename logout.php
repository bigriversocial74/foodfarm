<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\verify_csrf;

header('Cache-Control: no-store, max-age=0');

$user = $auth->user();
if ($user === null) {
    redirect('/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? null);

    try {
        $statement = $pdo->prepare(
            "INSERT INTO authentication_events
             (user_id, household_id, event_type, ip_address, user_agent)
             VALUES (?, ?, 'logout', ?, ?)"
        );
        $statement->execute([
            $user['id'],
            $user['household_id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $exception) {
        error_log('Homestead logout audit failed: ' . $exception->getMessage());
    }

    $auth->logout();
    flash('success', 'You have been signed out.');
    redirect('/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign out · Homestead</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to sign out</a>
<main id="main-content" class="page-container" style="max-width:620px;padding-top:8vh">
    <section class="panel">
        <p class="eyebrow">Account security</p>
        <h1>Sign out?</h1>
        <p class="page-description">End the active session for <?= e((string)$user['display_name']) ?>.</p>
        <form method="post" class="form-grid" style="margin-top:20px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="button primary" type="submit">Sign out securely</button>
            <a class="button secondary" href="/phase3.php">Cancel</a>
        </form>
    </section>
</main>
</body>
</html>
