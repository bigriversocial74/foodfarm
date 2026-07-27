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
$householdId = (int)$user['household_id'];
$canInvite = $auth->can($user, 'members.invite');
$canManagePermissions = $auth->can($user, 'permissions.manage');
$canManageMembers = $auth->can($user, 'members.manage');
if (!$canInvite && !$canManagePermissions && !$canManageMembers) {
    http_response_code(403);
    exit('You do not have permission to administer household access.');
}

$permissions = [
    'members.manage', 'members.invite', 'permissions.manage',
    'storage.view', 'storage.manage', 'inventory.view', 'inventory.manage',
    'recipes.view', 'recipes.manage', 'recipes.complete', 'meals.manage',
    'garden.view', 'garden.manage', 'harvest.record',
    'preservation.view', 'preservation.manage',
    'tasks.manage', 'tasks.complete',
    'finance.view', 'finance.manage',
    'nutrition.view', 'nutrition.manage',
    'notifications.view', 'notifications.manage',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'invite') {
            $auth->requirePermission($user, 'members.invite');
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $role = (string)($_POST['role'] ?? 'adult_member');
            $ageGroup = (string)($_POST['age_group'] ?? 'adult');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
                || $displayName === '' || mb_strlen($displayName) > 120
                || !in_array($role, ['administrator', 'adult_member', 'youth_member', 'guest_helper'], true)
                || !in_array($ageGroup, ['adult', 'teen', 'child', 'guest'], true)) {
                throw new InvalidArgumentException('Enter a valid name, email, age group, and role.');
            }
            if (($role === 'youth_member' && !in_array($ageGroup, ['teen', 'child'], true))
                || (in_array($role, ['administrator', 'adult_member'], true) && $ageGroup !== 'adult')
                || ($role === 'guest_helper' && $ageGroup !== 'guest')) {
                throw new InvalidArgumentException('The selected age group and household role do not match.');
            }

            $pdo->beginTransaction();
            $householdLock = $pdo->prepare('SELECT id FROM households WHERE id = ? FOR UPDATE');
            $householdLock->execute([$householdId]);
            if (!$householdLock->fetchColumn()) {
                throw new RuntimeException('The household is unavailable.');
            }
            $existing = $pdo->prepare(
                'SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)
                 UNION ALL
                 SELECT 1 FROM household_invitations
                 WHERE household_id = ? AND LOWER(email) = LOWER(?) AND accepted_at IS NULL
                   AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1'
            );
            $existing->execute([$email, $householdId, $email]);
            if ($existing->fetchColumn()) {
                throw new RuntimeException('An account or active invitation already exists for this email address.');
            }

            $token = bin2hex(random_bytes(32));
            $statement = $pdo->prepare(
                "INSERT INTO household_invitations
                 (household_id, email, display_name, age_group, role, token_hash, invited_by_member_id, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))"
            );
            $statement->execute([$householdId, $email, $displayName, $ageGroup, $role, hash('sha256', $token), $user['member_id']]);
            $pdo->prepare(
                "INSERT INTO authentication_events (user_id, household_id, event_type, metadata)
                 VALUES (?, ?, 'invitation_created', ?)"
            )->execute([$user['id'], $householdId, json_encode(['email_hash' => hash('sha256', $email)], JSON_THROW_ON_ERROR)]);
            $pdo->commit();

            $_SESSION['latest_invite_url'] = '/accept-invite.php?token=' . $token;
            flash('success', 'Invitation created. Copy the secure invitation URL shown below.');
            redirect('/phase3.php');
        }

        if ($action === 'revoke_invite') {
            $auth->requirePermission($user, 'members.invite');
            $id = (int)($_POST['invitation_id'] ?? 0);
            if ($id < 1) {
                throw new InvalidArgumentException('Choose an invitation to revoke.');
            }
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'UPDATE household_invitations SET revoked_at = UTC_TIMESTAMP()
                 WHERE id = ? AND household_id = ? AND accepted_at IS NULL AND revoked_at IS NULL'
            );
            $statement->execute([$id, $householdId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The invitation is unavailable or already closed.');
            }
            $pdo->prepare(
                "INSERT INTO authentication_events (user_id, household_id, event_type, metadata)
                 VALUES (?, ?, 'invitation_revoked', ?)"
            )->execute([$user['id'], $householdId, json_encode(['invitation_id' => $id], JSON_THROW_ON_ERROR)]);
            $pdo->commit();
            flash('success', 'Invitation revoked.');
            redirect('/phase3.php');
        }

        if ($action === 'update_permissions') {
            $auth->requirePermission($user, 'permissions.manage');
            $targetMemberId = (int)($_POST['member_id'] ?? 0);
            if ($targetMemberId < 1 || $targetMemberId === (int)$user['member_id']) {
                throw new RuntimeException('You cannot change your own permission overrides here.');
            }
            $target = $pdo->prepare('SELECT role FROM household_members WHERE id = ? AND household_id = ? LIMIT 1');
            $target->execute([$targetMemberId, $householdId]);
            $targetRole = $target->fetchColumn();
            if (!$targetRole || $targetRole === 'owner') {
                throw new RuntimeException('The selected member cannot be modified.');
            }

            $overrides = [];
            foreach ($permissions as $permission) {
                $value = (string)($_POST['permission'][$permission] ?? 'inherit');
                if ($value === 'allow') {
                    $overrides[$permission] = true;
                } elseif ($value === 'deny') {
                    $overrides[$permission] = false;
                } elseif ($value !== 'inherit') {
                    throw new InvalidArgumentException('A permission override is invalid.');
                }
            }
            $statement = $pdo->prepare(
                "UPDATE household_members SET permission_overrides = ?
                 WHERE id = ? AND household_id = ? AND role <> 'owner'"
            );
            $statement->execute([json_encode($overrides, JSON_THROW_ON_ERROR), $targetMemberId, $householdId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Permission overrides were not updated.');
            }
            $pdo->prepare(
                "INSERT INTO authentication_events (user_id, household_id, event_type, metadata)
                 VALUES (?, ?, 'permission_updated', ?)"
            )->execute([$user['id'], $householdId, json_encode(['member_id' => $targetMemberId], JSON_THROW_ON_ERROR)]);
            flash('success', 'Permission overrides updated.');
            redirect('/phase3.php');
        }

        throw new InvalidArgumentException('Unknown action.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', user_error_message($exception));
        redirect('/phase3.php');
    }
}

