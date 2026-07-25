<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use Homestead\StarterKitAdminService;
use function Homestead\consume_flashes;
use function Homestead\csrf_token;
use function Homestead\e;
use function Homestead\flash;
use function Homestead\redirect;
use function Homestead\user_error_message;
use function Homestead\verify_csrf;

$user = $auth->requireUser();
$auth->requirePlatformAdmin($user);
$service = new StarterKitAdminService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'duplicate_version') {
            $newVersionId = $service->duplicateVersion(
                (int)($_POST['source_version_id'] ?? 0),
                (int)($_POST['version_number'] ?? 0),
                (string)($_POST['sku'] ?? '')
            );
            flash('success', 'Draft version #' . $newVersionId . ' created with copied items, recipes, and tasks.');
        } elseif ($action === 'retire_version') {
            $service->retireVersion((int)($_POST['version_id'] ?? 0));
            flash('success', 'Starter-kit version retired. Existing activations remain valid.');
        } elseif ($action === 'retire_kit') {
            $service->retireKit((int)($_POST['kit_id'] ?? 0));
            flash('success', 'Starter kit and all of its versions retired. Existing activations remain valid.');
        } else {
            throw new InvalidArgumentException('Unknown lifecycle action.');
        }
    } catch (Throwable $exception) {
        flash('error', user_error_message($exception));
    }
    redirect('/starter-kit-lifecycle.php');
}

$versions = $pdo->query(
    'SELECT v.id, v.version_number, v.sku, v.status, v.published_at,
            k.id AS kit_id, k.name AS kit_name, k.status AS kit_status,
            COUNT(DISTINCT i.id) AS item_count,
            COUNT(DISTINCT r.recipe_id) AS recipe_count,
            COUNT(DISTINCT t.id) AS task_count
     FROM starter_kit_versions v
     JOIN starter_kits k ON k.id = v.starter_kit_id
     LEFT JOIN starter_kit_items i ON i.starter_kit_version_id = v.id
     LEFT JOIN starter_kit_recipes r ON r.starter_kit_version_id = v.id
     LEFT JOIN starter_kit_tasks t ON t.starter_kit_version_id = v.id
     GROUP BY v.id ORDER BY k.name, v.version_number DESC'
)->fetchAll();
$kits = $pdo->query(
    "SELECT id, name, status FROM starter_kits WHERE status <> 'retired' ORDER BY name"
)->fetchAll();
$sourceVersions = array_values(array_filter(
    $versions,
    static fn(array $version): bool => $version['kit_status'] !== 'retired'
));
$flashes = consume_flashes();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Starter Kit Lifecycle · Homestead</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><a class="skip-link" href="#main-content">Skip to Starter Kit lifecycle</a><main id="main-content" class="page-container">
<header class="page-header"><div><p class="eyebrow">Platform administration</p><h1>Starter Kit Lifecycle</h1><p class="page-description">Duplicate immutable versions into new drafts and retire versions or complete kit families without changing existing customer activation records.</p></div><a class="button secondary" href="/phase5.php">Back to Starter Kits</a></header>
<?php foreach ($flashes as $message): ?><div role="status" class="status status-<?= $message['type'] === 'error' ? 'warning' : 'good' ?>" style="display:block;margin-bottom:12px"><?= e((string)$message['message']) ?></div><?php endforeach; ?>
<section class="content-grid">
<article class="panel span-2"><h2>Duplicate version into a draft</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="duplicate_version"><label>Source version<select name="source_version_id" required><?php foreach ($sourceVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · v<?= (int)$version['version_number'] ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label><label>New version number<input class="search-field" type="number" name="version_number" min="1" max="100000" required></label><label>New SKU<input class="search-field" name="sku" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._-]{1,99}" required></label><button class="button primary" type="submit">Duplicate as draft</button></form></article>
<article class="panel"><h2>Retire a kit family</h2><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="retire_kit"><label>Starter kit<select name="kit_id" required><?php foreach ($kits as $kit): ?><option value="<?= (int)$kit['id'] ?>"><?= e((string)$kit['name']) ?> · <?= e((string)$kit['status']) ?></option><?php endforeach; ?></select></label><p class="page-description">This blocks new orders and retires all versions. Existing activation tokens are preserved.</p><button class="button secondary" type="submit">Retire kit family</button></form></article>
</section>
<section class="panel"><h2>Version history</h2><div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Kit</th><th scope="col">Version</th><th scope="col">Status</th><th scope="col">Contents</th><th scope="col">Published</th><th scope="col">Action</th></tr></thead><tbody><?php if ($versions === []): ?><tr><td colspan="6">No Starter Kit versions exist yet.</td></tr><?php endif; ?><?php foreach ($versions as $version): ?><tr><td><strong><?= e((string)$version['kit_name']) ?></strong><br><small><?= e((string)$version['kit_status']) ?></small></td><td>v<?= (int)$version['version_number'] ?><br><small><?= e((string)$version['sku']) ?></small></td><td><?= e((string)$version['status']) ?></td><td><?= (int)$version['item_count'] ?> items · <?= (int)$version['recipe_count'] ?> recipes · <?= (int)$version['task_count'] ?> tasks</td><td><?= e((string)($version['published_at'] ?: '—')) ?></td><td><?php if ($version['status'] !== 'retired'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="retire_version"><input type="hidden" name="version_id" value="<?= (int)$version['id'] ?>"><button class="button secondary" type="submit">Retire version</button></form><?php else: ?>Retired<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main></body></html>
