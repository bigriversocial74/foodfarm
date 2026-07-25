<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\verify_csrf;

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = hash('sha256', $token);
$statement = $pdo->prepare("SELECT * FROM household_invitations WHERE token_hash = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1");
$statement->execute([$tokenHash]);
$invitation = $statement->fetch();
$error = null;

if (!is_array($invitation)) {
    http_response_code(404);
    $error = 'This invitation is invalid, expired, or has already been used.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($invitation)) {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        if (strlen($password) < 12 || $password !== $confirmation) {
            throw new InvalidArgumentException('Use at least 12 characters and confirm the same password.');
        }

        $pdo->beginTransaction();
        $existing = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $existing->execute([$invitation['email']]);
        if ($existing->fetchColumn()) {
            throw new RuntimeException('An account already exists for this email address.');
        }

        $createUser = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, status) VALUES (?, ?, ?, 'active')");
        $createUser->execute([$invitation['email'], password_hash($password, PASSWORD_DEFAULT), $invitation['display_name']]);
        $userId = (int)$pdo->lastInsertId();

        $createMember = $pdo->prepare("INSERT INTO household_members (household_id, user_id, display_name, age_group, role, status, permission_overrides, joined_at) VALUES (?, ?, ?, ?, ?, 'active', ?, CURRENT_DATE)");
        $createMember->execute([$invitation['household_id'], $userId, $invitation['display_name'], $invitation['age_group'], $invitation['role'], $invitation['permission_overrides']]);
        $memberId = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE household_invitations SET accepted_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$invitation['id']]);
        $pdo->prepare("INSERT INTO authentication_events (user_id, household_id, event_type, metadata) VALUES (?, ?, 'invitation_accepted', ?)")->execute([$userId, $invitation['household_id'], json_encode(['invitation_id' => $invitation['id']])]);
        $pdo->commit();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['member_id'] = $memberId;
        $_SESSION['household_id'] = (int)$invitation['household_id'];
        flash('success', 'Your Homestead account is ready.');
        redirect('/phase3.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Accept invitation · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="page-container" style="max-width:680px;padding-top:8vh"><section class="panel"><p class="eyebrow">Family invitation</p><h1>Join Homestead</h1>
<?php if ($error): ?><div class="status status-warning" style="display:block;margin:20px 0"><?= e($error) ?></div><?php elseif (is_array($invitation)): ?><p class="page-description">Create an account for <strong><?= e((string)$invitation['email']) ?></strong> as <?= e(str_replace('_',' ',(string)$invitation['role'])) ?>.</p><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><label>Password<input class="search-field" type="password" name="password" minlength="12" required></label><label>Confirm password<input class="search-field" type="password" name="password_confirmation" minlength="12" required></label><button class="button primary" type="submit">Create account</button></form><?php endif; ?></section></main></body></html>
