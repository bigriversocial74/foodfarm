<?php

declare(strict_types=1);

$page = $_GET['page'] ?? 'dashboard';
$allowedPages = [
    'dashboard', 'family', 'inventory', 'recipes', 'garden', 'preservation',
    'shopping', 'grow-lights', 'storage', 'tasks', 'reports', 'settings'
];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

$pageTitles = [
    'dashboard' => 'Dashboard',
    'family' => 'Family Members',
    'inventory' => 'Pantry Inventory',
    'recipes' => 'Recipes & Meal Planning',
    'garden' => 'Garden Monitoring',
    'preservation' => 'Preservation Tracking',
    'shopping' => 'Shopping List',
    'grow-lights' => 'Grow Light Schedules',
    'storage' => 'Storage Locations',
    'tasks' => 'Tasks & Calendar',
    'reports' => 'Reports',
    'settings' => 'Settings',
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function navItem(string $slug, string $label, string $icon, string $active): string
{
    $class = $slug === $active ? 'nav-item active' : 'nav-item';
    return '<a class="' . $class . '" href="?page=' . e($slug) . '"><span class="nav-icon">' . $icon . '</span><span>' . e($label) . '</span></a>';
}

function metricCard(string $label, string $value, string $meta, string $icon, string $tone = 'neutral'): string
{
    return '<article class="metric-card tone-' . e($tone) . '"><div class="metric-icon">' . $icon . '</div><div><p>' . e($label) . '</p><strong>' . e($value) . '</strong><span>' . e($meta) . '</span></div></article>';
}

function statusBadge(string $label, string $tone = 'good'): string
{
    return '<span class="status status-' . e($tone) . '">' . e($label) . '</span>';
}

function renderDashboard(): void
{
    echo '<section class="metrics-grid">';
    echo metricCard('Pantry items', '612', '$1,248 estimated value', '◫', 'gold');
    echo metricCard('Family tasks', '8', '4 due today', '✓', 'green');
    echo metricCard('Plants growing', '32', '4 active zones', '♧', 'green');
    echo metricCard('Preservation', '5', '92 jars stored', '▱', 'gold');
    echo metricCard('Expiring soon', '12', 'Within 30 days', '!', 'warning');
    echo metricCard('System health', 'Good', 'All manual systems normal', '∿', 'green');
    echo '</section>';

    echo '<section class="content-grid dashboard-grid">';
    echo '<article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Household overview</p><h2>Everything in one food cycle</h2></div><span class="live-dot">Live mock data</span></div>';
    echo '<div class="overview-columns">';
    $items = [
        ['Pantry', 'Well stocked', '81%', '18 low-stock items', 'Grains & flours'],
        ['Garden', 'Thriving', '88%', 'Basil ready in 2 days', '32 plants growing'],
        ['Preservation', 'On track', '72%', '5 active batches', '2 labels due'],
        ['Family', 'Coordinated', '76%', '8 tasks this week', '4 members active'],
    ];
    foreach ($items as $item) {
        echo '<div class="overview-block"><div class="overview-title"><strong>' . e($item[0]) . '</strong>' . statusBadge($item[1]) . '</div><div class="progress"><span style="width:' . e($item[2]) . '"></span></div><p>' . e($item[3]) . '</p><small>' . e($item[4]) . '</small></div>';
    }
    echo '</div></article>';

    echo '<article class="panel"><div class="panel-heading"><div><p class="eyebrow">Today</p><h2>Quick actions</h2></div></div><div class="quick-actions">';
    $actions = ['Add inventory', 'Assign task', 'Start recipe', 'Log harvest', 'Start batch', 'Add shopping item'];
    foreach ($actions as $action) {
        echo '<button class="quick-action" type="button"><span>＋</span>' . e($action) . '</button>';
    }
    echo '</div></article>';

    echo '<article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Recent activity</p><h2>Household ledger</h2></div><a href="?page=reports">View report</a></div><div class="activity-list">';
    $activity = [
        ['Maya', 'Harvested basil from Herb Shelf', '10:20 AM', 'Garden'],
        ['David', 'Completed canned tomato batch', 'Yesterday', 'Preservation'],
        ['Elena', 'Added flour and oats to shopping list', 'Yesterday', 'Shopping'],
        ['Noah', 'Fed sourdough starter', 'Mon 8:05 AM', 'Kitchen'],
    ];
    foreach ($activity as $row) {
        echo '<div class="activity-row"><span class="avatar">' . e(substr($row[0], 0, 1)) . '</span><div><strong>' . e($row[0]) . '</strong><p>' . e($row[1]) . '</p></div><div class="activity-meta"><span>' . e($row[3]) . '</span><small>' . e($row[2]) . '</small></div></div>';
    }
    echo '</div></article>';

    echo '<article class="panel"><div class="panel-heading"><div><p class="eyebrow">Upcoming</p><h2>Tasks</h2></div><a href="?page=tasks">Calendar</a></div><div class="task-list">';
    $tasks = [
        ['Feed sourdough starter', 'Noah', '8:00 AM'],
        ['Harvest basil', 'Maya', '10:00 AM'],
        ['Rotate pantry stock', 'David', '2:00 PM'],
        ['Start apple dehydrator', 'Elena', '4:00 PM'],
    ];
    foreach ($tasks as $task) {
        echo '<label class="task-row"><input type="checkbox"><span><strong>' . e($task[0]) . '</strong><small>' . e($task[1]) . ' · ' . e($task[2]) . '</small></span></label>';
    }
    echo '</div></article></section>';
}

function renderFamily(): void
{
    echo '<section class="metrics-grid compact">';
    echo metricCard('Active members', '4', '3 adults · 1 youth', '♙', 'gold');
    echo metricCard('Tasks this week', '18', '13 completed', '✓', 'green');
    echo metricCard('Meals planned', '11', '36 servings assigned', '◉', 'neutral');
    echo metricCard('Skills in progress', '7', '2 ready for review', '✦', 'gold');
    echo '</section>';

    echo '<section class="content-grid family-layout"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Household</p><h2>Family members</h2></div><button class="button primary">Add member</button></div><div class="member-grid">';
    $members = [
        ['David', 'Owner', 'Adult', 'DE', 'Pantry planning · Bread', 8, 6, 'Independent'],
        ['Maya', 'Garden manager', 'Adult', 'MA', 'Garden · Harvest', 5, 5, 'Can teach'],
        ['Elena', 'Kitchen manager', 'Adult', 'EL', 'Meals · Preservation', 4, 3, 'Independent'],
        ['Noah', 'Youth member', 'Teen', 'NO', 'Sourdough · Microgreens', 3, 2, 'Learning'],
    ];
    foreach ($members as $member) {
        echo '<article class="member-card"><div class="member-top"><span class="member-avatar">' . e($member[3]) . '</span><div><h3>' . e($member[0]) . '</h3><p>' . e($member[1]) . ' · ' . e($member[2]) . '</p></div>' . statusBadge('Active') . '</div><div class="member-stats"><span><strong>' . e((string)$member[5]) . '</strong> assigned</span><span><strong>' . e((string)$member[6]) . '</strong> completed</span></div><p class="member-focus">' . e($member[4]) . '</p><div class="member-footer"><span>Skill level</span><strong>' . e($member[7]) . '</strong></div></article>';
    }
    echo '</div></article>';

    echo '<article class="panel"><div class="panel-heading"><div><p class="eyebrow">Responsibility load</p><h2>This week</h2></div></div><div class="bar-list">';
    $bars = [['David', 75], ['Maya', 92], ['Elena', 68], ['Noah', 54]];
    foreach ($bars as $bar) {
        echo '<div><div class="bar-label"><span>' . e($bar[0]) . '</span><strong>' . e((string)$bar[1]) . '%</strong></div><div class="progress"><span style="width:' . e((string)$bar[1]) . '%"></span></div></div>';
    }
    echo '</div></article>';

    echo '<article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Assignments</p><h2>Current responsibilities</h2></div><button class="button secondary">Assign task</button></div><div class="table-wrap"><table><thead><tr><th>Task</th><th>Member</th><th>Area</th><th>Due</th><th>Status</th></tr></thead><tbody>';
    $rows = [
        ['Feed sourdough starter', 'Noah', 'Kitchen', 'Today 8:00 AM', 'In progress'],
        ['Harvest basil', 'Maya', 'Garden', 'Today 10:00 AM', 'Ready'],
        ['Rotate freezer inventory', 'David', 'Storage', 'Today 2:00 PM', 'Planned'],
        ['Label tomato jars', 'Elena', 'Preservation', 'Tomorrow', 'Planned'],
        ['Check microgreen moisture', 'Noah', 'Garden', 'Tomorrow', 'Planned'],
    ];
    foreach ($rows as $row) {
        $tone = $row[4] === 'Ready' ? 'good' : ($row[4] === 'In progress' ? 'warning' : 'neutral');
        echo '<tr><td><strong>' . e($row[0]) . '</strong></td><td>' . e($row[1]) . '</td><td>' . e($row[2]) . '</td><td>' . e($row[3]) . '</td><td>' . statusBadge($row[4], $tone) . '</td></tr>';
    }
    echo '</tbody></table></div></article>';

    echo '<article class="panel"><div class="panel-heading"><div><p class="eyebrow">Household meals</p><h2>Serving profile</h2></div></div><div class="info-list"><div><span>Standard household meal</span><strong>4.5 servings</strong></div><div><span>Vegetarian preference</span><strong>2 members</strong></div><div><span>Allergy notes</span><strong>1 restricted</strong></div><div><span>Friday pizza night</span><strong>All members</strong></div></div></article></section>';
}

function renderInventory(): void
{
    echo '<section class="metrics-grid compact">';
    echo metricCard('Total items', '612', '$1,248 value', '◫', 'gold');
    echo metricCard('Low stock', '18', 'Needs attention', '!', 'warning');
    echo metricCard('Expiring soon', '12', 'Within 30 days', '◷', 'warning');
    echo metricCard('Locations', '8', '63% average capacity', '▥', 'neutral');
    echo '</section>';
    echo '<section class="content-grid"><article class="panel span-3"><div class="panel-heading"><div><p class="eyebrow">Inventory</p><h2>Bulk goods and pantry staples</h2></div><button class="button primary">Add inventory item</button></div><div class="toolbar"><input class="search-field" placeholder="Search inventory"><select><option>All locations</option><option>Kitchen hutch</option><option>Pantry</option><option>Freezer</option></select><select><option>All categories</option><option>Grains & flours</option><option>Beans & legumes</option><option>Preserves</option></select></div><div class="table-wrap"><table><thead><tr><th>Item</th><th>Location</th><th>Quantity</th><th>Reorder at</th><th>Status</th><th>Updated</th></tr></thead><tbody>';
    $items = [
        ['All-purpose flour', 'Kitchen hutch', '12.5 lb', '5 lb', 'Good', 'Today'],
        ['Hard red wheat', 'Grain bin', '52.4 lb', '20 lb', 'Good', 'Today'],
        ['Rolled oats', 'Pantry shelf 2', '8 lb', '3 lb', 'Good', 'Yesterday'],
        ['Black beans', 'Pantry shelf 2', '6 lb', '8 lb', 'Low', 'Yesterday'],
        ['Raw honey', 'Kitchen hutch', '3 jars', '2 jars', 'Good', 'Mon'],
        ['Canning jars — pint', 'Canning shelf', '24 jars', '12 jars', 'Good', 'Mon'],
        ['Prepared lentil soup', 'Refrigerator', '3 servings', '—', 'Use soon', 'Today'],
        ['Apple butter', 'Canning shelf', '5 jars', '2 jars', 'Expiring', 'May 1'],
    ];
    foreach ($items as $item) {
        $tone = $item[4] === 'Good' ? 'good' : 'warning';
        echo '<tr><td><strong>' . e($item[0]) . '</strong></td><td>' . e($item[1]) . '</td><td>' . e($item[2]) . '</td><td>' . e($item[3]) . '</td><td>' . statusBadge($item[4], $tone) . '</td><td>' . e($item[5]) . '</td></tr>';
    }
    echo '</tbody></table></div></article></section>';
}

function renderRecipes(): void
{
    echo '<section class="metrics-grid compact">';
    echo metricCard('Pantry-ready', '12', 'Can make today', '◉', 'green');
    echo metricCard('Suggested', '8', 'Based on inventory', '✦', 'gold');
    echo metricCard('Shopping gaps', '6', 'Ingredients missing', '▣', 'warning');
    echo metricCard('Meals planned', '11', 'This week', '□', 'neutral');
    echo '</section>';
    echo '<section class="content-grid recipes-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Frontier kitchen</p><h2>Recipes matched to your pantry</h2></div><button class="button primary">Add recipe</button></div><div class="recipe-grid">';
    $recipes = [
        ['Sourdough country loaf', '95% match', '4h 30m', 'Bread'],
        ['Cast-iron tortillas', '100% match', '35m', 'Dough'],
        ['Garden vegetable soup', '90% match', '50m', 'One pot'],
        ['Friday pizza dough', '92% match', '24h', 'Fermented'],
        ['Black bean stew', '88% match', '55m', 'Pantry meal'],
        ['Dehydrated herb crackers', '82% match', '2h', 'Preservation'],
    ];
    foreach ($recipes as $recipe) {
        echo '<article class="recipe-card"><div class="recipe-image"><span>' . e($recipe[3]) . '</span></div><div><h3>' . e($recipe[0]) . '</h3><p>' . e($recipe[2]) . '</p><div class="recipe-meta">' . statusBadge($recipe[1]) . '<button>View recipe</button></div></div></article>';
    }
    echo '</div></article>';
    echo '<article class="panel"><div class="panel-heading"><div><p class="eyebrow">Weekly plan</p><h2>Household meals</h2></div></div><div class="meal-days">';
    $days = [['Mon', 'Lentil soup'], ['Tue', 'Tortillas & beans'], ['Wed', 'Garden pasta'], ['Thu', 'Leftover night'], ['Fri', 'Homestead pizza'], ['Sat', 'Sourdough breakfast'], ['Sun', 'Roast vegetables']];
    foreach ($days as $day) {
        echo '<div><strong>' . e($day[0]) . '</strong><span>' . e($day[1]) . '</span></div>';
    }
    echo '</div></article></section>';
}

function renderGarden(): void
{
    echo '<section class="metrics-grid compact">';
    echo metricCard('Temperature', '72.4°F', 'Optimal', '♨', 'green');
    echo metricCard('Humidity', '45%', 'Optimal', '◉', 'green');
    echo metricCard('Soil moisture', '42%', 'Good', '◒', 'green');
    echo metricCard('Grow lights', '3 active', 'On schedule', '☼', 'gold');
    echo '</section>';
    echo '<section class="content-grid garden-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Grow room</p><h2>Zone overview</h2></div><span class="live-dot">Manual readings</span></div><div class="zone-map">';
    $zones = [
        ['Microgreens shelf', 'Healthy', '72.1°F · 48%', '16 trays'],
        ['Herb shelf', 'Healthy', '72.3°F · 44%', 'Basil, mint, thyme'],
        ['Vegetable bed', 'Thriving', '72.6°F · 46%', 'Lettuce, kale, tomatoes'],
        ['Seedling rack', 'Good', '71.8°F · 45%', '8 trays'],
    ];
    foreach ($zones as $zone) {
        echo '<article class="zone-card"><div class="zone-visual"></div><div><h3>' . e($zone[0]) . '</h3>' . statusBadge($zone[1]) . '<p>' . e($zone[2]) . '</p><small>' . e($zone[3]) . '</small></div></article>';
    }
    echo '</div></article><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Next harvests</p><h2>Harvest window</h2></div></div><div class="harvest-list">';
    $harvests = [['Basil', '2 days', 92], ['Arugula microgreens', '4 days', 86], ['Butter lettuce', '8 days', 68], ['Kale', '15 days', 48]];
    foreach ($harvests as $harvest) {
        echo '<div><div class="bar-label"><span>' . e($harvest[0]) . '</span><strong>' . e($harvest[1]) . '</strong></div><div class="progress"><span style="width:' . e((string)$harvest[2]) . '%"></span></div></div>';
    }
    echo '</div></article></section>';
}

function renderPreservation(): void
{
    echo '<section class="metrics-grid compact">';
    echo metricCard('Canning batches', '18', '92 jars total', '▱', 'gold');
    echo metricCard('Dehydrator runs', '7', '24 trays total', '▤', 'neutral');
    echo metricCard('Fermentations', '5', '2 active today', '◉', 'green');
    echo metricCard('Expiring', '6', 'Within 30 days', '!', 'warning');
    echo '</section>';
    echo '<section class="content-grid"><article class="panel span-3"><div class="panel-heading"><div><p class="eyebrow">Batch ledger</p><h2>Preservation tracking</h2></div><button class="button primary">Start batch</button></div><div class="table-wrap"><table><thead><tr><th>Batch</th><th>Method</th><th>Date</th><th>Yield</th><th>Location</th><th>Status</th></tr></thead><tbody>';
    $batches = [
        ['Canned tomatoes', 'Water bath', 'May 12', '7 jars', 'Canning shelf', 'Good'],
        ['Dill pickles', 'Water bath', 'May 8', '6 jars', 'Canning shelf', 'Good'],
        ['Dried apples', 'Dehydrating', 'May 5', '3 trays', 'Pantry shelf 3', 'Good'],
        ['Sauerkraut', 'Fermentation', 'Apr 20', '2 jars', 'Pantry shelf 1', 'Active'],
        ['Sourdough starter', 'Fermentation', 'Apr 15', '1 jar', 'Kitchen hutch', 'Active'],
        ['Apple butter', 'Water bath', 'Mar 20', '5 jars', 'Canning shelf', 'Expiring soon'],
    ];
    foreach ($batches as $batch) {
        $tone = str_contains($batch[5], 'Expiring') ? 'warning' : (str_contains($batch[5], 'Active') ? 'neutral' : 'good');
        echo '<tr><td><strong>' . e($batch[0]) . '</strong></td><td>' . e($batch[1]) . '</td><td>' . e($batch[2]) . '</td><td>' . e($batch[3]) . '</td><td>' . e($batch[4]) . '</td><td>' . statusBadge($batch[5], $tone) . '</td></tr>';
    }
    echo '</tbody></table></div></article></section>';
}

function renderShopping(): void
{
    echo '<section class="metrics-grid compact">';
    echo metricCard('Estimated total', '$123.14', '27 items', '$', 'gold');
    echo metricCard('High priority', '9', 'Restock soon', '!', 'warning');
    echo metricCard('Budget remaining', '$76.86', '38% available', '◒', 'green');
    echo metricCard('Vendors', '4', 'Suggested sources', '▣', 'neutral');
    echo '</section>';
    echo '<section class="content-grid shopping-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Pantry restock</p><h2>Smart shopping list</h2></div><button class="button primary">Add item</button></div><div class="shopping-list">';
    $items = [
        ['All-purpose flour', '5 lb', 'High', '$6.25'], ['Black beans', '5 lb', 'High', '$5.75'], ['Rolled oats', '10 lb', 'Medium', '$7.40'], ['Raw honey', '2 jars', 'Medium', '$14.00'], ['Canning lids', '24 lids', 'High', '$9.60'], ['Food-grade buckets', '2', 'Low', '$14.00']
    ];
    foreach ($items as $item) {
        $tone = $item[2] === 'High' ? 'warning' : ($item[2] === 'Low' ? 'good' : 'neutral');
        echo '<label class="shopping-row"><input type="checkbox"><span><strong>' . e($item[0]) . '</strong><small>' . e($item[1]) . '</small></span>' . statusBadge($item[2], $tone) . '<b>' . e($item[3]) . '</b></label>';
    }
    echo '</div></article><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Suggestions</p><h2>Why these items?</h2></div></div><div class="suggestion-list"><div><strong>Low stock</strong><span>Black beans and flour are below target.</span></div><div><strong>Planned recipes</strong><span>Pizza night needs mozzarella and yeast.</span></div><div><strong>Preservation</strong><span>Tomato harvest requires 12 pint jars.</span></div><div><strong>Wait for harvest</strong><span>Skip basil purchase; harvest due in 2 days.</span></div></div></article></section>';
}

function renderGrowLights(): void
{
    echo '<section class="metrics-grid compact">';
    echo metricCard('Active lights', '3', '1 zone off', '☼', 'gold');
    echo metricCard('Today', '2.84 kWh', '$0.43 estimated', '↯', 'neutral');
    echo metricCard('Avg intensity', '79%', 'Within target', '≋', 'green');
    echo metricCard('Overrides', '1', 'Vegetable bed dimmed', '!', 'warning');
    echo '</section>';
    echo '<section class="content-grid light-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Schedules</p><h2>Grow-light automation</h2></div><button class="button primary">Add schedule</button></div><div class="schedule-list">';
    $schedules = [
        ['Microgreens shelf', '6:00 AM', '10:00 PM', '95%', 'Vegetative', 'On'],
        ['Herb shelf', '5:30 AM', '9:30 PM', '80%', 'Vegetative', 'On'],
        ['Vegetable bed', '5:00 AM', '9:00 PM', '90%', 'Vegetative', 'On'],
        ['Seedling rack', '6:00 AM', '8:00 PM', '70%', 'Seedling', 'Off'],
        ['Vertical garden', '7:00 AM', '7:00 PM', '60%', 'Harvest prep', 'Off'],
    ];
    foreach ($schedules as $schedule) {
        echo '<article class="schedule-row"><div><h3>' . e($schedule[0]) . '</h3><p>' . e($schedule[4]) . ' mode</p></div><div><small>On</small><strong>' . e($schedule[1]) . '</strong></div><div><small>Off</small><strong>' . e($schedule[2]) . '</strong></div><div><small>Intensity</small><strong>' . e($schedule[3]) . '</strong></div>' . statusBadge($schedule[5], $schedule[5] === 'On' ? 'good' : 'neutral') . '</article>';
    }
    echo '</div></article><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Automation rules</p><h2>Active safeguards</h2></div></div><div class="rule-list"><label><input type="checkbox" checked><span><strong>High-temperature dimming</strong><small>Dim to 70% above 78°F</small></span></label><label><input type="checkbox" checked><span><strong>Weekend short cycle</strong><small>Reduce cycle by one hour</small></span></label><label><input type="checkbox" checked><span><strong>Manual override logging</strong><small>Record every schedule change</small></span></label></div></article></section>';
}

function renderStorage(): void
{
    echo '<section class="metrics-grid compact">';
    echo metricCard('Locations', '8', '651 items assigned', '▥', 'gold');
    echo metricCard('Average capacity', '63%', '1,248 of 1,983 slots', '◒', 'green');
    echo metricCard('Environment alerts', '2', 'Humidity concerns', '!', 'warning');
    echo metricCard('Moves suggested', '3', 'Improve access', '↔', 'neutral');
    echo '</section>';
    echo '<section class="content-grid storage-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Physical storage</p><h2>Location map</h2></div><button class="button primary">Add location</button></div><div class="location-grid">';
    $locations = [
        ['Kitchen hutch', 78, '154 items', '65°F · 45% RH'], ['Pantry shelf 2', 65, '186 items', '65°F · 45% RH'], ['Grain bin', 72, '92 items', '60°F · 35% RH'], ['Herb drawer', 38, '28 items', '60°F · 50% RH'], ['Canning shelf', 82, '88 items', '65°F · 45% RH'], ['Root cellar', 61, '67 items', '50°F · 85% RH'], ['Freezer', 57, '44 items', '0°F'], ['Storage room', 47, '42 items', '60°F · 50% RH']
    ];
    foreach ($locations as $location) {
        echo '<article class="location-card"><div class="location-icon">▥</div><div><h3>' . e($location[0]) . '</h3><p>' . e($location[2]) . '</p><small>' . e($location[3]) . '</small></div><div class="capacity"><strong>' . e((string)$location[1]) . '%</strong><div class="progress"><span style="width:' . e((string)$location[1]) . '%"></span></div></div></article>';
    }
    echo '</div></article><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Alerts</p><h2>Location attention</h2></div></div><div class="suggestion-list"><div><strong>Root cellar humidity</strong><span>85% RH — inspect root crops.</span></div><div><strong>Canning shelf capacity</strong><span>82% full — reserve space for tomatoes.</span></div><div><strong>Move grains</strong><span>Shift 6 items to Pantry Shelf 3.</span></div></div></article></section>';
}

function renderTasks(): void
{
    echo '<section class="content-grid"><article class="panel span-3"><div class="panel-heading"><div><p class="eyebrow">Household coordination</p><h2>Tasks & calendar</h2></div><button class="button primary">Create task</button></div><div class="calendar-grid">';
    $days = ['Mon 13', 'Tue 14', 'Wed 15', 'Thu 16', 'Fri 17', 'Sat 18', 'Sun 19'];
    foreach ($days as $index => $day) {
        echo '<div class="calendar-day"><strong>' . e($day) . '</strong>';
        if ($index === 0) echo '<span>Feed starter</span><span>Harvest basil</span><span>Rotate stock</span>';
        if ($index === 1) echo '<span>Water seedlings</span><span>Check freezer</span>';
        if ($index === 4) echo '<span>Pizza dough</span><span>Meal night</span>';
        if ($index === 6) echo '<span>Weekly inventory</span>';
        echo '</div>';
    }
    echo '</div></article></section>';
}

function renderReports(): void
{
    echo '<section class="metrics-grid compact">';
    echo metricCard('Food coverage', '42 days', 'Current household reserve', '◫', 'green');
    echo metricCard('Monthly spend', '$386', '8% under target', '$', 'gold');
    echo metricCard('Waste prevented', '18 lb', 'This month', '♧', 'green');
    echo metricCard('Garden yield', '26.4 lb', 'Year to date', '✦', 'neutral');
    echo '</section><section class="content-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Trends</p><h2>Household food activity</h2></div></div><div class="fake-chart"><span style="height:42%"></span><span style="height:58%"></span><span style="height:47%"></span><span style="height:70%"></span><span style="height:65%"></span><span style="height:82%"></span><span style="height:74%"></span><span style="height:90%"></span></div></article><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Top outcomes</p><h2>This month</h2></div></div><div class="info-list"><div><span>Bulk-purchase savings</span><strong>$74</strong></div><div><span>Meals from pantry</span><strong>31</strong></div><div><span>Prepared servings frozen</span><strong>18</strong></div><div><span>Preserved jars added</span><strong>19</strong></div></div></article></section>';
}

function renderSettings(): void
{
    echo '<section class="content-grid settings-grid"><article class="panel span-2"><div class="panel-heading"><div><p class="eyebrow">Household</p><h2>General settings</h2></div><button class="button primary">Save changes</button></div><form class="settings-form"><label>Household name<input value="Evans Homestead"></label><label>Default measurement system<select><option>US customary</option><option>Metric</option></select></label><label>Default currency<select><option>USD ($)</option></select></label><label>Expiration warning window<select><option>30 days</option><option>14 days</option><option>7 days</option></select></label><label class="full">Household description<textarea>Frontier-style cooking, indoor growing, bulk pantry storage, and family food planning.</textarea></label></form></article><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Manual-first</p><h2>Automation mode</h2></div></div><div class="rule-list"><label><input type="checkbox" checked><span><strong>Simulate sensors</strong><small>Use demonstration readings in V1.</small></span></label><label><input type="checkbox" checked><span><strong>Require confirmation</strong><small>Confirm all inventory deductions.</small></span></label><label><input type="checkbox"><span><strong>Enable device adapters</strong><small>Reserved for future hardware.</small></span></label></div></article></section>';
}

$renderers = [
    'dashboard' => 'renderDashboard', 'family' => 'renderFamily', 'inventory' => 'renderInventory',
    'recipes' => 'renderRecipes', 'garden' => 'renderGarden', 'preservation' => 'renderPreservation',
    'shopping' => 'renderShopping', 'grow-lights' => 'renderGrowLights', 'storage' => 'renderStorage',
    'tasks' => 'renderTasks', 'reports' => 'renderReports', 'settings' => 'renderSettings',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#17130f">
    <title><?= e($pageTitles[$page]) ?> · Homestead</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a href="?page=dashboard" class="brand"><span class="brand-mark">H</span><span><strong>Homestead</strong><small>Household food system</small></span></a>
        <nav>
            <p class="nav-heading">Main</p>
            <?= navItem('dashboard', 'Dashboard', '⌂', $page) ?>
            <?= navItem('family', 'Family Members', '♙', $page) ?>
            <?= navItem('inventory', 'Pantry Inventory', '◫', $page) ?>
            <?= navItem('recipes', 'Recipes & Meal Planning', '▤', $page) ?>
            <?= navItem('garden', 'Garden Monitoring', '♧', $page) ?>
            <?= navItem('preservation', 'Preservation Tracking', '▱', $page) ?>
            <?= navItem('grow-lights', 'Grow Light Schedules', '☼', $page) ?>
            <p class="nav-heading">Manage</p>
            <?= navItem('shopping', 'Shopping List', '▣', $page) ?>
            <?= navItem('storage', 'Storage Locations', '▥', $page) ?>
            <?= navItem('tasks', 'Tasks & Calendar', '□', $page) ?>
            <p class="nav-heading">System</p>
            <?= navItem('reports', 'Reports', '▥', $page) ?>
            <?= navItem('settings', 'Settings', '⚙', $page) ?>
        </nav>
        <div class="sidebar-foot"><p>V1 application shell</p><small>Manual data · simulated sensors</small></div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <button class="icon-button menu-button" id="menuButton" aria-label="Toggle menu">☰</button>
            <div class="top-search"><span>⌕</span><input placeholder="Search Homestead"></div>
            <div class="top-actions"><button class="icon-button" aria-label="Notifications">♢<span class="notification-count">3</span></button><button class="profile-button"><span class="avatar">DE</span><span><strong>David Evans</strong><small>Household owner</small></span><b>⌄</b></button></div>
        </header>

        <div class="page-container">
            <header class="page-header">
                <div><p class="eyebrow">Homestead V1</p><h1><?= e($pageTitles[$page]) ?></h1><p class="page-description">Plan, grow, store, cook, preserve, and coordinate the household from one connected system.</p></div>
                <div class="page-header-art"><span>Grow</span><span>Store</span><span>Cook</span><span>Preserve</span></div>
            </header>
            <?php $renderers[$page](); ?>
        </div>
    </main>
</div>
<div class="toast" id="toast">Demo action recorded.</div>
<script src="assets/js/app.js"></script>
</body>
</html>
