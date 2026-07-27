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
$totalVersions = count($versions);
$draftVersions = array_values(array_filter($versions, static fn(array $version): bool => $version['status'] === 'draft'));
$publishedVersions = array_values(array_filter($versions, static fn(array $version): bool => $version['status'] === 'published'));
$retiredVersions = array_values(array_filter($versions, static fn(array $version): bool => $version['status'] === 'retired'));
$activeFamilyCount = count($kits);
$totalContents = array_sum(array_map(static fn(array $version): int => (int)$version['item_count'] + (int)$version['recipe_count'] + (int)$version['task_count'], $versions));
$publishedWithContents = count(array_filter($publishedVersions, static fn(array $version): bool => ((int)$version['item_count'] + (int)$version['recipe_count'] + (int)$version['task_count']) > 0));
$releaseCoverage = $publishedVersions === [] ? 0 : (int)round(($publishedWithContents / count($publishedVersions)) * 100);
$latestPublishedAt = 'No published release';
foreach ($publishedVersions as $version) {
    if (!empty($version['published_at'])) {
        $latestPublishedAt = (string)$version['published_at'];
        break;
    }
}
$familyVersionCounts = [];
foreach ($versions as $version) {
    $familyVersionCounts[(int)$version['kit_id']] = ($familyVersionCounts[(int)$version['kit_id']] ?? 0) + 1;
}
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Starter Kit Lifecycle · Homestead</title></head>
<body>
<a class="skip-link" href="#main-content">Skip to Starter Kit lifecycle</a>
<main id="main-content" class="page-container lifecycle-page">
    <section class="lifecycle-hero" aria-labelledby="lifecycle-title">
        <div class="lifecycle-hero__copy">
            <p class="lifecycle-kicker">Immutable release governance</p>
            <h1 id="lifecycle-title">Starter Kit Lifecycle</h1>
            <p class="lifecycle-hero__lead">Release with confidence. Retire without rewriting history.</p>
            <p>Duplicate established versions into editable drafts, inspect release composition, and retire versions or entire kit families while preserving every existing customer activation.</p>
            <div class="lifecycle-hero__links"><a href="phase5.php">Starter Kit operations</a><a href="starter-kit-lifecycle.php#versions">Version history</a><a href="starter-kit-lifecycle.php#governance">Governance</a></div>
        </div>
        <aside class="lifecycle-readiness" aria-label="Release coverage">
            <p class="lifecycle-kicker">Published release coverage</p>
            <strong><?= $releaseCoverage ?>%</strong>
            <span>published versions with traceable kit contents</span>
            <div class="lifecycle-readiness__bar"><span style="width:<?= $releaseCoverage ?>%"></span></div>
            <small><?= count($publishedVersions) ?> published · <?= count($draftVersions) ?> editable drafts</small>
        </aside>
    </section>

    <?php foreach ($flashes as $message): ?><div role="status" class="lifecycle-flash lifecycle-flash--<?= $message['type'] === 'error' ? 'warning' : 'good' ?>"><?= e((string)$message['message']) ?></div><?php endforeach; ?>

    <section class="lifecycle-metrics" aria-label="Lifecycle metrics">
        <article><span>◇</span><div><small>Active families</small><strong><?= $activeFamilyCount ?></strong><p>eligible for new versions</p></div></article>
        <article><span>◫</span><div><small>Total versions</small><strong><?= $totalVersions ?></strong><p>complete release history</p></div></article>
        <article class="lifecycle-metric--gold"><span>✎</span><div><small>Draft versions</small><strong><?= count($draftVersions) ?></strong><p>editable candidates</p></div></article>
        <article class="lifecycle-metric--green"><span>◆</span><div><small>Published</small><strong><?= count($publishedVersions) ?></strong><p>immutable customer releases</p></div></article>
        <article class="lifecycle-metric--red"><span>—</span><div><small>Retired</small><strong><?= count($retiredVersions) ?></strong><p>historical records retained</p></div></article>
        <article class="lifecycle-metric--blue"><span>▦</span><div><small>Content records</small><strong><?= $totalContents ?></strong><p>items, recipes, and tasks</p></div></article>
    </section>

    <section class="lifecycle-layout">
        <div class="lifecycle-main">
            <section id="versions" class="lifecycle-panel" aria-labelledby="versions-heading">
                <header class="lifecycle-panel__heading lifecycle-panel__heading--toolbar">
                    <div><p class="lifecycle-kicker">Release lineage</p><h2 id="versions-heading">Version history</h2></div>
                    <label class="lifecycle-search"><span>⌕</span><input type="search" data-lifecycle-search placeholder="Search kit, SKU, status"></label>
                </header>
                <div class="lifecycle-tabs" role="tablist" aria-label="Version filters">
                    <button type="button" class="active" data-lifecycle-filter="all">All <span><?= $totalVersions ?></span></button>
                    <button type="button" data-lifecycle-filter="draft">Draft <span><?= count($draftVersions) ?></span></button>
                    <button type="button" data-lifecycle-filter="published">Published <span><?= count($publishedVersions) ?></span></button>
                    <button type="button" data-lifecycle-filter="retired">Retired <span><?= count($retiredVersions) ?></span></button>
                </div>
                <div class="lifecycle-version-list" data-lifecycle-list>
                    <?php foreach ($versions as $version):
                        $contents = (int)$version['item_count'] + (int)$version['recipe_count'] + (int)$version['task_count'];
                        $searchText = strtolower((string)$version['kit_name'].' '.(string)$version['sku'].' '.(string)$version['status'].' '.(string)$version['kit_status']);
                    ?>
                    <article class="lifecycle-version" data-lifecycle-card data-status="<?= e((string)$version['status']) ?>" data-search="<?= e($searchText) ?>">
                        <div class="lifecycle-version__rail lifecycle-version__rail--<?= e((string)$version['status']) ?>"><span><?= $version['status'] === 'published' ? '◆' : ($version['status'] === 'retired' ? '—' : '✎') ?></span></div>
                        <div class="lifecycle-version__identity">
                            <div><h3><?= e((string)$version['kit_name']) ?></h3><span class="lifecycle-status lifecycle-status--<?= e((string)$version['status']) ?>"><?= e((string)$version['status']) ?></span></div>
                            <p>Version <?= (int)$version['version_number'] ?> · <?= e((string)$version['sku']) ?></p>
                            <div class="lifecycle-version__facts">
                                <span><small>Items</small><strong><?= (int)$version['item_count'] ?></strong></span>
                                <span><small>Recipes</small><strong><?= (int)$version['recipe_count'] ?></strong></span>
                                <span><small>Tasks</small><strong><?= (int)$version['task_count'] ?></strong></span>
                                <span><small>Total content</small><strong><?= $contents ?></strong></span>
                            </div>
                        </div>
                        <div class="lifecycle-version__release">
                            <small>Published</small><strong><?= e((string)($version['published_at'] ?: 'Not released')) ?></strong>
                            <span>Family <?= e((string)$version['kit_status']) ?></span>
                        </div>
                        <div class="lifecycle-version__action">
                            <?php if ($version['status'] !== 'retired'): ?>
                            <details><summary>Version actions</summary><div class="lifecycle-action-popover">
                                <p>Retiring prevents future selection but preserves customer activations and historical contents.</p>
                                <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="retire_version"><input type="hidden" name="version_id" value="<?= (int)$version['id'] ?>"><button type="submit" class="lifecycle-danger-button">Retire version</button></form>
                            </div></details>
                            <?php else: ?><span class="lifecycle-closed">Historical</span><?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                    <?php if ($versions === []): ?><div class="lifecycle-empty"><strong>No Starter Kit versions exist yet.</strong><p>Create the first kit and draft version from Starter Kit operations.</p><a href="phase5.php">Open Starter Kits</a></div><?php endif; ?>
                </div>
            </section>

            <section class="lifecycle-panel" aria-labelledby="lineage-heading">
                <header class="lifecycle-panel__heading"><div><p class="lifecycle-kicker">Family lineage</p><h2 id="lineage-heading">Release families</h2></div><span><?= count($familyVersionCounts) ?> represented families</span></header>
                <div class="lifecycle-family-grid">
                    <?php foreach ($kits as $kit): $versionCount = $familyVersionCounts[(int)$kit['id']] ?? 0; ?>
                    <article><span>◇</span><div><strong><?= e((string)$kit['name']) ?></strong><p><?= $versionCount ?> version<?= $versionCount === 1 ? '' : 's' ?> in history</p></div><em><?= e((string)$kit['status']) ?></em></article>
                    <?php endforeach; ?>
                    <?php if ($kits === []): ?><div class="lifecycle-empty"><strong>No active kit families</strong><p>Retired families remain visible in version history.</p></div><?php endif; ?>
                </div>
            </section>
        </div>

        <aside class="lifecycle-sidebar" aria-label="Lifecycle operations">
            <section class="lifecycle-panel">
                <header class="lifecycle-panel__heading"><div><p class="lifecycle-kicker">Safe iteration</p><h2>Duplicate into draft</h2></div></header>
                <form method="post" class="lifecycle-form lifecycle-form--padded">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="duplicate_version">
                    <p>Copy items, recipe snapshots, and onboarding tasks into a new editable version without modifying the source release.</p>
                    <label>Source version<select name="source_version_id" required><?php foreach ($sourceVersions as $version): ?><option value="<?= (int)$version['id'] ?>"><?= e((string)$version['kit_name']) ?> · v<?= (int)$version['version_number'] ?> · <?= e((string)$version['sku']) ?></option><?php endforeach; ?></select></label>
                    <div class="lifecycle-form__split"><label>New version<input type="number" name="version_number" min="1" max="100000" required></label><label>New SKU<input name="sku" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._-]{1,99}" required></label></div>
                    <button type="submit">Duplicate as draft</button>
                </form>
            </section>

            <section id="governance" class="lifecycle-panel">
                <header class="lifecycle-panel__heading"><div><p class="lifecycle-kicker">Family retirement</p><h2>Retire kit family</h2></div></header>
                <form method="post" class="lifecycle-form lifecycle-form--padded">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="retire_kit">
                    <p>This blocks new orders and retires every version. Existing activation tokens and customer records remain valid.</p>
                    <label>Starter kit<select name="kit_id" required><?php foreach ($kits as $kit): ?><option value="<?= (int)$kit['id'] ?>"><?= e((string)$kit['name']) ?> · <?= e((string)$kit['status']) ?></option><?php endforeach; ?></select></label>
                    <button type="submit" class="lifecycle-danger-button">Retire kit family</button>
                </form>
            </section>

            <section class="lifecycle-panel">
                <header class="lifecycle-panel__heading"><div><p class="lifecycle-kicker">Release safeguards</p><h2>Immutable protection</h2></div></header>
                <div class="lifecycle-governance">
                    <article><span>1</span><div><strong>Published stays frozen</strong><p>Release contents remain stable for every customer activation.</p></div></article>
                    <article><span>2</span><div><strong>Duplicate to iterate</strong><p>New work begins from a copied draft with a new version and SKU.</p></div></article>
                    <article><span>3</span><div><strong>Retire, never erase</strong><p>Historical ownership and activation evidence remain available.</p></div></article>
                </div>
            </section>

            <section class="lifecycle-panel">
                <header class="lifecycle-panel__heading"><div><p class="lifecycle-kicker">Latest release</p><h2>Publication activity</h2></div></header>
                <div class="lifecycle-latest"><span>◆</span><div><strong><?= e($latestPublishedAt) ?></strong><p>most recent published timestamp in the release history</p></div></div>
            </section>
        </aside>
    </section>
</main>
<script src="assets/js/homestead-kit-lifecycle.js" defer></script>
</body></html>
