<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\flash;
use function Homestead\redirect;

$user = $auth->user();
if ($user !== null) {
    $statement = $pdo->prepare("INSERT INTO authentication_events (user_id, household_id, event_type, ip_address, user_agent) VALUES (?, ?, 'logout', ?, ?)");
    $statement->execute([$user['id'], $user['household_id'], $_SERVER['REMOTE_ADDR'] ?? null, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
}
$auth->logout();
flash('success', 'You have been signed out.');
redirect('/login.php');
