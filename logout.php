<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\verify_csrf;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

verify_csrf($_POST['csrf_token'] ?? null);
$user = $auth->user();
if ($user !== null) {
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
}

$auth->logout();
unset($_SESSION['csrf_token'], $_SESSION['recipe_completion_key']);
flash('success', 'You have been signed out.');
redirect('/login.php');