$membersStmt = $pdo->prepare(
    "SELECT hm.id, hm.display_name, hm.age_group, hm.role, hm.status, hm.permission_overrides, u.email
     FROM household_members hm
     LEFT JOIN users u ON u.id = hm.user_id
     WHERE hm.household_id = ?
     ORDER BY FIELD(hm.role, 'owner','administrator','adult_member','youth_member','guest_helper'), hm.display_name"
);
$membersStmt->execute([$householdId]);
$members = $membersStmt->fetchAll();
$inviteStmt = $pdo->prepare('SELECT id, email, role, expires_at, accepted_at, revoked_at FROM household_invitations WHERE household_id = ? ORDER BY created_at DESC LIMIT 20');
$inviteStmt->execute([$householdId]);
$invitations = $inviteStmt->fetchAll();
$eventsStmt = $pdo->prepare(
    'SELECT ae.event_type, ae.occurred_at, ae.ip_address, u.display_name
     FROM authentication_events ae
     LEFT JOIN users u ON u.id = ae.user_id
     WHERE ae.household_id = ? ORDER BY ae.occurred_at DESC LIMIT 20'
);
$eventsStmt->execute([$householdId]);
$events = $eventsStmt->fetchAll();
$flashes = consume_flashes();
$inviteUrl = $_SESSION['latest_invite_url'] ?? null;
unset($_SESSION['latest_invite_url']);
$roleLabels = [
    'owner' => 'Household owner',
    'administrator' => 'Administrator',
    'adult_member' => 'Adult member',
    'youth_member' => 'Youth member',
    'guest_helper' => 'Guest / helper',
];
$permissionGroups = [
    'Household access' => ['members.manage', 'members.invite', 'permissions.manage'],
    'Pantry & storage' => ['storage.view', 'storage.manage', 'inventory.view', 'inventory.manage'],
    'Meals & recipes' => ['recipes.view', 'recipes.manage', 'recipes.complete', 'meals.manage'],
    'Garden & preservation' => ['garden.view', 'garden.manage', 'harvest.record', 'preservation.view', 'preservation.manage'],
    'Planning & operations' => ['tasks.manage', 'tasks.complete', 'notifications.view', 'notifications.manage'],
    'Finance & nutrition' => ['finance.view', 'finance.manage', 'nutrition.view', 'nutrition.manage'],
];
$memberCount = count($members);
$loginCount = 0;
$youthCount = 0;
$adminCount = 0;
$overrideCount = 0;
foreach ($members as $member) {
    if (!empty($member['email'])) {
        $loginCount++;
    }
    if ((string)$member['role'] === 'youth_member') {
        $youthCount++;
    }
    if (in_array((string)$member['role'], ['owner', 'administrator'], true)) {
        $adminCount++;
    }
    $memberOverrides = json_decode((string)($member['permission_overrides'] ?? '[]'), true);
    if (is_array($memberOverrides) && $memberOverrides !== []) {
        $overrideCount++;
    }
}
$pendingInviteCount = 0;
foreach ($invitations as $invitation) {
    if (!$invitation['accepted_at'] && !$invitation['revoked_at'] && strtotime((string)$invitation['expires_at']) >= time()) {
        $pendingInviteCount++;
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <title>Family Access · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to access administration</a>
<main id="main-content" class="page-container access-page">
    <section class="access-hero" aria-labelledby="access-title">
        <div class="access-hero__copy">
            <p class="access-kicker">Household administration</p>
            <h1 id="access-title" aria-label="Family access & permissions">The right access.<br><span>For every role.</span></h1>
            <p>Invite family members, understand role boundaries, review permission overrides, and keep the household security history visible.</p>
            <div class="access-hero__links">
                <a href="phase2.php?section=family">Family records</a>
                <a href="phase7.php">Planning & tasks</a>
                <a href="account.php">Account settings</a>
            </div>
        </div>
        <aside class="access-posture" aria-label="Current household access posture">
            <p class="access-kicker">Access posture</p>
            <strong><?= $memberCount ?></strong>
            <span>household members</span>
            <div class="access-posture__bar"><span style="width:<?= $memberCount > 0 ? min(100, (int)round(($loginCount / $memberCount) * 100)) : 0 ?>%"></span></div>
            <small><?= $loginCount ?> login-enabled · <?= $pendingInviteCount ?> pending invitation<?= $pendingInviteCount === 1 ? '' : 's' ?></small>
        </aside>
    </section>

    <?php foreach ($flashes as $message): ?>
        <div role="status" class="access-flash access-flash--<?= $message['type'] === 'error' ? 'warning' : 'good' ?>"><?= e((string)$message['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($inviteUrl): ?>
        <section class="access-invite-link" aria-labelledby="invite-link-title">
            <div>
                <p class="access-kicker">Secure invitation URL</p>
                <h2 id="invite-link-title">Copy this link now</h2>
                <p>The raw token is displayed once, should be shared privately, and expires after seven days.</p>
            </div>
            <div class="access-copy-field">
                <input id="latest-invite-url" readonly value="<?= e((string)$inviteUrl) ?>" aria-label="One-time invitation URL">
                <button type="button" data-copy-target="#latest-invite-url">Copy link</button>
            </div>
        </section>
    <?php endif; ?>

    <section class="access-metrics" aria-label="Household access metrics">
        <article><span>♙</span><div><small>Members</small><strong><?= $memberCount ?></strong><p>all household roles</p></div></article>
        <article><span>⌁</span><div><small>Login enabled</small><strong><?= $loginCount ?></strong><p>linked user accounts</p></div></article>
        <article><span>◆</span><div><small>Administrators</small><strong><?= $adminCount ?></strong><p>owner and admin roles</p></div></article>
        <article><span>◌</span><div><small>Youth members</small><strong><?= $youthCount ?></strong><p>restricted role defaults</p></div></article>
        <article class="access-metric--gold"><span>✉</span><div><small>Pending invites</small><strong><?= $pendingInviteCount ?></strong><p>active for seven days</p></div></article>
        <article class="access-metric--blue"><span>⚿</span><div><small>Overrides</small><strong><?= $overrideCount ?></strong><p>members with custom rules</p></div></article>
    </section>

    <div class="access-layout">
        <div class="access-main">
            <section class="access-panel" aria-labelledby="members-heading">
                <header class="access-panel__heading access-panel__heading--toolbar">
                    <div><p class="access-kicker">Household directory</p><h2 id="members-heading">Members & role access</h2></div>
                    <label class="access-search"><span aria-hidden="true">⌕</span><input type="search" placeholder="Search members" data-member-search aria-label="Search household members"></label>
                </header>
                <div class="access-role-legend" aria-label="Role summary">
                    <span><b>Owner</b> complete household authority</span>
                    <span><b>Administrator</b> operational administration</span>
                    <span><b>Adult</b> household participation</span>
                    <span><b>Youth / guest</b> restricted defaults</span>
                </div>
                <div class="access-member-list" data-member-list>
                <?php if ($members === []): ?>
                    <div class="access-empty"><strong>No household members found.</strong></div>
                <?php endif; ?>
                <?php foreach ($members as $member):
                    $overrides = json_decode((string)($member['permission_overrides'] ?? '[]'), true) ?: [];
                    $role = (string)$member['role'];
                    $initials = '';
                    foreach (preg_split('/\s+/', trim((string)$member['display_name'])) ?: [] as $namePart) {
                        $initials .= mb_substr($namePart, 0, 1);
                    }
                    $initials = mb_strtoupper(mb_substr($initials, 0, 2));
                    $canEditMember = $canManagePermissions && $role !== 'owner' && (int)$member['id'] !== (int)$user['member_id'];
                ?>
                    <article class="access-member" data-member data-search="<?= e(strtolower((string)$member['display_name'] . ' ' . (string)($member['email'] ?? '') . ' ' . $role)) ?>">
                        <div class="access-avatar" aria-hidden="true"><?= e($initials !== '' ? $initials : 'HM') ?></div>
                        <div class="access-member__identity">
                            <div><h3><?= e((string)$member['display_name']) ?></h3><span class="access-role access-role--<?= e($role) ?>"><?= e($roleLabels[$role] ?? str_replace('_', ' ', $role)) ?></span></div>
                            <p><?= e((string)($member['email'] ?? 'No login account linked')) ?></p>
                            <div class="access-member__facts">
                                <span><?= e(ucfirst((string)$member['age_group'])) ?></span>
                                <span><?= e(ucfirst((string)$member['status'])) ?></span>
                                <span><?= count($overrides) ?> custom override<?= count($overrides) === 1 ? '' : 's' ?></span>
                            </div>
                        </div>
                        <div class="access-member__state">
                            <span class="access-login access-login--<?= !empty($member['email']) ? 'enabled' : 'disabled' ?>"><?= !empty($member['email']) ? 'Login enabled' : 'Profile only' ?></span>
                            <?php if ($canEditMember): ?><span>Role defaults + overrides</span><?php else: ?><span><?= $role === 'owner' ? 'Protected owner role' : 'Read-only access' ?></span><?php endif; ?>
                        </div>
                        <?php if ($canEditMember): ?>
                        <details class="access-permissions">
                            <summary>Review permissions</summary>
                            <form method="post" class="access-permission-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_permissions">
                                <input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>">
                                <div class="access-permission-intro">
                                    <div><strong><?= e((string)$member['display_name']) ?></strong><span>Only explicit overrides differ from the <?= e($roleLabels[$role] ?? $role) ?> default.</span></div>
                                    <span><?= count($overrides) ?> active</span>
                                </div>
                                <div class="access-permission-groups">
                                <?php foreach ($permissionGroups as $groupLabel => $groupPermissions): ?>
                                    <section>
                                        <h4><?= e($groupLabel) ?></h4>
                                        <?php foreach ($groupPermissions as $permission): ?>
                                        <label>
                                            <span><?= e(str_replace('.', ' · ', $permission)) ?></span>
                                            <select name="permission[<?= e($permission) ?>]">
                                                <option value="inherit">Role default</option>
                                                <option value="allow" <?= ($overrides[$permission] ?? null) === true ? 'selected' : '' ?>>Allow</option>
                                                <option value="deny" <?= ($overrides[$permission] ?? null) === false ? 'selected' : '' ?>>Deny</option>
                                            </select>
                                        </label>
                                        <?php endforeach; ?>
                                    </section>
                                <?php endforeach; ?>
                                </div>
                                <button class="access-primary-button" type="submit">Save permission overrides</button>
                            </form>
                        </details>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                </div>
                <div class="access-empty" data-member-empty hidden><strong>No members match that search.</strong></div>
            </section>

            <section class="access-panel" aria-labelledby="invitations-heading">
                <header class="access-panel__heading"><div><p class="access-kicker">Invitation lifecycle</p><h2 id="invitations-heading">Pending & historical invitations</h2></div><span><?= count($invitations) ?> recent</span></header>
                <div class="access-invitation-list">
                <?php if ($invitations === []): ?><div class="access-empty"><strong>No invitations recorded.</strong></div><?php endif; ?>
                <?php foreach ($invitations as $invite):
                    $inviteStatus = $invite['accepted_at'] ? 'Accepted' : ($invite['revoked_at'] ? 'Revoked' : (strtotime((string)$invite['expires_at']) < time() ? 'Expired' : 'Pending'));
                ?>
                    <article class="access-invitation">
                        <span class="access-invitation__mark access-invitation__mark--<?= e(strtolower($inviteStatus)) ?>" aria-hidden="true"><?= $inviteStatus === 'Accepted' ? '✓' : ($inviteStatus === 'Pending' ? '✉' : '×') ?></span>
                        <div><strong><?= e((string)$invite['email']) ?></strong><p><?= e($roleLabels[(string)$invite['role']] ?? str_replace('_', ' ', (string)$invite['role'])) ?> · expires <?= e((string)$invite['expires_at']) ?></p></div>
                        <span class="access-status access-status--<?= e(strtolower($inviteStatus)) ?>"><?= e($inviteStatus) ?></span>
                        <?php if ($inviteStatus === 'Pending' && $canInvite): ?>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke_invite"><input type="hidden" name="invitation_id" value="<?= (int)$invite['id'] ?>"><button type="submit">Revoke</button></form>
                        <?php else: ?><span class="access-invitation__closed">—</span><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                </div>
            </section>

            <section class="access-panel" aria-labelledby="events-heading">
                <header class="access-panel__heading"><div><p class="access-kicker">Security history</p><h2 id="events-heading">Authentication events</h2></div><span>latest <?= count($events) ?></span></header>
                <div class="access-event-list">
                <?php if ($events === []): ?><div class="access-empty"><strong>No authentication events recorded.</strong></div><?php endif; ?>
                <?php foreach ($events as $event): ?>
                    <article class="access-event">
                        <span class="access-event__mark" aria-hidden="true">⚿</span>
                        <div><strong><?= e(ucwords(str_replace('_', ' ', (string)$event['event_type']))) ?></strong><p><?= e((string)($event['display_name'] ?? 'System / unknown user')) ?></p></div>
                        <time><?= e((string)$event['occurred_at']) ?></time>
                        <span><?= e((string)($event['ip_address'] ?? 'IP unavailable')) ?></span>
                    </article>
                <?php endforeach; ?>
                </div>
            </section>
        </div>

        <aside class="access-sidebar">
            <section class="access-panel access-invite-panel" aria-labelledby="invite-heading">
                <header class="access-panel__heading"><div><p class="access-kicker">Invite</p><h2 id="invite-heading">Add a family member</h2></div></header>
                <?php if ($canInvite): ?>
                <form method="post" class="access-invite-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="invite">
                    <label><span>Name</span><input name="display_name" maxlength="120" required autocomplete="name"></label>
                    <label><span>Email</span><input type="email" name="email" maxlength="190" required autocomplete="email"></label>
                    <div class="access-form-pair">
                        <label><span>Age group</span><select name="age_group"><option value="adult">Adult</option><option value="teen">Teen</option><option value="child">Child</option><option value="guest">Guest</option></select></label>
                        <label><span>Role</span><select name="role"><option value="adult_member">Adult member</option><option value="administrator">Administrator</option><option value="youth_member">Youth member</option><option value="guest_helper">Guest / helper</option></select></label>
                    </div>
                    <button class="access-primary-button" type="submit">Create secure invitation</button>
                    <small>The invitation link is shown once and expires in seven days.</small>
                </form>
                <?php else: ?><div class="access-empty"><strong>Invitation permission is not enabled.</strong></div><?php endif; ?>
            </section>

            <section class="access-panel access-guide" aria-labelledby="role-guide-heading">
                <header class="access-panel__heading"><div><p class="access-kicker">Role guide</p><h2 id="role-guide-heading">Default boundaries</h2></div></header>
                <div>
                    <article><span>◆</span><div><strong>Administrator</strong><p>Manages household operations and selected access controls.</p></div></article>
                    <article><span>♙</span><div><strong>Adult member</strong><p>Participates in food, garden, meals, and assigned work.</p></div></article>
                    <article><span>◌</span><div><strong>Youth member</strong><p>Uses age-appropriate participation defaults and restrictions.</p></div></article>
                    <article><span>⌁</span><div><strong>Guest / helper</strong><p>Receives limited temporary or task-specific access.</p></div></article>
                </div>
            </section>

            <section class="access-panel access-security-note">
                <p class="access-kicker">Security boundary</p>
                <h2>Least privilege by default</h2>
                <p>Role defaults establish the normal boundary. Member overrides should be used only for deliberate exceptions and are recorded in the authentication history.</p>
                <div><span>Own permissions protected</span><span>Owner role protected</span><span>Invitation tokens hashed</span></div>
            </section>
        </aside>
    </div>
</main>
<script src="assets/js/homestead-access.js?v=20260727-1"></script>
</body>
</html>
