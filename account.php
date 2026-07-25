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

        $attempts = array_values(array_filter((array)($_SESSION['password_change_attempts'] ?? []), static fn(int $time): bool => $time >= time() - 900));
        if (count($attempts) >= 5) {
            throw new RuntimeException('Too many password-change attempts. Try again later.');
        }

        $current = (string)($_POST['current_password'] ?? '');
        $password = (string)($_POST['new_password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        if ($current === '' || strlen($current) > 4096 || strlen($password) > 4096 || strlen($confirmation) > 4096) {
            throw new InvalidArgumentException('Enter valid password values.');
        }
        if (strlen($password) < 12 || $password !== $confirmation) {
            throw new InvalidArgumentException('The new password must be at least 12 characters and match its confirmation.');
        }

        $pdo->beginTransaction();
        $statement = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND status = 'active' FOR UPDATE");
        $statement->execute([$user['id']]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || !password_verify($current, $hash)) {
            $attempts[] = time();
            $_SESSION['password_change_attempts'] = $attempts;
            $pdo->rollBack();
            $pdo->prepare("INSERT INTO authentication_events (user_id, household_id, event_type, ip_address, user_agent) VALUES (?, ?, 'password_change_failure', ?, ?)")
                ->execute([$user['id'], $user['household_id'], $_SERVER['REMOTE_ADDR'] ?? null, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
            throw new RuntimeException('The current password is incorrect.');
        }
        if (password_verify($password, $hash)) {
            throw new InvalidArgumentException('Choose a password different from the current password.');
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($newHash)) {
            throw new RuntimeException('Password processing failed.');
        }
        $update = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'active'");
        $update->execute([$newHash, $user['id']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The account could not be updated.');
        }
        $pdo->prepare("INSERT INTO authentication_events (user_id, household_id, event_type, ip_address, user_agent) VALUES (?, ?, 'password_changed', ?, ?)")
            ->execute([$user['id'], $user['household_id'], $_SERVER['REMOTE_ADDR'] ?? null, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        $pdo->commit();

        unset($_SESSION['password_change_attempts']);
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        flash('success', 'Password updated.');
        redirect('/account.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
        redirect('/account.php');
    }
}

$flashes = consume_flashes();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="page-container" style="max-width:760px"><header class="page-header"><div><p class="eyebrow">Account security</p><h1><?= e((string)$user['display_name']) ?></h1><p class="page-description"><?= e((string)$user['email']) ?> · <?= e(str_replace('_',' ',(string)$user['role'])) ?></p></div><a href="/phase3.php">Back to access workspace</a></header><?php foreach ($flashes as $message): ?><div class="status status-<?= $message['type']==='error'?'warning':'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?><section class="panel"><p class="eyebrow">Password</p><h2>Change password</h2><form method="post" class="form-grid" style="margin-top:20px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Current password<input class="search-field" type="password" name="current_password" autocomplete="current-password" maxlength="4096" required></label><label>New password<input class="search-field" type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="4096" required></label><label>Confirm new password<input class="search-field" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="4096" required></label><button class="button primary" type="submit">Update password</button></form></section></main></body></html>
