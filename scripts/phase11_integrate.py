from pathlib import Path


def ensure_replace(path: str, old: str, new: str, count: int = 1) -> None:
    target = Path(path)
    text = target.read_text()
    if new in text:
        return
    actual = text.count(old)
    if actual < count:
        raise SystemExit(f"Expected marker missing in {path}: {old[:120]!r}; found {actual}")
    target.write_text(text.replace(old, new, count))


def ensure_replace_all(path: str, old: str, new: str, expected: int) -> None:
    target = Path(path)
    text = target.read_text()
    if text.count(new) >= expected:
        return
    actual = text.count(old)
    if actual != expected:
        raise SystemExit(f"Expected {expected} markers in {path}: {old[:120]!r}; found {actual}")
    target.write_text(text.replace(old, new))

# Role defaults and member-specific overrides.
auth = Path('app/Auth.php')
text = auth.read_text()
if "'notifications.manage'" not in text:
    old = "                'nutrition.view', 'nutrition.manage',\n"
    if text.count(old) != 2:
        raise SystemExit('Expected two adult nutrition permission blocks in Auth.php')
    text = text.replace(old, old + "                'notifications.view', 'notifications.manage',\n")
    old_youth = "                'preservation.view', 'finance.view', 'nutrition.view',\n"
    if old_youth not in text:
        raise SystemExit('Youth permission marker missing in Auth.php')
    text = text.replace(old_youth, "                'preservation.view', 'finance.view', 'nutrition.view', 'notifications.view',\n", 1)
    auth.write_text(text)

ensure_replace(
    'phase3.php',
    "    'nutrition.view', 'nutrition.manage',\n];",
    "    'nutrition.view', 'nutrition.manage',\n    'notifications.view', 'notifications.manage',\n];",
)

# Dashboard availability, metrics, and navigation.
ensure_replace(
    'dashboard.php',
    "$canViewNutrition = $auth->can($user, 'nutrition.view') || $auth->can($user, 'nutrition.manage');\n",
    "$canViewNutrition = $auth->can($user, 'nutrition.view') || $auth->can($user, 'nutrition.manage');\n$canViewNotifications = $auth->can($user, 'notifications.view') || $auth->can($user, 'notifications.manage');\n",
)
phase11_block = '''$phase11Available = false;
if ($canViewNotifications) {
    $availability = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('household_notifications','household_calendar_events','notification_sync_runs')"
    );
    $availability->execute();
    $phase11Available = (int)$availability->fetchColumn() === 3;
}

$metrics = [];
if ($phase11Available) {
    $adultAccess = in_array((string)$user['role'], ['owner','administrator','adult_member'], true) ? 1 : 0;
    $notificationMetrics = $pdo->prepare(
        "SELECT
            SUM(status = 'unread') AS unread_count,
            SUM(priority IN ('high','critical') AND status IN ('unread','acknowledged')) AS urgent_count
         FROM household_notifications
         WHERE household_id = ? AND status <> 'expired'
           AND (
               visibility = 'household'
               OR (visibility = 'adults_only' AND ? = 1)
               OR (visibility = 'private' AND recipient_member_id = ?)
           )"
    );
    $notificationMetrics->execute([$householdId, $adultAccess, (int)$user['member_id']]);
    $notificationCounts = $notificationMetrics->fetch();
    if (is_array($notificationCounts)) {
        $metrics[] = ['label' => 'Unread alerts', 'value' => (int)$notificationCounts['unread_count'], 'prefix' => '', 'suffix' => '', 'href' => '/phase11.php'];
        $metrics[] = ['label' => 'Urgent alerts', 'value' => (int)$notificationCounts['urgent_count'], 'prefix' => '', 'suffix' => '', 'href' => '/phase11.php'];
    }
}
'''
ensure_replace('dashboard.php', '$metrics = [];', phase11_block)
ensure_replace(
    'dashboard.php',
    'Forecast, plan, assign, stock, grow, cook, preserve, balance household nutrition, measure cost and waste, and improve the next food cycle.',
    'Detect, notify, assign, stock, grow, cook, preserve, balance household nutrition, measure cost and waste, and improve the next food cycle.',
)
ensure_replace(
    'dashboard.php',
    '    <?php if ($phase10Available): ?><a class="panel" href="/phase10.php">',
    '    <?php if ($phase11Available): ?><a class="panel" href="/phase11.php"><p class="eyebrow">Notify</p><h2>Alerts & shared calendar</h2><p class="page-description" style="margin-top:12px">One permission-aware inbox for tasks, shortages, meals, harvest windows, use-by dates, finance reviews, nutrition follow-up, digests, and ICS calendar export.</p></a><?php endif; ?>\n    <?php if ($phase10Available): ?><a class="panel" href="/phase10.php">',
)

