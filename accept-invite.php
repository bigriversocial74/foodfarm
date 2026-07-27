<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

header('Referrer-Policy: no-referrer');

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = null;
$invitation = null;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $lookup = $pdo->prepare(
        "SELECT id, household_id, email, display_name, age_group, role, permission_overrides
         FROM household_invitations
         WHERE token_hash = ? AND accepted_at IS NULL AND revoked_at IS NULL
           AND expires_at > UTC_TIMESTAMP() LIMIT 1"
    );
    $lookup->execute([hash('sha256', $token)]);
    $invitation = $lookup->fetch() ?: null;
}
if (!is_array($invitation)) {
    http_response_code(404);
    $error = 'This invitation is invalid, expired, or has already been used.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($invitation)) {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        if (strlen($password) < 12 || strlen($password) > 4096 || $password !== $confirmation) {
            throw new InvalidArgumentException('Use 12–4,096 characters and confirm the same password.');
        }

        $pdo->beginTransaction();
        $lock = $pdo->prepare(
            "SELECT id, household_id, email, display_name, age_group, role, permission_overrides
             FROM household_invitations
             WHERE id = ? AND token_hash = ? AND accepted_at IS NULL AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP() FOR UPDATE"
        );
        $lock->execute([(int)$invitation['id'], hash('sha256', $token)]);
        $invitation = $lock->fetch();
        if (!is_array($invitation)) {
            throw new RuntimeException('This invitation is no longer available.');
        }

        $existing = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1 FOR UPDATE');
        $existing->execute([$invitation['email']]);
        if ($existing->fetchColumn()) {
            throw new RuntimeException('An account already exists for this email address.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Password processing failed.');
        }
        $createUser = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, status) VALUES (?, ?, ?, 'active')");
        $createUser->execute([strtolower((string)$invitation['email']), $passwordHash, $invitation['display_name']]);
        $userId = (int)$pdo->lastInsertId();

        $createMember = $pdo->prepare(
            "INSERT INTO household_members
             (household_id, user_id, display_name, age_group, role, status, permission_overrides, joined_at)
             VALUES (?, ?, ?, ?, ?, 'active', ?, CURRENT_DATE)"
        );
        $createMember->execute([
            $invitation['household_id'], $userId, $invitation['display_name'], $invitation['age_group'],
            $invitation['role'], $invitation['permission_overrides'],
        ]);
        $memberId = (int)$pdo->lastInsertId();

        $consume = $pdo->prepare(
            'UPDATE household_invitations SET accepted_at = UTC_TIMESTAMP()
             WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL'
        );
        $consume->execute([$invitation['id']]);
        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('This invitation was already used.');
        }
        $pdo->prepare(
            "INSERT INTO authentication_events (user_id, household_id, event_type, metadata)
             VALUES (?, ?, 'invitation_accepted', ?)"
        )->execute([$userId, $invitation['household_id'], json_encode(['invitation_id' => $invitation['id']], JSON_THROW_ON_ERROR)]);
        $pdo->commit();

        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['member_id'] = $memberId;
        $_SESSION['household_id'] = (int)$invitation['household_id'];
        $_SESSION['auth_version'] = 1;
        $_SESSION['authenticated_at'] = time();
        flash('success', 'Your Homestead account is ready.');
        redirect('/phase3.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = user_error_message($exception, 'The invitation could not be accepted. Try again.');
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title>Accept invitation · Homestead</title>
    <link rel="icon" href="assets/icons/homestead-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/homestead-public.css?v=20260727-1">
</head>
<body class="access-page">
<a class="skip-link" href="#main-content">Skip to invitation</a>
<main id="main-content" class="access-layout">
    <section class="access-story invite-story">
        <a class="site-brand light-brand" href="./" aria-label="Return to Homestead home">
            <span class="brand-seal"><svg viewBox="0 0 48 48" aria-hidden="true">
<circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="1.5"/>
<path d="M24 10v27M24 15c-5-1-8-4-9-8M24 20c5-1 8-4 9-8M24 25c-5-1-8-4-9-8M24 30c5-1 8-4 9-8M18 37h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
</svg></span>
            <span class="brand-word">Homestead</span>
        </a>
        <div class="access-story-copy">
            <p class="gold-kicker">Family invitation</p>
            <h1>Bring your part of the household into view.</h1>
            <p>Join the shared food system with access appropriate to your household role and responsibilities.</p>
        </div>
        <div class="access-points">
            <span>Private, role-aware access</span>
            <span>Shared tasks, meals, shopping, and calendars</span>
            <span>Connected pantry, garden, and preservation records</span>
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
        <div class="form-heading">
            <p class="gold-kicker">Household onboarding</p>
            <h2>Join Homestead</h2>
            <?php if (is_array($invitation)): ?>
                <p>Create an account for <strong><?= e((string)$invitation['email']) ?></strong> as <?= e(str_replace('_', ' ', (string)$invitation['role'])) ?>.</p>
            <?php else: ?>
                <p>Invitation access could not be verified.</p>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-warning" role="alert"><?= e($error) ?></div>
        <?php elseif (is_array($invitation)): ?>
            <form method="post" class="public-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>
                    <span>Password</span>
                    <input type="password" name="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                </label>
                <label>
                    <span>Confirm password</span>
                    <input type="password" name="password_confirmation" minlength="12" maxlength="4096" autocomplete="new-password" required>
                </label>
                <button class="gold-button full-width" type="submit">Create account</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
