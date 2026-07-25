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
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Family Access · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><a class="skip-link" href="#main-content">Skip to access administration</a><main id="main-content" class="page-container"><header class="page-header"><div><p class="eyebrow">Household administration</p><h1>Family access & permissions</h1><p class="page-description">Manage accounts, invitations, role defaults, and member-specific permission overrides.</p></div><div><strong><?= e((string)$user['display_name']) ?></strong><div class="toolbar" style="margin-top:10px"><a class="button secondary" href="/phase2.php">Household</a><a class="button secondary" href="/phase4.php">Recipes</a><a class="button secondary" href="/phase6.php">Grow & preserve</a><a class="button secondary" href="/phase10.php">Nutrition</a><a class="button secondary" href="/account.php">Account</a><a class="button secondary" href="/logout.php">Sign out</a></div></div></header>
<?php foreach ($flashes as $message): ?><div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<?php if ($inviteUrl): ?><section class="panel" style="margin-bottom:22px"><p class="eyebrow">Secure invitation URL</p><label>One-time invitation URL<input class="search-field" readonly value="<?= e((string)$inviteUrl) ?>" onclick="this.select()"></label><p style="color:var(--muted);margin-top:10px">Share privately. The raw token is displayed once and expires in seven days.</p></section><?php endif; ?>
<section class="content-grid"><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Invite</p><h2>Add a family member</h2></div></div>
<?php if ($canInvite): ?><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="invite"><label>Name<input class="search-field" name="display_name" maxlength="120" required></label><label>Email<input class="search-field" type="email" name="email" maxlength="190" required></label><label>Age group<select name="age_group"><option>adult</option><option>teen</option><option>child</option><option>guest</option></select></label><label>Role<select name="role"><option value="adult_member">Adult member</option><option value="administrator">Administrator</option><option value="youth_member">Youth member</option><option value="guest_helper">Guest/helper</option></select></label><button class="button primary" type="submit">Create invitation</button></form><?php else: ?><p>You do not have invitation permission.</p><?php endif; ?></article>
<article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Members</p><h2>Role and permission administration</h2></div></div><?php foreach ($members as $member): $overrides = json_decode((string)($member['permission_overrides'] ?? '[]'), true) ?: []; ?><details class="member-card" style="margin-bottom:12px"><summary><strong><?= e((string)$member['display_name']) ?></strong> · <?= e(str_replace('_', ' ', (string)$member['role'])) ?> · <?= e((string)($member['email'] ?? 'No login')) ?></summary><?php if ($canManagePermissions && $member['role'] !== 'owner' && (int)$member['id'] !== (int)$user['member_id']): ?><form method="post" class="form-grid" style="margin-top:16px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_permissions"><input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>"><div class="table-wrap"><table><thead><tr><th>Permission</th><th>Override</th></tr></thead><tbody><?php foreach ($permissions as $permission): ?><tr><td><?= e($permission) ?></td><td><select name="permission[<?= e($permission) ?>]"><option value="inherit">Use role default</option><option value="allow" <?= ($overrides[$permission] ?? null) === true ? 'selected' : '' ?>>Allow</option><option value="deny" <?= ($overrides[$permission] ?? null) === false ? 'selected' : '' ?>>Deny</option></select></td></tr><?php endforeach; ?></tbody></table></div><button class="button secondary" type="submit">Save overrides</button></form><?php endif; ?></details><?php endforeach; ?></article>
<article class="panel span-3"><div class="panel-heading"><div><p class="eyebrow">Invitations</p><h2>Pending and historical invitations</h2></div></div><div class="table-wrap"><table><thead><tr><th>Email</th><th>Role</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($invitations as $invite): $status = $invite['accepted_at'] ? 'Accepted' : ($invite['revoked_at'] ? 'Revoked' : (strtotime((string)$invite['expires_at']) < time() ? 'Expired' : 'Pending')); ?><tr><td><?= e((string)$invite['email']) ?></td><td><?= e(str_replace('_', ' ', (string)$invite['role'])) ?></td><td><?= e((string)$invite['expires_at']) ?></td><td><?= e($status) ?></td><td><?php if ($status === 'Pending' && $canInvite): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke_invite"><input type="hidden" name="invitation_id" value="<?= (int)$invite['id'] ?>"><button class="button secondary" type="submit">Revoke</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></article>
<article class="panel span-3"><div class="panel-heading"><div><p class="eyebrow">Security history</p><h2>Authentication events</h2></div></div><div class="table-wrap"><table><thead><tr><th>Event</th><th>User</th><th>Time</th><th>IP</th></tr></thead><tbody><?php foreach ($events as $event): ?><tr><td><?= e(str_replace('_', ' ', (string)$event['event_type'])) ?></td><td><?= e((string)($event['display_name'] ?? 'Unknown')) ?></td><td><?= e((string)$event['occurred_at']) ?></td><td><?= e((string)($event['ip_address'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div></article></section></main></body></html>