# README and installation order.
ensure_replace(
    'README.md',
    'Homestead is a household food system for growing, storing, cooking, preserving, planning, forecasting, balancing household nutrition, measuring cost and waste, and coordinating real food.',
    'Homestead is a household food system for growing, storing, cooking, preserving, planning, forecasting, balancing household nutrition, measuring cost and waste, coordinating real food, and delivering the next household action.',
)
ensure_replace(
    'README.md',
    '- `/phase10.php` — ingredient nutrition, dietary patterns, allergen controls, recipe nutrition, meal-plan assessments, and household wellness planning\n',
    '- `/phase10.php` — ingredient nutrition, dietary patterns, allergen controls, recipe nutrition, meal-plan assessments, and household wellness planning\n- `/phase11.php` — household alerts, member preferences, digests, shared calendar, and adapter-ready delivery outbox\n- `/phase11-calendar.php` — authenticated, permission-aware ICS calendar export\n',
)
ensure_replace(
    'README.md',
    '- `/api/phase10-health.php` — protected nutrition-profile, allergen, recipe-snapshot, meal-assessment, and recommendation validation\n',
    '- `/api/phase10-health.php` — protected nutrition-profile, allergen, recipe-snapshot, meal-assessment, and recommendation validation\n- `/api/phase11-health.php` — protected notification, calendar, digest, outbox, and lifecycle validation\n',
)
ensure_replace(
    'README.md',
    'database/phase10_nutrition_dietary_wellness.sql\n```',
    'database/phase10_nutrition_dietary_wellness.sql\ndatabase/phase11_alerts_calendar_notifications.sql\n```',
)
phase11_readme = '''## Alerts, notifications, and shared calendar

- Permission-aware household notification inbox
- Deterministic alerts from tasks, low stock, prepared-food dates, forecasts, seasonal plans, finance, nutrition, meals, and harvest windows
- Member-specific channel, category, priority, digest, quiet-hour, and preview preferences
- Household, adults-only, and member-private visibility enforcement
- Shared calendar and authenticated ICS export
- Daily and weekly digest records
- One provenance-linked Phase 7 task per notification
- Adapter-ready email and web-push outbox, disabled until configured
- Sensitive wellness preview redaction
- Protected relational, lifecycle, and stale-run diagnostics

Phase 11 does not dispatch email or web push through an external provider. It stores in-app notifications, calendar records, digests, and adapter-ready outbox payloads.

## Nutrition, dietary planning, and household wellness
'''
ensure_replace('README.md', '## Nutrition, dietary planning, and household wellness\n', phase11_readme)

# Authoritative validation matrix.
ensure_replace(
    '.github/workflows/php-lint.yml',
    '          php tests/phase10_static_audit.php\n',
    '          php tests/phase10_static_audit.php\n          php tests/phase11_static_audit.php\n',
)
workflow = Path('.github/workflows/php-lint.yml')
text = workflow.read_text()
if text.count('            database/phase11_alerts_calendar_notifications.sql\n') < 2:
    old = '            database/phase10_nutrition_dietary_wellness.sql\n'
    if text.count(old) != 2:
        raise SystemExit('Expected Phase 10 migration twice in php-lint.yml')
    text = text.replace(old, old + '            database/phase11_alerts_calendar_notifications.sql\n')
    workflow.write_text(text)
ensure_replace(
    '.github/workflows/php-lint.yml',
    '          php tests/phase10_integration.php\n',
    '          php tests/phase10_integration.php\n          php tests/phase11_integration.php\n',
)
ensure_replace(
    '.github/workflows/php-lint.yml',
    '      - name: Show server log on failure\n',
    '      - name: Run Phase 11 authenticated HTTP smoke suite\n        run: bash tests/phase11_http_smoke.sh\n\n      - name: Show server log on failure\n',
)

