<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

$user = $auth->requireUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);

        $attempts = array_values(array_filter(
            (array)($_SESSION['password_change_attempts'] ?? []),
            static fn(mixed $time): bool => is_int($time) && $time >= time() - 900
        ));
        $persistent = $pdo->prepare(
            "SELECT COUNT(*) FROM authentication_events
             WHERE user_id = ? AND event_type = 'password_change_failure'
               AND occurred_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
        );
        $persistent->execute([$user['id']]);
        if (count($attempts) >= 5 || (int)$persistent->fetchColumn() >= 8) {
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
        $statement = $pdo->prepare("SELECT password_hash, auth_version FROM users WHERE id = ? AND status = 'active' FOR UPDATE");
        $statement->execute([$user['id']]);
        $account = $statement->fetch();
        if (!is_array($account) || !password_verify($current, (string)$account['password_hash'])) {
            $attempts[] = time();
            $_SESSION['password_change_attempts'] = $attempts;
            $pdo->rollBack();
            $pdo->prepare(
                "INSERT INTO authentication_events
                 (user_id, household_id, event_type, ip_address, user_agent)
                 VALUES (?, ?, 'password_change_failure', ?, ?)"
            )->execute([
                $user['id'], $user['household_id'], $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            throw new RuntimeException('The current password is incorrect.');
        }
        if (password_verify($password, (string)$account['password_hash'])) {
            throw new InvalidArgumentException('Choose a password different from the current password.');
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($newHash)) {
            throw new RuntimeException('Password processing failed.');
        }
        $newAuthVersion = (int)$account['auth_version'] + 1;
        $update = $pdo->prepare(
            "UPDATE users SET password_hash = ?, auth_version = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'active' AND auth_version = ?"
        );
        $update->execute([$newHash, $newAuthVersion, $user['id'], $account['auth_version']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The account could not be updated.');
        }
        $pdo->prepare(
            "INSERT INTO authentication_events
             (user_id, household_id, event_type, ip_address, user_agent)
             VALUES (?, ?, 'password_changed', ?, ?)"
        )->execute([
            $user['id'], $user['household_id'], $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $pdo->commit();

        unset($_SESSION['password_change_attempts']);
        session_regenerate_id(true);
        $_SESSION['auth_version'] = $newAuthVersion;
        unset($_SESSION['csrf_token']);
        flash('success', 'Password updated. Other signed-in sessions were invalidated.');
        redirect('/account.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception, 'The password could not be updated. Try again.'));
        redirect('/account.php');
    }
}

$flashes = consume_flashes();
$roleLabel = ucwords(str_replace('_', ' ', (string)$user['role']));
$initials = '';
foreach (preg_split('/\s+/', trim((string)$user['display_name'])) ?: [] as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
$initials = substr($initials !== '' ? $initials : 'HS', 0, 2);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Account & Security · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to account settings</a>
<main id="main-content" class="page-container account-page">
    <section class="account-hero" aria-labelledby="account-title">
        <div class="account-hero__copy">
            <p class="account-kicker">Account security</p>
            <h1 id="account-title">Your identity.<br><span>Your access.</span></h1>
            <p>Review the account connected to this household and update the password protecting your Homestead access.</p>
            <div class="account-hero__meta" aria-label="Account summary">
                <span>Active account</span>
                <span><?= e($roleLabel) ?></span>
                <span>Household #<?= (int)$user['household_id'] ?></span>
            </div>
        </div>
        <article class="account-identity-card">
            <div class="account-avatar" aria-hidden="true"><?= e($initials) ?></div>
            <div>
                <p class="account-kicker">Signed in as</p>
                <h2><?= e((string)$user['display_name']) ?></h2>
                <p><?= e((string)$user['email']) ?></p>
                <span><?= e($roleLabel) ?></span>
            </div>
        </article>
    </section>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="account-flash account-flash--<?= $message['type'] === 'error' ? 'warning' : 'good' ?>"><?= e((string)$message['message']) ?></div>
    <?php endforeach; ?>

    <section class="account-metrics" aria-label="Security posture">
        <article><span>●</span><div><small>Account status</small><strong>Active</strong><p>Authenticated household access</p></div></article>
        <article><span>12+</span><div><small>Password minimum</small><strong>12 characters</strong><p>Required before submission</p></div></article>
        <article><span>↻</span><div><small>Session protection</small><strong>Automatic</strong><p>Other sessions close after change</p></div></article>
        <article><span>5</span><div><small>Attempt limit</small><strong>Protected</strong><p>15-minute change throttle</p></div></article>
    </section>

    <div class="account-layout">
        <div class="account-primary">
            <section class="account-panel account-password-panel" aria-labelledby="password-title">
                <header class="account-panel__heading">
                    <div><p class="account-kicker">Password</p><h2 id="password-title">Change password</h2></div>
                    <span>Other signed-in sessions will be invalidated</span>
                </header>
                <form method="post" class="account-password-form" data-password-form>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label>Current password
                        <span class="account-input-wrap"><input type="password" name="current_password" autocomplete="current-password" maxlength="4096" required data-password-field="current"><button type="button" class="account-reveal" data-password-toggle="current" aria-label="Show current password">Show</button></span>
                    </label>
                    <div class="account-form-pair">
                        <label>New password
                            <span class="account-input-wrap"><input type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="4096" required data-password-field="new"><button type="button" class="account-reveal" data-password-toggle="new" aria-label="Show new password">Show</button></span>
                        </label>
                        <label>Confirm new password
                            <span class="account-input-wrap"><input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="4096" required data-password-field="confirm"><button type="button" class="account-reveal" data-password-toggle="confirm" aria-label="Show password confirmation">Show</button></span>
                        </label>
                    </div>
                    <div class="account-strength" aria-live="polite">
                        <div><span data-strength-bar></span></div>
                        <p data-strength-label>Use at least 12 characters. A longer, unique passphrase is easier to remember and harder to guess.</p>
                    </div>
                    <ul class="account-password-checks" aria-label="Password requirements">
                        <li data-password-check="length">At least 12 characters</li>
                        <li data-password-check="different">Different from the current password</li>
                        <li data-password-check="match">Confirmation matches</li>
                    </ul>
                    <div class="account-form-actions">
                        <button type="submit">Update password</button>
                        <p>Password changes are logged as account-security events.</p>
                    </div>
                </form>
            </section>

            <section class="account-panel">
                <header class="account-panel__heading"><div><p class="account-kicker">Built-in safeguards</p><h2>How your account is protected</h2></div></header>
                <div class="account-protections">
                    <article><span>01</span><div><h3>Current-password verification</h3><p>Your existing password must be verified before the account can be changed.</p></div></article>
                    <article><span>02</span><div><h3>Attempt throttling</h3><p>Repeated failures are limited using both the current session and persistent authentication events.</p></div></article>
                    <article><span>03</span><div><h3>Session invalidation</h3><p>A successful change advances the authentication version and signs out other active sessions.</p></div></article>
                    <article><span>04</span><div><h3>Transaction safety</h3><p>The active account is locked while its password hash and authentication version are updated together.</p></div></article>
                </div>
            </section>
        </div>

        <aside class="account-sidebar" aria-label="Account details and related settings">
            <section class="account-panel account-details">
                <header class="account-panel__heading"><div><p class="account-kicker">Account details</p><h2>Profile</h2></div></header>
                <dl>
                    <div><dt>Display name</dt><dd><?= e((string)$user['display_name']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= e((string)$user['email']) ?></dd></div>
                    <div><dt>Household role</dt><dd><?= e($roleLabel) ?></dd></div>
                    <div><dt>Member ID</dt><dd>#<?= (int)$user['member_id'] ?></dd></div>
                    <div><dt>Household ID</dt><dd>#<?= (int)$user['household_id'] ?></dd></div>
                </dl>
            </section>

            <section class="account-panel account-links">
                <header class="account-panel__heading"><div><p class="account-kicker">Related controls</p><h2>Manage access</h2></div></header>
                <nav aria-label="Related account controls">
                    <a href="phase3.php"><span>Family Access</span><small>Members, invitations, roles, and permissions</small></a>
                    <a href="phase11.php"><span>Alerts & Calendar</span><small>Notification and digest preferences</small></a>
                    <a href="dashboard.php"><span>Dashboard</span><small>Return to the household command center</small></a>
                </nav>
            </section>

            <section class="account-security-note">
                <span aria-hidden="true">i</span>
                <div><strong>Profile changes</strong><p>Display name, email, role, and household membership are controlled through the Family Access workspace.</p></div>
            </section>
        </aside>
    </div>
</main>
<script src="assets/js/homestead-account.js" defer></script>
</body>
</html>
