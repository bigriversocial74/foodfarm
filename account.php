<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\verify_csrf;

$user = $auth->requireUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $current = (string)($_POST['current_password'] ?? '');
        $password = (string)($_POST['new_password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');

        $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$user['id']]);
        $hash = (string)$statement->fetchColumn();
        if (!password_verify($current, $hash)) {
            throw new RuntimeException('The current password is incorrect.');
        }
        if (strlen($password) < 12 || $password !== $confirmation) {
            throw new InvalidArgumentException('The new password must be at least 12 characters and match its confirmation.');
        }
        if (password_verify($password, $hash)) {
            throw new InvalidArgumentException('Choose a password different from the current password.');
        }

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        $pdo->prepare("INSERT INTO authentication_events (user_id, household_id, event_type, ip_address, user_agent) VALUES (?, ?, 'password_changed', ?, ?)")->execute([$user['id'],$user['household_id'],$_SERVER['REMOTE_ADDR'] ?? null,substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,255)]);
        $pdo->commit();
        session_regenerate_id(true);
        flash('success','Password updated.');
        redirect('/account.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error',$exception->getMessage());
        redirect('/account.php');
    }
}

$flashes = consume_flashes();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="page-container" style="max-width:760px"><header class="page-header"><div><p class="eyebrow">Account security</p><h1><?= e((string)$user['display_name']) ?></h1><p class="page-description"><?= e((string)$user['email']) ?> · <?= e(str_replace('_',' ',(string)$user['role'])) ?></p></div><a href="/phase3.php">Back to access workspace</a></header><?php foreach ($flashes as $message): ?><div class="status status-<?= $message['type']==='error'?'warning':'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?><section class="panel"><p class="eyebrow">Password</p><h2>Change password</h2><form method="post" class="form-grid" style="margin-top:20px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Current password<input class="search-field" type="password" name="current_password" autocomplete="current-password" required></label><label>New password<input class="search-field" type="password" name="new_password" autocomplete="new-password" minlength="12" required></label><label>Confirm new password<input class="search-field" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label><button class="button primary" type="submit">Update password</button></form></section></main></body></html>