# Complete database audit.
ensure_replace(
    'tests/database_integration_audit.php',
    "    'nutrition_recommendations', 'nutrition_lifecycle_events',\n];",
    "    'nutrition_recommendations', 'nutrition_lifecycle_events',\n    'household_notification_settings', 'member_notification_preferences', 'notification_sync_runs',\n    'household_notifications', 'household_calendar_events', 'notification_delivery_outbox',\n    'notification_delivery_attempts', 'notification_digest_runs', 'notification_digest_items',\n    'notification_lifecycle_events',\n];",
)
ensure_replace(
    'tests/database_integration_audit.php',
    "    'Phase 10 recommendation uniqueness installed' => $indexExists($pdo, 'nutrition_recommendations', 'uq_nutrition_recommendation_generation'),\n",
    "    'Phase 10 recommendation uniqueness installed' => $indexExists($pdo, 'nutrition_recommendations', 'uq_nutrition_recommendation_generation'),\n    'Phase 11 notification uniqueness installed' => $indexExists($pdo, 'household_notifications', 'uq_household_notification_dedup'),\n    'Phase 11 calendar uniqueness installed' => $indexExists($pdo, 'household_calendar_events', 'uq_household_calendar_event'),\n    'Phase 11 outbox uniqueness installed' => $indexExists($pdo, 'notification_delivery_outbox', 'uq_notification_outbox_delivery'),\n    'Phase 11 digest uniqueness installed' => $indexExists($pdo, 'notification_digest_runs', 'uq_notification_digest_run'),\n",
)
ensure_replace(
    'tests/database_integration_audit.php',
    "$checks['foreign-key protections installed'] = $foreignKeyCount >= 90;",
    "$checks['foreign-key protections installed'] = $foreignKeyCount >= 105;",
)

# Whole-application static audit.
ensure_replace(
    'tests/application_static_audit.php',
    "$phase10 = $read('phase10.php');\n",
    "$phase10 = $read('phase10.php');\n$phase11 = $read('phase11.php');\n",
)
ensure_replace(
    'tests/application_static_audit.php',
    "    $read('api/phase10-health.php'),\n",
    "    $read('api/phase10-health.php'),\n    $read('api/phase11-health.php'),\n",
)
ensure_replace(
    'tests/application_static_audit.php',
    "'Role permission defaults include task finance and nutrition permissions'",
    "'Role permission defaults include task finance nutrition and notification permissions'",
)
ensure_replace(
    'tests/application_static_audit.php',
    "&& str_contains($auth, \"'nutrition.manage'\")",
    "&& str_contains($auth, \"'nutrition.manage'\") && str_contains($auth, \"'notifications.view'\") && str_contains($auth, \"'notifications.manage'\")",
)
ensure_replace(
    'tests/application_static_audit.php',
    "    'Phase 10 requires nutrition permissions and safety boundary' => str_contains($phase10, 'nutrition.view') && str_contains($phase10, 'nutrition.manage') && str_contains($phase10, 'not diagnosis, treatment, or medical advice'),\n",
    "    'Phase 10 requires nutrition permissions and safety boundary' => str_contains($phase10, 'nutrition.view') && str_contains($phase10, 'nutrition.manage') && str_contains($phase10, 'not diagnosis, treatment, or medical advice'),\n    'Phase 11 requires notification permissions and delivery boundary' => str_contains($phase11, 'notifications.view') && str_contains($phase11, 'notifications.manage') && str_contains($phase11, 'adapter-ready outside the app'),\n",
)
ensure_replace(
    'tests/application_static_audit.php',
    "    'CI imports Phase 10 migration' => str_contains($workflow, 'database/phase10_nutrition_dietary_wellness.sql'),\n",
    "    'CI imports Phase 10 migration' => str_contains($workflow, 'database/phase10_nutrition_dietary_wellness.sql'),\n    'CI imports Phase 11 migration' => str_contains($workflow, 'database/phase11_alerts_calendar_notifications.sql'),\n",
)
ensure_replace(
    'tests/application_static_audit.php',
    "    'CI runs Phase 10 integration' => str_contains($workflow, 'tests/phase10_integration.php'),\n",
    "    'CI runs Phase 10 integration' => str_contains($workflow, 'tests/phase10_integration.php'),\n    'CI runs Phase 11 integration' => str_contains($workflow, 'tests/phase11_integration.php'),\n",
)
ensure_replace(
    'tests/application_static_audit.php',
    "str_contains($workflow, 'tests/phase10_http_smoke.sh')",
    "str_contains($workflow, 'tests/phase10_http_smoke.sh') && str_contains($workflow, 'tests/phase11_http_smoke.sh')",
)

# Remove temporary synchronization code from the final feature branch.
for path in [
    '.github/workflows/phase11-finalize-sync.yml',
    '.github/workflows/phase11-finalize-runner.yml',
    '.github/workflows/phase11-complete.yml',
    'scripts/phase11_payload_core.py',
    'scripts/phase11_payload_tests.py',
    'scripts/phase11_integrate.py',
]:
    target = Path(path)
    if target.exists():
        target.unlink()

print('Phase 11 application integration applied.')
