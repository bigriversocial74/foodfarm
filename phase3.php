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
$householdId = (int)$user['household_id'];
$permissions = ['members.manage','members.invite','permissions.manage','storage.manage','inventory.manage','tasks.manage','tasks.complete','storage.view','inventory.view'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'invite') {
            $auth->requirePermission($user, 'members.invite');
            $email = trim((string)($_POST['email'] ?? ''));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $role = (string)($_POST['role'] ?? 'adult_member');
            $ageGroup = (string)($_POST['age_group'] ?? 'adult');
            $roles = ['administrator','adult_member','youth_member','guest_helper'];
            $ages = ['adult','teen','child','guest'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $displayName === '' || !in_array($role, $roles, true) || !in_array($ageGroup, $ages, true)) {
                throw new InvalidArgumentException('Enter a valid name, email, age group, and role.');
            }

            $token = bin2hex(random_bytes(32));
            $statement = $pdo->prepare("INSERT INTO household_invitations (household_id,email,display_name,age_group,role,token_hash,invited_by_member_id,expires_at) VALUES (?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))");
            $statement->execute([$householdId,$email,$displayName,$ageGroup,$role,hash('sha256',$token),$user['member_id']]);
            $pdo->prepare("INSERT INTO authentication_events (user_id,household_id,event_type,metadata) VALUES (?,?,'invitation_created',?)")->execute([$user['id'],$householdId,json_encode(['email'=>$email])]);
            $_SESSION['latest_invite_url'] = '/accept-invite.php?token=' . $token;
            flash('success','Invitation created. Copy the secure invitation URL shown below.');
        }

        if ($action === 'revoke_invite') {
            $auth->requirePermission($user, 'members.invite');
            $id = (int)($_POST['invitation_id'] ?? 0);
            $pdo->prepare('UPDATE household_invitations SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND household_id=? AND accepted_at IS NULL')->execute([$id,$householdId]);
            flash('success','Invitation revoked.');
        }

        if ($action === 'update_permissions') {
            $auth->requirePermission($user, 'permissions.manage');
            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId === (int)$user['member_id']) {
                throw new RuntimeException('You cannot change your own permission overrides here.');
            }
            $overrides = [];
            foreach ($permissions as $permission) {
                $value = $_POST['permission'][$permission] ?? 'inherit';
                if ($value === 'allow') $overrides[$permission] = true;
                if ($value === 'deny') $overrides[$permission] = false;
            }
            $statement = $pdo->prepare("UPDATE household_members SET permission_overrides=? WHERE id=? AND household_id=? AND role<>'owner'");
            $statement->execute([json_encode($overrides),$memberId,$householdId]);
            $pdo->prepare("INSERT INTO authentication_events (user_id,household_id,event_type,metadata) VALUES (?,?,'permission_updated',?)")->execute([$user['id'],$householdId,json_encode(['member_id'=>$memberId])]);
            flash('success','Permission overrides updated.');
        }

        redirect('/phase3.php');
    } catch (Throwable $exception) {
        flash('error',$exception->getMessage());
        redirect('/phase3.php');
    }
}

$membersStmt = $pdo->prepare("SELECT hm.id,hm.display_name,hm.age_group,hm.role,hm.status,hm.permission_overrides,u.email FROM household_members hm LEFT JOIN users u ON u.id=hm.user_id WHERE hm.household_id=? ORDER BY FIELD(hm.role,'owner','administrator','adult_member','youth_member','guest_helper'),hm.display_name");
$membersStmt->execute([$householdId]);
$members = $membersStmt->fetchAll();
$inviteStmt = $pdo->prepare("SELECT * FROM household_invitations WHERE household_id=? ORDER BY created_at DESC LIMIT 20");
$inviteStmt->execute([$householdId]);
$invitations = $inviteStmt->fetchAll();
$eventsStmt = $pdo->prepare("SELECT ae.*,u.display_name FROM authentication_events ae LEFT JOIN users u ON u.id=ae.user_id WHERE ae.household_id=? ORDER BY ae.occurred_at DESC LIMIT 20");
$eventsStmt->execute([$householdId]);
$events = $eventsStmt->fetchAll();
$flashes = consume_flashes();
$inviteUrl = $_SESSION['latest_invite_url'] ?? null;
unset($_SESSION['latest_invite_url']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Phase 3 · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="page-container"><header class="page-header"><div><p class="eyebrow">Phase 3 workspace</p><h1>Family access & permissions</h1><p class="page-description">Manage secure accounts, household invitations, role defaults, and member-specific permission overrides.</p></div><div><strong><?= e((string)$user['display_name']) ?></strong><br><a href="/logout.php">Sign out</a></div></header>
<?php foreach ($flashes as $message): ?><div class="status status-<?= $message['type']==='error'?'warning':'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<?php if ($inviteUrl): ?><section class="panel" style="margin-bottom:22px"><p class="eyebrow">Secure invitation URL</p><input class="search-field" readonly value="<?= e($inviteUrl) ?>" onclick="this.select()"><p style="color:var(--muted);margin-top:10px">Share this privately. The link expires in seven days and is stored only as a hash.</p></section><?php endif; ?>
<section class="content-grid"><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Invite</p><h2>Add a family member</h2></div></div>
<?php if ($auth->can($user,'members.invite')): ?><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="invite"><label>Name<input class="search-field" name="display_name" required></label><label>Email<input class="search-field" type="email" name="email" required></label><label>Age group<select name="age_group"><option>adult</option><option>teen</option><option>child</option><option>guest</option></select></label><label>Role<select name="role"><option value="adult_member">Adult member</option><option value="administrator">Administrator</option><option value="youth_member">Youth member</option><option value="guest_helper">Guest/helper</option></select></label><button class="button primary" type="submit">Create invitation</button></form><?php else: ?><p>You do not have invitation permission.</p><?php endif; ?></article>
<article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Members</p><h2>Role and permission administration</h2></div></div><?php foreach ($members as $member): $overrides=json_decode((string)($member['permission_overrides']??'[]'),true)?:[]; ?><details class="member-card" style="margin-bottom:12px"><summary><strong><?= e((string)$member['display_name']) ?></strong> · <?= e(str_replace('_',' ',(string)$member['role'])) ?> · <?= e((string)($member['email']??'No login')) ?></summary><form method="post" class="form-grid" style="margin-top:16px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_permissions"><input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>"><div class="table-wrap"><table><thead><tr><th>Permission</th><th>Override</th></tr></thead><tbody><?php foreach ($permissions as $permission): ?><tr><td><?= e($permission) ?></td><td><select name="permission[<?= e($permission) ?>]"><option value="inherit">Use role default</option><option value="allow" <?= ($overrides[$permission]??null)===true?'selected':'' ?>>Allow</option><option value="deny" <?= ($overrides[$permission]??null)===false?'selected':'' ?>>Deny</option></select></td></tr><?php endforeach; ?></tbody></table></div><?php if ($member['role']!=='owner' && $auth->can($user,'permissions.manage')): ?><button class="button secondary" type="submit">Save overrides</button><?php endif; ?></form></details><?php endforeach; ?></article>
<article class="panel span-3"><div class="panel-heading"><div><p class="eyebrow">Invitations</p><h2>Pending and historical invitations</h2></div></div><div class="table-wrap"><table><thead><tr><th>Email</th><th>Role</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($invitations as $invite): $status=$invite['accepted_at']?'Accepted':($invite['revoked_at']?'Revoked':(strtotime((string)$invite['expires_at'])<time()?'Expired':'Pending')); ?><tr><td><?= e((string)$invite['email']) ?></td><td><?= e(str_replace('_',' ',(string)$invite['role'])) ?></td><td><?= e((string)$invite['expires_at']) ?></td><td><?= e($status) ?></td><td><?php if ($status==='Pending' && $auth->can($user,'members.invite')): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke_invite"><input type="hidden" name="invitation_id" value="<?= (int)$invite['id'] ?>"><button class="button secondary" type="submit">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></article>
<article class="panel span-3"><div class="panel-heading"><div><p class="eyebrow">Security history</p><h2>Authentication events</h2></div></div><div class="table-wrap"><table><thead><tr><th>Event</th><th>User</th><th>Time</th><th>IP</th></tr></thead><tbody><?php foreach ($events as $event): ?><tr><td><?= e(str_replace('_',' ',(string)$event['event_type'])) ?></td><td><?= e((string)($event['display_name']??'Unknown')) ?></td><td><?= e((string)$event['occurred_at']) ?></td><td><?= e((string)($event['ip_address']??'—')) ?></td></tr><?php endforeach; ?></tbody></table></div></article></section></main></body></html>
